<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessChunkJob;
use App\Models\MeetingParticipant;
use App\Support\IntroEnrollmentPolicy;
use App\Support\MeetingAudioInputProfile;
use App\Support\MeetingAudioStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

class IntroChunkController extends Controller
{
    /**
     * POST /api/meetings/{id}/intro/chunk
     *
     * Intro enrollment chunk: creates participants ONLY when a real name is detected.
     */
    public function store(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'chunk_index' => ['required', 'integer', 'min:0'],
            'audio' => ['required', 'file', 'max:51200'],
            'duration_seconds' => ['nullable', 'numeric', 'min:1', 'max:60'],
            'audio_input_profile' => ['nullable', 'string', 'max:32'],
            'assistant_utterance' => ['nullable', 'string', 'max:400'],
            'bind_participant_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $chunkIndex = (int) $validated['chunk_index'];
        $durationSeconds = isset($validated['duration_seconds']) ? (float) $validated['duration_seconds'] : null;
        $audioInputProfile = MeetingAudioInputProfile::normalize($validated['audio_input_profile'] ?? null);
        $assistantRaw = trim((string) ($validated['assistant_utterance'] ?? ''));
        $assistantForJob = IntroEnrollmentPolicy::normalizeAssistantUtterance($request, $assistantRaw !== '' ? $assistantRaw : null);
        $bindForJob = $this->resolvedBindParticipantId($request, $id, $validated['bind_participant_id'] ?? null);
        // Default async: intro analysis needs Deepgram + ffmpeg + optional SpeechBrain.
        // Use ?sync=1 from the demo (or tooling) to run inline without a queue worker.
        $sync = filter_var($request->query('sync', false), FILTER_VALIDATE_BOOL);

        $filePath = $this->storeChunkFile($request, $id, $chunkIndex);
        Log::info('intro_chunk_received', [
            'meeting_id' => $id,
            'chunk_index' => $chunkIndex,
            'sync' => (bool) $sync,
            'duration_seconds' => $durationSeconds,
            'audio_input_profile' => $audioInputProfile,
            'file_path' => $filePath,
            'size_bytes' => (int) ($request->file('audio')?->getSize() ?? 0),
            'ext' => (string) ($request->file('audio')?->getClientOriginalExtension() ?? ''),
            'bind_participant_id' => $bindForJob,
        ]);

        if ($sync) {
            $startedAt = now();
            try {
                $job = new ProcessChunkJob(
                    meetingId: $id,
                    filePath: $filePath,
                    chunkIndex: $chunkIndex,
                    mode: 'intro',
                    durationSeconds: $durationSeconds,
                    audioInputProfile: $audioInputProfile,
                );
                if ($assistantForJob !== null) {
                    $job->assistantUtterance = $assistantForJob;
                }
                if ($bindForJob !== null) {
                    $job->bindParticipantId = $bindForJob;
                }
                Bus::dispatchSync($job);
            } catch (\Throwable $e) {
                Log::warning('intro_chunk_failed', [
                    'meeting_id' => $id,
                    'chunk_index' => $chunkIndex,
                    'sync' => (bool) $sync,
                    'message' => $e->getMessage(),
                ]);

                return response()->json([
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                    'meeting_id' => $id,
                    'chunk_index' => $chunkIndex,
                    'mode' => 'intro',
                ], 422);
            }

            // Return newly enrolled participants so the client UI can update instantly.
            // We consider "enrolled" any participant updated/created by intro enrollment.
            $enrolled = MeetingParticipant::query()
                ->where('meeting_id', $id)
                ->where(function ($q) use ($startedAt) {
                    $q->where('created_at', '>=', $startedAt->copy()->subSeconds(2))
                        ->orWhere('updated_at', '>=', $startedAt->copy()->subSeconds(2));
                })
                ->get(['id', 'name', 'voice_embedding'])
                ->values()
                ->map(fn ($p) => [
                    'id' => (int) $p->id,
                    'name' => (string) $p->name,
                    'voice_embedding' => $p->voice_embedding,
                    'voice_ready' => self::participantHasVoiceprintVector($p->voice_embedding),
                ]);

            $voiceReadyCount = $enrolled->filter(fn ($row) => (bool) ($row['voice_ready'] ?? false))->count();

            $heard = $this->buildHeardForIntroChunk($id, $chunkIndex, $assistantForJob);

            $introSttHint = null;
            if ($heard === []) {
                $maxDb = MeetingAudioStorage::withLocalPath($filePath, function (string $abs): ?float {
                    return is_file($abs) ? $this->ffmpegMaxVolumeDb($abs) : null;
                });
                if ($maxDb !== null && $maxDb > -28.0) {
                    $introSttHint = 'stt_no_words_normal_level';
                } elseif ($maxDb !== null && $maxDb <= -45.0) {
                    $introSttHint = 'stt_no_words_quiet';
                }
                Log::info('intro_chunk_no_transcript_words', [
                    'meeting_id' => $id,
                    'chunk_index' => $chunkIndex,
                    'intro_stt_hint' => $introSttHint,
                    'bytes' => null,
                    'assistant_sent' => $assistantForJob !== null,
                ]);
            }

            $assistantRejected = $assistantRaw !== '' && $assistantForJob === null;

            $payload = [
                'status' => 'completed',
                'meeting_id' => $id,
                'chunk_index' => $chunkIndex,
                'mode' => 'intro',
                'enrolled_participants' => $enrolled,
                'intro_voiceprint_ready' => $enrolled->isNotEmpty() && $voiceReadyCount >= $enrolled->count(),
                'heard' => $heard,
                'intro_stt_hint' => $introSttHint,
                'intro_assistant_applied' => $assistantForJob !== null,
                'intro_assistant_rejected' => $assistantRejected,
                'intro_manual_bind_applied' => $bindForJob !== null,
            ];

            if ($enrolled->isNotEmpty() && $voiceReadyCount < $enrolled->count()) {
                $payload['intro_voiceprint_note'] =
                    'Name enrolled, but voice fingerprint is still missing (SpeechBrain/ffmpeg). Solo meetings can still start live; for 2+ speakers re-enroll after fixing Python embed_audio.py on the server.';
            }

            if ($assistantRejected) {
                $payload['intro_pipeline_note'] =
                    'Device caption was sent but rejected by server policy. Set APP_ENV=local or MEETING_INTRO_ALLOW_ASSISTANT_UTTERANCE=true (Capacitor shell allows captions by default).';
            } elseif ($heard === [] && $introSttHint !== null && $enrolled === []) {
                $payload['intro_pipeline_note'] =
                    'Speech-to-text produced no words from this clip. Say your name when prompted before upload, use “My name is …”, or tap Caption with device.';
            }

            if (config('app.debug')) {
                $payload['intro_debug'] = [
                    'deepgram_key_set' => trim((string) env('DEEPGRAM_API_KEY', '')) !== '',
                    'queue_default' => (string) config('queue.default'),
                    'analyzer_python' => (string) config('meeting_analytics.analyzer.python'),
                ];
            }

            return response()->json($payload, 200);
        }

        $job = new ProcessChunkJob(
            meetingId: $id,
            filePath: $filePath,
            chunkIndex: $chunkIndex,
            mode: 'intro',
            durationSeconds: $durationSeconds,
            audioInputProfile: $audioInputProfile,
        );
        if ($assistantForJob !== null) {
            $job->assistantUtterance = $assistantForJob;
        }
        if ($bindForJob !== null) {
            $job->bindParticipantId = $bindForJob;
        }
        $job->onQueue((string) config('wechirp.queue.audio', 'audio'));
        Bus::dispatch($job);

        $queuedPayload = [
            'status' => 'queued',
            'meeting_id' => $id,
            'chunk_index' => $chunkIndex,
            'mode' => 'intro',
            'intro_assistant_applied' => $assistantForJob !== null,
            'intro_manual_bind_applied' => $bindForJob !== null,
        ];

        if (config('queue.default') !== 'sync') {
            $queuedPayload['queue_worker_required'] = true;
            $queuedPayload['queue_worker_command'] = 'php artisan queue:listen --tries=1 --timeout=0 --queue=audio,default';
            $queuedPayload['queue_note'] =
                'Intro chunks are processed asynchronously on the "audio" queue. If nothing happens, start a queue worker with the command above (or use composer run dev).';
        }

        return response()->json($queuedPayload, 202);
    }

