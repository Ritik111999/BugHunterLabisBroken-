<?php

namespace App\Jobs;

use App\Models\MeetingParticipant;
use App\Services\VoiceprintService;
use App\Support\MeetingAudioStorage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ComputeIntroVoiceprintJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $meetingId;

    public int $participantId;

    public string $filePath;

    public int $chunkIndex;

    public ?float $maxSeconds;

    public ?string $audioInputProfile = null;

    public function __construct(int $meetingId, int $participantId, string $filePath, int $chunkIndex = 0, ?float $maxSeconds = null, ?string $audioInputProfile = null)
    {
        $this->meetingId = $meetingId;
        $this->participantId = $participantId;
        $this->filePath = $filePath;
        $this->chunkIndex = $chunkIndex;
        $this->maxSeconds = $maxSeconds;
        $this->audioInputProfile = $audioInputProfile;
    }

    public function handle(VoiceprintService $voiceprints): void
    {
        $p = MeetingParticipant::query()
            ->whereKey($this->participantId)
            ->where('meeting_id', $this->meetingId)
            ->first();

        if (! $p) {
            return;
        }

        $prev = is_array($p->voice_embedding) ? $p->voice_embedding : [];
        $engine = strtolower(trim((string) ($prev['voiceprint_engine'] ?? $prev['engine'] ?? '')));
        $hasVp = is_array($prev['voiceprint'] ?? null) && count((array) ($prev['voiceprint'] ?? [])) >= 32;
        if ($hasVp && $engine === 'speechbrain_ecapa') {
            return;
        }

        $computed = MeetingAudioStorage::withLocalPath(
            $this->filePath,
            fn (string $abs) => $voiceprints->computeEcapa($abs, $this->maxSeconds, $this->audioInputProfile),
        );
        if (! $computed) {
            Log::warning('intro_voiceprint_async_failed', [
                'meeting_id' => $this->meetingId,
                'participant_id' => $this->participantId,
                'chunk_index' => $this->chunkIndex,
                'file_path' => $this->filePath,
            ]);

            return;
        }

        $vec = $computed['embedding'];
        $engine = $computed['engine'];

        $voiceprintsList = [];
        $existingList = $prev['voiceprints'] ?? null;
        if (is_array($existingList)) {
            foreach ($existingList as $v) {
                if (is_array($v) && count($v) >= 32) {
                    $voiceprintsList[] = array_map('floatval', $v);
                }
            }
        }
        $existingVp = $prev['voiceprint'] ?? null;
        if (is_array($existingVp) && count($existingVp) >= 32) {
            $voiceprintsList[] = array_map('floatval', $existingVp);
        }
        $voiceprintsList[] = array_map('floatval', $vec);

        $max = (int) env('MEETING_INTRO_MAX_VOICEPRINTS_PER_PERSON', 5);
        $max = max(1, min(10, $max));
        if (count($voiceprintsList) > $max) {
            $voiceprintsList = array_slice($voiceprintsList, -1 * $max);
        }

        if (! $hasVp) {
            $prev['voiceprint'] = $voiceprintsList[count($voiceprintsList) - 1];
        }

        $prev['voiceprint_dim'] = is_array($prev['voiceprint'] ?? null) ? count((array) $prev['voiceprint']) : 0;
        $prev['voiceprints'] = $voiceprintsList;
        $prev['voiceprint_engine'] = $engine;
        $prev['voiceprint_source'] = 'async_embed_audio';
        $prev['voiceprint_updated_at'] = now()->toISOString();

        $p->update([
            'voice_embedding' => $prev,
        ]);

        Log::info('intro_voiceprint_async_ok', [
            'meeting_id' => $this->meetingId,
            'participant_id' => $this->participantId,
            'chunk_index' => $this->chunkIndex,
            'dim' => (int) ($prev['voiceprint_dim'] ?? 0),
            'engine' => $engine,
        ]);
    }
}
