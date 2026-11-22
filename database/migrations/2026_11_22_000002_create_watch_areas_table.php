<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watch_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->double('latitude');
            $table->double('longitude');
            $table->double('radius_km');
            $table->jsonb('event_types');
            $table->timestamps();
        });
        DB::statement('alter table watch_areas add column area geometry(Polygon, 4326)');
        DB::statement('create index watch_areas_area_idx on watch_areas using gist (area)');

        Schema::create('alert_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('watch_area_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('tenement_event_id');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['watch_area_id', 'tenement_event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_deliveries');
        Schema::dropIfExists('watch_areas');
    }
};
