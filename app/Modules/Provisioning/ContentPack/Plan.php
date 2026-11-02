<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\ContentPack;

/**
 * The loader's plan for one pack on one site — what the dry run prints and what --apply executes
 * after recomputing it and comparing fingerprints. The fingerprint covers the pack's content, the
 * site, the forced keys, every item's verdict and hashes, and the ledger: any staff edit or pack
 * change between the dry run and the apply changes it.
 */
final readonly class Plan
{
    public string $fingerprint;

    /**
     * @param  list<PlanItem>  $items
     * @param  list<string>  $forced
     * @param  list<string>  $orphans  ledger keys no longer in the pack (reported, never deleted)
     * @param  list<string>  $warnings
     * @param  array{pack_version: int, loaded_at: string, items: array<string, array{hash: string, ref: string}>}|null  $ledger
     * @param  array{slots: int, resolved: int}  $media
     */
    public function __construct(
        public Pack $pack,
        public string $siteUlid,
        public string $siteName,
        public string $host,
        public array $items,
        public array $forced,
        public array $orphans,
        public array $warnings,
        public ?array $ledger,
        public array $media,
    ) {
        $forcedKeys = $forced;
        sort($forcedKeys, SORT_STRING);
        $lines = array_map(
            fn (PlanItem $item): array => [$item->item->key, $item->verdict, $item->currentHash(), $item->desiredHash()],
            $items,
        );

        $this->fingerprint = substr(CanonicalHash::of([
            'pack' => [$pack->code, $pack->version, $pack->contentHash],
            'site' => $siteUlid,
            'forced' => $forcedKeys,
            'items' => $lines,
            'ledger' => $ledger === null ? null : [$ledger['pack_version'], $ledger['items']],
        ]), 0, 12);
    }

    /** @return list<PlanItem> */
    public function conflicts(): array
    {
        return array_values(array_filter($this->items, fn (PlanItem $item): bool => $item->verdict === PlanItem::CONFLICT));
    }

    /** @return list<PlanItem> */
    public function writes(): array
    {
        return array_values(array_filter($this->items, fn (PlanItem $item): bool => $item->writes()));
    }

    /** @return array<string, int> verdict → count, in a fixed order */
    public function totals(): array
    {
        $totals = array_fill_keys(['create', 'update', 'adopt', 'unchanged', 'skip', 'conflict'], 0);
        foreach ($this->items as $item) {
            $bucket = str_starts_with($item->verdict, 'skip:') ? 'skip' : $item->verdict;
            $totals[$bucket]++;
        }

        return $totals;
    }

    public function item(string $key): ?PlanItem
    {
        foreach ($this->items as $item) {
            if ($item->item->key === $key) {
                return $item;
            }
        }

        return null;
    }

    /**
     * The same data the text output shows, for --json. No database ids.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'site' => ['name' => $this->siteName, 'host' => $this->host, 'ulid' => $this->siteUlid],
            'pack' => ['code' => $this->pack->code, 'version' => $this->pack->version],
            'ledger' => $this->ledger === null ? null : ['pack_version' => $this->ledger['pack_version'], 'loaded_at' => $this->ledger['loaded_at']],
            'items' => array_map(fn (PlanItem $item): array => [
                'key' => $item->item->key,
                'kind' => $item->item->kind,
                'verdict' => $item->verdict,
                'forced' => $item->forced,
                'unpublish' => $item->unpublishes(),
                'ref' => $item->ref,
                'notes' => $item->notes,
                'changes' => array_keys($item->changes()),
            ], $this->items),
            'orphans' => $this->orphans,
            'warnings' => $this->warnings,
            'media' => $this->media,
            'totals' => $this->totals(),
            'fingerprint' => $this->fingerprint,
        ];
    }
}
