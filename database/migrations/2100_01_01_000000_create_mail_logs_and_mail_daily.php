<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * What a website's mail did, for its «Thư gần đây» list and its daily mail count.
 *
 * `mail_logs` — one row per mail a website sent or failed to send: which kind, through which
 * outbox (the platform's shared one or the website's own), how many addresses and the first one,
 * and why it failed. Never the body, the subject or what the mail server answered.
 *  - Split into one table per calendar month (UTC), `mail_logs_yYYYYmMM`, the way the audit log is
 *    (2046_01_01_000000_partition_audit_logs_by_month.php): the role `mail_log_owner` (NOLOGIN, no
 *    default privileges) owns the parent, every month and the functions below. The app may only
 *    INSERT and SELECT through the parent; a month named directly is refused to it. It cannot create
 *    or drop a table itself: mail_log_months_ensure() creates the running month and the three after
 *    it, mail_log_month_drop(m) drops one month and refuses the running month and the three before
 *    it whatever it is asked. A month is created empty and then ATTACHed under a 2s lock_timeout.
 *  - PRIMARY KEY (id, created_at): PostgreSQL needs the partition key in every unique index. No ulid:
 *    a row is never addressed from outside (the list pages by (created_at, id), no detail page).
 *  - No foreign key to `sites`: attaching or dropping a month would then take a lock on `sites`,
 *    which every request reads. A website removed for good leaves its rows until their month is
 *    dropped (at most about four months); RLS keeps them hidden meanwhile.
 *  - RLS ENABLE + FORCE on the parent and on every month, fail closed (no site → no row).
 *  - kind/via/status/reason_code are varchar + CHECK (D13): a new kind of mail = drop and add the
 *    kind CHECK in a later migration. to_first is citext (D12), created_at timestamptz (D11).
 *  - Index (site_id, created_at DESC, id DESC) for the list, and the failed ones apart.
 *
 * `mail_daily` — per website, per local day (the website's time zone), per outbox: mails sent,
 * failed, and held back to the next day. At most two rows per website per day, so not split.
 * FK sites CASCADE (D4); RLS ENABLE + FORCE. The app may SELECT, INSERT and UPDATE (the count is an
 * upsert), never DELETE: old days are removed by mail_daily_prune(before), a SECURITY DEFINER
 * function of mail_log_owner that refuses any day of the last 90. Because FORCE applies the policies
 * to everyone, mail_log_owner gets its own SELECT and DELETE policies (it never has a website).
 *
 * Every function runs with a fixed search_path (pg_catalog, pg_temp), TimeZone UTC, names its tables
 * with format('%I') from a validated first day of a finite month, and is STRICT where it takes one.
 *
 * New, empty tables: indexes are built with them. Needs the superuser migration role (pgsql_admin):
 * it creates a role and switches to it. Re-runnable: nothing is done when `mail_logs` exists. down()
 * drops both tables, the functions and the role.
 */
return new class extends Migration
{
    /** Each step below runs in one explicit transaction, also when a test calls up() directly. */
    public $withinTransaction = false;

    private const string SITE_POLICY = "site_id = NULLIF(current_setting('app.current_site_id', true), '')::bigint";

    private const string FUNCTIONS = <<<'SQL'
        CREATE FUNCTION public.mail_log_month_create(m date) RETURNS boolean
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
                RAISE EXCEPTION 'mail log month must be the first day of a month, got %', m USING ERRCODE = '22023';
            END IF;

            part := 'mail_logs_' || to_char(m, '"y"YYYY"m"MM');
            rel := to_regclass(format('public.%I', part));
            IF rel IS NOT NULL THEN
                IF EXISTS (SELECT 1 FROM pg_inherits WHERE inhrelid = rel AND inhparent = 'public.mail_logs'::regclass) THEN
                    RETURN false;
                END IF;
                RAISE EXCEPTION 'table % exists but is not a month of the mail log', part USING ERRCODE = '42P07';
            END IF;

            EXECUTE format('CREATE TABLE public.%I (LIKE public.mail_logs INCLUDING DEFAULTS INCLUDING CONSTRAINTS)', part);
            EXECUTE format('REVOKE ALL ON TABLE public.%I FROM PUBLIC, app_user', part);
            EXECUTE format('ALTER TABLE public.%I ENABLE ROW LEVEL SECURITY', part);
            EXECUTE format('ALTER TABLE public.%I FORCE ROW LEVEL SECURITY', part);
            EXECUTE format(
                'CREATE POLICY %I ON public.%I FOR ALL USING (site_id = NULLIF(current_setting(%L, true), %L)::bigint) WITH CHECK (site_id = NULLIF(current_setting(%L, true), %L)::bigint)',
                part || '_site_isolation', part, 'app.current_site_id', '', 'app.current_site_id', ''
            );
            EXECUTE format(
                'ALTER TABLE public.mail_logs ATTACH PARTITION public.%I FOR VALUES FROM (%L) TO (%L)',
                part,
                to_char(m, 'YYYY-MM-DD') || ' 00:00:00+00',
                to_char((m + interval '1 month')::date, 'YYYY-MM-DD') || ' 00:00:00+00'
            );

            RETURN true;
        END
        $fn$;

        CREATE FUNCTION public.mail_log_months_ensure() RETURNS integer
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
                    IF public.mail_log_month_create(m) THEN
                        made := made + 1;
                    END IF;
                EXCEPTION WHEN lock_not_available THEN
                    RAISE WARNING 'mail log month % left for the next run: %', to_char(m, 'YYYY-MM'), SQLERRM;
                END;
            END LOOP;

            RETURN made;
        END
        $fn$;

        CREATE FUNCTION public.mail_log_month_drop(m date) RETURNS boolean
            LANGUAGE plpgsql VOLATILE STRICT SECURITY DEFINER
            SET search_path = pg_catalog, pg_temp
            SET TimeZone = 'UTC'
            SET lock_timeout = '2s'
        AS $fn$
        DECLARE
            kept_from date := (date_trunc('month', now() AT TIME ZONE 'UTC') - interval '3 months')::date;
            part text;
            rel regclass;
        BEGIN
            IF NOT isfinite(m) OR m <> date_trunc('month', m::timestamp)::date THEN
                RAISE EXCEPTION 'mail log month must be the first day of a month, got %', m USING ERRCODE = '22023';
            END IF;
            IF m >= kept_from THEN
                RAISE EXCEPTION 'mail log month % is kept: the running month and the 3 before it are never removed', to_char(m, 'YYYY-MM')
                    USING ERRCODE = 'MLKPT';
            END IF;

            part := 'mail_logs_' || to_char(m, '"y"YYYY"m"MM');
            rel := to_regclass(format('public.%I', part));
            IF rel IS NULL THEN
                RETURN false;
            END IF;
            IF NOT EXISTS (SELECT 1 FROM pg_inherits WHERE inhrelid = rel AND inhparent = 'public.mail_logs'::regclass) THEN
                RAISE EXCEPTION 'table % is not a month of the mail log', part USING ERRCODE = '42809';
            END IF;

            EXECUTE format('ALTER TABLE public.mail_logs DETACH PARTITION public.%I', part);
            EXECUTE format('DROP TABLE public.%I', part);

            RETURN true;
        END
        $fn$;

        CREATE FUNCTION public.mail_log_months() RETURNS TABLE (month date)
            LANGUAGE sql STABLE
            SET search_path = pg_catalog, pg_temp
            SET TimeZone = 'UTC'
        AS $fn$
            SELECT to_date(substring(c.relname FROM '^mail_logs_y([0-9]{4})m[0-9]{2}$')
                        || substring(c.relname FROM '^mail_logs_y[0-9]{4}m([0-9]{2})$'), 'YYYYMM')
            FROM pg_inherits i
            JOIN pg_class c ON c.oid = i.inhrelid
            WHERE i.inhparent = 'public.mail_logs'::regclass
              AND c.relname ~ '^mail_logs_y[0-9]{4}m[0-9]{2}$'
            ORDER BY 1
        $fn$;

        CREATE FUNCTION public.mail_daily_prune(before date) RETURNS bigint
            LANGUAGE plpgsql VOLATILE STRICT SECURITY DEFINER
            SET search_path = pg_catalog, pg_temp
            SET TimeZone = 'UTC'
            SET lock_timeout = '2s'
        AS $fn$
        DECLARE
            removed bigint;
        BEGIN
            IF NOT isfinite(before) OR before > (now() AT TIME ZONE 'UTC')::date - 90 THEN
                RAISE EXCEPTION 'mail days of the last 90 days are kept, got %', before USING ERRCODE = 'MLKPT';
            END IF;

            DELETE FROM public.mail_daily WHERE day < before;
            GET DIAGNOSTICS removed = ROW_COUNT;

            RETURN removed;
        END
        $fn$;

        REVOKE ALL ON FUNCTION public.mail_log_month_create(date) FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.mail_log_months_ensure() FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.mail_log_month_drop(date) FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.mail_log_months() FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.mail_daily_prune(date) FROM PUBLIC;
        GRANT EXECUTE ON FUNCTION public.mail_log_months_ensure() TO app_user;
        GRANT EXECUTE ON FUNCTION public.mail_log_month_drop(date) TO app_user;
        GRANT EXECUTE ON FUNCTION public.mail_log_months() TO app_user;
        GRANT EXECUTE ON FUNCTION public.mail_daily_prune(date) TO app_user;
        SQL;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || DB::scalar("select to_regclass('public.mail_logs')") !== null) {
            return;
        }

        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            if (DB::scalar('SELECT rolsuper FROM pg_roles WHERE rolname = current_user') !== true) {
                throw new RuntimeException('The mail log creates the mail_log_owner role and switches to it: run it on the superuser migration connection (--database=pgsql_admin).');
            }

            DB::unprepared(<<<'SQL'
                DO $do$
                BEGIN
                    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'mail_log_owner') THEN
                        CREATE ROLE mail_log_owner NOLOGIN;
                    END IF;
                END
                $do$;
                ALTER ROLE mail_log_owner WITH NOLOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION;
                GRANT USAGE, CREATE ON SCHEMA public TO mail_log_owner;
                SQL);

            DB::unprepared(<<<'SQL'
                CREATE TABLE public.mail_daily (
                    site_id bigint NOT NULL REFERENCES public.sites (id) ON DELETE CASCADE,
                    day     date NOT NULL,
                    via     varchar(10) NOT NULL,
                    sent    integer NOT NULL DEFAULT 0,
                    failed  integer NOT NULL DEFAULT 0,
                    held    integer NOT NULL DEFAULT 0,
                    CONSTRAINT mail_daily_pkey PRIMARY KEY (site_id, day, via),
                    CONSTRAINT mail_daily_via_check CHECK (via IN ('platform', 'own')),
                    CONSTRAINT mail_daily_counts_check CHECK (sent >= 0 AND failed >= 0 AND held >= 0)
                );
                ALTER TABLE public.mail_daily ENABLE ROW LEVEL SECURITY;
                ALTER TABLE public.mail_daily FORCE ROW LEVEL SECURITY;
                CREATE POLICY mail_daily_owner_read ON public.mail_daily FOR SELECT TO mail_log_owner USING (true);
                CREATE POLICY mail_daily_owner_prune ON public.mail_daily FOR DELETE TO mail_log_owner USING (true);
                REVOKE ALL ON TABLE public.mail_daily FROM PUBLIC;
                GRANT SELECT, DELETE ON TABLE public.mail_daily TO mail_log_owner;
                DO $do$
                BEGIN
                    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_user') THEN
                        REVOKE ALL ON TABLE public.mail_daily FROM app_user;
                        GRANT SELECT, INSERT, UPDATE ON TABLE public.mail_daily TO app_user;
                    END IF;
                END
                $do$;
                SQL);
            DB::statement('CREATE POLICY mail_daily_site_isolation ON public.mail_daily FOR ALL USING ('.self::SITE_POLICY.') WITH CHECK ('.self::SITE_POLICY.')');

            DB::statement('SET LOCAL ROLE mail_log_owner');
            DB::unprepared(<<<'SQL'
                CREATE TABLE public.mail_logs (
                    id              bigint GENERATED BY DEFAULT AS IDENTITY,
                    site_id         bigint NOT NULL,
                    notification_id uuid NULL,
                    kind            varchar(40) NOT NULL,
                    via             varchar(10) NOT NULL,
                    recipient_count smallint NOT NULL,
                    to_first        citext NULL,
                    status          varchar(10) NOT NULL,
                    reason_code     varchar(20) NULL,
                    created_at      timestamptz NOT NULL DEFAULT now(),
                    CONSTRAINT mail_logs_pkey PRIMARY KEY (id, created_at),
                    CONSTRAINT mail_logs_kind_check CHECK (kind IN (
                        'password_reset', 'email_otp', 'email_change_code', 'email_changed', 'support_sign_in',
                        'owner_recovered', 'two_factor_disabled', 'email_verification', 'lead', 'invitation',
                        'domain_notice', 'domain_help', 'other')),
                    CONSTRAINT mail_logs_via_check CHECK (via IN ('platform', 'own')),
                    CONSTRAINT mail_logs_recipient_count_check CHECK (recipient_count >= 1),
                    CONSTRAINT mail_logs_status_check CHECK (status IN ('sent', 'failed')),
                    CONSTRAINT mail_logs_reason_code_check CHECK (reason_code IN ('auth', 'connection', 'rejected', 'refused_host', 'config', 'other')),
                    CONSTRAINT mail_logs_reason_status_check CHECK ((status = 'failed') = (reason_code IS NOT NULL))
                ) PARTITION BY RANGE (created_at);
                CREATE INDEX mail_logs_site_created_idx ON public.mail_logs (site_id, created_at DESC, id DESC);
                CREATE INDEX mail_logs_site_failed_idx ON public.mail_logs (site_id, created_at DESC) WHERE status = 'failed';
                ALTER TABLE public.mail_logs ENABLE ROW LEVEL SECURITY;
                ALTER TABLE public.mail_logs FORCE ROW LEVEL SECURITY;
                SQL);
            DB::statement('CREATE POLICY mail_logs_site_isolation ON public.mail_logs FOR ALL USING ('.self::SITE_POLICY.') WITH CHECK ('.self::SITE_POLICY.')');
            DB::unprepared(self::FUNCTIONS);
            DB::statement('SELECT public.mail_log_months_ensure()');
            DB::statement('RESET ROLE');

            DB::unprepared(<<<'SQL'
                REVOKE ALL ON TABLE public.mail_logs FROM PUBLIC;
                DO $do$
                BEGIN
                    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_user') THEN
                        GRANT SELECT, INSERT ON TABLE public.mail_logs TO app_user;
                    END IF;
                END
                $do$;
                SQL);
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::unprepared(<<<'SQL'
                DROP TABLE IF EXISTS public.mail_logs;
                DROP TABLE IF EXISTS public.mail_daily;
                DROP FUNCTION IF EXISTS public.mail_log_months_ensure();
                DROP FUNCTION IF EXISTS public.mail_log_month_drop(date);
                DROP FUNCTION IF EXISTS public.mail_log_months();
                DROP FUNCTION IF EXISTS public.mail_daily_prune(date);
                DROP FUNCTION IF EXISTS public.mail_log_month_create(date);
                DO $do$
                BEGIN
                    IF EXISTS (SELECT FROM pg_roles WHERE rolname = 'mail_log_owner') THEN
                        REVOKE ALL ON SCHEMA public FROM mail_log_owner;
                        DROP ROLE mail_log_owner;
                    END IF;
                END
                $do$;
                SQL);
        });
    }
};
