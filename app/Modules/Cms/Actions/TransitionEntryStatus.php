<?php

namespace App\Modules\Cms\Actions;

use App\Modules\Cms\Enums\EntryStatus;
use App\Modules\Cms\Exceptions\WorkflowGovernedException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Support\EntryWorkflowResolver;
use App\Modules\Identity\Models\User;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Workflow Tầng-1 lifecycle transitions the Pages admin drives from the row status menu:
 * draft (take offline), scheduled (auto-publish at published_at), private (finished but
 * deliberately non-public), archived (retired). Going straight to `published` is NOT here —
 * that must run through PublishEntry so the canonical route + 301 stay in sync — and `pending`
 * is only ever the output of a submit. Every transition needs the `publish` ability: changing
 * a page's lifecycle (above all taking a live page offline) is a publisher control, the same
 * guard PublishEntry applies so a submit-only author cannot unpublish live content.
 *
 * `scheduled` is the one target that later ENDS in `published` (via entries:publish-due), so
 * where an approval workflow governs the entry it is refused like a direct publish would be —
 * otherwise "schedule it for a minute from now" would walk straight around the gate. The other
 * targets only ever take content off the public site, which no gate needs to approve.
 */
final readonly class TransitionEntryStatus
{
    /** States this action may set — published/pending are excluded by design (see class doc). */
    private const array ALLOWED = [
        EntryStatus::Draft->value,
        EntryStatus::Scheduled->value,
        EntryStatus::Private->value,
        EntryStatus::Archived->value,
    ];

    public function __construct(private EntryWorkflowResolver $workflows) {}

    /**
     * @throws WorkflowGovernedException scheduling content an active workflow governs
     */
    public function handle(Entry $entry, EntryStatus $target, User $actor, ?DateTimeInterface $publishAt = null): void
    {
        if (! in_array($target->value, self::ALLOWED, true)) {
            throw new InvalidArgumentException("Trạng thái “{$target->value}” phải đi qua quy trình đăng, không đặt trực tiếp.");
        }

        if (! Gate::forUser($actor)->allows('publish', $entry)) {
            throw new AuthorizationException('Bạn không có quyền đổi trạng thái nội dung này.');
        }

        if ($target === EntryStatus::Scheduled) {
            if ($publishAt === null || $publishAt <= now()) {
                throw new InvalidArgumentException('Lên lịch cần một thời điểm trong tương lai.');
            }

            if ($this->workflows->forEntry($entry) !== null) {
                throw new WorkflowGovernedException;
            }

            $entry->update(['status' => EntryStatus::Scheduled->value, 'published_at' => $publishAt]);

            return;
        }

        $entry->update(['status' => $target->value]);
    }
}
