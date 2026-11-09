<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gc_stands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('airport_id')->constrained('gc_airports')->cascadeOnDelete();
            $table->string('name');
            $table->string('area')->nullable();
            $table->double('lat');
            $table->double('lon');
            $table->unsignedSmallInteger('heading')->nullable();
            // Null falls back to the airport's match_radius_m.
            $table->unsignedInteger('radius_m')->nullable();
            $table->string('source');
            $table->string('attribution');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['airport_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gc_stands');
    }
};
