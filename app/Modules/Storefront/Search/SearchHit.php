<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Search;

/**
 * One item found: its id (never shown), its ulid (the "Xem thêm" cursor), for a post or page its main
 * category, and its place in its group (1 = first; counted from the cursor when the newest items were walked).
 */
final readonly class SearchHit
{
    public function __construct(
        public int $id,
        public string $ulid,
        public ?int $primaryCategory,
        public int $position,
    ) {}
}
