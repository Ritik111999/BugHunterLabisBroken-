<?php

namespace App\Support;

/**
 * Normalizes optional client hints for FFmpeg / embed tuning (matches intro chunk uploads).
 */
final class MeetingAudioInputProfile
{
    public static function normalize(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }
        $p = strtolower(trim($raw));
        if ($p === '' || $p === 'auto') {
            return null;
        }
        if (in_array($p, ['bt', 'wireless', 'headset', 'bluetooth'], true)) {
            return 'bluetooth';
        }
        if (in_array($p, ['line', 'usb', 'studio', 'wired'], true)) {
            return 'wired';
        }
        if ($p === 'default') {
            return 'default';
        }

        return null;
    }
}
