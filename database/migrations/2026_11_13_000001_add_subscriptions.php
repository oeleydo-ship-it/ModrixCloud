<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The monthly subscription. Each paid package says whether it is sold
 * individually, included in the subscription, or both; existing packages stay
 * sold individually only. Licenses obtained through the subscription have the
 * type "subscription" (licenses.type is a string, so no column change).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('packages', 'sold_individually')) {
            Schema::table('packages', function (Blueprint $table) {
                $table->boolean('sold_individually')->default(true);
                $table->boolean('in_subscription')->default(false);
            });
        }

        if (! Schema::hasTable('subscriptions')) {
            Schema::create('subscriptions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                // pending | active | trialing | past_due | incomplete | unpaid | canceled | expired
                $table->string('status', 24)->default('pending');
                $table->decimal('amount', 12, 2);
                $table->char('currency', 3);
                $table->string('stripe_session', 191)->nullable()->unique();
                $table->string('stripe_subscription', 191)->nullable()->unique();
                $table->string('stripe_customer', 191)->nullable()->index();
                $table->timestamp('current_period_end')->nullable();
                $table->boolean('cancel_at_period_end')->default(false);
                $table->timestamp('ended_at')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');

        if (Schema::hasColumn('packages', 'sold_individually')) {
            Schema::table('packages', function (Blueprint $table) {
                $table->dropColumn(['sold_individually', 'in_subscription']);
            });
        }
    }
};
