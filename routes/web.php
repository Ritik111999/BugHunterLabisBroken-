<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SupportController;
use App\Http\Controllers\Admin\PageController;
use App\Http\Controllers\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Admin\MeetingController;
use App\Http\Controllers\Admin\SubscriptionPlanController;
use App\Http\Controllers\Admin\SubscriptionController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\FaqController;
use App\Http\Controllers\Admin\SystemController;
use App\Http\Controllers\Admin\PaymentController;
use App\Http\Controllers\Admin\AdminAccountDeletionController;
use App\Http\Controllers\PublicDeleteAccountController;
use App\Models\Faq;

Route::get('/', function () {
    return redirect()->route('admin.auth.signin');
});

Route::get('/tester', function () {
    return view('api-tester');
});

Route::get('/demo', function () {
    return view('meeting-demo');
});

Route::get('/faqs', function () {
    $faqs = Faq::query()
        ->where('is_active', true)
        ->orderBy('order')
        ->orderBy('id')
        ->get();

    return view('faqs', ['faqs' => $faqs]);
});

Route::get('/legal/{type}', function (string $type) {
    if (!in_array($type, ['privacy', 'terms', 'about'], true)) {
        abort(404);
    }
    $page = \App\Models\Page::query()->where('type', $type)->firstOrFail();

    return response()->view('public.legal-simple', [
        'title' => $page->title,
        'content' => $page->content,
    ]);
})->name('public.legal');

// Public account deletion (browser): GET http://localhost:8000/delete-account when APP_URL=http://localhost:8000
Route::middleware('throttle:delete-account-otp')->group(function () {
    Route::post('/delete-account/send-otp', [PublicDeleteAccountController::class, 'sendOtp'])->name('delete-account.send-otp');
    Route::post('/delete-account/verify-otp', [PublicDeleteAccountController::class, 'verifyOtp'])->name('delete-account.verify-otp');
});

Route::middleware('throttle:public-delete-account')->group(function () {
    Route::get('/delete-account', [PublicDeleteAccountController::class, 'show'])->name('delete-account.show');
    Route::post('/delete-account', [PublicDeleteAccountController::class, 'submit'])->name('delete-account.submit');
});
Route::get('/delete-account/success', [PublicDeleteAccountController::class, 'success'])->name('delete-account.success');

