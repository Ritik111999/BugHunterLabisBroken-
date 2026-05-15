<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Hot live meeting stats + transcript lines for HTTP/SSE readers during active relay sessions.
 */
class MeetingRelayLiveCache
{
    public static function putSnapshot(int $meetingId, array $snapshot): void
    {
        if (! self::enabled()) {
            return;
        }

        $ttl = self::ttl();
        Cache::put(self::snapshotKey($meetingId), $snapshot, $ttl);
    }

    public static function getSnapshot(int $meetingId): ?array
    {
        if (! self::enabled()) {
            return null;
        }

        $payload = Cache::get(self::snapshotKey($meetingId));

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    public static function putTranscriptLines(int $meetingId, array $lines): void
    {
        if (! self::enabled()) {
            return;
        }

        Cache::put(self::transcriptKey($meetingId), [
            'lines' => $lines,
            'updated_at' => now()->toISOString(),
        ], self::ttl());
    }

    /**
     * @return array{lines: array<int, array<string, mixed>>, updated_at: ?string}|null
     */
    public static function getTranscriptLines(int $meetingId): ?array
    {
        if (! self::enabled()) {
            return null;
        }

        $payload = Cache::get(self::transcriptKey($meetingId));

        return is_array($payload) ? $payload : null;
    }

    public static function forget(int $meetingId): void
    {
        Cache::forget(self::snapshotKey($meetingId));
        Cache::forget(self::transcriptKey($meetingId));
    }

    public static function enabled(): bool
    {
        return filter_var(config('meeting_voice.relay.redis_hot_state', true), FILTER_VALIDATE_BOOL);
    }

    private static function ttl(): int
    {
        return max(60, (int) config('meeting_voice.relay.redis_snapshot_ttl_seconds', 7200));
    }

    private static function snapshotKey(int $meetingId): string
    {
        return 'meeting_relay_live:'.$meetingId;
    }

    private static function transcriptKey(int $meetingId): string
    {
        return 'meeting_relay_transcript:'.$meetingId;
    }
}
