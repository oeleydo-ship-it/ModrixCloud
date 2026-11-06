<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Banned accounts (no sign-in, no store connections) and banned stores (by installation or domain). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('banned_at')->nullable();
            $table->string('ban_reason', 255)->nullable();
        });

        Schema::create('banned_stores', function (Blueprint $table) {
            $table->id();
            $table->string('instance_id', 128)->nullable()->index();
            $table->string('domain', 255)->nullable()->index();
            $table->string('reason', 255)->nullable();
            $table->foreignId('banned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banned_stores');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['banned_at', 'ban_reason']);
        });
    }
};
