<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Core platform releases (signed ZIPs of the store application) and their single-use download links. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_releases', function (Blueprint $table) {
            $table->id();
            $table->string('version', 64)->unique();
            $table->text('notes')->nullable();
            $table->string('min_php', 16);
            $table->string('path');
            $table->char('sha256', 64);
            $table->unsignedBigInteger('size');
            $table->string('status', 16)->default('draft')->index();
            $table->json('signed_metadata')->nullable();
            $table->text('signature')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('core_download_tokens', function (Blueprint $table) {
            $table->char('token_hash', 64)->primary();
            $table->foreignId('core_release_id')->constrained('core_releases')->cascadeOnDelete();
            $table->foreignId('store_connection_id')->constrained('store_connections')->cascadeOnDelete();
            $table->string('instance_id', 128);
            $table->timestamp('expires_at')->useCurrent()->index(); // Explicit default: no implicit ON UPDATE.
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_download_tokens');
        Schema::dropIfExists('core_releases');
    }
};
