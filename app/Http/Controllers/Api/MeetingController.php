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

    public function end($id)
    {
        $meeting = Meeting::findOrFail($id);
        $meeting->update([
            'ended_at' => now(),
            'status' => 'completed'
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
