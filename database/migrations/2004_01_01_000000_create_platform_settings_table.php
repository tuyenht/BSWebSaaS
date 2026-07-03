<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-global (non-tenant) grouped key/value config — the platform-wide defaults that
 * tenants fall back to (e.g. the platform SMTP/email + shared OAuth apps). Secret values
 * inside `value` are AES-256-GCM encrypted by the application (SecretCipher); the column
 * itself stores the ciphertext, never plaintext.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('group_name');
            $table->string('key')->default('config');
            $table->jsonb('value')->nullable();
            $table->timestamps();

            $table->unique(['group_name', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
