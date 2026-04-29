<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use Illuminate\Http\Request;

class ParticipantController extends Controller
{
    // ✅ Add Participant (Intro Step)
    public function store(Request $request)
    {
        $request->validate([
            'meeting_id' => 'required|exists:meetings,id',
            'name' => 'required|string|max:255',
            'voice_embedding' => 'nullable|array'
        ]);

        $meeting = Meeting::query()
            ->whereKey((int) $request->meeting_id)
            ->where('host_id', auth()->id())
            ->first();
        if (!$meeting) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Meeting not found or unauthorized',
            ], 404);
        }

        $plan = $request->user()?->currentPlan();
        $max = $plan?->max_participants_per_meeting;
        if (is_numeric($max)) {
            $count = MeetingParticipant::query()->where('meeting_id', $meeting->id)->count();
            if ($count >= (int) $max) {
                return response()->json([
                    'status' => 'failed',
                    'message' => 'Participant limit reached for your plan',
                    'limit' => (int) $max,
                    'plan' => $plan?->code,
                ], 403);
            }
        }

        $participant = MeetingParticipant::create([
            'meeting_id' => $request->meeting_id,
            'name' => $request->name,
            'voice_embedding' => $request->voice_embedding
        ]);

        return response()->json([
            'message' => 'Participant added successfully',
            'data' => $participant
        ]);
    }

    // ✅ Get Participants of a Meeting
    public function list($meeting_id)
    {
        $participants = MeetingParticipant::where('meeting_id', $meeting_id)->get();

        return response()->json([
            'data' => $participants
        ]);
    }
}