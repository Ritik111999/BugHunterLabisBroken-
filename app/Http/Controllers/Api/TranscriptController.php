<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Meeting;
use App\Models\MeetingTranscriptSegment;
use App\Models\Transcript;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TranscriptController extends Controller
{
    public function list(int $id)
    {
        $meeting = Meeting::query()
            ->whereKey($id)
            ->where('host_id', auth()->id())
            ->firstOrFail();

        $segments = $this->fetchSegments($meeting->id);
        if ($segments !== []) {
            return response()->json([
                'meeting_id' => (int) $meeting->id,
                'source' => 'segments',
                'items' => $segments,
                'lines' => $this->segmentsToLiveLines($segments),
            ]);
        }

        $items = $this->fetchLegacyItems($meeting->id);

        return response()->json([
            'meeting_id' => (int) $meeting->id,
            'source' => 'transcripts',
            'items' => $items,
            'lines' => $this->itemsToLiveLines($items),
        ]);
    }

    /**
     * SSE stream for live transcript lines (mobile + web).
     * GET /api/meetings/{id}/transcripts/stream?token=
     */
    public function stream(Request $request, int $id): StreamedResponse
    {
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        @ignore_user_abort(true);

        if (! auth()->check()) {
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

        return new StreamedResponse(function () use ($meeting) {
            @ini_set('output_buffering', 'off');
            @ini_set('zlib.output_compression', '0');
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            flush();

            $lastFingerprint = null;
            $startedAt = time();

            while ((time() - $startedAt) < 600) {
                $segments = $this->fetchSegments($meeting->id, 40);
                if ($segments !== []) {
                    $lines = $this->segmentsToLiveLines($segments);
                } else {
                    $items = $this->fetchLegacyItems($meeting->id, 30);
                    $lines = $this->itemsToLiveLines($items);
                }
                $payload = [
                    'meeting_id' => (int) $meeting->id,
                    'lines' => $lines,
                ];
                $fingerprint = sha1(json_encode($payload));
                if ($fingerprint !== $lastFingerprint) {
                    $lastFingerprint = $fingerprint;
                    echo "event: transcript.updated\n";
                    echo 'data: '.json_encode($payload)."\n\n";
                    flush();
                } else {
                    echo ": keepalive\n\n";
                    flush();
                }
                usleep(500_000);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * @return array<int, array{id: int, speaker_name: string, text: string, start_ms: int, end_ms: int, created_at: ?string}>
     */
    private function fetchSegments(int $meetingId, int $limit = 50): array
    {
        if (! Schema::hasTable('meeting_transcript_segments')) {
            return [];
        }

        return MeetingTranscriptSegment::query()
            ->where('meeting_id', $meetingId)
            ->latest('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (MeetingTranscriptSegment $s) => [
                'id' => (int) $s->id,
                'speaker_name' => (string) $s->speaker_name,
                'text' => (string) $s->text,
                'start_ms' => (int) $s->start_ms,
                'end_ms' => (int) $s->end_ms,
                'created_at' => $s->created_at?->toISOString(),
            ])
            ->all();
    }

    /**
     * @return array<int, array{id: int, text: string, start_time: float, end_time: float, created_at: ?string}>
     */
    private function fetchLegacyItems(int $meetingId, int $limit = 50): array
    {
        return Transcript::query()
            ->where('meeting_id', $meetingId)
            ->latest('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (Transcript $t) => [
                'id' => (int) $t->id,
                'text' => (string) $t->text,
                'start_time' => (float) $t->start_time,
                'end_time' => (float) $t->end_time,
                'created_at' => $t->created_at?->toISOString(),
            ])
            ->all();
    }

    /**
     * @param  array<int, array{id: int, speaker_name: string, text: string}>  $segments
     * @return array<int, array{key: string, name: string, text: string}>
     */
    private function segmentsToLiveLines(array $segments): array
    {
        $lines = [];
        foreach ($segments as $i => $seg) {
            $text = trim((string) ($seg['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $name = trim((string) ($seg['speaker_name'] ?? 'Speaker')) ?: 'Speaker';
            $lines[] = [
                'key' => 'seg-'.($seg['id'] ?? $i),
                'name' => $name,
                'text' => $text,
            ];
        }

        return $lines;
    }

    /**
     * @param  array<int, array{text: string}>  $items
     * @return array<int, array{key: string, name: string, text: string}>
     */
    private function itemsToLiveLines(array $items): array
    {
        $lines = [];
        foreach ($items as $i => $item) {
            $raw = trim((string) ($item['text'] ?? ''));
            if ($raw === '') {
                continue;
            }
            if (preg_match('/^\[([^\]]+)\]\s*(.*)$/s', $raw, $m)) {
                $name = trim($m[1]);
                $text = trim($m[2]);
            } else {
                $name = 'Speaker';
                $text = $raw;
            }
            if ($text === '') {
                continue;
            }
            $lines[] = [
                'key' => 'line-'.$i,
                'name' => $name !== '' ? $name : 'Speaker',
                'text' => $text,
            ];
        }

        return $lines;
    }
}
