<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UserSubscription;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $status = $request->query('status');
        $platform = $request->query('platform');
        $from = $request->query('from');
        $to = $request->query('to');

        $payments = UserSubscription::query()
            ->with(['user', 'plan'])
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($q2) use ($q) {
                    $q2->where('transaction_id', 'like', "%{$q}%")
                        ->orWhere('product_id', 'like', "%{$q}%")
                        ->orWhereHas('user', fn ($u) => $u->where('email', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%"));
                });
            })
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($platform, fn ($query) => $query->where('platform', $platform))
            ->when($from, fn ($query) => $query->whereDate('created_at', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('created_at', '<=', $to))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.manage.payments.index', [
            'title' => 'Payment History',
            'payments' => $payments,
            'q' => $q,
            'statusFilter' => $status,
            'platformFilter' => $platform,
            'from' => $from,
            'to' => $to,
        ]);
    }
}

