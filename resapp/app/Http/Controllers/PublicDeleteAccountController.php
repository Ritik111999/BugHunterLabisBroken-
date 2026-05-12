<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWebDeleteAccountRequest;
use App\Mail\SendOtpMail;
use App\Models\User;
use App\Services\AccountDeletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

class PublicDeleteAccountController extends Controller
{
    public function __construct(
        private readonly AccountDeletionService $accountDeletionService
    ) {}

    public function show(): View
    {
        return view('public.delete-account', [
            'title' => 'Account Deletion Request',
            'supportEmail' => config('app.support_email') ?: config('mail.from.address', 'support@example.com'),
            'privacyUrl' => route('public.legal', ['type' => 'privacy']),
            'termsUrl' => route('public.legal', ['type' => 'terms']),
            'aboutUrl' => route('public.legal', ['type' => 'about']),
            'sendOtpUrl' => route('delete-account.send-otp'),
            'verifyOtpUrl' => route('delete-account.verify-otp'),
        ]);
    }

    public function sendOtp(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::exists('users', 'email')->whereNull('deleted_at'),
            ],
        ], [
            'email.exists' => 'We could not find an active account with this email address.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $email = strtolower(trim((string) $request->input('email')));
        $otp = (string) random_int(100000, 999999);
        $key = (string) config('app.key');
        $otpHmac = hash_hmac('sha256', $otp, $key);

        $request->session()->put('delete_account_pending', [
            'email' => $email,
            'otp_hmac' => $otpHmac,
            'expires_at' => now()->addMinutes(5)->getTimestamp(),
        ]);
        $request->session()->forget([
            'delete_account_email_verified',
            'delete_account_email_verified_expires',
        ]);

        try {
            Mail::to($email)->send(new SendOtpMail($otp));
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'We could not send the email right now. Please try again shortly.',
            ], 503);
        }

        return response()->json([
            'success' => true,
            'message' => 'A verification code was sent to your email.',
        ]);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email', 'max:255'],
            'otp' => ['required', 'digits:6'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $email = strtolower(trim((string) $request->input('email')));
        $pending = $request->session()->get('delete_account_pending');

        if (!is_array($pending) || ($pending['email'] ?? '') !== $email) {
            return response()->json([
                'success' => false,
                'message' => 'Send a new verification code first.',
            ], 422);
        }

        if (now()->getTimestamp() > (int) ($pending['expires_at'] ?? 0)) {
            return response()->json([
                'success' => false,
                'message' => 'That code has expired. Please send a new code.',
            ], 422);
        }

        $key = (string) config('app.key');
        $expected = (string) ($pending['otp_hmac'] ?? '');
        $actual = hash_hmac('sha256', (string) $request->input('otp'), $key);

        if (!hash_equals($expected, $actual)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid verification code.',
            ], 422);
        }

        $request->session()->put('delete_account_email_verified', $email);
        $request->session()->put('delete_account_email_verified_expires', now()->addMinutes(30)->getTimestamp());
        $request->session()->forget('delete_account_pending');

        return response()->json([
            'success' => true,
            'message' => 'Email verified. You can submit your deletion request.',
        ]);
    }

    public function submit(StoreWebDeleteAccountRequest $request): RedirectResponse
    {
        try {
            $this->accountDeletionService->createWebRequest($request->validated());
        } catch (RuntimeException $e) {
            return back()
                ->withInput()
                ->withErrors(['email' => $e->getMessage()]);
        }

        $request->session()->forget([
            'delete_account_email_verified',
            'delete_account_email_verified_expires',
            'delete_account_pending',
        ]);

        return redirect()
            ->route('delete-account.success')
            ->with('status', 'submitted');
    }

    public function success(): View|RedirectResponse
    {
        if (!session('status')) {
            return redirect()->route('delete-account.show');
        }

        return view('public.delete-account-success', [
            'title' => 'Request Received',
            'supportEmail' => config('app.support_email') ?: config('mail.from.address', 'support@example.com'),
            'privacyUrl' => route('public.legal', ['type' => 'privacy']),
            'termsUrl' => route('public.legal', ['type' => 'terms']),
            'aboutUrl' => route('public.legal', ['type' => 'about']),
        ]);
    }
}