Route::prefix('admin')->group(function () {
    // (UI only for now; real auth wiring can come later)
    Route::get('/signin', [AdminAuthController::class, 'showLogin'])->name('admin.auth.signin');
    Route::post('/signin', [AdminAuthController::class, 'login'])->name('admin.auth.login');
    Route::post('/logout', [AdminAuthController::class, 'logout'])->name('admin.auth.logout');
    // Route::get('/signup', fn () => view('admin.pages.auth.signup', ['title' => 'Sign Up']));

    Route::middleware(['admin'])->group(function () {
        Route::get('/', [SystemController::class, 'dashboard'])->name('admin.dashboard');

        // Dynamic admin management
        Route::get('/users', [UserController::class, 'index'])->name('admin.users.index');
        Route::get('/users/{user}', [UserController::class, 'show'])->name('admin.users.show');
        Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('admin.users.edit');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('admin.users.update');
        Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('admin.users.reset_password');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('admin.users.destroy');

        Route::get('/roles', [RoleController::class, 'index'])->name('admin.roles.index');

        Route::get('/support/faqs', [SupportController::class, 'faqs'])->name('admin.support.faqs.index');
        Route::get('/support/faqs/{faq}/edit', [SupportController::class, 'editFaq'])->name('admin.support.faqs.edit');
        Route::put('/support/faqs/{faq}', [SupportController::class, 'updateFaq'])->name('admin.support.faqs.update');
        Route::get('/support', [SupportController::class, 'index'])->name('admin.support.index');
        Route::get('/support/{ticket}', [SupportController::class, 'show'])->name('admin.support.show');
        Route::put('/support/{ticket}', [SupportController::class, 'update'])->name('admin.support.update');

        Route::get('/pages', [PageController::class, 'index'])->name('admin.pages.index');
        Route::get('/pages/{type}/edit', [PageController::class, 'edit'])->name('admin.pages.edit');
        Route::put('/pages/{type}', [PageController::class, 'update'])->name('admin.pages.update');

        // Backward-compatible aliases for previously used page types
        Route::get('/pages/terms_of_service/edit', fn () => redirect()->route('admin.pages.edit', 'terms'));
        Route::get('/pages/privacy_policy/edit', fn () => redirect()->route('admin.pages.edit', 'privacy'));
        Route::get('/pages/about_us/edit', fn () => redirect()->route('admin.pages.edit', 'about'));

        // Reports / Meetings
        Route::get('/meetings', [MeetingController::class, 'index'])->name('admin.meetings.index');
        Route::get('/meetings/export', [MeetingController::class, 'export'])->name('admin.meetings.export');
        Route::get('/meetings/export/download', [MeetingController::class, 'exportDownload'])->name('admin.meetings.export_download');
        Route::get('/meetings/export-analytics', [MeetingController::class, 'exportAnalytics'])->name('admin.meetings.export_analytics');
        Route::get('/meetings/export-analytics/download', [MeetingController::class, 'exportAnalyticsDownload'])->name('admin.meetings.export_analytics_download');
        Route::get('/meetings/{meeting}', [MeetingController::class, 'show'])->name('admin.meetings.show');
        Route::get('/meetings/{meeting}/export', [MeetingController::class, 'exportMeeting'])->name('admin.meetings.export_one');
        Route::get('/meetings/{meeting}/export-analytics', [MeetingController::class, 'exportMeetingAnalytics'])->name('admin.meetings.export_one_analytics');
        Route::get('/meetings/{meeting}/export-transcript', [MeetingController::class, 'exportTranscript'])->name('admin.meetings.export_transcript');
        Route::get('/meetings/{meeting}/audios/{audio}/download', [MeetingController::class, 'downloadAudio'])->name('admin.meetings.download_audio');
        Route::delete('/meetings/{meeting}', [MeetingController::class, 'destroy'])->name('admin.meetings.destroy');

        // Subscriptions
        Route::get('/subscriptions', [SubscriptionController::class, 'index'])->name('admin.subscriptions.index');
        Route::get('/subscriptions/{subscription}', [SubscriptionController::class, 'show'])->name('admin.subscriptions.show');
        Route::post('/subscriptions/{subscription}/refund', [SubscriptionController::class, 'refund'])->name('admin.subscriptions.refund');
        Route::get('/plans', [SubscriptionPlanController::class, 'index'])->name('admin.plans.index');
        Route::get('/plans/{plan}/edit', [SubscriptionPlanController::class, 'edit'])->name('admin.plans.edit');
        Route::put('/plans/{plan}', [SubscriptionPlanController::class, 'update'])->name('admin.plans.update');

        // Payment history
        Route::get('/payments', [PaymentController::class, 'index'])->name('admin.payments.index');

        // Settings / Feature flags
        Route::get('/settings', [SettingController::class, 'redirect'])->name('admin.settings.index');
        Route::get('/settings/app', [SettingController::class, 'app'])->name('admin.settings.app');
        Route::post('/settings/app', [SettingController::class, 'storeApp'])->name('admin.settings.app.store');
        Route::get('/settings/feature-flags', [SettingController::class, 'featureFlags'])->name('admin.settings.flags');
        Route::post('/settings/feature-flags', [SettingController::class, 'storeFlag'])->name('admin.settings.flags.store');
        Route::post('/settings/feature-flags/toggle', [SettingController::class, 'toggleFlag'])->name('admin.settings.flags.toggle');
        Route::delete('/settings/{setting}', [SettingController::class, 'destroy'])->name('admin.settings.destroy');

        // FAQ content
        Route::get('/faqs', [FaqController::class, 'index'])->name('admin.faqs.index');
        Route::post('/faqs', [FaqController::class, 'store'])->name('admin.faqs.store');
        Route::put('/faqs/{faq}', [FaqController::class, 'update'])->name('admin.faqs.update');
        Route::delete('/faqs/{faq}', [FaqController::class, 'destroy'])->name('admin.faqs.destroy');

        Route::get('/account-deletion-requests', [AdminAccountDeletionController::class, 'index'])->name('admin.account-deletion-requests.index');
        Route::post('/account-deletion-requests/{account_deletion_request}/approve', [AdminAccountDeletionController::class, 'approve'])->name('admin.account-deletion-requests.approve');
        Route::post('/account-deletion-requests/{account_deletion_request}/reject', [AdminAccountDeletionController::class, 'reject'])->name('admin.account-deletion-requests.reject');
        Route::post('/account-deletion-requests/{account_deletion_request}/delete-user', [AdminAccountDeletionController::class, 'deleteUser'])->name('admin.account-deletion-requests.delete-user');

        // Logs / performance
        Route::get('/logs', [SystemController::class, 'logs'])->name('admin.logs');
        Route::get('/performance', [SystemController::class, 'performance'])->name('admin.performance');
    });
});
