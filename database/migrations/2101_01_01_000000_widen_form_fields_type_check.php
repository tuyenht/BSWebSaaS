<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Four more form field types: `number` («Số»), `date` («Ngày»), `radio` («Chọn một») and
 * `checkboxes` («Chọn nhiều»). A choice field keeps its choices in `options` like a select, and the
 * optional limits (min/max, not_past, max_choices) sit in `validation` (FormFieldTypes); nothing is
 * filtered or sorted by them, so no column.
 *
 * The type CHECK (D13) is swapped for one listing the new types: dropped and re-added NOT VALID,
 * then validated — the house pattern for a CHECK on an existing table. Both run in the migration's
 * one transaction; form_fields is small (a few dozen rows a form), so the validating scan is short,
 * and taking the lock gives up after 5s rather than queue lead sends behind it. Re-runnable after
 * a mid-run failure (DROP IF EXISTS). No row is rewritten.
 *
 * down() refuses while a form still has a field of one of these types (it says how many): those
 * rows would break the old CHECK, and a rollback asks for them to be removed in «Biểu mẫu» first.
 */
return new class extends Migration
{
    private const array TYPES = ['text', 'email', 'tel', 'textarea', 'select', 'checkbox', 'file'];

    private const array ADDED = ['number', 'date', 'radio', 'checkboxes'];

    public function up(): void
    {
        $this->listTypes([...self::TYPES, ...self::ADDED]);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $left = DB::table('form_fields')->whereIn('type', self::ADDED)->count();
        if ($left > 0) {
            throw new RuntimeException("{$left} form fields are number, date, one-choice or several-choice fields: remove them in «Biểu mẫu», then roll back.");
        }
        $this->listTypes(self::TYPES);
    }

    /** @param list<string> $types */
    private function listTypes(array $types): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $list = implode(',', array_map(fn (string $type): string => "'{$type}'", $types));

        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement('ALTER TABLE form_fields DROP CONSTRAINT IF EXISTS form_fields_type_check');
        DB::statement("ALTER TABLE form_fields ADD CONSTRAINT form_fields_type_check CHECK (type IN ({$list})) NOT VALID");
        DB::statement('ALTER TABLE form_fields VALIDATE CONSTRAINT form_fields_type_check');
    }
};
