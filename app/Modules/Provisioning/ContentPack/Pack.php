<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\ContentPack;

use App\Modules\Shared\Support\Cast;

/**
 * A content pack as read from its folder: the header (code, version, the hosts it may load into,
 * its languages), every item in the order the loader writes them (settings → profile → categories
 * → products → posts → pages → menus → redirects) and the image slots. Data only — nothing in a
 * pack can execute.
 */
final readonly class Pack
{
    /** Kinds in write order: what a later kind points at is written first. */
    public const array KINDS = ['setting', 'profile', 'taxonomy', 'product', 'post', 'page', 'menu', 'redirect'];

    /**
     * @param  list<string>  $hosts
     * @param  list<string>  $locales
     * @param  list<PackItem>  $items
     * @param  array<string, array<string, mixed>>  $slots  slot name → slot
     */
    public function __construct(
        public string $code,
        public int $version,
        public array $hosts,
        public string $defaultLocale,
        public array $locales,
        public array $items,
        public array $slots,
        public string $dir,
        public string $contentHash,
    ) {}

    public function item(string $key): ?PackItem
    {
        foreach ($this->items as $item) {
            if ($item->key === $key) {
                return $item;
            }
        }

        return null;
    }

    /** @return list<PackItem> */
    public function ofKind(string $kind): array
    {
        return array_values(array_filter($this->items, static fn (PackItem $item): bool => $item->kind === $kind));
    }

    /**
     * Every claim in the pack (the unverified sentences), item by item — the confirmation sheet.
     *
     * @return array<string, list<string>>
     */
    public function claims(): array
    {
        $claims = [];
        foreach ($this->items as $item) {
            $list = Cast::strings($item->data['claims'] ?? null);
            if ($list !== []) {
                $claims[$item->key] = $list;
            }
        }

        return $claims;
    }
}
