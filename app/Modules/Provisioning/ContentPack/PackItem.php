<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\ContentPack;

/**
 * One item of a content pack: its kind, its key (unique in the pack; the key is also the anchor
 * that finds the live record) and its raw data as the JSON file holds it.
 */
final readonly class PackItem
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $kind,
        public string $key,
        public array $data,
    ) {}

    /** The default-locale slug or path the key carries (`post:a-b` → `a-b`, `page:/x` → `/x`). */
    public function anchor(): string
    {
        $colon = strpos($this->key, ':');

        return $colon === false ? '' : substr($this->key, $colon + 1);
    }
}
