<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Meeting;
use App\Models\MeetingAnalytic;
use App\Models\ParticipantStat;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MeetingStatsController extends Controller
{
    public function show(int $id)
    {
        $meeting = Meeting::query()
            ->whereKey($id)
            ->where('host_id', auth()->id())
            ->firstOrFail();

        $stats = ParticipantStat::query()
            ->with('participant:id,name')
            ->where('meeting_id', $meeting->id)
            ->get()
            ->map(function (ParticipantStat $s) {
                return [
                    'participant_id' => (int) $s->participant_id,
                    'name' => (string) ($s->participant?->name ?? 'Unknown'),
                    'talk_time' => (int) $s->talk_time,
                    'talk_percentage' => (float) $s->talk_percentage,
                    'times_spoken' => (int) $s->times_spoken,
                ];
            })
            ->values();

        $analytic = MeetingAnalytic::query()->where('meeting_id', $meeting->id)->first();

        return response()->json([
            'meeting_id' => (int) $meeting->id,
            'total_participants' => (int) $stats->count(),
            'participants' => $stats,
            'crosstalk_percentage' => (float) ($analytic?->crosstalk_percentage ?? 0),
            'updated_at' => $analytic?->updated_at?->toISOString(),
            'source' => 'db',
        ]);
    }

    /**
     * Server-Sent Events stream for realtime stats to frontend.
     * Frontend can connect: GET /api/meetings/{id}/stats/stream
     */
    public function stream(Request $request, int $id): StreamedResponse
    {
        // SSE is a long-lived request; disable PHP execution time limit.
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        @ignore_user_abort(true);

        // EventSource can't send Authorization header; allow token via query param for local testing.
        if (!auth()->check()) {
            $token = (string) $request->query('token', '');
            if ($token !== '') {
                $pat = PersonalAccessToken::findToken($token);
                if ($pat?->tokenable) {
                    auth()->setUser($pat->tokenable);
                }
            }
        }

        $meeting = Meeting::query()
            ->whereKey($id)
            ->where('host_id', auth()->id())
            ->firstOrFail();

        $response = new StreamedResponse(function () use ($meeting) {
            @ini_set('output_buffering', 'off');
            @ini_set('zlib.output_compression', '0');
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            flush();

            $lastFingerprint = null;
            $startedAt = time();

            // Stream for up to 10 minutes; client should reconnect if needed.
            while ((time() - $startedAt) < 600) {
                $analytic = MeetingAnalytic::query()->where('meeting_id', $meeting->id)->first();
                $stats = ParticipantStat::query()
                    ->with('participant:id,name')
                    ->where('meeting_id', $meeting->id)
                    ->get()
                    ->map(function (ParticipantStat $s) {
                        return [
                            'participant_id' => (int) $s->participant_id,
                            'name' => (string) ($s->participant?->name ?? 'Unknown'),
                            'talk_time' => (int) $s->talk_time,
                            'talk_percentage' => (float) $s->talk_percentage,
                            'times_spoken' => (int) $s->times_spoken,
                        ];
                    })
                    ->values()
                    ->all();

                $payload = [
                    'meeting_id' => (int) $meeting->id,
                    'total_participants' => (int) count($stats),
                    'participants' => $stats,
                    'crosstalk_percentage' => (float) ($analytic?->crosstalk_percentage ?? 0),
                    'updated_at' => $analytic?->updated_at?->toISOString(),
                ];

                $fingerprint = md5(json_encode($payload));
                if ($fingerprint !== $lastFingerprint) {
                    $lastFingerprint = $fingerprint;
                    echo "event: stats.updated\n";
                    echo 'data: ' . json_encode($payload) . "\n\n";
                    flush();
                } else {
                    // keepalive to prevent proxy timeouts
                    echo ": keepalive\n\n";
                    flush();
                }

                usleep(750000); // 0.75s
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache, no-transform');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }
}

