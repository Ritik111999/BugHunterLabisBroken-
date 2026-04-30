<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Meeting;
use Illuminate\Http\Request;

class MeetingController extends Controller
{
public function index()
{
    $user = auth()->user();
    $plan = $user?->currentPlan();
    $days = $plan?->meeting_history_days;

    $q = Meeting::where('host_id', auth()->id());
    if (is_numeric($days)) {
        $q->where('created_at', '>=', now()->subDays((int) $days));
    }

    $meetings = $q
        ->withCount('participants')
        ->latest()
        ->get()
        ->map(function ($meeting) {
            $meeting->participants_count = (int) $meeting->participants_count;
            return $meeting;
        });

    return response()->json($meetings);
}
    public function create(Request $request)
    {
        $meeting = Meeting::create([
            'host_id' => auth()->id(),
            'title' => $request->title,
            'status' => 'pending'
        ]);

        return response()->json($meeting);
    }

    public function start($id)
    {
        $meeting = Meeting::findOrFail($id);
        $meeting->update([
            'started_at' => now(),
            'status' => 'processing'
        ]);

        return response()->json(['message' => 'Meeting started']);
    }

    public function end(Request $request, $id)
    {
        $meeting = Meeting::query()
            ->whereKey((int) $id)
            ->where('host_id', auth()->id())
            ->firstOrFail();

        $validated = $request->validate([
            'duration_seconds' => ['nullable', 'numeric', 'min:0', 'max:86400'],
        ]);

        $durationSeconds = null;
        if (array_key_exists('duration_seconds', $validated) && $validated['duration_seconds'] !== null) {
            $durationSeconds = (int) round((float) $validated['duration_seconds']);
        } elseif ($meeting->started_at) {
            $durationSeconds = (int) $meeting->started_at->diffInSeconds(now());
        }

        $meeting->update([
            'ended_at' => now(),
            'status' => 'completed',
            'duration' => $durationSeconds,
        ]);

        return response()->json(['message' => 'Meeting ended']);
    }
    public function delete($id)
{
    $meeting = Meeting::where('id', $id)
        ->where('host_id', auth()->id())
        ->first();

    if (!$meeting) {
        return response()->json([
            'message' => 'Meeting not found or unauthorized'
        ], 404);
    }

    $meeting->delete();

    return response()->json([
        'message' => 'Meeting deleted successfully'
    ]);
}
public function show($id)
{
    $meeting = Meeting::with([
        'participants.stats',
        'participants.mappings'
    ])
    ->where('id', $id)
    ->where('host_id', auth()->id())
    ->first();

    if (!$meeting) {
        return response()->json([
            'message' => 'Meeting not found or unauthorized'
        ], 404);
    }

    return response()->json([
        'meeting' => $meeting,
        'participants' => $meeting->participants,
        'analytics' => $meeting->participants->pluck('stats')
    ]);
}
}
