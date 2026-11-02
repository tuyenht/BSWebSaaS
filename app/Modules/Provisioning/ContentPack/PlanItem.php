<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\ContentPack;

use App\Modules\Cms\Routing\CategoryChange;
use App\Modules\Shared\Support\Cast;

/**
 * One line of the plan: what the loader will do with one pack item, and why. `current` and
 * `desired` are the item's managed fields in canonical form (null current = no live record); their
 * hashes, with the ledger's, decide the verdict. `recordId` is internal (never printed, never
 * stored): the Applier writes through it. `scope` is a hash of the other rows the write changes
 * along the way (the posts and products a renamed or moved category carries), so the fingerprint
 * changes when one of them does.
 */
final readonly class PlanItem
{
    public const string CREATE = 'create';

    public const string UPDATE = 'update';

    public const string ADOPT = 'adopt';

    public const string UNCHANGED = 'unchanged';

    public const string SKIP_EDITED = 'skip:edited';

    public const string SKIP_DELETED = 'skip:deleted';

    public const string SKIP_DRAFT = 'skip:draft-pending';

    public const string CONFLICT = 'conflict';

    /** Verdicts that write. */
    public const array WRITES = [self::CREATE, self::UPDATE, self::ADOPT];

    /**
     * @param  array<string, mixed>|null  $current
     * @param  array<string, mixed>  $desired
     * @param  list<string>  $notes  plain-language detail for the dry run (unpublish, URL moves, reasons)
     * @param  string|null  $scope  hash of the rows the write carries along (fingerprinted)
     */
    public function __construct(
        public PackItem $item,
        public string $verdict,
        public ?array $current,
        public array $desired,
        public ?string $ref,
        public ?int $recordId,
        public array $notes = [],
        public bool $forced = false,
        public ?string $scope = null,
    ) {}

    /** The same line as a conflict: nothing is written, and the dry run says why. */
    public function asConflict(string $why): self
    {
        return new self($this->item, self::CONFLICT, $this->current, $this->desired, $this->ref, $this->recordId, [$why]);
    }

    /**
     * The same line with more notes and the scope of the rows it carries along.
     *
     * @param  list<string>  $notes
     */
    public function carrying(array $notes, ?string $scope): self
    {
        return new self($this->item, $this->verdict, $this->current, $this->desired, $this->ref, $this->recordId, [...$this->notes, ...$notes], $this->forced, $scope);
    }

    /**
     * What a category write does to the addresses under it: Renamed when its slug changes in any
     * language, Moved when its parent changes — both when both do. The Planner predicts the posts
     * and products it carries along from this list and the Applier relinks the branch with it, so the
     * two agree on which items follow. Empty: nothing under it moves.
     *
     * @param  array<string, mixed>|null  $current
     * @param  array<string, mixed>  $desired
     * @return list<CategoryChange>
     */
    public static function taxonomyChanges(?array $current, array $desired): array
    {
        if ($current === null) {
            return [];
        }
        $changes = [];
        $before = Cast::arr($current['translations'] ?? null);
        foreach (Cast::arr($desired['translations'] ?? null) as $locale => $row) {
            $slug = Cast::arr($row)['slug'] ?? null;
            if (is_string($slug) && $slug !== (Cast::arr($before[$locale] ?? null)['slug'] ?? null)) {
                $changes[] = CategoryChange::Renamed;
                break;
            }
        }
        if (($current['parent'] ?? null) !== ($desired['parent'] ?? null)) {
            $changes[] = CategoryChange::Moved;
        }

        return $changes;
    }

    /**
     * Whether a category write changes an address under it (see taxonomyChanges()).
     *
     * @param  array<string, mixed>|null  $current
     * @param  array<string, mixed>  $desired
     */
    public static function taxonomyMoves(?array $current, array $desired): bool
    {
        return self::taxonomyChanges($current, $desired) !== [];
    }

    public function currentHash(): ?string
    {
        return $this->current === null ? null : CanonicalHash::of($this->current);
    }

    public function desiredHash(): string
    {
        return CanonicalHash::of($this->desired);
    }

    public function writes(): bool
    {
        return in_array($this->verdict, self::WRITES, true);
    }

    public function unpublishes(): bool
    {
        return ($this->current['status'] ?? null) === 'published' && ($this->desired['status'] ?? null) === 'draft';
    }

    /**
     * The managed fields that differ between the live record and the pack (top level and per locale).
     *
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public function changes(): array
    {
        if ($this->current === null) {
            return [];
        }

        $changes = [];
        foreach (array_unique([...array_keys($this->current), ...array_keys($this->desired)]) as $field) {
            $before = $this->current[$field] ?? null;
            $after = $this->desired[$field] ?? null;
            if ($field === 'translations' && is_array($before) && is_array($after)) {
                foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $locale) {
                    $old = is_array($before[$locale] ?? null) ? $before[$locale] : [];
                    $new = is_array($after[$locale] ?? null) ? $after[$locale] : [];
                    foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $name) {
                        if (CanonicalHash::of($old[$name] ?? null) !== CanonicalHash::of($new[$name] ?? null)) {
                            $changes["{$name} ({$locale})"] = [$old[$name] ?? null, $new[$name] ?? null];
                        }
                    }
                }

                continue;
            }
            if (CanonicalHash::of($before) !== CanonicalHash::of($after)) {
                $changes[(string) $field] = [$before, $after];
            }
        }

        return $changes;
    }
}
