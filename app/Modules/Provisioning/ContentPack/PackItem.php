<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\ContentPack;

use App\Modules\Shared\Support\Cast;

/**
 * One item of a content pack: its kind, its key (unique in the pack; the key is also the anchor
 * that finds the live record) and its raw data as the JSON file holds it.
 */
final readonly class PackItem
{
    /** The data flag of a section the pack removes (sections.json `remove`). */
    public const string REMOVE = 'remove';

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $kind,
        public string $key,
        public array $data,
    ) {}

    /**
     * The top-level names of a setting's value the pack owns (the others keep what the site has), or
     * null when the pack owns the whole value. A block-slot setting owns the slots it names unless
     * the item says otherwise: the admin keeps slots a save does not name, so must a load.
     *
     * @return list<string>|null
     */
    public function managedKeys(): ?array
    {
        if (isset($this->data['managed_keys'])) {
            return Cast::strings($this->data['managed_keys']);
        }
        if ($this->key === 'setting:storefront.layout_blocks') {
            return array_map('strval', array_keys(Cast::arr($this->data['value'] ?? null)));
        }

        return null;
    }

    /**
     * The categories a section item covers, its first one first: `categories` (a list, for a
     * section over several categories side by side) or `category` (one). Not validated here.
     *
     * @return list<string>
     */
    public function sectionCategories(): array
    {
        $list = $this->data['categories'] ?? null;
        if (is_array($list)) {
            return array_values(array_filter($list, is_string(...)));
        }
        $one = $this->data['category'] ?? null;

        return is_string($one) ? [$one] : [];
    }

    /** Whether the item removes a content section instead of writing one. */
    public function removes(): bool
    {
        return $this->kind === 'section' && ($this->data[self::REMOVE] ?? false) === true;
    }

    /** The default-locale slug or path the key carries (`post:a-b` → `a-b`, `page:/x` → `/x`). */
    public function anchor(): string
    {
        $colon = strpos($this->key, ':');

        return $colon === false ? '' : substr($this->key, $colon + 1);
    }
}
