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
       Schema::create('transcripts', function (Blueprint $table) {
    $table->id();

    $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();

    $table->text('text');
    $table->float('start_time');
    $table->float('end_time');

    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transcripts');
    }
};
