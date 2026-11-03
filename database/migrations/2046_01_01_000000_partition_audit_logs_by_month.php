<?php

declare(strict_types=1);

use App\Modules\Shared\Support\Cast;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The audit log is split into one table per calendar month (UTC), so a whole month can be removed
 * at once: the scheduled prune drops months older than the running month and the 24 full months
 * before it, and the platform super-admin may drop older months by hand, never one of the running
 * month and the 12 before it. Removing a month is a DROP of its table, not a DELETE of its rows.
 *
 * Who may do what:
 *   - `audit_owner` (NOLOGIN, no default privileges) owns the parent table, every month and the
 *     functions below. A month created later therefore carries no grant at all: the app reaches
 *     rows only through the parent, where it may INSERT and SELECT and nothing else, and a month
 *     named directly is refused to it.
 *   - The app cannot create or drop a table itself (no owner or superuser password in any app
 *     process). It calls SECURITY DEFINER functions instead: audit_log_months_ensure() creates the
 *     running month and the three after it; audit_log_month_drop(m, record) drops one month, and
 *     refuses the running month and the 12 before it, whatever the caller asks, and any month
 *     whose audit row (`record`, its ulid, written in the same transaction just before) is
 *     missing, and a month that no longer exists; it writes the row count into that row. Those refusals are the guarantee; the PHP
 *     checks before them only give the friendly message. Each function runs with a fixed
 *     search_path (pg_catalog, pg_temp: every table is named with its schema) and TimeZone UTC, names tables with format('%I'), takes
 *     only the first day of a finite month and is STRICT, so a temporary table named like a month,
 *     a caller's time zone, infinity or NULL cannot steer it.
 *   - A month is created as an empty table and then ATTACHed (a lock that lets reads and writes
 *     of the log go on), never with CREATE TABLE … PARTITION OF (which blocks every audited
 *     request behind a long list query). A lock not obtained within 2s is left for the next run.
 *
 * Keys: PostgreSQL needs the partition key in every unique index, so the primary key becomes
 * (id, created_at) and the ulid is unique together with created_at. Column order and types are
 * unchanged, so the plans PgBouncer's server connections cached still fit after a restart of the
 * pooler. No DEFAULT month: a row there would block creating its month later; a write into a month
 * that does not exist fails inside AuditService's savepoint and leaves the request alive.
 *
 * Conversion, in one transaction under a 5s lock_timeout: the old table and its indexes,
 * constraints and sequence are renamed to *_old; the parent is created with the canonical names;
 * months are created from the month of the oldest row (or 24 months back, if earlier) to the month
 * of the newest row (or three months ahead, if later); rows are copied with their ids; the identity
 * continues after the largest id; the indexes are built on the parent (each month gets its own);
 * the old table is dropped. Needs the superuser migration role (pgsql_admin): it creates a role and
 * switches to it. Re-runnable: a table already partitioned is left alone.
 *
 * down() rebuilds the plain table (bigserial id, global UNIQUE(ulid), the same indexes and the
 * same app grants: INSERT and SELECT only), copies every row back, and drops the functions and the
 * role.
 */
