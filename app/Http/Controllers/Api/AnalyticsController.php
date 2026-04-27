<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MeetingAnalytic;
use App\Models\ParticipantStat;

class AnalyticsController extends Controller
{
    public function show($meeting_id)
    {
        $meetingId = (int) $meeting_id;

        // Always read from DB so real participant names (not raw speaker labels) are used.
        $stats = ParticipantStat::query()
            ->with('participant:id,name,voice_embedding')
            ->where('meeting_id', $meetingId)
            ->get()
            ->filter(fn ($s) => $s->participant !== null && !$this->isPlaceholder($s->participant->name))
            ->map(fn ($s) => [
                'participant_id'  => (int) $s->participant_id,
                'name'            => (string) $s->participant->name,
                'talk_time'       => (int) $s->talk_time,
                'talk_percentage' => (float) $s->talk_percentage,
                'times_spoken'    => (int) $s->times_spoken,
                'provider'        => is_array($s->participant->voice_embedding)
                    ? ($s->participant->voice_embedding['provider'] ?? null)
                    : null,
            ])
            ->sortByDesc('talk_time')
            ->values();

        $analytic = MeetingAnalytic::query()->where('meeting_id', $meetingId)->first();

        return response()->json([
            'participants'         => $stats,
            'crosstalk_percentage' => (float) ($analytic?->crosstalk_percentage ?? 0),
            'total_speakers'       => (int) ($analytic?->total_speakers ?? $stats->count()),
            'keywords'             => $analytic?->keywords ?? [],
            'summary'              => $analytic?->summary ?? '',
            'action_items'         => $analytic?->action_items ?? [],
            'sentiment'            => $analytic?->sentiment ?? null,
            'source'               => 'db',
        ]);
    }

    private function isPlaceholder(string $name): bool
    {
        return (bool) preg_match('/^(Speaker\s+\d+|speaker_\d+|chunk\d+_\S+)$/i', $name);
    }
}
