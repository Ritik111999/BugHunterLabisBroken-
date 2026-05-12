<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
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

        // Count only real enrollments (exclude diarization placeholder rows like "Speaker 1").
        $meetings = $q
            ->with(['participants:id,meeting_id,name'])
            ->latest()
            ->get()
            ->map(function (Meeting $meeting) {
                $meeting->participants_count = (int) $meeting->participants
                    ->filter(fn ($p) => ! $this->isPlaceholderParticipantName((string) ($p->name ?? '')))
                    ->count();
                $meeting->unsetRelation('participants');

                return $meeting;
            });

        return response()->json($meetings);
    }

    private function isPlaceholderParticipantName(string $name): bool
    {
        return (bool) preg_match('/^(Speaker\s+\d+|speaker_\d+|speaker_unknown|chunk\d+_\S+)$/i', trim($name));
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
        $meeting = Meeting::query()
            ->whereKey((int) $id)
            ->where('host_id', auth()->id())
            ->firstOrFail();

        // Hard safety: if 2+ real participants exist, ensure voiceprints are valid and not near-identical.
        // Otherwise the system will silently credit everything to one person.
        $participants = MeetingParticipant::query()
            ->where('meeting_id', $meeting->id)
            ->get(['id', 'name', 'voice_embedding'])
            ->filter(fn ($p) => ! $this->isPlaceholderParticipantName((string) ($p->name ?? '')))
            ->values();

        if ($participants->count() >= 2) {
            $vectors = [];
            $missing = [];
            foreach ($participants as $p) {
                $ve = is_array($p->voice_embedding) ? $p->voice_embedding : [];
                $vp = $ve['voiceprint'] ?? null;
                if (!is_array($vp) || count($vp) < 32) {
                    $missing[] = ['participant_id' => (int) $p->id, 'name' => (string) ($p->name ?? '')];
                    continue;
                }
                $vec = [];
                foreach ($vp as $v) {
                    if (is_numeric($v)) {
                        $vec[] = (float) $v;
                    }
                }
                if (count($vec) < 32) {
                    $missing[] = ['participant_id' => (int) $p->id, 'name' => (string) ($p->name ?? '')];
                    continue;
                }
                $vectors[] = ['id' => (int) $p->id, 'name' => (string) ($p->name ?? ''), 'vec' => $vec];
            }

            if (count($missing) > 0) {
                return response()->json([
                    'message' => 'Voiceprints missing for enrolled participants. Re-enroll before starting meeting.',
                    'code' => 'voiceprints_missing',
                    'missing' => $missing,
                ], 422);
            }

            $dupThr = (float) env('MEETING_START_DUPLICATE_VOICEPRINT_SIM', 0.985);
            $dupThr = max(0.85, min(0.999, $dupThr));
            for ($i = 0; $i < count($vectors); $i++) {
                for ($j = $i + 1; $j < count($vectors); $j++) {
                    $sim = $this->cosineSimilarity($vectors[$i]['vec'], $vectors[$j]['vec']);
                    if ($sim >= $dupThr) {
                        return response()->json([
                            'message' => 'Two participants have near-identical voiceprints. Re-enroll to avoid wrong attribution.',
                            'code' => 'voiceprints_too_similar',
                            'similarity' => $sim,
                            'threshold' => $dupThr,
                            'a' => ['participant_id' => $vectors[$i]['id'], 'name' => $vectors[$i]['name']],
                            'b' => ['participant_id' => $vectors[$j]['id'], 'name' => $vectors[$j]['name']],
                        ], 422);
                    }
                }
            }
        }

        $meeting->update([
            'started_at' => now(),
            'status' => 'processing'
        ]);

        return response()->json(['message' => 'Meeting started']);
    }

    /** @param array<int,float> $a @param array<int,float> $b */
    private function cosineSimilarity(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        if ($n <= 0) {
            return 0.0;
        }
        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $x = (float) $a[$i];
            $y = (float) $b[$i];
            $dot += $x * $y;
            $na += $x * $x;
            $nb += $y * $y;
        }
        $den = sqrt(max(1e-12, $na)) * sqrt(max(1e-12, $nb));
        return $den > 0 ? ($dot / $den) : 0.0;
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
