<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Central gates for intro enrollment workarounds (device captions, manual bind).
 */
class IntroEnrollmentPolicy
{
    public static function assistantUtteranceAllowed(?Request $request = null): bool
    {
        if (app()->environment('local')) {
            return true;
        }

        if (filter_var(env('MEETING_INTRO_ALLOW_ASSISTANT_UTTERANCE', false), FILTER_VALIDATE_BOOL)) {
            return true;
        }

        if ($request !== null && self::requestFromCapacitorShell($request)) {
            return filter_var(env('MEETING_INTRO_ALLOW_ASSISTANT_UTTERANCE_CAPACITOR', true), FILTER_VALIDATE_BOOL);
        }

        return false;
    }

    public static function manualBindAllowed(?Request $request = null): bool
    {
        if (app()->environment('local')) {
            return true;
        }

        if (filter_var(env('MEETING_INTRO_ALLOW_MANUAL_BIND', false), FILTER_VALIDATE_BOOL)) {
            return true;
        }

        if ($request !== null && self::requestFromCapacitorShell($request)) {
            return filter_var(env('MEETING_INTRO_ALLOW_MANUAL_BIND_CAPACITOR', true), FILTER_VALIDATE_BOOL);
        }

        return false;
    }

    public static function requestFromCapacitorShell(Request $request): bool
    {
        $tok = trim((string) config('services.wechirp.native_shell_ua_token', ''));
        if ($tok === '') {
            return false;
        }

        return str_contains((string) ($request->userAgent() ?? ''), $tok);
    }

    public static function normalizeAssistantUtterance(?Request $request, ?string $raw): ?string
    {
        if (! self::assistantUtteranceAllowed($request)) {
            return null;
        }
        $t = trim((string) $raw);
        if ($t === '') {
            return null;
        }

        return mb_substr($t, 0, 400);
    }
}
