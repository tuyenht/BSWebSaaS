<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use App\Modules\Forms\Support\FormDraft;
use App\Modules\Shared\Support\Cast;

/**
 * «Gửi thông báo tới» of the Email screen: the addresses a website wants its notices sent to,
 * stored as `email.notify_to` ({"emails": [...]}) in its settings. They are only what a NEW form
 * starts with as its recipients (the «Biểu mẫu» editor); a form that exists keeps its own list,
 * an empty one included, so saving this never changes who an existing form notifies.
 */
final readonly class MailNotifyTo
{
    public const string GROUP = 'email';

    public const string KEY = 'notify_to';

    /** As many as one form may notify, so the whole list always fits a new form. */
    public const int MAX = FormDraft::MAX_EMAILS;

    public function __construct(private SettingsRepository $settings) {}

    /**
     * The stored addresses: well-formed, each once (case aside), at most MAX, in their order.
     *
     * @return list<string>
     */
    public function emails(): array
    {
        return self::clean(Cast::strings(Cast::arr($this->settings->get(self::GROUP, self::KEY))['emails'] ?? null));
    }

    /** @param list<string> $emails */
    public function put(array $emails): void
    {
        $this->settings->put(self::GROUP, self::KEY, ['emails' => self::clean($emails)]);
    }

    /**
     * @param  list<string>  $emails
     * @return list<string>
     */
    public static function clean(array $emails): array
    {
        $out = [];
        $seen = [];
        foreach ($emails as $email) {
            $email = trim($email);
            $folded = mb_strtolower($email);
            if ($email === '' || isset($seen[$folded]) || ! FormDraft::isEmail($email)) {
                continue;
            }
            $seen[$folded] = true;
            $out[] = $email;
            if (count($out) === self::MAX) {
                break;
            }
        }

        return $out;
    }
}
