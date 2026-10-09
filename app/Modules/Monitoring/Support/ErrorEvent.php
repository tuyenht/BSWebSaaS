<?php

declare(strict_types=1);

namespace App\Modules\Monitoring\Support;

/**
 * One reported fault, reduced to the ALLOW-LIST of fields an error notice may carry — and the
 * sample kept in Valkey holds exactly these. There is deliberately no field for the exception
 * message, the real path, the query string, any URL, the request body, headers, cookies, the
 * session, the stack trace, SQL text or bindings, or a job's arguments and payload: customer
 * text lives in all of those, and it stays in the server log.
 */
final readonly class ErrorEvent
{
    public const string KIND_REQUEST = 'request';

    public const string KIND_JOB = 'job';

    public const string KIND_COMMAND = 'command';

    public const string KIND_OTHER = 'other';

    public function __construct(
        public string $fingerprint,
        public string $kind,
        public int $occurredAt,
        public string $environment,
        public ?int $siteId = null,
        public ?string $host = null,
        public ?string $routeName = null,
        public ?string $routePattern = null,
        public ?string $method = null,
        public ?string $job = null,
        public ?string $command = null,
        public ?string $exception = null,
        public ?string $location = null,
        public ?string $sqlState = null,
        public ?int $userId = null,
        public ?string $requestId = null,
    ) {}

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'fingerprint' => $this->fingerprint,
            'kind' => $this->kind,
            'occurredAt' => $this->occurredAt,
            'environment' => $this->environment,
            'siteId' => $this->siteId,
            'host' => $this->host,
            'routeName' => $this->routeName,
            'routePattern' => $this->routePattern,
            'method' => $this->method,
            'job' => $this->job,
            'command' => $this->command,
            'exception' => $this->exception,
            'location' => $this->location,
            'sqlState' => $this->sqlState,
            'userId' => $this->userId,
            'requestId' => $this->requestId,
        ];
    }

    /** @param  array<mixed>  $data */
    public static function fromArray(array $data): ?self
    {
        $string = fn (string $key): ?string => isset($data[$key]) && is_string($data[$key]) ? $data[$key] : null;
        $int = fn (string $key): ?int => isset($data[$key]) && is_int($data[$key]) ? $data[$key] : null;

        $fingerprint = $string('fingerprint');
        $kind = $string('kind');
        $occurredAt = $int('occurredAt');

        if ($fingerprint === null || $kind === null || $occurredAt === null) {
            return null;
        }

        return new self(
            fingerprint: $fingerprint,
            kind: $kind,
            occurredAt: $occurredAt,
            environment: $string('environment') ?? '',
            siteId: $int('siteId'),
            host: $string('host'),
            routeName: $string('routeName'),
            routePattern: $string('routePattern'),
            method: $string('method'),
            job: $string('job'),
            command: $string('command'),
            exception: $string('exception'),
            location: $string('location'),
            sqlState: $string('sqlState'),
            userId: $int('userId'),
            requestId: $string('requestId'),
        );
    }
}
