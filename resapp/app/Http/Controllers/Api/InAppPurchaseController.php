<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;
use App\Models\UserSubscription;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class InAppPurchaseController extends Controller
{
    /**
     * Activate a subscription entitlement for the authenticated user.
     *
     * Note: This endpoint currently supports "trust client" mode (default)
     * to unblock integration. For production, add server-side verification
     * for Google Play / Apple App Store.
     */
    public function activate(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Unauthorized',
            ], 401);
        }

        $trustClient = filter_var((string) env('IAP_TRUST_CLIENT', 'true'), FILTER_VALIDATE_BOOL);
        // iOS verification should be independent from IAP_TRUST_CLIENT so we can do secure iOS
        // even while Android is still in integration/trust mode.
        $verifyIosReceipt = filter_var((string) env('IAP_VERIFY_IOS_RECEIPT', 'true'), FILTER_VALIDATE_BOOL);

        $defaultExpiresDays = (int) (env('IAP_DEFAULT_EXPIRES_DAYS', 30));
        $defaultExpiresDays = max(1, $defaultExpiresDays);

        $platform = strtolower(trim((string) $request->input('platform', '')));
        $productId = (string) $request->input('product_id', '');

        $rules = [
            'platform' => 'required|in:android,ios',
            'product_id' => 'required|string',
            'purchase_token' => 'nullable|string|max:255',
            'transaction_id' => 'nullable|string|max:255',
            'receipt_data' => 'nullable|string',
            'expires_at' => 'nullable|date',
            'raw_payload' => 'nullable|array',
        ];

        if (!$trustClient) {
            if ($platform === 'android') {
                $rules['purchase_token'] = 'required|string|max:255';
            }
        }

        if ($platform === 'ios' && $verifyIosReceipt) {
            $rules['receipt_data'] = 'required|string';
        }

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return response()->json([
                'status' => 'failed',
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $plan = $this->resolvePlan($platform, $productId);
        if (!$plan) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Invalid product_id for this platform',
            ], 422);
        }

        if ($platform === 'android' && !$request->filled('purchase_token') && !$trustClient) {
            return response()->json([
                'status' => 'failed',
                'message' => 'purchase_token is required for android',
            ], 422);
        }

        $startedAt = now();

        $expiresAt = $request->input('expires_at');
        if ($expiresAt) {
            $expiresAt = (string) $expiresAt;
        } else {
            $expiresAt = now()->addDays($defaultExpiresDays)->toISOString();
        }

        $rawPayload = $request->input('raw_payload');
        if (!is_array($rawPayload)) {
            $rawPayload = null;
        }

        $purchaseToken = $request->input('purchase_token');
        $transactionId = $request->input('transaction_id');

        // Secure iOS: verify receipt_data with Apple and derive subscription dates.
        if ($platform === 'ios' && $verifyIosReceipt) {
            $sharedSecret = (string) env('APPLE_SHARED_SECRET', '');
            if (trim($sharedSecret) === '') {
                return response()->json([
                    'status' => 'failed',
                    'message' => 'APPLE_SHARED_SECRET is required for iOS receipt verification',
                ], 500);
            }

            $receiptData = (string) $request->input('receipt_data', '');
            if (trim($receiptData) === '') {
                return response()->json([
                    'status' => 'failed',
                    'message' => 'receipt_data is required for iOS',
                ], 422);
            }

            try {
                $verified = $this->verifyIosReceipt($receiptData, $sharedSecret);
            } catch (\Throwable $e) {
                return response()->json([
                    'status' => 'failed',
                    'message' => 'Apple receipt verification failed',
                    'error' => $e->getMessage(),
                ], 422);
            }

            $latest = $verified['latest_receipt_info'] ?? null;
            if (!is_array($latest) || count($latest) === 0) {
                return response()->json([
                    'status' => 'failed',
                    'message' => 'Apple response missing latest_receipt_info',
                ], 422);
            }

            $matching = array_values(array_filter(
                $latest,
                fn ($row) => is_array($row) && (string) ($row['product_id'] ?? '') === $productId
            ));

            if (count($matching) === 0) {
                return response()->json([
                    'status' => 'failed',
                    'message' => 'Receipt does not contain expected product_id',
                ], 422);
            }

            // Pick the row with the latest expiry time (renewals produce multiple rows).
            usort($matching, function ($a, $b) {
                $ae = (int) ($a['expires_date_ms'] ?? 0);
                $be = (int) ($b['expires_date_ms'] ?? 0);
                return $be <=> $ae;
            });

            $row = $matching[0];
            $expiresMs = (int) ($row['expires_date_ms'] ?? 0);
            if ($expiresMs <= 0) {
                return response()->json([
                    'status' => 'failed',
                    'message' => 'Apple response missing expires_date_ms',
                ], 422);
            }

            $expiresAtCarbon = Carbon::createFromTimestampMs($expiresMs);
            $startedAtCarbon = Carbon::createFromTimestampMs((int) ($row['purchase_date_ms'] ?? $row['original_purchase_date_ms'] ?? 0));

            $transactionId = $row['transaction_id'] ?? $transactionId;
            $startedAt = $startedAtCarbon->isValid() ? $startedAtCarbon : now();
            $expiresAt = $expiresAtCarbon->toISOString();

            // Do not grant if the subscription is already expired.
            if ($expiresAtCarbon->lte(now())) {
                return response()->json([
                    'status' => 'failed',
                    'message' => 'Subscription is expired (based on Apple receipt)',
                    'expires_at' => $expiresAt,
                ], 422);
            }

            // Keep a small receipt audit trail (optional).
            if ($rawPayload === null) {
                $rawPayload = [
                    'apple_status' => $verified['status'] ?? null,
                    'product_id' => $productId,
                ];
            }
        }

        // Mark the latest entitlement record as active.
        // Renewals will call this again with a newer token/transaction.
        $subscription = UserSubscription::query()->updateOrCreate(
            [
                'user_id' => (int) $user->id,
                'platform' => $platform,
                'purchase_token' => $purchaseToken,
                'transaction_id' => $transactionId,
            ],
            [
                'plan_id' => (int) $plan->id,
                'product_id' => $productId,
                'status' => 'active',
                'started_at' => $startedAt,
                'expires_at' => $expiresAt,
                'raw_payload' => $rawPayload,
            ]
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Subscription activated',
            'data' => [
                'is_premium' => true,
                'platform' => $subscription->platform,
                'product_id' => $subscription->product_id,
                'expires_at' => $subscription->expires_at?->toISOString(),
                'plan' => $this->planToArray($plan),
            ],
        ]);
    }

    /**
     * Restore previously purchased subscription.
     *
     * For security, this endpoint uses the same verification inputs as `activate`
     * (iOS receipt_data / Android purchase_token). iOS clients typically call restore
     * with the latest app receipt after SKStoreProductViewController/SKPaymentQueue
     * finishes.
     */
    public function restore(Request $request)
    {
        // Reuse activation flow; it already verifies iOS receipts when enabled.
        return $this->activate($request);
    }

    /**
     * Verify an iOS subscription receipt with Apple.
     *
     * @return array<string,mixed>
     */
    private function verifyIosReceipt(string $receiptData, string $sharedSecret): array
    {
        $prodUrl = (string) env('APPLE_VERIFY_RECEIPT_URL_PROD', 'https://buy.itunes.apple.com/verifyReceipt');
        $sandboxUrl = (string) env('APPLE_VERIFY_RECEIPT_URL_SANDBOX', 'https://sandbox.itunes.apple.com/verifyReceipt');

        $payload = [
            'receipt-data' => $receiptData,
            'password' => $sharedSecret,
        ];

        $res = Http::asForm()->post($prodUrl, $payload);
        $json = $res->json();
        if (!is_array($json)) {
            throw new \RuntimeException('Apple did not return valid JSON');
        }

        $status = (int) ($json['status'] ?? -1);
        // 21007: Sandbox receipt sent to production.
        if ($status === 21007) {
            $res = Http::asForm()->post($sandboxUrl, $payload);
            $json = $res->json();
            if (!is_array($json)) {
                throw new \RuntimeException('Apple sandbox did not return valid JSON');
            }
            $status = (int) ($json['status'] ?? -1);
        }

        // status == 0 => success
        if ($status !== 0) {
            throw new \RuntimeException('Apple verifyReceipt failed with status=' . $status);
        }

        return $json;
    }

    private function resolvePlan(string $platform, string $productId): ?SubscriptionPlan
    {
        $q = SubscriptionPlan::query()->where('is_active', true);
        if ($platform === 'ios') {
            $q->where('product_id_ios', $productId);
        } else if ($platform === 'android') {
            $q->where('product_id_android', $productId);
        } else {
            return null;
        }
        return $q->first();
    }

    /**
     * @return array<string,mixed>
     */
    private function planToArray(SubscriptionPlan $plan): array
    {
        return [
            'id' => (int) $plan->id,
            'code' => (string) $plan->code,
            'name' => (string) $plan->name,
            'price_usd' => (float) $plan->price_usd,
            'currency' => (string) $plan->currency,
            'billing_cycle' => (string) $plan->billing_cycle,
            'product_id_ios' => $plan->product_id_ios,
            'product_id_android' => $plan->product_id_android,
            'max_participants_per_meeting' => $plan->max_participants_per_meeting,
            'meeting_history_days' => $plan->meeting_history_days,
            'advanced_analytics' => (bool) $plan->advanced_analytics,
            'transcript_search' => (bool) $plan->transcript_search,
            'export_reports' => (bool) $plan->export_reports,
            'trial_days' => (int) $plan->trial_days,
        ];
    }

    /**
     * Get current subscription status for the authenticated user.
     */
    public function status(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Unauthorized',
            ], 401);
        }

        $now = now();
        $sub = UserSubscription::query()
            ->where('user_id', (int) $user->id)
            ->where('status', 'active')
            ->where('expires_at', '>', $now)
            ->orderByDesc('expires_at')
            ->first();

        if (!$sub) {
            return response()->json([
                'status' => 'success',
                'data' => [
                    'is_premium' => false,
                    'expires_at' => null,
                ],
            ]);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'is_premium' => true,
                'platform' => $sub->platform,
                'product_id' => $sub->product_id,
                'expires_at' => $sub->expires_at?->toISOString(),
            ],
        ]);
    }
}

