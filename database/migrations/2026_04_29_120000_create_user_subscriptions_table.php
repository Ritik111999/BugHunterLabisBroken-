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
        Schema::create('user_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // android | ios
            $table->string('platform', 16);

            // Example: com.wechirp.premium.monthly
            $table->string('product_id');

            // google play
            $table->string('purchase_token', 255)->nullable();

            // apple app store
            $table->string('transaction_id', 255)->nullable();

            $table->string('status', 32)->default('active');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            // Store any optional client payload for auditing/debug.
            $table->json('raw_payload')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'platform']);
            $table->unique(['user_id', 'platform', 'purchase_token']);
            $table->unique(['user_id', 'platform', 'transaction_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_subscriptions');
    }
};

