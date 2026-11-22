<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Support\Leads\LeadCsvExport;
use App\Modules\Admin\Support\Leads\LeadExportTicket;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Forms\Models\Form;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Entitlement\Entitlement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The download behind «Tải tệp về máy» in «Khách liên hệ». The dialog checked the member's password
 * (or code) and issued a one-time ticket; this opens it, once, for that member on that website, and
 * streams the CSV of the filter the ticket holds (LeadCsvExport).
 *
 * Asked again here, whatever the ticket says: the plan has «Khách liên hệ», the member manages leads
 * (forms.manage), and this is not a support session (staff never take a file of visitors' personal
 * data on a member's behalf). The audit row (who, which filter, never the search text) is written
 * in the request's own transaction before the first byte: when it cannot be written, no file goes
 * out. A second row, once the file is written, says how many leads it held.
 */
final class LeadExportController
{
    public function __invoke(string $token, CurrentSite $current, Entitlement $entitlement, SupportSession $support, SiteTime $time, Tenancy $tenancy, LeadCsvExport $export): StreamedResponse|RedirectResponse
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);
        abort_unless(in_array('contacts', $entitlement->entitledModuleKeys(), true), 403);
        abort_unless(Gate::allows('forms.manage'), 403);
        $support->forbid();

        $siteId = $current->id();
        $filter = LeadExportTicket::take($token, $user, $siteId);
        if ($filter === null) {
            return redirect()->route('admin.forms.index')->with('leads.notice', __('leads.export_expired'));
        }

        $keys = Form::query()->whereIn('ulid', array_slice($filter['forms'], 0, 50))->orderBy('id')->limit(50)->pluck('key')->all();
        Audit::recordOrFail('forms.leads.exported', ['meta' => [
            'tab' => $filter['tab'],
            'forms' => array_values(array_map(fn (mixed $key): string => Cast::string($key), $keys)),
            'when' => $filter['when'],
            'from' => $filter['from'],
            'to' => $filter['to'],
            'search' => trim($filter['search']) !== '',
        ]]);

        $locale = app()->getLocale();
        $name = LeadCsvExport::fileName($time);

        return new StreamedResponse(function () use ($export, $tenancy, $siteId, $filter, $locale): void {
            // A big list takes longer than a page: no time limit while this file is written (the
            // worker's own limit is put back afterwards, since an Octane worker sets it once when it
            // starts), and the writer is not stopped when the browser goes away, so it can see that
            // and say so in the closing audit row.
            $limit = (int) ini_get('max_execution_time');
            set_time_limit(0);
            $ignored = ignore_user_abort(true);
            $out = fopen('php://output', 'wb');
            $written = 0;
            $complete = false;
            try {
                if ($out === false) {
                    return;
                }
                try {
                    $complete = $export->write($siteId, $filter, $locale, $out, $written);
                } catch (Throwable $e) {
                    report($e);
                    // the browser already holds part of the file: its last line says it is not whole
                    $export->unfinished($out);
                }
            } finally {
                if ($out !== false) {
                    fclose($out);
                }
                $tenancy->enter($siteId, fn () => Audit::record('forms.leads.export_finished', ['meta' => [
                    'rows' => $written,
                    'complete' => $complete,
                ]]));
                ignore_user_abort((bool) $ignored);
                set_time_limit($limit);
            }
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