    private function storeChunkFile(Request $request, int $meetingId, int $chunkIndex): string
    {
        $file = $request->file('audio');
        $extension = $file->getClientOriginalExtension();
        $extension = $extension !== '' ? $extension : 'bin';
        $filename = "intro_{$chunkIndex}.".$extension;

        return MeetingAudioStorage::putUploadedChunk($file, $meetingId, $filename);
    }

    /**
     * Parse ffmpeg volumedetect max_volume (dBFS) from stderr.
     */
    private function ffmpegMaxVolumeDb(string $absolutePath): ?float
    {
        if (! is_readable($absolutePath)) {
            return null;
        }
        $p = new Process([
            'ffmpeg', '-hide_banner', '-nostats',
            '-i', $absolutePath,
            '-af', 'volumedetect',
            '-f', 'null', '-',
        ]);
        $p->setTimeout(25);
        $p->run();
        $err = $p->getErrorOutput();
        if (preg_match('/max_volume:\s*([-\d.]+)\s*dB/i', $err, $m)) {
            return (float) $m[1];
        }

        return null;
    }

    /**
     * @return list<array{label: string, text: string}>
     */
    private function buildHeardForIntroChunk(int $meetingId, int $chunkIndex, ?string $assistantForJob): array
    {
        $prefix = 'chunk'.$chunkIndex.'_';
        $heard = [];
        $statsSnap = Cache::get('meeting_'.$meetingId.'_stats');
        if (is_array($statsSnap) && isset($statsSnap['speaker_text']) && is_array($statsSnap['speaker_text'])) {
            foreach ($statsSnap['speaker_text'] as $lab => $txt) {
                $label = (string) $lab;
                if (! str_starts_with($label, $prefix)) {
                    continue;
                }
                $text = trim((string) $txt);
                if ($text === '') {
                    continue;
                }
                $heard[] = ['label' => $label, 'text' => $text];
            }
        }

        if ($heard === [] && $assistantForJob !== null) {
            $heard[] = [
                'label' => $prefix.'speaker_0',
                'text' => $assistantForJob,
            ];
        }

        return $heard;
    }

