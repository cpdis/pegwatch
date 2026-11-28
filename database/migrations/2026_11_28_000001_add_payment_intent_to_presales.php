<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A paid pre-sale keeps its Stripe PaymentIntent, so a refund made in the
 * Stripe dashboard (charge.refunded, which names only the PaymentIntent) can
 * find it. Statuses now also include failed and expired.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('presales', function (Blueprint $table) {
            $table->string('stripe_payment_intent')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('presales', function (Blueprint $table) {
            $table->dropColumn('stripe_payment_intent');
        });
    }
};
