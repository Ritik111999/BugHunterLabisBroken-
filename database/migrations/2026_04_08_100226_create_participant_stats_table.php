<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('participant_stats', function (Blueprint $table) {
    $table->id();

    $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
    $table->foreignId('participant_id')
        ->constrained('meeting_participants')
        ->cascadeOnDelete();

    $table->integer('talk_time')->default(0);
    $table->float('talk_percentage')->default(0);

    $table->integer('interruptions')->default(0);
    $table->integer('times_spoken')->default(0);

    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('participant_stats');
    }
};
