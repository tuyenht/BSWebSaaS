<?php

declare(strict_types=1);

namespace App\Modules\Media\Jobs;

use App\Modules\Media\Services\PdfPages;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Counts a newly uploaded PDF's pages (PdfPages) outside the upload request. Dispatched after the
 * upload's transaction commits, so the row is there to read; the count runs inside the website's
 * own tenant context (the job carries the site id, never a current-site singleton).
 *
 * One try: a file qpdf cannot count stays uncounted (the library shows «PDF» alone) and is marked,
 * so neither a retry nor the one-off media:pdf-pages command reads it again while it is the same
 * file. When qpdf is not installed nothing is marked, and media:pdf-pages fills it later.
 * The job's timeout stays above the storage read's and qpdf's together (5 + 15 + 5 + 20 seconds,
 * PdfPages) and below the queue worker's.
 */
final class CountPdfPages implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 50;

    public function __construct(public int $siteId, public int $mediaId)
    {
        $this->afterCommit();
    }

    public function handle(PdfPages $pages): void
    {
        $pages->record($this->siteId, $this->mediaId);
    }
}
