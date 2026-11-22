<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The weekly WA digest (plan step 2, the demand test): email-only subscribers,
 * no account. Stores the address and nothing else about the person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('digest_subscribers', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('token', 64)->unique();
            $table->string('source', 64)->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('unsubscribed_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('digest_issues', function (Blueprint $table) {
            $table->id();
            $table->string('week', 8)->unique(); // ISO week in Perth, e.g. 2026-W48
            $table->timestampTz('period_start');
            $table->timestampTz('period_end');
            $table->jsonb('content');
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('digest_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('digest_issue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('digest_subscriber_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampsTz();
            $table->unique(['digest_issue_id', 'digest_subscriber_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digest_deliveries');
        Schema::dropIfExists('digest_issues');
        Schema::dropIfExists('digest_subscribers');
    }
};
