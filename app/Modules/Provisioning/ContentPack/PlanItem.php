<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\ContentPack;

/**
 * One line of the plan: what the loader will do with one pack item, and why. `current` and
 * `desired` are the item's managed fields in canonical form (null current = no live record); their
 * hashes, with the ledger's, decide the verdict. `recordId` is internal (never printed, never
 * stored): the Applier writes through it.
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
    ) {}

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
