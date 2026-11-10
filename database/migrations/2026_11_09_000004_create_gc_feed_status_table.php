<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gc_feed_status', function (Blueprint $table) {
            $table->id();
            $table->string('feed')->unique();
            $table->string('last_outcome'); // ok | failed
            $table->timestamp('last_attempt_at');
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gc_feed_status');
    }
};
