<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Forms / lead capture (design.md §16/§23/§27). An Vinh's contact/quote form on the
 * storefront → submissions stored for the admin inbox + email notify. All three are
 * tenant tables (site_id) → RLS + FORCE + fail-closed policy, so a lead captured for
 * site A is never readable in site B's context (RlsCoverageTest enforces this).
 *   • forms             — a form definition (key + localized name + notify recipients)
 *   • form_fields        — its fields (type + label + validation)
 *   • form_submissions   — captured leads (typed name/email/phone + data JSONB + status)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->ulid('ulid')->unique();
            $table->string('key');
            $table->jsonb('name')->default('{}');                 // per-locale {"vi": …}
            $table->jsonb('success_message')->default('{}');
            $table->jsonb('notify_emails')->default('[]');         // lead-notification recipients
            $table->jsonb('settings')->default('{}');
            $table->string('status')->default('active');           // D13 CHECK below
            $table->timestampsTz();

            $table->unique(['site_id', 'key']);
        });

        Schema::create('form_fields', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('form_id')->constrained('forms')->cascadeOnDelete();
            $table->string('key');
            $table->jsonb('label')->default('{}');                 // per-locale label
            $table->string('type')->default('text');               // D13 CHECK below
            $table->boolean('is_required')->default(false);
            $table->jsonb('options')->default('{}');                // for select/checkbox
            $table->jsonb('validation')->default('{}');
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();

            $table->unique(['form_id', 'key']);
        });

        Schema::create('form_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->ulid('ulid')->unique();
            $table->foreignId('form_id')->constrained('forms')->cascadeOnDelete();
            $table->jsonb('data')->default('{}');                  // all submitted fields (flexible)
            $table->string('name')->nullable();                    // "graduated" columns for the inbox
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('source_url')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('status')->default('new');              // D13 CHECK below
            $table->boolean('is_spam')->default(false);
            $table->timestampsTz();

            $table->index(['site_id', 'form_id', 'created_at']);
            $table->index(['site_id', 'status']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // D13: status / type = varchar + CHECK (extensible, not native ENUM).
        DB::statement("ALTER TABLE forms ADD CONSTRAINT forms_status_check CHECK (status IN ('active','disabled'))");
        DB::statement("ALTER TABLE form_fields ADD CONSTRAINT form_fields_type_check CHECK (type IN ('text','email','tel','textarea','select','checkbox'))");
        DB::statement("ALTER TABLE form_submissions ADD CONSTRAINT form_submissions_status_check CHECK (status IN ('new','read','archived','spam'))");

        foreach (['forms', 'form_fields', 'form_submissions'] as $table) {
            $this->enableRls($table);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('form_submissions');
        Schema::dropIfExists('form_fields');
        Schema::dropIfExists('forms');
    }

    private function enableRls(string $table): void
    {
        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement(<<<SQL
            CREATE POLICY {$table}_site_isolation ON {$table}
                FOR ALL
                USING      (site_id = NULLIF(current_setting('app.current_site_id', true), '')::bigint)
                WITH CHECK (site_id = NULLIF(current_setting('app.current_site_id', true), '')::bigint)
            SQL);
    }
};
