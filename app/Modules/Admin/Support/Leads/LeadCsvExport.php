<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Leads;

use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Support\LeadAnswers;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Context\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;

/**
 * «Tải danh sách về máy»: the leads of a filter (status group, search, forms, time) as a CSV that
 * Excel opens with its Vietnamese intact (UTF-8 with a byte-order mark), one row per lead: its code,
 * name, email, phone, form, page, the time it was sent (in the site's zone), its status and the rest
 * of its answers. Never a database id, nor the visitor's IP or browser.
 *
 * Written while the response streams, after the request's own tenant transaction has ended: every
 * read here enters the site again through Tenancy::enter (its own short transaction, SET LOCAL,
 * the same assertion as a request), CHUNK leads at a time by keyset on (created_at, ulid), newest
 * first, so a site with millions of leads holds one chunk in memory and never an OFFSET. A lead
 * without a time (none is written so today) comes first, by its code.
 *
 * A cell that starts with = + - @, a tab or a carriage return gets a leading apostrophe, so a
 * spreadsheet shows what the visitor typed instead of running it as a formula.
 */
final readonly class LeadCsvExport
{
    public const int CHUNK = 1000;

    /** What a line needs, and nothing more. */
    private const array COLUMNS = ['ulid', 'form_id', 'name', 'email', 'phone', 'source_url', 'status', 'data', 'created_at'];

    /** The contact columns have their own cells: the answers cell holds the rest. */
    private const array OWN_CELLS = ['name', 'email', 'phone'];

    public function __construct(private Tenancy $tenancy, private SiteTime $time) {}

    /** The file's name: today in the site's own calendar ("lien-he-23-11-2026.csv"). */
    public static function fileName(SiteTime $time): string
    {
        return Cast::string(__('leads.export_file', ['date' => $time->now()->format('d-m-Y')])).'.csv';
    }

    /** A cell as a spreadsheet must take it: text, never a formula. */
    public static function cell(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    /**
     * Write the file to $out; returns how many leads it holds.
     *
     * @param  array{tab: string, search: string, forms: list<string>, when: string, from: string, to: string}  $filter
     * @param  resource  $out
     */
    public function write(int $siteId, array $filter, string $locale, $out): int
    {
        /** @var array{0: Collection<int, Form>, 1: LeadListQuery, 2: string, 3: string} $read */
        $read = $this->tenancy->enter($siteId, function () use ($filter): array {
            [$forms, $hidden] = LeadListQuery::knownForms();

            return [$forms, LeadListQuery::of($filter['search'], $filter['forms'], $filter['when'], $filter['from'], $filter['to'], $forms, $hidden, $this->time),
                $this->time->zone(), $this->time->dateFormat()];
        });
        [$forms, $list, $zone, $dayFormat] = $read;
        $byId = $forms->keyBy('id');
        $tab = in_array($filter['tab'], LeadListQuery::TABS, true) ? $filter['tab'] : 'all';

        fwrite($out, "\xEF\xBB\xBF");
        $this->put($out, array_map(fn (string $key): string => Cast::string(__('leads.csv_'.$key)), ['ref', 'name', 'email', 'phone', 'form', 'page', 'sent', 'status', 'answers']));

        $written = 0;
        $undated = true;
        $cursor = null;
        while (true) {
            $rows = $this->tenancy->enter($siteId, fn (): BaseCollection => $this->chunk($list, $tab, $undated, $cursor));
            foreach ($rows as $row) {
                $this->put($out, $this->line($row, $byId->get(Cast::int($row->form_id)), $locale, $zone, $dayFormat));
                $written++;
            }
            flush();
            if ($rows->count() < self::CHUNK) {
                if (! $undated) {
                    break;
                }
                $undated = false;
                $cursor = null;

                continue;
            }
            $last = $rows->last();
            if ($last === null) {
                break;
            }
            $cursor = [Cast::string($last->created_at), Cast::string($last->ulid)];
        }

        return $written;
    }

    /**
     * The next CHUNK leads after $cursor: the undated ones by code, then the rest newest first.
     *
     * @param  array{0: string, 1: string}|null  $cursor  created_at and ulid of the last lead written
     * @return BaseCollection<int, \stdClass>
     */
    private function chunk(LeadListQuery $list, string $tab, bool $undated, ?array $cursor): BaseCollection
    {
        $query = LeadListQuery::inTab($list->base(), $tab)->toBase()->select(self::COLUMNS);
        if ($undated) {
            $query->whereNull('created_at')->orderByDesc('ulid');
            if ($cursor !== null) {
                $query->where('ulid', '<', $cursor[1]);
            }
        } else {
            $query->whereNotNull('created_at')->orderByDesc('created_at')->orderByDesc('ulid');
            if ($cursor !== null) {
                $query->whereRaw('(created_at, ulid) < (?::timestamptz, ?)', $cursor);
            }
        }

        return $query->limit(self::CHUNK)->get();
    }

    /** @return list<string> */
    private function line(\stdClass $row, ?Form $form, string $locale, string $zone, string $dayFormat): array
    {
        $data = json_decode(Cast::string($row->data), true);
        $answers = array_filter(LeadAnswers::for($form, is_array($data) ? $data : [], $locale), fn (array $a): bool => ! in_array($a['key'], self::OWN_CELLS, true));
        $sent = Cast::string($row->created_at);
        $local = $sent === '' ? null : CarbonImmutable::parse($sent)->setTimezone($zone);
        $names = Cast::arr($form?->name);
        $formName = Cast::string($names[$locale] ?? $names['vi'] ?? (array_values($names)[0] ?? ''));

        return [
            Cast::string($row->ulid),
            Cast::string($row->name),
            Cast::string($row->email),
            Cast::string($row->phone),
            $formName !== '' ? $formName : Cast::string(__('leads.form_untitled')),
            Cast::string($row->source_url),
            $local === null ? '' : SiteTime::formatDay($local, $dayFormat, $locale).' '.$local->format('H:i'),
            Cast::string(__('leads.st_'.Cast::string($row->status))),
            implode("\n", array_map(fn (array $a): string => $a['label'].': '.$a['value'], array_values($answers))),
        ];
    }

    /**
     * @param  resource  $out
     * @param  list<string>  $cells
     */
    private function put($out, array $cells): void
    {
        fputcsv($out, array_map(self::cell(...), $cells), ',', '"', '');
    }
}
