<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // basic | premium
            $table->string('name');

            $table->integer('price_cents')->default(0);
            $table->string('currency', 8)->default('USD');
            $table->string('billing_cycle', 16)->default('monthly'); // monthly | yearly | one_time

            // Platform product ids (optional for free/basic)
            $table->string('product_id_ios')->nullable();
            $table->string('product_id_android')->nullable();

            // Limits / entitlements
            $table->integer('max_participants_per_meeting')->nullable(); // null = unlimited
            $table->integer('meeting_history_days')->nullable();         // null = unlimited
            $table->boolean('advanced_analytics')->default(false);
            $table->boolean('transcript_search')->default(false);
            $table->boolean('export_reports')->default(false);
            $table->integer('trial_days')->default(0);

            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);

            $table->timestamps();
        });

        // Seed default plans (safe idempotent insert).
        $now = now();
        $rows = [
            [
                'code' => 'basic',
                'name' => 'Basic',
                'price_cents' => 0,
                'currency' => 'USD',
                'billing_cycle' => 'monthly',
                'product_id_ios' => null,
                'product_id_android' => null,
                'max_participants_per_meeting' => 5,
                'meeting_history_days' => 30,
                'advanced_analytics' => false,
                'transcript_search' => false,
                'export_reports' => false,
                'trial_days' => 0,
                'is_active' => true,
                'sort_order' => 10,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'premium',
                'name' => 'Premium',
                'price_cents' => 999,
                'currency' => 'USD',
                'billing_cycle' => 'monthly',
                'product_id_ios' => 'com.wechirp.premium.monthly',
                'product_id_android' => 'com.wechirp.premium.monthly',
                'max_participants_per_meeting' => null,
                'meeting_history_days' => null,
                'advanced_analytics' => true,
                'transcript_search' => true,
                'export_reports' => true,
                'trial_days' => 7,
                'is_active' => true,
                'sort_order' => 20,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        foreach ($rows as $r) {
            $exists = DB::table('subscription_plans')->where('code', $r['code'])->exists();
            if (!$exists) {
                DB::table('subscription_plans')->insert($r);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};

