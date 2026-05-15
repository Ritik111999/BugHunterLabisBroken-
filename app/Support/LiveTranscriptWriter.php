<?php

namespace App\Support;

use App\Models\Meeting;
use App\Models\MeetingTranscriptSegment;
use App\Models\Transcript;
use Illuminate\Support\Facades\Cache;

/**
 * Persist live STT lines to transcripts + per-sentence segments (relay + HTTP chunks).
 */
class LiveTranscriptWriter
{
    /**
     * @param  array<int, array{label?: string, name?: string, text?: string}>  $lines
     */
    public static function persistRelayLines(int $meetingId, array $lines, float $audioSeconds = 0.0): void
    {
        if (! Meeting::query()->whereKey($meetingId)->exists()) {
            return;
        }

        $text = self::formatLines($lines);
        if ($text === '') {
            return;
        }

        $cacheKey = "live_transcript_fp:{$meetingId}";
        $fingerprint = sha1($text);
        if (Cache::get($cacheKey) === $fingerprint) {
            return;
        }
        Cache::put($cacheKey, $fingerprint, now()->addHours(6));

        $end = max(0.0, $audioSeconds);
        Transcript::create([
            'meeting_id' => $meetingId,
            'text' => $text,
            'start_time' => max(0.0, $end - 3.0),
            'end_time' => $end,
        ]);

        self::persistSegmentsFromLines($meetingId, $lines, $end);
    }

    /**
     * @param  array<string, string>  $speakerText  label => text
     */
    public static function persistChunkSpeakerText(int $meetingId, array $speakerText, float $chunkEndSeconds): void
    {
        $lines = [];
        foreach ($speakerText as $label => $t) {
            $text = trim((string) $t);
            if ($text === '') {
                continue;
            }
            $lines[] = [
                'label' => (string) $label,
                'name' => (string) $label,
                'text' => $text,
            ];
        }
        if ($lines === []) {
            return;
        }

        $formatted = self::formatLines($lines);
        if ($formatted === '') {
            return;
        }

        $end = max(0.0, $chunkEndSeconds);
        Transcript::create([
            'meeting_id' => $meetingId,
            'text' => $formatted,
            'start_time' => max(0.0, $end - max(1.0, $end * 0.15)),
            'end_time' => $end,
        ]);

        self::persistSegmentsFromLines($meetingId, $lines, $end);
    }

    /**
     * @param  array<int, array{label?: string, name?: string, text?: string}>  $lines
     */
    public static function persistSegmentsFromLines(int $meetingId, array $lines, float $audioSecondsEnd): void
    {
        $endMs = (int) round(max(0.0, $audioSecondsEnd) * 1000);
        foreach ($lines as $line) {
            $text = trim((string) ($line['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $name = trim((string) ($line['name'] ?? $line['label'] ?? 'Speaker'));
            if ($name === '') {
                $name = 'Speaker';
            }
            $label = trim((string) ($line['label'] ?? ''));
            $startMs = max(0, $endMs - 3000);

            $fp = sha1($meetingId.'|'.$label.'|'.$name.'|'.$text.'|'.$endMs);
            $cacheKey = "live_segment_fp:{$meetingId}:{$fp}";
            if (Cache::get($cacheKey)) {
                continue;
            }
            Cache::put($cacheKey, 1, now()->addHours(6));

            MeetingTranscriptSegment::create([
                'meeting_id' => $meetingId,
                'speaker_name' => $name,
                'speaker_label' => $label !== '' ? $label : null,
                'text' => $text,
                'start_ms' => $startMs,
                'end_ms' => $endMs,
                'is_final' => true,
            ]);
        }
    }

    /**
     * @param  array<int, array{label?: string, name?: string, text?: string}>  $lines
     */
    public static function formatLines(array $lines): string
    {
        $parts = [];
        foreach ($lines as $line) {
            $t = trim((string) ($line['text'] ?? ''));
            if ($t === '') {
                continue;
            }
            $name = trim((string) ($line['name'] ?? $line['label'] ?? 'Speaker'));
            if ($name === '') {
                $name = 'Speaker';
            }
            $parts[] = "[{$name}] {$t}";
        }

        return trim(implode("\n", $parts));
    }

    public static function clearMeetingCache(int $meetingId): void
    {
        Cache::forget("live_transcript_fp:{$meetingId}");
    }
}
