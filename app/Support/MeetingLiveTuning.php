<?php

namespace App\Support;

/**
 * Typed accessors for config/meeting_voice.php live + voiceprint tuning.
 */
final class MeetingLiveTuning
{
    public static function bool(string $key, bool $default = false): bool
    {
        $v = config('meeting_voice.'.$key, $default);

        return filter_var($v, FILTER_VALIDATE_BOOL);
    }

    public static function int(string $key, int $default = 0): int
    {
        return (int) config('meeting_voice.'.$key, $default);
    }

    public static function float(string $key, float $default = 0.0): float
    {
        return (float) config('meeting_voice.'.$key, $default);
    }

    public static function string(string $key, string $default = ''): string
    {
        return trim((string) config('meeting_voice.'.$key, $default));
    }
}
