<?php

namespace App\Modules\Shared\Support;

/**
 * Text a person typed — a visitor's message, a member's name, a reason — on its way into a
 * notification mail. MailMessage lines are rendered as Markdown, so untouched text can draw a
 * link, an image, a heading or bold type inside a mail that carries the sender's name. Blade
 * escapes the HTML characters (< > & and quotes) before the Markdown pass, which is why they are
 * left alone here; this neutralises the Markdown, so the mail shows the text as it was typed.
 */
final class MailText
{
    public static function plain(string $text): string
    {
        // One line, as MailMessage::line() makes of it anyway: only its start can open a block.
        $line = trim((string) preg_replace('/\s+/', ' ', $text));

        // Anywhere: the escape itself, code, emphasis, links and images, table cells, fences.
        $line = (string) preg_replace('/([\\\\`*_\[\]|~])/', '\\\\$1', $line);

        // At the start: a heading, a bullet, a rule or an underline.
        $line = (string) preg_replace('/^([#+=-])/', '\\\\$1', $line);

        // At the start: a numbered list ("1." or "1)").
        return (string) preg_replace('/^(\d{1,9})([.)])/', '$1\\\\$2', $line);
    }
}