return new class extends Migration
{
    /** Each step below must run in one explicit transaction, also when a test calls up() directly. */
    public $withinTransaction = false;

    /** @var list<string> every column, in table order */
    private const array COLUMNS = [
        'id', 'ulid', 'request_id', 'actor_id', 'actor_label', 'site_id', 'action', 'auditable_type', 'auditable_id',
        'ip_address', 'user_agent', 'meta', 'created_at', 'impersonator_id', 'impersonator_label', 'support_session_ulid',
    ];

    /** The columns after id, as the table defines them (id and the unique keys differ per shape). */
    private const string COLUMN_DEFINITIONS = <<<'SQL'
        ulid character(26) NOT NULL,
        request_id character varying(255),
        actor_id bigint,
        actor_label character varying(255),
        site_id bigint,
        action character varying(255) NOT NULL,
        auditable_type character varying(255),
        auditable_id bigint,
        ip_address character varying(45),
        user_agent text,
        meta jsonb NOT NULL DEFAULT '{}'::jsonb,
        created_at timestamp(0) with time zone NOT NULL,
        impersonator_id bigint,
        impersonator_label character varying(255),
        support_session_ulid character(26)
        SQL;

    /** @var array<string, string> index name => definition after "ON public.audit_logs" */
    private const array INDEXES = [
        'audit_logs_auditable_type_auditable_id_index' => '(auditable_type, auditable_id)',
        'audit_logs_site_id_created_at_index' => '(site_id, created_at)',
        'audit_logs_action_index' => '(action)',
        'audit_logs_created_at_id_index' => '(created_at, id)',
        'audit_logs_actor_id_index' => '(actor_id)',
        'audit_logs_impersonator_id_index' => '(impersonator_id) WHERE impersonator_id IS NOT NULL',
        'audit_logs_support_created_at_index' => "(created_at, id) WHERE impersonator_id IS NOT NULL OR action LIKE 'support.%'",
        'audit_logs_support_session_created_at_index' => '(support_session_ulid, created_at, id) WHERE support_session_ulid IS NOT NULL',
    ];

    /**
     * The functions, owned by audit_owner. Each starts with the same checks; the month table's name
     * is always built from a validated date, never taken from the caller as text.
     */
    private const string FUNCTIONS = <<<'SQL'
        CREATE FUNCTION public.audit_log_month_create(m date) RETURNS boolean
            LANGUAGE plpgsql VOLATILE STRICT SECURITY DEFINER
            SET search_path = pg_catalog, pg_temp
            SET TimeZone = 'UTC'
            SET lock_timeout = '2s'
        AS $fn$
        DECLARE
            part text;
            rel regclass;
        BEGIN
            IF NOT isfinite(m) OR m <> date_trunc('month', m::timestamp)::date THEN
                RAISE EXCEPTION 'audit log month must be the first day of a month, got %', m USING ERRCODE = '22023';
            END IF;

            part := 'audit_logs_' || to_char(m, '"y"YYYY"m"MM');
            rel := to_regclass(format('public.%I', part));
            IF rel IS NOT NULL THEN
                IF EXISTS (SELECT 1 FROM pg_inherits WHERE inhrelid = rel AND inhparent = 'public.audit_logs'::regclass) THEN
                    RETURN false;
                END IF;
                RAISE EXCEPTION 'table % exists but is not a month of the audit log', part USING ERRCODE = '42P07';
            END IF;

            EXECUTE format('CREATE TABLE public.%I (LIKE public.audit_logs INCLUDING DEFAULTS)', part);
            EXECUTE format('REVOKE ALL ON TABLE public.%I FROM PUBLIC, app_user', part);
            EXECUTE format(
                'ALTER TABLE public.audit_logs ATTACH PARTITION public.%I FOR VALUES FROM (%L) TO (%L)',
                part,
                to_char(m, 'YYYY-MM-DD') || ' 00:00:00+00',
                to_char((m + interval '1 month')::date, 'YYYY-MM-DD') || ' 00:00:00+00'
            );

            RETURN true;
        END
        $fn$;

        CREATE FUNCTION public.audit_log_months_ensure() RETURNS integer
            LANGUAGE plpgsql VOLATILE SECURITY DEFINER
            SET search_path = pg_catalog, pg_temp
            SET TimeZone = 'UTC'
            SET lock_timeout = '2s'
        AS $fn$
        DECLARE
            running date := date_trunc('month', now() AT TIME ZONE 'UTC')::date;
            m date;
            made integer := 0;
        BEGIN
            FOR m IN SELECT g::date FROM generate_series(running, running + interval '3 months', interval '1 month') g LOOP
                BEGIN
                    IF public.audit_log_month_create(m) THEN
                        made := made + 1;
                    END IF;
                EXCEPTION WHEN lock_not_available THEN
                    RAISE WARNING 'audit log month % left for the next run: %', to_char(m, 'YYYY-MM'), SQLERRM;
                END;
            END LOOP;

            RETURN made;
        END
        $fn$;

        CREATE FUNCTION public.audit_log_month_drop(m date, record text) RETURNS bigint
            LANGUAGE plpgsql VOLATILE STRICT SECURITY DEFINER
            SET search_path = pg_catalog, pg_temp
            SET TimeZone = 'UTC'
            SET lock_timeout = '2s'
        AS $fn$
        DECLARE
            kept_from date := (date_trunc('month', now() AT TIME ZONE 'UTC') - interval '12 months')::date;
            part text;
            rel regclass;
            noted timestamptz;
            removed bigint;
        BEGIN
            IF NOT isfinite(m) OR m <> date_trunc('month', m::timestamp)::date THEN
                RAISE EXCEPTION 'audit log month must be the first day of a month, got %', m USING ERRCODE = '22023';
            END IF;
            IF m >= kept_from THEN
                RAISE EXCEPTION 'audit log month % is kept: the running month and the 12 before it are never removed', to_char(m, 'YYYY-MM')
                    USING ERRCODE = 'AUKPT';
            END IF;
            -- the audit row that says so comes first, in the same transaction: no month goes unrecorded
            BEGIN
                SELECT a.created_at INTO STRICT noted FROM public.audit_logs a
                WHERE a.ulid = record::character(26)
                  AND a.created_at >= now() - interval '10 minutes'
                  AND a.action IN ('audit.month_deleted', 'audit.months_pruned')
                  AND a.meta->>'month' = to_char(m, 'YYYY-MM');
            EXCEPTION WHEN no_data_found OR too_many_rows THEN
                RAISE EXCEPTION 'audit log month % is removed only after its audit row is written', to_char(m, 'YYYY-MM')
                    USING ERRCODE = 'AUNRC';
            END;

            part := 'audit_logs_' || to_char(m, '"y"YYYY"m"MM');
            rel := to_regclass(format('public.%I', part));
            -- a month already gone is refused, never "removed with 0 rows": a second call with the
            -- same audit row could otherwise rewrite the row count that row recorded
            IF rel IS NULL THEN
                RAISE EXCEPTION 'audit log month % does not exist', to_char(m, 'YYYY-MM') USING ERRCODE = 'AUGON';
            ELSIF NOT EXISTS (SELECT 1 FROM pg_inherits WHERE inhrelid = rel AND inhparent = 'public.audit_logs'::regclass) THEN
                RAISE EXCEPTION 'table % is not a month of the audit log', part USING ERRCODE = '42809';
            ELSE
                -- counted before the DETACH: the parent is locked from the DETACH until the commit,
                -- and an old month gets no new rows (writers stamp now())
                EXECUTE format('SELECT count(*) FROM public.%I', part) INTO removed;
                EXECUTE format('ALTER TABLE public.audit_logs DETACH PARTITION public.%I', part);
                EXECUTE format('DROP TABLE public.%I', part);
            END IF;

            UPDATE public.audit_logs SET meta = jsonb_set(meta, '{rows}', to_jsonb(removed))
            WHERE ulid = record::character(26) AND created_at = noted;

            RETURN removed;
        END
        $fn$;

        CREATE FUNCTION public.audit_log_months() RETURNS TABLE (month date, rows_estimate bigint)
            LANGUAGE sql STABLE
            SET search_path = pg_catalog, pg_temp
            SET TimeZone = 'UTC'
        AS $fn$
            SELECT to_date(substring(c.relname FROM '^audit_logs_y([0-9]{4})m[0-9]{2}$')
                        || substring(c.relname FROM '^audit_logs_y[0-9]{4}m([0-9]{2})$'), 'YYYYMM'),
                   greatest(c.reltuples, 0)::bigint
            FROM pg_inherits i
            JOIN pg_class c ON c.oid = i.inhrelid
            WHERE i.inhparent = 'public.audit_logs'::regclass
              AND c.relname ~ '^audit_logs_y[0-9]{4}m[0-9]{2}$'
            ORDER BY 1
        $fn$;

        CREATE FUNCTION public.audit_log_month_count(m date) RETURNS bigint
            LANGUAGE plpgsql STABLE STRICT
            SET search_path = pg_catalog, pg_temp
            SET TimeZone = 'UTC'
        AS $fn$
        DECLARE
            removed bigint;
        BEGIN
            IF NOT isfinite(m) OR m <> date_trunc('month', m::timestamp)::date THEN
                RAISE EXCEPTION 'audit log month must be the first day of a month, got %', m USING ERRCODE = '22023';
            END IF;

            SELECT count(*) INTO removed FROM public.audit_logs
            WHERE created_at >= (m::timestamp AT TIME ZONE 'UTC')
              AND created_at < ((m + interval '1 month') AT TIME ZONE 'UTC');

            RETURN removed;
        END
        $fn$;

        REVOKE ALL ON FUNCTION public.audit_log_month_create(date) FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.audit_log_months_ensure() FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.audit_log_month_drop(date, text) FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.audit_log_months() FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.audit_log_month_count(date) FROM PUBLIC;
        GRANT EXECUTE ON FUNCTION public.audit_log_months_ensure() TO app_user;
        GRANT EXECUTE ON FUNCTION public.audit_log_month_drop(date, text) TO app_user;
        GRANT EXECUTE ON FUNCTION public.audit_log_months() TO app_user;
        GRANT EXECUTE ON FUNCTION public.audit_log_month_count(date) TO app_user;
        SQL;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || $this->kind() !== 'r') {
            return;
        }

        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            $this->requireSuperuser();

            DB::unprepared(<<<'SQL'
                DO $do$
                BEGIN
                    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'audit_owner') THEN
                        CREATE ROLE audit_owner NOLOGIN;
                    END IF;
                END
                $do$;
                ALTER ROLE audit_owner WITH NOLOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION;
                GRANT USAGE, CREATE ON SCHEMA public TO audit_owner;
                LOCK TABLE public.audit_logs IN ACCESS EXCLUSIVE MODE;
                SQL);

            $this->renameTo('audit_logs_old');

            DB::statement('SET LOCAL ROLE audit_owner');
            DB::unprepared('CREATE TABLE public.audit_logs (id bigint GENERATED BY DEFAULT AS IDENTITY, '.self::COLUMN_DEFINITIONS.', '
                .'CONSTRAINT audit_logs_pkey PRIMARY KEY (id, created_at), '
                .'CONSTRAINT audit_logs_ulid_unique UNIQUE (ulid, created_at)) PARTITION BY RANGE (created_at)');
            DB::unprepared(self::FUNCTIONS);
            DB::statement('RESET ROLE');

            // a stray date (a clock fault) would otherwise make one month per month in between
            $stray = Cast::int(DB::scalar(<<<'SQL'
                SELECT count(*) FROM public.audit_logs_old
                WHERE created_at < now() - interval '10 years' OR created_at > now() + interval '1 year'
                SQL));
            if ($stray !== 0) {
                throw new RuntimeException("audit_logs has {$stray} rows dated more than 10 years back or 1 year ahead: correct or remove them before splitting the log by month.");
            }

            DB::select(<<<'SQL'
                WITH span AS (
                    SELECT date_trunc('month', now() AT TIME ZONE 'UTC') AS running,
                           date_trunc('month', min(created_at) AT TIME ZONE 'UTC') AS oldest,
                           date_trunc('month', max(created_at) AT TIME ZONE 'UTC') AS newest
                    FROM public.audit_logs_old
                )
                SELECT public.audit_log_month_create(g::date)
                FROM span, generate_series(
                    least(coalesce(oldest, running), running - interval '24 months'),
                    greatest(coalesce(newest, running), running + interval '3 months'),
                    interval '1 month'
                ) g
                SQL);

            $columns = implode(', ', self::COLUMNS);
            DB::statement("INSERT INTO public.audit_logs ({$columns}) SELECT {$columns} FROM public.audit_logs_old");
            $this->continueIdsAfterLargest('ALTER TABLE public.audit_logs ALTER COLUMN id RESTART WITH %d');

            $this->createIndexes();
            DB::unprepared(<<<'SQL'
                REVOKE ALL ON TABLE public.audit_logs FROM PUBLIC;
                GRANT SELECT, INSERT ON TABLE public.audit_logs TO app_user;
                DROP TABLE public.audit_logs_old;
                SQL);
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql' || $this->kind() !== 'p') {
            return;
        }

        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            $this->requireSuperuser();
            DB::statement('LOCK TABLE public.audit_logs IN ACCESS EXCLUSIVE MODE');

            $this->renameTo('audit_logs_parted');

            // Created by the migration role, so its default privileges grant the app every right
            // on the table and on its sequence, as they did on the original table; UPDATE and
            // DELETE are taken back below, as the append-only migration did.
            DB::unprepared('CREATE TABLE public.audit_logs (id bigserial NOT NULL, '.self::COLUMN_DEFINITIONS.', '
                .'CONSTRAINT audit_logs_pkey PRIMARY KEY (id), CONSTRAINT audit_logs_ulid_unique UNIQUE (ulid))');

            $columns = implode(', ', self::COLUMNS);
            DB::statement("INSERT INTO public.audit_logs ({$columns}) SELECT {$columns} FROM public.audit_logs_parted");
            $this->continueIdsAfterLargest("SELECT setval('public.audit_logs_id_seq', %d)");

            $this->createIndexes();
            DB::unprepared(<<<'SQL'
                REVOKE UPDATE, DELETE ON TABLE public.audit_logs FROM app_user;
                DROP TABLE public.audit_logs_parted;
                DROP FUNCTION IF EXISTS public.audit_log_months_ensure();
                DROP FUNCTION IF EXISTS public.audit_log_month_drop(date, text);
                DROP FUNCTION IF EXISTS public.audit_log_months();
                DROP FUNCTION IF EXISTS public.audit_log_month_count(date);
                DROP FUNCTION IF EXISTS public.audit_log_month_create(date);
                REVOKE ALL ON SCHEMA public FROM audit_owner;
                DROP ROLE IF EXISTS audit_owner;
                SQL);
        });
    }

    /** 'r' for the plain table, 'p' for the partitioned one, null when there is none. */
    private function kind(): ?string
    {
        $kind = DB::scalar("SELECT relkind::text FROM pg_class WHERE oid = to_regclass('public.audit_logs')");

        return is_string($kind) ? $kind : null;
    }

    private function requireSuperuser(): void
    {
        if (DB::scalar('SELECT rolsuper FROM pg_roles WHERE rolname = current_user') !== true) {
            throw new RuntimeException('Partitioning audit_logs creates the audit_owner role and switches to it: run it on the superuser migration connection (--database=pgsql_admin).');
        }
    }

    /**
     * Moves audit_logs, its indexes (and so its key constraints) and its id sequence out of the
     * way, so the new table takes the canonical names.
     */
    private function renameTo(string $name): void
    {
        DB::statement("ALTER TABLE public.audit_logs RENAME TO {$name}");

        $indexes = DB::select(
            "SELECT c.relname FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE i.indrelid = to_regclass(?) AND c.relname LIKE 'audit\\_logs\\_%'",
            ['public.'.$name],
        );
        foreach ($indexes as $index) {
            $old = is_object($index) ? (get_object_vars($index)['relname'] ?? null) : null;
            if (is_string($old)) {
                DB::statement(sprintf('ALTER INDEX public.%s RENAME TO %s', $old, $name.substr($old, strlen('audit_logs'))));
            }
        }

        DB::statement("ALTER SEQUENCE IF EXISTS public.audit_logs_id_seq RENAME TO {$name}_id_seq");
    }

    /** @param  string  $statement  with one %d for the next id or the largest one */
    private function continueIdsAfterLargest(string $statement): void
    {
        $largest = DB::scalar('SELECT max(id) FROM public.audit_logs');
        if (is_int($largest)) {
            DB::statement(sprintf($statement, str_contains($statement, 'RESTART') ? $largest + 1 : $largest));
        }
    }

    private function createIndexes(): void
    {
        foreach (self::INDEXES as $index => $definition) {
            DB::statement("CREATE INDEX {$index} ON public.audit_logs {$definition}");
        }
    }
};
