<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubscriptionPlan extends Model
{
    use HasFactory;

    protected $table = 'subscription_plans';

    protected $fillable = [
        'code',
        'name',
        'price_cents',
        'price_usd',
        'currency',
        'billing_cycle',
        'product_id_ios',
        'product_id_android',
        'max_participants_per_meeting',
        'meeting_history_days',
        'advanced_analytics',
        'transcript_search',
        'export_reports',
        'trial_days',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'price_usd' => 'decimal:2',
        'advanced_analytics' => 'boolean',
        'transcript_search' => 'boolean',
        'export_reports' => 'boolean',
        'is_active' => 'boolean',
    ];
}

