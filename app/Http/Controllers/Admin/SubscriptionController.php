<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UserSubscription;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->query('status');
        $platform = $request->query('platform');

        $subs = UserSubscription::query()
            ->with(['user', 'plan'])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($platform, fn ($q) => $q->where('platform', $platform))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $stats = [
            'total' => UserSubscription::query()->count(),
            'active' => UserSubscription::query()->where('status', 'active')->count(),
            'expired' => UserSubscription::query()->where('status', 'expired')->count(),
            'canceled' => UserSubscription::query()->where('status', 'canceled')->count(),
        ];

        return view('admin.manage.subscriptions.index', [
            'title' => 'Subscriptions',
            'subs' => $subs,
            'statusFilter' => $status,
            'platformFilter' => $platform,
            'stats' => $stats,
        ]);
    }

    public function show(UserSubscription $subscription)
    {
        $subscription->load(['user', 'plan']);

        return view('admin.manage.subscriptions.show', [
            'title' => 'Subscription',
            'sub' => $subscription,
        ]);
    }

    public function refund(Request $request, UserSubscription $subscription)
    {
        $data = $request->validate([
            'note' => 'nullable|string|max:2000',
        ]);

        $payload = is_array($subscription->raw_payload) ? $subscription->raw_payload : [];
        $payload['refund'] = [
            'marked_by_admin' => true,
            'note' => $data['note'] ?? null,
            'marked_at' => now()->toISOString(),
        ];

        $subscription->status = 'refunded';
        $subscription->raw_payload = $payload;
        $subscription->save();

        return redirect()->route('admin.subscriptions.show', $subscription)->with('status', 'Refund marked.');
    }
}

