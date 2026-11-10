<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gc_airports', function (Blueprint $table) {
            // Field elevation in feet MSL; null is read as sea level by the ground filter.
            $table->integer('elevation_ft')->nullable()->after('lon');
        });
    }

    public function down(): void
    {
        Schema::table('gc_airports', function (Blueprint $table) {
            $table->dropColumn('elevation_ft');
        });
    }
};
