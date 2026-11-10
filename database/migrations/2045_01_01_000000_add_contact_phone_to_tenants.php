<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A customer's contact phone (tenants, a GLOBAL table — D6): optional, used only by platform staff
 * to call back or message the customer while helping them, never shown on a public page. It is
 * stored in one international form (E.164: "+", the country code, then the number, e.g.
 * +84901234567) so a search by digits and a tel: link work the same for every customer; the form
 * staff type and read ("0901 234 567") is built by ContactPhone. The CHECK keeps any other shape
 * out, whichever code writes. The column goes when the customer row goes (account deletion).
 *
 * Adding a nullable column with no default only touches the catalog; lock_timeout caps the wait
 * for the table lock at 5s, after which the migration gives up (and can be run again) rather than
 * queue requests behind it.
 */
return new class extends Migration
{
    public const string CHECK = 'tenants_contact_phone_e164_check';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement('ALTER TABLE tenants ADD COLUMN IF NOT EXISTS contact_phone varchar(16) NULL');
            if (DB::selectOne("SELECT 1 FROM pg_constraint WHERE conrelid = 'tenants'::regclass AND conname = ?", [self::CHECK]) === null) {
                DB::statement('ALTER TABLE tenants ADD CONSTRAINT '.self::CHECK." CHECK (contact_phone ~ '^\\+[1-9][0-9]{7,14}$')");
            }
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement('ALTER TABLE tenants DROP COLUMN IF EXISTS contact_phone');
        });
    }
};
