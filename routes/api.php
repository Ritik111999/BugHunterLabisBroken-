<?php

use App\Http\Controllers\Api\AccountDeletionController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AudioChunkController;
use App\Http\Controllers\Api\AudioController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ContactController;
// NEW Controllers
use App\Http\Controllers\Api\FaqController;
use App\Http\Controllers\Api\ForgotPasswordController;
use App\Http\Controllers\Api\InAppPurchaseController;
use App\Http\Controllers\Api\IntroChunkController;
use App\Http\Controllers\Api\MeetingController;
use App\Http\Controllers\Api\MeetingServicesHealthController;
use App\Http\Controllers\Api\MeetingStatsController;
use App\Http\Controllers\Api\PageController;
use App\Http\Controllers\Api\ParticipantController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\PublicDeleteAccountController as ApiPublicDeleteAccountController;
use App\Http\Controllers\Api\SpeakerMappingController;
use App\Http\Controllers\Api\SubscriptionPlanController;
use App\Http\Controllers\Api\TranscriptController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
*/

// Auth
Route::post('/signup', [AuthController::class, 'signup']);
Route::post('/login', [AuthController::class, 'login']);

/*
|--------------------------------------------------------------------------
| Node relay gateway (internal — X-Relay-Secret)
|--------------------------------------------------------------------------
*/
Route::prefix('internal/relay')->middleware('relay.internal')->group(function () {
    Route::post('/validate-auth', [\App\Http\Controllers\Api\Internal\RelayGatewayController::class, 'validateAuth']);
    Route::post('/persist', [\App\Http\Controllers\Api\Internal\RelayGatewayController::class, 'persist']);
    Route::post('/voice-chunk', [\App\Http\Controllers\Api\Internal\RelayGatewayController::class, 'ingestVoiceChunk']);
});

// Forgot Password
Route::post('/send-otp', [ForgotPasswordController::class, 'sendOtp']);
Route::post('/verify-otp', [ForgotPasswordController::class, 'verifyOtp']);
Route::post('/reset-password', [ForgotPasswordController::class, 'resetPassword']);

// Static Pages (Public)
Route::get('/pages/{type}', [PageController::class, 'show']);
Route::get('/faqs', [FaqController::class, 'index']);
Route::get('/subscription-plans', [SubscriptionPlanController::class, 'index']);

Route::middleware('throttle:public-delete-account')->group(function () {
    Route::post('/public/delete-account-request', [ApiPublicDeleteAccountController::class, 'store']);
});

/*
|--------------------------------------------------------------------------
| Protected Routes (Auth Required)
|--------------------------------------------------------------------------
*/

Route::middleware(['api.auth', 'throttle:api-authenticated'])->group(function () {

    /*
    |----------------------------------
    | Auth & Profile
    |----------------------------------
    */
    Route::post('/logout', [AuthController::class, 'logout'])->name('api.logout');

    Route::get('/profile', [ProfileController::class, 'profile']);
    Route::post('/profile/update', [ProfileController::class, 'update']);
    Route::post('/profile/change-password', [ProfileController::class, 'changePassword']);

    Route::post('/account/delete-request', [AccountDeletionController::class, 'store']);
    Route::get('/account/delete-request/status', [AccountDeletionController::class, 'status']);
    Route::post('/account/delete-request/cancel', [AccountDeletionController::class, 'cancel']);

    /*
    |----------------------------------
    | Contact
    |----------------------------------
    */
    Route::post('/contact', [ContactController::class, 'store']);

    /*
    |----------------------------------
    | Meetings
    |----------------------------------
    */

    Route::get('/health/meeting-services', [MeetingServicesHealthController::class, 'show']);
    Route::get('/meetings/search', [\App\Http\Controllers\Api\MeetingSearchController::class, 'search']);

    // list meetings
    Route::get('/meetings', [MeetingController::class, 'index']);

    // Create meeting
    Route::post('/meetings', [MeetingController::class, 'create']);

    // Start meeting
    Route::post('/meetings/{id}/start', [MeetingController::class, 'start']);

    // Short-lived WebSocket relay token (preferred over Sanctum PAT in WS URL)
    Route::post('/meetings/{id}/live-token', [\App\Http\Controllers\Api\MeetingLiveTokenController::class, 'store']);

    // End meeting
    Route::post('/meetings/{id}/end', [MeetingController::class, 'end']);

    // Get single meeting details
    Route::get('/meetings/{id}', [MeetingController::class, 'show']);

    // delete meeting
    Route::delete('/meetings/{id}', [MeetingController::class, 'delete']);

    /*
    |----------------------------------
    | Participants (Intro Step)
    |----------------------------------
    */

    // Add participant with voice embedding
    Route::post('/participants', [ParticipantController::class, 'store']);

    // Get participants of meeting
    Route::get('/meetings/{id}/participants', [ParticipantController::class, 'list']);

    /*
    |----------------------------------
    | Audio Upload
    |----------------------------------
    */

    Route::post('/audio/upload', [AudioController::class, 'upload']);
    Route::post('/audio/chunk', [AudioChunkController::class, 'storeChunk']);
    Route::post('/meetings/{id}/intro/chunk', [IntroChunkController::class, 'store']);

    /*
    |----------------------------------
    | Analytics / Results
    |----------------------------------
    */

    // Get final analytics
    Route::get('/meetings/{id}/analytics', [AnalyticsController::class, 'show']);

    // Realtime meeting stats (DB-backed)
    Route::get('/meetings/{id}/stats', [MeetingStatsController::class, 'show']);
    Route::get('/meetings/{id}/stats/stream', [MeetingStatsController::class, 'stream']);
    Route::get('/meetings/{id}/speakers', [SpeakerMappingController::class, 'list']);
    Route::post('/meetings/{id}/speakers/map', [SpeakerMappingController::class, 'map']);
    Route::get('/meetings/{id}/transcripts', [TranscriptController::class, 'list']);
    Route::get('/meetings/{id}/transcripts/stream', [TranscriptController::class, 'stream']);

    /*
    |--------------------------------------------------------------------------
    | In-App Purchase (Subscription)
    |--------------------------------------------------------------------------
    */
    Route::post('/iap/subscription/activate', [InAppPurchaseController::class, 'activate']);
    Route::post('/iap/subscription/restore', [InAppPurchaseController::class, 'restore']);
    Route::get('/iap/subscription/status', [InAppPurchaseController::class, 'status']);

});
