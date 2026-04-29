<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_subscriptions', function (Blueprint $table) {
            $table->foreignId('plan_id')->nullable()->after('user_id')->constrained('subscription_plans');
            $table->index(['plan_id', 'platform']);
        });

        // Best-effort backfill based on product_id (premium monthly).
        $premiumId = DB::table('subscription_plans')->where('code', 'premium')->value('id');
        if ($premiumId) {
            DB::table('user_subscriptions')
                ->where('product_id', 'com.wechirp.premium.monthly')
                ->update(['plan_id' => (int) $premiumId]);
        }
    }

    public function down(): void
    {
        Schema::table('user_subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plan_id');
        });
    }
};

