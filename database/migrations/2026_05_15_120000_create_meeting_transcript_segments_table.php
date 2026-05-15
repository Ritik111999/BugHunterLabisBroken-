<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_transcript_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('participant_id')->nullable()->constrained('meeting_participants')->nullOnDelete();
            $table->string('speaker_name', 120)->default('Speaker');
            $table->string('speaker_label', 64)->nullable();
            $table->text('text');
            $table->unsignedBigInteger('start_ms')->default(0);
            $table->unsignedBigInteger('end_ms')->default(0);
            $table->boolean('is_final')->default(true);
            $table->timestamps();

            $table->index(['meeting_id', 'id']);
        });

        Schema::create('meeting_search_indexes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->longText('text_blob');
            $table->json('embedding')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_search_indexes');
        Schema::dropIfExists('meeting_transcript_segments');
    }
};
