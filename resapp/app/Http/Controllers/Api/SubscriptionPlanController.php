<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;

class SubscriptionPlanController extends Controller
{
    public function index()
    {
        $plans = SubscriptionPlan::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (SubscriptionPlan $p) => [
                'id' => (int) $p->id,
                'code' => (string) $p->code,
                'name' => (string) $p->name,
                'price_usd' => (float) $p->price_usd,
                'currency' => (string) $p->currency,
                'billing_cycle' => (string) $p->billing_cycle,
                'product_id_ios' => $p->product_id_ios,
                'product_id_android' => $p->product_id_android,
                'max_participants_per_meeting' => $p->max_participants_per_meeting,
                'meeting_history_days' => $p->meeting_history_days,
                'advanced_analytics' => (bool) $p->advanced_analytics,
                'transcript_search' => (bool) $p->transcript_search,
                'export_reports' => (bool) $p->export_reports,
                'trial_days' => (int) $p->trial_days,
            ])
            ->values();

        return response()->json([
            'status' => 'success',
            'data' => $plans,
        ]);
    }
}

