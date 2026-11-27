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
 * One try: a file qpdf cannot count stays uncounted (the library shows «PDF» alone) and the
 * one-off media:pdf-pages command can try it again; a retry would only repeat the same answer.
 * The job's timeout stays above qpdf's own and below the queue worker's.
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
