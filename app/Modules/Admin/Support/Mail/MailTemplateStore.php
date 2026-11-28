<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Mail;

use App\Modules\Admin\Models\MailTemplate;
use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;

/**
 * Writes the current website's templates: its edited copies (mail_templates, one row per template and
 * language, through the website's scope and RLS) and its on/off switches. The caller has checked the
 * template, the language and the text (Livewire EmailTemplates); this keeps the rows' shape.
 */
final class MailTemplateStore
{
    public function __construct(
        private readonly CurrentSite $current,
        private readonly SettingsRepository $settings,
    ) {}

    /** One template in one language, cleaned already: written over what is stored (one statement). */
    public function put(string $key, string $locale, string $subject, string $body): void
    {
        $now = now();
        MailTemplate::query()->upsert(
            [['site_id' => $this->current->id(), 'template_key' => $key, 'locale' => $locale, 'subject' => $subject, 'body' => $body, 'created_at' => $now, 'updated_at' => $now]],
            ['site_id', 'template_key', 'locale'],
            ['subject', 'body', 'updated_at'],
        );
    }

    /** «Về mẫu gốc»: the template's copies in every language go; the number removed. */
    public function forget(string $key): int
    {
        return Cast::int(MailTemplate::query()->where('template_key', $key)->delete());
    }

    /**
     * «Về mẫu gốc tất cả»: the copies of the templates the website sees (all of them without a list).
     *
     * @param  list<string>|null  $keys
     */
    public function forgetAll(?array $keys = null): int
    {
        return Cast::int(MailTemplate::query()->when($keys !== null, fn ($q) => $q->whereIn('template_key', $keys))->delete());
    }

    /** A switch: the template goes on the website's off list, or comes off it. */
    public function setOff(string $key, bool $off): void
    {
        $this->settings->change('email', 'templates', static function (?array $value) use ($key, $off): array {
            $list = array_values(array_filter(Cast::arr($value['off'] ?? null), static fn (mixed $k): bool => is_string($k) && $k !== $key && MailTemplateCatalog::switchable($k)));
            if ($off) {
                $list[] = $key;
            }

            return ['off' => $list];
        });
        app(MailTemplates::class)->forgetOff();
    }
}
