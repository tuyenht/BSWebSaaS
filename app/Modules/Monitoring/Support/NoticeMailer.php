<?php

declare(strict_types=1);

namespace App\Modules\Monitoring\Support;

use App\Modules\Shared\Support\Cast;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Mail\Message;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;

/**
 * Writes and sends the error notices on the dedicated `monitoring` mailer — named on every send,
 * never the default mailer (which the per-tenant mail settings rewrite), never a fallback: when
 * the recipient, the sender address or the mail host is missing, nothing is sent. Every message
 * carries its own From and Reply-To (the platform's sender), so neither the tenant's global
 * sender nor a customer's reply-to can attach to it. Language and time zone come from
 * config/monitoring.php, never from the request that failed. Plain text only.
 */
final readonly class NoticeMailer
{
    public function __construct(private MailFactory $mail) {}

    public static function enabled(): bool
    {
        $mailer = self::mailerName();

        return self::recipients() !== []
            && filled(config("mail.mailers.{$mailer}.from.address"))
            && filled(config("mail.mailers.{$mailer}.host"));
    }

    public function sendNotice(ErrorEvent $event, string $reason, int $count): void
    {
        $site = $this->siteLabel($event);
        $subject = $reason === 'milestone'
            ? $this->t('monitoring.mail.subject.milestone', ['site' => $site, 'count' => $this->number($count)])
            : $this->t('monitoring.mail.subject.first', ['site' => $site, 'doing' => $this->doing($event)]);

        $intro = $reason === 'milestone'
            ? $this->t('monitoring.mail.intro.milestone', ['count' => $this->number($count)])
            : $this->t('monitoring.mail.intro.first', ['doing' => $this->doing($event)]);

        $lines = [
            $intro,
            '',
            $this->t('monitoring.mail.details'),
            '',
            ...$this->fieldLines($event, $count),
            '',
            $this->t('monitoring.mail.footer'),
        ];

        $this->send($subject, implode("\n", $lines));
    }

    public function sendSummary(string $subject, string $body): void
    {
        $this->send($subject, $body."\n\n".$this->t('monitoring.mail.footer'));
    }

    /**
     * The allow-listed fields of one event, one "Label: value" line each; empty fields are left out.
     *
     * @return list<string>
     */
    public function fieldLines(ErrorEvent $event, ?int $count = null): array
    {
        $route = $event->routeName !== null && $event->routePattern !== null
            ? $event->routeName.' ('.$event->routePattern.')'
            : ($event->routeName ?? $event->routePattern);

        $fields = [
            'time' => $this->t('monitoring.mail.local_time', ['time' => $this->localTime($event->occurredAt)]),
            'environment' => $event->environment,
            'site' => $event->siteId === null
                ? $this->t('monitoring.mail.site_platform')
                : '#'.$event->siteId.($event->host !== null ? ' ('.$event->host.')' : ''),
            'route' => $route,
            'method' => $event->method,
            'job' => $event->job,
            'command' => $event->command,
            'exception' => $event->exception,
            'sqlstate' => $event->sqlState,
            'location' => $event->location,
            'count' => $count !== null ? $this->number($count) : null,
            'user' => $event->userId !== null ? (string) $event->userId : null,
            'request_id' => $event->requestId,
        ];

        $lines = [];
        foreach ($fields as $field => $value) {
            if ($value !== null && $value !== '') {
                $lines[] = $this->t("monitoring.mail.field.{$field}").': '.$value;
            }
        }

        return $lines;
    }

    public function siteLabel(ErrorEvent $event): string
    {
        return match (true) {
            $event->siteId === null => $this->t('monitoring.mail.site_platform'),
            $event->host !== null => $this->t('monitoring.mail.site_named', ['id' => (string) $event->siteId, 'host' => $event->host]),
            default => $this->t('monitoring.mail.site_numbered', ['id' => (string) $event->siteId]),
        };
    }

    /** @param  array<string, string>  $replace */
    public function t(string $key, array $replace = []): string
    {
        $line = __($key, $replace, $this->locale());

        return is_string($line) ? $line : $key;
    }

    public function number(int $value): string
    {
        $formatted = Number::format($value, locale: $this->locale());

        return is_string($formatted) ? $formatted : (string) $value;
    }

    public function localTime(int $timestamp, string $format = 'd/m/Y H:i:s'): string
    {
        $zone = config('monitoring.timezone');

        return Carbon::createFromTimestamp($timestamp, is_string($zone) && $zone !== '' ? $zone : 'UTC')->format($format);
    }

    private function doing(ErrorEvent $event): string
    {
        return match ($event->kind) {
            ErrorEvent::KIND_REQUEST => $this->t('monitoring.mail.doing.page'),
            ErrorEvent::KIND_JOB => $this->t('monitoring.mail.doing.job'),
            ErrorEvent::KIND_COMMAND => $this->t('monitoring.mail.doing.command'),
            default => $this->t('monitoring.mail.doing.other'),
        };
    }

    private function send(string $subject, string $body): void
    {
        if (! self::enabled()) {
            return;
        }

        $mailer = self::mailerName();
        $from = Cast::string(config("mail.mailers.{$mailer}.from.address"));
        $name = Cast::string(config("mail.mailers.{$mailer}.from.name"));
        $name = $name !== '' ? $name : Cast::string(config('admin.brand.name'), Cast::string(config('app.name')));
        $to = self::recipients();

        $this->mail->mailer($mailer)->raw($body, function (Message $message) use ($from, $name, $to, $subject): void {
            $message->from($from, $name)
                ->replyTo($from, $name)
                ->to($to)
                ->subject($subject);
        });
    }

    private function locale(): string
    {
        $locale = config('monitoring.locale');

        return is_string($locale) && $locale !== '' ? $locale : 'vi';
    }

    private static function mailerName(): string
    {
        $mailer = config('monitoring.mailer');

        return is_string($mailer) && $mailer !== '' ? $mailer : 'monitoring';
    }

    /** @return list<string> */
    private static function recipients(): array
    {
        $to = config('monitoring.to');

        if (! is_string($to)) {
            return [];
        }

        return array_values(array_filter(array_map(trim(...), explode(',', $to)), fn (string $address): bool => $address !== ''));
    }
}
