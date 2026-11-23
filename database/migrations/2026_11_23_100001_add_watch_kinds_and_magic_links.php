<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Email-first watching (W1, T7, H7, G15): a watch is a circle, a tenement
 * outline plus 2 km, or a holder; it starts unconfirmed until the emailed
 * link is pressed. Accounts can exist without a password (magic links).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });

        Schema::table('watch_areas', function (Blueprint $table) {
            $table->string('kind', 16)->default('circle')->after('name');
            $table->double('latitude')->nullable()->change();
            $table->double('longitude')->nullable()->change();
            $table->double('radius_km')->nullable()->change();
            $table->string('tenid')->nullable()->after('radius_km');
            $table->string('holder_slug')->nullable()->after('tenid');
            $table->boolean('include_covered_deaths')->default(false)->after('event_types');
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('unsubscribed_at')->nullable();
            $table->index('holder_slug');
        });
        // A tenement outline plus a buffer can be several polygons.
        DB::statement('alter table watch_areas alter column area type geometry(MultiPolygon, 4326) using ST_Multi(area)');
        // Every area made before this was saved by a signed-in, verified user.
        DB::statement('update watch_areas set confirmed_at = created_at where confirmed_at is null');

        // Day-before and hour-before release reminders, one row per area, release and kind.
        Schema::create('release_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('watch_area_id')->constrained()->cascadeOnDelete();
            $table->string('tenid');
            $table->string('rlesid');
            $table->timestampTz('release_at');
            $table->string('kind', 8); // day | hour
            $table->timestampTz('sent_at')->nullable();
            $table->timestampsTz();
            $table->unique(['watch_area_id', 'tenid', 'rlesid', 'release_at', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('release_reminders');
        DB::statement('delete from watch_areas where latitude is null or longitude is null or radius_km is null');
        DB::statement('alter table watch_areas alter column area type geometry(Polygon, 4326) using ST_GeometryN(area, 1)');
        Schema::table('watch_areas', function (Blueprint $table) {
            $table->dropIndex(['holder_slug']);
            $table->dropColumn(['kind', 'tenid', 'holder_slug', 'include_covered_deaths', 'confirmed_at', 'unsubscribed_at']);
            $table->double('latitude')->nullable(false)->change();
            $table->double('longitude')->nullable(false)->change();
            $table->double('radius_km')->nullable(false)->change();
        });
        DB::statement("update users set password = '' where password is null");
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable(false)->change();
        });
    }
};
