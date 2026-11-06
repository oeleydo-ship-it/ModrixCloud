<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Stores linked to a customer account (Modrix Cloud Connector) and their one-time pairing codes. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('instance_id', 128);
            $table->string('store_name', 160);
            $table->string('domain', 255);
            // SHA-256 of the bearer token; the token itself is never stored.
            $table->char('token_hash', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['instance_id', 'revoked_at']);
        });

        Schema::create('connection_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('instance_id', 128);
            $table->string('store_name', 160);
            $table->string('domain', 255);
            $table->char('code_hash', 64)->unique();
            // PKCE S256: base64url(sha256(verifier)).
            $table->string('code_challenge', 128);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connection_codes');
        Schema::dropIfExists('store_connections');
    }
};
