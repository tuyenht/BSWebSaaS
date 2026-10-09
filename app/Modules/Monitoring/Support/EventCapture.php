<?php

declare(strict_types=1);

namespace App\Modules\Monitoring\Support;

use App\Modules\Shared\Support\RequestId;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\ResolvedHost;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use PDOException;
use Throwable;

/**
 * Turns a fault into an ErrorEvent at the moment it is reported — the tenant context and the
 * signed-in user are gone once the request ends, so they are read now. Runs NO SQL: after a
 * database fault the connection refuses every statement until the request's transaction rolls
 * back. The site comes from the current-site holder or the host resolved earlier in the request,
 * the user id only when the guard already holds a loaded user, the request id only when it looks
 * like one. Everything else a request carries is left where it is.
 */
final readonly class EventCapture
{
    public function __construct(private Application $app) {}

    /**
     * A fault on the report path. Inside a job, $jobSiteId (the job's own site id) names the site
     * when no site context is open any more — a job's tenant middleware closes it before the
     * worker reports.
     */
    public function fromThrowable(Throwable $e, ?string $job, ?string $command, ?int $jobSiteId = null): ErrorEvent
    {
        [$file, $line] = $this->firstApplicationFrame($e);
        $request = $this->httpRequest();
        $kind = match (true) {
            $request?->route() instanceof Route => ErrorEvent::KIND_REQUEST,
            $job !== null => ErrorEvent::KIND_JOB,
            $command !== null => ErrorEvent::KIND_COMMAND,
            $request !== null => ErrorEvent::KIND_REQUEST,
            default => ErrorEvent::KIND_OTHER,
        };

        return $this->event(
            fingerprint: substr(hash('sha256', get_debug_type($e).'|'.$file.'|'.$line), 0, 20),
            kind: $kind,
            e: $e,
            location: $file.':'.$line,
            request: $request,
            job: $job,
            command: $command,
            jobSiteId: $jobSiteId,
        );
    }

    /** A queued job that failed for good: grouped by job class; the site is the job's own. */
    public function forJob(Throwable $e, string $job, ?int $siteId): ErrorEvent
    {
        [$file, $line] = $this->firstApplicationFrame($e);

        return $this->event(
            fingerprint: substr(hash('sha256', 'job|'.$job), 0, 20),
            kind: ErrorEvent::KIND_JOB,
            e: $e,
            location: $file.':'.$line,
            request: $this->httpRequest(),
            job: $job,
            command: null,
            siteId: $siteId,
        );
    }

    /** A scheduled command that failed: grouped by command name. */
    public function forCommand(Throwable $e, string $command): ErrorEvent
    {
        [$file, $line] = $this->firstApplicationFrame($e);

        return $this->event(
            fingerprint: substr(hash('sha256', 'command|'.$command), 0, 20),
            kind: ErrorEvent::KIND_COMMAND,
            e: $e,
            location: $file.':'.$line,
            request: null,
            job: null,
            command: $command,
        );
    }

    private function event(
        string $fingerprint,
        string $kind,
        Throwable $e,
        string $location,
        ?Request $request,
        ?string $job,
        ?string $command,
        ?int $siteId = null,
        ?int $jobSiteId = null,
    ): ErrorEvent {
        $route = $request?->route();
        $route = $route instanceof Route ? $route : null;
        $host = $request?->attributes->get(ResolvedHost::class);
        $host = $host instanceof ResolvedHost ? $host : null;
        $current = $this->app->make(CurrentSite::class);

        return new ErrorEvent(
            fingerprint: $fingerprint,
            kind: $kind,
            occurredAt: now()->getTimestamp(),
            environment: (string) $this->app->environment(),
            siteId: $siteId ?? ($current->hasId() ? $current->id() : ($jobSiteId ?? $host?->siteId)),
            host: $host?->primaryHost,
            routeName: $route?->getName(),
            routePattern: $route?->uri(),
            method: $request?->getMethod(),
            job: $job,
            command: $command,
            exception: get_debug_type($e),
            location: $location,
            sqlState: $this->sqlState($e),
            userId: $this->loadedUserId(),
            requestId: $request !== null ? RequestId::accept($request->headers->get(RequestId::HEADER)) : null,
        );
    }

    /**
     * The request being served, if this is one. A console process has a placeholder request with
     * no route; that one is not a visitor's request and says nothing.
     */
    private function httpRequest(): ?Request
    {
        if (! $this->app->bound('request')) {
            return null;
        }

        $request = $this->app->make('request');

        if ($this->app->runningInConsole() && ! $request->route() instanceof Route) {
            return null;
        }

        return $request;
    }

    /**
     * The numeric id of a user the guard has ALREADY loaded. Asking the guard for its user would
     * read the session and query the users table — never on this path.
     */
    private function loadedUserId(): ?int
    {
        if (! $this->app->resolved('auth')) {
            return null;
        }

        $auth = $this->app->make('auth');

        if (! $auth->hasResolvedGuards()) {
            return null;
        }

        $guard = $auth->guard();

        if (! $guard->hasUser()) {
            return null;
        }

        $id = $guard->user()?->getAuthIdentifier();

        return match (true) {
            is_int($id) => $id,
            is_string($id) && ctype_digit($id) => (int) $id,
            default => null,
        };
    }

    /**
     * Where in OUR code it happened: the throw site when it is ours, else the nearest caller that
     * is — so a fault raised inside the framework is pinned to the line of ours that called it.
     * Paths are relative to the project root.
     *
     * @return array{string, int}
     */
    private function firstApplicationFrame(Throwable $e): array
    {
        $base = rtrim($this->app->basePath(), '/').'/';
        $vendor = $base.'vendor/';
        $frames = [['file' => $e->getFile(), 'line' => $e->getLine()], ...$e->getTrace()];

        foreach ($frames as $frame) {
            $file = $frame['file'] ?? null;
            $line = $frame['line'] ?? null;

            if (is_string($file) && is_int($line) && str_starts_with($file, $base) && ! str_starts_with($file, $vendor)) {
                return [substr($file, strlen($base)), $line];
            }
        }

        $file = $e->getFile();

        return [str_starts_with($file, $base) ? substr($file, strlen($base)) : basename($file), $e->getLine()];
    }

    /** A database error contributes its SQLSTATE code — never its message, SQL or bindings. */
    private function sqlState(Throwable $e): ?string
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof PDOException) {
                $state = $cause->errorInfo[0] ?? $cause->getCode();

                return is_string($state) && preg_match('/\A[0-9A-Z]{5}\z/', $state) === 1 ? $state : null;
            }
        }

        return null;
    }
}
