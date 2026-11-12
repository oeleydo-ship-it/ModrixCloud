<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Yearly subscriptions: each subscription is billed every month or every
 * year. Subscriptions that exist before this migration are monthly.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('subscriptions', 'interval')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->string('interval', 8)->default('month'); // month | year
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('subscriptions', 'interval')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->dropColumn('interval');
            });
        }
    }
};
