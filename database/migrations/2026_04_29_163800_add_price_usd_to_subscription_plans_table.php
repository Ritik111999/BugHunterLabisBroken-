<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            // Store price in dollars (e.g., 9.99)
            $table->decimal('price_usd', 10, 2)->default(0)->after('price_cents');
        });

        // Backfill from price_cents when present.
        $plans = DB::table('subscription_plans')->get(['id', 'price_cents']);
        foreach ($plans as $p) {
            $usd = ((int) ($p->price_cents ?? 0)) / 100;
            DB::table('subscription_plans')->where('id', (int) $p->id)->update([
                'price_usd' => $usd,
            ]);
        }

        // Default currency to USD if empty.
        DB::table('subscription_plans')->whereNull('currency')->update(['currency' => 'USD']);
    }

    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn('price_usd');
        });
    }
};

