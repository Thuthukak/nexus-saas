<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Login handoff nonces (signup -> tenant host). Central table, not under RLS: it is written
| during provisioning before any tenant context exists, and read on redemption by tenant_id.
| Only a SHA-256 hash of the nonce is stored. Prune rows past expires_at on a schedule.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_handoffs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->unsignedBigInteger('user_id');
            $t->char('nonce_hash', 64)->unique();
            $t->timestamp('expires_at');
            $t->timestamp('redeemed_at')->nullable();
            $t->timestamp('created_at');

            $t->index(['tenant_id', 'user_id']);
            $t->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_handoffs');
    }
};
