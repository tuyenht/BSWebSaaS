<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Global (D6 — no site_id, no RLS) link between a user and a provider's stable subject id
 * (sub). An authenticated member "connects" a social provider; social login then matches by
 * (provider, sub), so providers that don't assert a verified email (Facebook/Microsoft/Yahoo)
 * can sign in safely. `sub`/`email` are public identifiers, stored plaintext (no SecretCipher).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('provider');          // google | microsoft | yahoo | facebook
            $table->string('sub');               // the provider's stable subject id
            $table->string('email')->nullable(); // display only
            $table->timestampsTz();

            $table->unique(['provider', 'sub']);     // one provider identity ↔ one account
            $table->unique(['user_id', 'provider']); // one link per provider per user
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_identities');
    }
};
