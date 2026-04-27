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
        Schema::create('speaker_mappings', function (Blueprint $table) {
    $table->id();

    $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
    $table->string('speaker_label');

    $table->foreignId('participant_id')
        ->constrained('meeting_participants')
        ->cascadeOnDelete();

    $table->float('confidence')->nullable();

    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('speaker_mappings');
    }
};
