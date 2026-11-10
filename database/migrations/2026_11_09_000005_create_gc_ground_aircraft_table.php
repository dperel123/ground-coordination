<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gc_ground_aircraft', function (Blueprint $table) {
            $table->id();
            $table->foreignId('airport_id')->constrained('gc_airports')->cascadeOnDelete();
            $table->string('callsign');
            $table->unsignedBigInteger('cid');
            $table->double('lat');
            $table->double('lon');
            $table->unsignedSmallInteger('groundspeed');
            $table->unsignedSmallInteger('heading');
            $table->integer('altitude');
            $table->string('aircraft_type')->nullable();
            $table->string('departure')->nullable();
            $table->string('arrival')->nullable();
            // The stand the aircraft is at now; null once it leaves (stand_left_at says when).
            $table->foreignId('stand_id')->nullable()->constrained('gc_stands')->nullOnDelete();
            $table->double('stand_distance_m')->nullable();
            $table->string('phase'); // at_stand | moving | off_stand
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at')->index();
            $table->timestamp('stand_entered_at')->nullable();
            $table->timestamp('stand_left_at')->nullable();
            $table->timestamps();

            $table->unique(['airport_id', 'callsign']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gc_ground_aircraft');
    }
};
