<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\SpeakerMapping;
use Illuminate\Http\Request;

class SpeakerMappingController extends Controller
{
    /**
     * Map Deepgram diarization label (speaker_0, speaker_1...) to a participant.
     *
     * POST /api/meetings/{id}/speakers/map
     * Body:
     * - speaker_label: string (required)
     * - participant_id: int (optional)
     * - name: string (optional; creates/renames participant)
     */
    public function map(Request $request, int $id)
    {
        $meeting = Meeting::query()
            ->whereKey($id)
            ->where('host_id', auth()->id())
            ->firstOrFail();

        $data = $request->validate([
            'speaker_label' => ['required', 'string', 'max:64'],
            'participant_id' => ['nullable', 'integer', 'min:1'],
            'name' => ['nullable', 'string', 'max:255'],
            'confidence' => ['nullable', 'numeric', 'min:0', 'max:1'],
        ]);

        $speakerLabel = (string) $data['speaker_label'];

        $participantId = $data['participant_id'] ?? null;
        $name = $data['name'] ?? null;

        $participant = null;
        if ($participantId) {
            $participant = MeetingParticipant::query()
                ->where('meeting_id', $meeting->id)
                ->whereKey($participantId)
                ->firstOrFail();
        } else {
            // If no participant_id, create (or find) by name.
            $displayName = $name ?: $speakerLabel;
            $participant = MeetingParticipant::query()
                ->where('meeting_id', $meeting->id)
                ->where('name', $displayName)
                ->first();

            if (!$participant) {
                $participant = MeetingParticipant::create([
                    'meeting_id' => $meeting->id,
                    'user_id' => null,
                    'name' => $displayName,
                    'voice_embedding' => null,
                ]);
            }
        }

        if ($name && $participant->name !== $name) {
            $participant->update(['name' => $name]);
        }

        $mapping = SpeakerMapping::updateOrCreate(
            [
                'meeting_id' => $meeting->id,
                'speaker_label' => $speakerLabel,
            ],
            [
                'participant_id' => $participant->id,
                'confidence' => $data['confidence'] ?? null,
            ]
        );

        return response()->json([
            'status' => true,
            'data' => [
                'mapping' => $mapping,
                'participant' => $participant,
            ],
        ]);
    }

    public function list(int $id)
    {
        $meeting = Meeting::query()
            ->whereKey($id)
            ->where('host_id', auth()->id())
            ->firstOrFail();

        $mappings = SpeakerMapping::query()
            ->with('participant:id,name')
            ->where('meeting_id', $meeting->id)
            ->get();

        return response()->json([
            'status' => true,
            'data' => $mappings,
        ]);
    }
}

