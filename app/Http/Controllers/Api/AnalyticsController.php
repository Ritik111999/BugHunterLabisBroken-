<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Meeting;
use App\Models\MeetingAnalytic;
use App\Models\MeetingParticipant;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    public function show(Request $request, $meeting_id)
    {
        $meetingId = (int) $meeting_id;

        $period = $this->normalizePeriod((string) $request->query('period', 'all_time'));
        $periodStart = $this->getPeriodStart($period);

        // Always read from DB so real participant names (not raw speaker labels) are used.
        $statsQuery = DB::table('participant_stats')
            ->where('meeting_id', $meetingId);

        if ($periodStart !== null) {
            $statsQuery->where('created_at', '>=', $periodStart);
        }

        $statsRows = $statsQuery
            ->select([
                'participant_id',
                DB::raw('SUM(talk_time) as talk_time'),
                DB::raw('SUM(times_spoken) as times_spoken'),
            ])
            ->groupBy('participant_id')
            ->get()
            ->keyBy('participant_id');

        $meeting = Meeting::query()->find($meetingId);
        $meetingDurationSec = max(0, (int) ($meeting?->duration ?? 0));
        $totalTalkTime = (int) $statsRows->sum(fn ($r) => (int) ($r->talk_time ?? 0));
        $useMeetingDurationPct = $periodStart === null && $meetingDurationSec > 0;

        $enrolled = MeetingParticipant::query()
            ->where('meeting_id', $meetingId)
            ->get(['id', 'name', 'voice_embedding'])
            ->filter(fn ($p) => ! $this->isPlaceholder((string) $p->name))
            ->values();

        $stats = $enrolled
            ->map(function (MeetingParticipant $participant) use ($statsRows, $totalTalkTime, $useMeetingDurationPct, $meetingDurationSec) {
                $row = $statsRows->get((int) $participant->id);
                $talkTime = (int) ($row->talk_time ?? 0);
                $timesSpoken = (int) ($row->times_spoken ?? 0);

                if ($useMeetingDurationPct) {
                    $talkPercentage = min(100.0, max(0.0, ($talkTime / $meetingDurationSec) * 100));
                } else {
                    $talkPercentage = $totalTalkTime > 0 ? ($talkTime / $totalTalkTime) * 100 : 0.0;
                }

                $voiceEmbedding = $participant->voice_embedding;
                $provider = is_array($voiceEmbedding) ? ($voiceEmbedding['provider'] ?? null) : null;

                return [
                    'participant_id'    => (int) $participant->id,
                    'name'              => (string) $participant->name,
                    'talk_time'         => $talkTime,
                    'talk_percentage'   => (float) round($talkPercentage, 2),
                    'times_spoken'      => $timesSpoken,
                    'provider'          => $provider,
                ];
            })
            ->values()
            ->all();

        usort($stats, function (array $a, array $b): int {
            $c = ($b['talk_time'] ?? 0) <=> ($a['talk_time'] ?? 0);

            return $c !== 0 ? $c : strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        $analyticQuery = MeetingAnalytic::query()->where('meeting_id', $meetingId);
        if ($periodStart !== null) {
            $analyticQuery->where('created_at', '>=', $periodStart);
        }
        $analytic = $analyticQuery->orderByDesc('created_at')->first();

        return response()->json([
            'participants'         => $stats,
            'crosstalk_percentage' => (float) ($analytic?->crosstalk_percentage ?? 0),
            'total_speakers'       => (int) ($analytic?->total_speakers ?? count($stats)),
            'keywords'             => $analytic?->keywords ?? [],
            'summary'              => $analytic?->summary ?? '',
            'action_items'         => $analytic?->action_items ?? [],
            'sentiment'            => $analytic?->sentiment ?? null,
            'source'               => 'db',
        ]);
    }

    private function normalizePeriod(string $period): string
    {
        $p = strtolower(trim($period));
        $p = str_replace('-', '_', $p);

        return match ($p) {
            'week', 'weekly', '7d', 'last_7_days' => 'weekly',
            'month', 'monthly', '30d', 'last_30_days' => 'monthly',
            'all', 'alltime', 'all_time' => 'all_time',
            default => 'all_time',
        };
    }

    private function getPeriodStart(string $period): ?Carbon
    {
        return match ($period) {
            'weekly' => now()->subDays(7),
            'monthly' => now()->subDays(30),
            default => null,
        };
    }

    private function isPlaceholder(string $name): bool
    {
        return (bool) preg_match('/^(Speaker\s+\d+|speaker_\d+|speaker_unknown|chunk\d+_\S+)$/i', trim($name));
    }
}
