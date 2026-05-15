<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Meeting;
use App\Support\MeetingRelayTokenService;
use Illuminate\Http\JsonResponse;

class MeetingLiveTokenController extends Controller
{
    /**
     * Issue a short-lived token for the live audio WebSocket relay.
     */
    public function store(int $id): JsonResponse
    {
        $meeting = Meeting::query()
            ->whereKey($id)
            ->where('host_id', auth()->id())
            ->firstOrFail();

        return response()->json([
            'meeting_id' => (int) $meeting->id,
            ...MeetingRelayTokenService::issue((int) $meeting->id, (int) auth()->id()),
        ]);
    }
}