    /**
     * @param  mixed  $raw
     */
    private function resolvedBindParticipantId(Request $request, int $meetingId, $raw): ?int
    {
        if (! IntroEnrollmentPolicy::manualBindAllowed($request)) {
            return null;
        }
        $id = is_numeric($raw) ? (int) $raw : 0;
        if ($id < 1) {
            return null;
        }
        if (! MeetingParticipant::query()->where('meeting_id', $meetingId)->whereKey($id)->exists()) {
            throw ValidationException::withMessages([
                'bind_participant_id' => 'Participant not found for this meeting.',
            ]);
        }

        return $id;
    }

    /**
     * @param  mixed  $voiceEmbedding
     */
    private static function participantHasVoiceprintVector($voiceEmbedding): bool
    {
        $ve = is_array($voiceEmbedding) ? $voiceEmbedding : [];
        $countNumeric = static function (?array $arr): int {
            if (! is_array($arr)) {
                return 0;
            }
            $n = 0;
            foreach ($arr as $v) {
                if (is_numeric($v)) {
                    $n++;
                }
            }

            return $n;
        };
        if ($countNumeric($ve['voiceprint'] ?? null) >= 32) {
            return true;
        }
        $list = $ve['voiceprints'] ?? null;
        if (is_array($list)) {
            foreach ($list as $row) {
                if ($countNumeric(is_array($row) ? $row : null) >= 32) {
                    return true;
                }
            }
        }

        return false;
    }
}
