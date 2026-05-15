<?php

namespace App\Jobs;

use App\Services\LiveVoiceprintMatcher;
use App\Services\VoiceprintService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessLiveRelayVoiceChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $meetingId,
        public string $speakerLabel,
        public string $relativePath,
        public float $startSeconds,
    ) {
        $this->onQueue(config('wechirp.queue.audio', 'audio'));
    }

    public function handle(VoiceprintService $voiceprints, LiveVoiceprintMatcher $matcher): void
    {
        $disk = Storage::disk('local');
        if (! $disk->exists($this->relativePath)) {
            return;
        }

        $absolute = $disk->path($this->relativePath);
        $result = $voiceprints->computeEcapa($absolute, max(1.0, (float) config('meeting_voice.embed_max_seconds', 8)));
        $disk->delete($this->relativePath);

        if ($result === null || ! is_array($result['embedding'] ?? null)) {
            return;
        }

        $match = $matcher->matchForMeeting($this->meetingId, $result['embedding'], $this->speakerLabel);
        if ($match === null || ! ($match['matched'] ?? false)) {
            return;
        }

        $cacheKey = "live_relay_voice_match:{$this->meetingId}";
        $existing = Cache::get($cacheKey, []);
        if (! is_array($existing)) {
            $existing = [];
        }
        $existing[$this->speakerLabel] = $match;
        Cache::put($cacheKey, $existing, now()->addHours(2));

        Log::debug('live_relay_voice_match', [
            'meeting_id' => $this->meetingId,
            'label' => $this->speakerLabel,
            'participant_id' => $match['best_participant_id'] ?? null,
            'score' => $match['best_score'] ?? 0,
        ]);
    }
}
