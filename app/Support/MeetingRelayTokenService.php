<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Short-lived tokens for the live audio WebSocket relay (avoids long-lived Sanctum PAT in query strings).
 */
class MeetingRelayTokenService
{
    public static function issue(int $meetingId, int $userId): array
    {
        $ttl = max(60, (int) config('meeting_voice.relay.token_ttl_seconds', 900));
        $token = Str::random(64);
        Cache::put(self::cacheKey($token), [
            'meeting_id' => $meetingId,
            'user_id' => $userId,
        ], $ttl);

        $indexKey = self::meetingIndexKey($meetingId);
        $hashes = Cache::get($indexKey, []);
        if (! is_array($hashes)) {
            $hashes = [];
        }
        $hashes[] = hash('sha256', $token);
        $hashes = array_values(array_unique(array_slice($hashes, -30)));
        Cache::put($indexKey, $hashes, $ttl);

        return [
            'token' => $token,
            'expires_in' => $ttl,
        ];
    }

    /**
     * @return array{meeting_id:int,user_id:int}|null
     */
    public static function resolve(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $payload = Cache::get(self::cacheKey($token));
        if (! is_array($payload)) {
            return null;
        }

        $meetingId = (int) ($payload['meeting_id'] ?? 0);
        $userId = (int) ($payload['user_id'] ?? 0);
        if ($meetingId <= 0 || $userId <= 0) {
            return null;
        }

        return [
            'meeting_id' => $meetingId,
            'user_id' => $userId,
        ];
    }

    public static function revoke(string $token): void
    {
        $token = trim($token);
        if ($token !== '') {
            Cache::forget(self::cacheKey($token));
        }
    }

    public static function revokeForMeeting(int $meetingId): void
    {
        $indexKey = self::meetingIndexKey($meetingId);
        $hashes = Cache::get($indexKey, []);
        if (is_array($hashes)) {
            foreach ($hashes as $hash) {
                if (is_string($hash) && $hash !== '') {
                    Cache::forget('meeting_relay_token:'.$hash);
                }
            }
        }
        Cache::forget($indexKey);
    }

    private static function meetingIndexKey(int $meetingId): string
    {
        return 'meeting_relay_token_index:'.$meetingId;
    }

    private static function cacheKey(string $token): string
    {
        return 'meeting_relay_token:'.hash('sha256', $token);
    }
}

