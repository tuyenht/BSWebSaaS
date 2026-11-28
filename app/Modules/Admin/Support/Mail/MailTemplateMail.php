<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Mail;

use App\Modules\Admin\Support\MailBrand;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * A mail built from its template when the website or the platform worded it (MailTemplates): the
 * notifications that have a template ask here first and keep their own wording in code when this
 * gives null. The template goes into the shared mail frame (notifications::email) in place of the
 * frame's greeting, lines and button, so the logo, colour and signature stay the website's.
 */
final class MailTemplateMail
{
    public function __construct(
        private readonly MailTemplates $templates,
        private readonly MailTemplateRenderer $renderer,
        private readonly MailBrand $brand,
    ) {}

    /**
     * @param  array<string, string>  $values  the mail's own `{name}` values (the website's are added)
     */
    public function message(string $key, array $values, ?string $locale = null): ?MailMessage
    {
        $locale ??= app()->getLocale();
        try {
            $template = $this->templates->stored($key, $locale);
        } catch (Throwable $e) {
            // A template that cannot be read never stops the mail: it goes out in its wording in code.
            report($e);

            return null;
        }
        if ($template === null) {
            return null;
        }

        $all = $this->values($template['locale'], $values);
        $look = $this->brand->current();
        $color = $look['color'] ?? MailBrand::FALLBACK_COLOR;
        $ink = $look['text'] ?? '#ffffff';
        $html = $this->renderer->html($template['body'], $all, $color, $ink);

        return (new MailMessage)
            ->subject($this->renderer->subject($template['subject'], $all))
            ->markdown('notifications::email', [
                'templateHtml' => new HtmlString($html),
                'templateText' => MailTemplateRenderer::text($html),
            ]);
    }

    /**
     * The website's values with the mail's own over them.
     *
     * @param  array<string, string>  $values
     * @return array<string, string>
     */
    public function values(string $locale, array $values): array
    {
        return array_merge($this->brand->values($locale), $values);
    }
}
