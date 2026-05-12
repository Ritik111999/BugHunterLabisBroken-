<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;
use Illuminate\Http\Request;

class SubscriptionPlanController extends Controller
{
    public function index()
    {
        return view('admin.manage.subscriptions.plans.index', [
            'title' => 'Pricing Plans',
            'plans' => SubscriptionPlan::query()->orderBy('sort_order')->orderBy('id')->get(),
        ]);
    }

    public function edit(SubscriptionPlan $plan)
    {
        return view('admin.manage.subscriptions.plans.edit', [
            'title' => 'Edit Plan',
            'plan' => $plan,
        ]);
    }

    public function update(Request $request, SubscriptionPlan $plan)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'price_usd' => 'nullable|numeric',
            'currency' => 'required|string|max:10',
            'billing_cycle' => 'required|string|max:50',
            'max_participants_per_meeting' => 'nullable|integer',
            'meeting_history_days' => 'nullable|integer',
            'advanced_analytics' => 'nullable|boolean',
            'transcript_search' => 'nullable|boolean',
            'export_reports' => 'nullable|boolean',
            'trial_days' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
        ]);

        foreach (['advanced_analytics', 'transcript_search', 'export_reports', 'is_active'] as $b) {
            $data[$b] = (bool) ($request->input($b, false));
        }

        $plan->fill($data)->save();

        return redirect()->route('admin.plans.edit', $plan)->with('status', 'Plan updated.');
    }
}

