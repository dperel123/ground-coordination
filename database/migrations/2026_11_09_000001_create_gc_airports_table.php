<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gc_airports', function (Blueprint $table) {
            $table->id();
            $table->string('icao', 4)->unique();
            $table->string('name');
            $table->double('lat');
            $table->double('lon');
            $table->jsonb('bbox'); // {min_lat, min_lon, max_lat, max_lon}
            $table->string('timezone');
            // The default capture radius around a stand's center; a stand's own radius_m overrides it.
            $table->unsignedInteger('match_radius_m')->default(40);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gc_airports');
    }
};
