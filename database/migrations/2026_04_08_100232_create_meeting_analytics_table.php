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
        Schema::create('meeting_analytics', function (Blueprint $table) {
    $table->id();

    $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();

    $table->float('crosstalk_percentage')->nullable();
    $table->integer('total_speakers')->nullable();

    $table->json('keywords')->nullable();
    $table->text('summary')->nullable();
    $table->json('action_items')->nullable();
    $table->json('sentiment')->nullable();

    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meeting_analytics');
    }
};
