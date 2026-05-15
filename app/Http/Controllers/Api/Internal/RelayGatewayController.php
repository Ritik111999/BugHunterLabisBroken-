<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessLiveRelayVoiceChunkJob;
use App\Models\Meeting;
use App\Models\User;
use App\Services\LiveRelayPersistenceService;
use App\Services\VoiceprintService;
use App\Support\MeetingRelayTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;

class RelayGatewayController extends Controller
{
    public function validateAuth(Request $request): JsonResponse
    {
        $data = $request->validate([
            'meeting_id' => ['required', 'integer', 'min:1'],
            'token' => ['required', 'string', 'max:500'],
        ]);

        $meetingId = (int) $data['meeting_id'];
        $token = trim((string) $data['token']);
        $user = $this->resolveUser($token, $meetingId);

        if (! $user) {
            return response()->json(['ok' => false, 'error' => 'unauthenticated'], 401);
        }

        $meeting = Meeting::query()
            ->whereKey($meetingId)
            ->where('host_id', $user->id)
            ->first();

        if (! $meeting) {
            return response()->json(['ok' => false, 'error' => 'meeting_not_found'], 404);
        }

        return response()->json([
            'ok' => true,
            'meeting_id' => (int) $meeting->id,
            'user_id' => (int) $user->id,
            'status' => (string) ($meeting->status ?? ''),
        ]);
    }

    public function persist(Request $request, LiveRelayPersistenceService $persistence): JsonResponse
    {
        $data = $request->validate([
            'meeting_id' => ['required', 'integer', 'min:1'],
            'speaker_seconds' => ['nullable', 'array'],
            'overlap_seconds' => ['nullable', 'numeric', 'min:0'],
            'audio_cursor_seconds' => ['nullable', 'numeric', 'min:0'],
            'lines' => ['nullable', 'array'],
            'voice_matching' => ['nullable', 'array'],
        ]);

        $meetingId = (int) $data['meeting_id'];
        $speakerSeconds = is_array($data['speaker_seconds'] ?? null) ? $data['speaker_seconds'] : [];
        $lines = is_array($data['lines'] ?? null) ? $data['lines'] : [];

        $voiceMatching = is_array($data['voice_matching'] ?? null) ? $data['voice_matching'] : [];
        $cached = Cache::get("live_relay_voice_match:{$meetingId}", []);
        if (is_array($cached)) {
            foreach ($cached as $label => $match) {
                if (is_string($label) && is_array($match)) {
                    $voiceMatching[$label] = array_merge($voiceMatching[$label] ?? [], $match);
                }
            }
        }

        $persistence->persist(
            $meetingId,
            $speakerSeconds,
            (float) ($data['overlap_seconds'] ?? 0),
            (float) ($data['audio_cursor_seconds'] ?? 0),
            $lines,
            $voiceMatching,
        );

        return response()->json(['ok' => true]);
    }

    public function ingestVoiceChunk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'meeting_id' => ['required', 'integer', 'min:1'],
            'speaker_label' => ['required', 'string', 'max:64'],
            'pcm_base64' => ['required', 'string'],
            'sample_rate' => ['nullable', 'integer', 'min:8000', 'max:48000'],
            'start_seconds' => ['nullable', 'numeric', 'min:0'],
        ]);

        $meetingId = (int) $data['meeting_id'];
        if (! Meeting::query()->whereKey($meetingId)->exists()) {
            return response()->json(['ok' => false], 404);
        }

        $pcm = base64_decode((string) $data['pcm_base64'], true);
        if ($pcm === false || strlen($pcm) < 640) {
            return response()->json(['ok' => false, 'error' => 'invalid_pcm'], 422);
        }

        $sampleRate = (int) ($data['sample_rate'] ?? 16000);
        $relative = sprintf(
            'meeting_live_node/%d/%s_%s.pcm',
            $meetingId,
            preg_replace('/[^a-z0-9_]+/i', '_', (string) $data['speaker_label']),
            uniqid('', true),
        );
        Storage::disk('local')->put($relative, $pcm);

        $wavPath = $this->pcmToWav($relative, $sampleRate);
        if ($wavPath === null) {
            Storage::disk('local')->delete($relative);

            return response()->json(['ok' => false, 'error' => 'wav_convert_failed'], 500);
        }
        Storage::disk('local')->delete($relative);

        ProcessLiveRelayVoiceChunkJob::dispatch(
            $meetingId,
            (string) $data['speaker_label'],
            $wavPath,
            (float) ($data['start_seconds'] ?? 0),
        );

        return response()->json(['ok' => true, 'queued' => true]);
    }

    private function resolveUser(string $token, int $expectedMeetingId): ?User
    {
        $relay = MeetingRelayTokenService::resolve($token);
        if ($relay !== null && (int) $relay['meeting_id'] === $expectedMeetingId) {
            return User::query()->whereKey((int) $relay['user_id'])->first();
        }

        $pat = PersonalAccessToken::findToken($token);

        return $pat?->tokenable instanceof User ? $pat->tokenable : null;
    }

    private function pcmToWav(string $pcmRelativePath, int $sampleRate): ?string
    {
        $disk = Storage::disk('local');
        $pcmAbs = $disk->path($pcmRelativePath);
        $wavRelative = preg_replace('/\.pcm$/', '.wav', $pcmRelativePath) ?? ($pcmRelativePath.'.wav');
        $wavAbs = $disk->path($wavRelative);

        $cmd = sprintf(
            'ffmpeg -hide_banner -loglevel error -f s16le -ar %d -ac 1 -i %s -y %s',
            $sampleRate,
            escapeshellarg($pcmAbs),
            escapeshellarg($wavAbs),
        );
        $code = 0;
        @exec($cmd, $out, $code);
        if ($code !== 0 || ! is_file($wavAbs)) {
            return null;
        }

        return $wavRelative;
    }
}
