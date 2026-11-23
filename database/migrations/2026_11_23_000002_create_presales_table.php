<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prospector pre-sales and Team interest (plan step 2's second pass mark:
 * 20 pre-sold annual plans). One row per checkout or reservation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('presales', function (Blueprint $table) {
            $table->id();
            $table->string('plan', 16); // prospector | team
            $table->string('email');
            $table->string('status', 16); // reserved | checkout | paid | refunded
            $table->string('source', 64)->nullable();
            $table->string('stripe_session_id')->nullable()->unique();
            $table->unsignedInteger('amount_cents')->nullable();
            $table->string('currency', 3)->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampsTz();
            $table->index(['plan', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('presales');
    }
};
