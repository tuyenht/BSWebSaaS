<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The "Mua hàng" button at the end of the storefront phone bar is counted too: `buy` joins the
 * channels contact_click_daily accepts (ContactClickCounter::BUY, placement `bar` only; the
 * "Hiệu quả" card shows its row). The built-in list and the `c1`, `c2`, … pattern stay as
 * 2056 made them; that file still describes the table.
 *
 * The channel CHECK (D13) is swapped in one short transaction (drop the old one, add the wider one
 * NOT VALID: no moment without a CHECK, no scan under the ACCESS EXCLUSIVE lock, which gives up
 * after 5s instead of queueing the flush behind a long transaction), then validated in its own
 * transaction under SHARE UPDATE EXCLUSIVE, which lets reads and the flush's writes go on. Every
 * step can be repeated after a run that stopped half way (DROP IF EXISTS). No column changes:
 * PgBouncer needs no restart.
 *
 * down() puts the old list back, and never deletes a tally: while any `buy` row exists it refuses
 * (the rows would break the old CHECK). A `buy` row written between that look and the swap makes
 * the validation fail; the wider CHECK then goes back and down() refuses the same way. Any other
 * failed validation also puts the wider CHECK back, then reports its own error.
 */
return new class extends Migration
{
    /** Each step commits on its own, so VALIDATE CONSTRAINT runs under its weaker lock. */
    public $withinTransaction = false;

    private const array CHANNELS = [
        'phone', 'zalo', 'messenger', 'email', 'sms', 'whatsapp', 'telegram', 'viber',
        'tiktok', 'youtube', 'instagram', 'maps',
    ];

    private const string ADDED = 'buy';

    private const string REFUSED = 'The "Mua hàng" taps are still counted in contact_click_daily (channel = buy): '
        .'the old channel list cannot hold them. Keep this migration, or remove those rows yourself first.';

    public function up(): void
    {
        $this->swap([...self::CHANNELS, self::ADDED]);
        $this->validate();
    }

    public function down(): void
    {
        if (DB::table('contact_click_daily')->where('channel', self::ADDED)->exists()) {
            // Repairs a run that stopped after narrowing the CHECK and could not restore it.
            $this->swap([...self::CHANNELS, self::ADDED]);
            $this->validate();

            throw new RuntimeException(self::REFUSED);
        }

        $this->swap(self::CHANNELS);
        try {
            $this->validate();
        } catch (QueryException $e) {
            $this->swap([...self::CHANNELS, self::ADDED]);
            $this->validate();

            // Only a row the old list cannot hold (check_violation) means "Mua hàng" taps exist; any other
            // failure (a lock wait that gave up, say) is reported as itself.
            if ($e->getCode() !== '23514') {
                throw $e;
            }

            throw new RuntimeException(self::REFUSED);
        }
    }

    /**
     * Replace the channel CHECK with one listing $channels (plus a website's own keys), NOT VALID.
     *
     * @param  list<string>  $channels
     */
    private function swap(array $channels): void
    {
        $list = implode(', ', array_map(fn (string $channel): string => "'{$channel}'", $channels));

        DB::transaction(function () use ($list): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement('ALTER TABLE contact_click_daily DROP CONSTRAINT IF EXISTS contact_click_daily_channel_check');
            DB::statement(<<<SQL
                ALTER TABLE contact_click_daily ADD CONSTRAINT contact_click_daily_channel_check CHECK (
                    channel IN ({$list}) OR channel ~ '^c[1-9][0-9]{0,5}$'
                ) NOT VALID
                SQL);
        });
    }

    private function validate(): void
    {
        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement('ALTER TABLE contact_click_daily VALIDATE CONSTRAINT contact_click_daily_channel_check');
        });
    }
};
