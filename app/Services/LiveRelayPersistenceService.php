<?php

namespace App\Services;

use App\Models\Meeting;
use App\Models\MeetingAnalytic;
use App\Models\MeetingParticipant;
use App\Models\ParticipantStat;
use App\Models\SpeakerMapping;
use App\Support\LiveTranscriptWriter;
use App\Support\MeetingRelayLiveCache;
use Illuminate\Support\Facades\DB;

/**
 * Apply live meeting state pushed from the Node Deepgram gateway.
 */
class LiveRelayPersistenceService
{
    /**
     * @param  array<string, float>  $speakerSeconds
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<string, mixed>  $voiceMatching
     */
    public function persist(int $meetingId, array $speakerSeconds, float $overlapSeconds, float $audioCursorSeconds, array $lines, array $voiceMatching = []): void
    {
        if (! Meeting::query()->whereKey($meetingId)->exists()) {
            return;
        }

        $timeline = max(1.0, $audioCursorSeconds, array_sum(array_map(fn ($s) => max(0.0, (float) $s), $speakerSeconds)));
        $crosstalkPct = (float) round((max(0.0, $overlapSeconds) / $timeline) * 100, 2);
        $speechSum = max(1.0, array_sum(array_map(fn ($s) => max(0.0, (float) $s), $speakerSeconds)));

        DB::transaction(function () use ($meetingId, $speakerSeconds, $crosstalkPct, $speechSum, $voiceMatching) {
            $labelToParticipantId = [];

            foreach ($speakerSeconds as $label => $sec) {
                $label = (string) $label;
                $mapping = SpeakerMapping::query()
                    ->where('meeting_id', $meetingId)
                    ->where('speaker_label', $label)
                    ->first();

                if (! $mapping) {
                    $display = preg_match('/^speaker_(\d+)$/', $label, $m) ? 'Speaker '.$m[1] : $label;
                    $participant = MeetingParticipant::create([
                        'meeting_id' => $meetingId,
                        'user_id' => null,
                        'name' => $display,
                        'voice_embedding' => [
                            'provider' => 'deepgram',
                            'type' => 'speaker_label_identity',
                            'speaker_label' => $label,
                        ],
                    ]);
                    $mapping = SpeakerMapping::create([
                        'meeting_id' => $meetingId,
                        'speaker_label' => $label,
                        'participant_id' => $participant->id,
                        'confidence' => null,
                    ]);
                }

                $pid = (int) $mapping->participant_id;
                $vm = $voiceMatching[$label] ?? null;
                if (is_array($vm) && ($vm['matched'] ?? false) && is_numeric($vm['best_participant_id'] ?? null)) {
                    $bestPid = (int) $vm['best_participant_id'];
                    if ($bestPid > 0) {
                        SpeakerMapping::query()
                            ->where('meeting_id', $meetingId)
                            ->where('speaker_label', $label)
                            ->update([
                                'participant_id' => $bestPid,
                                'confidence' => is_numeric($vm['best_score'] ?? null) ? (float) $vm['best_score'] : null,
                            ]);
                        $pid = $bestPid;
                    }
                }

                $labelToParticipantId[$label] = $pid;
                $talkTime = (int) round(max(0.0, (float) $sec));
                $talkPct = (float) round((max(0.0, (float) $sec) / $speechSum) * 100, 2);

                ParticipantStat::updateOrCreate(
                    ['meeting_id' => $meetingId, 'participant_id' => $pid],
                    [
                        'talk_time' => $talkTime,
                        'talk_percentage' => min(100.0, max(0.0, $talkPct)),
                    ]
                );
            }

            MeetingAnalytic::updateOrCreate(
                ['meeting_id' => $meetingId],
                [
                    'crosstalk_percentage' => $crosstalkPct,
                    'total_speakers' => count($speakerSeconds),
                ]
            );
        });

        if ($lines !== []) {
            LiveTranscriptWriter::persistRelayLines($meetingId, $lines, $audioCursorSeconds);
        }

        $snapshot = $this->buildSnapshotPayload($meetingId, $speakerSeconds, $crosstalkPct, $audioCursorSeconds, $lines, $voiceMatching);
        MeetingRelayLiveCache::putSnapshot($meetingId, $snapshot);
        MeetingRelayLiveCache::putTranscriptLines($meetingId, $lines);
    }

    /**
     * @param  array<string, float>  $speakerSeconds
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<string, mixed>  $voiceMatching
     * @return array<string, mixed>
     */
    private function buildSnapshotPayload(int $meetingId, array $speakerSeconds, float $crosstalkPct, float $audioCursorSeconds, array $lines, array $voiceMatching): array
    {
        $total = max(0.001, array_sum(array_map(fn ($s) => max(0.0, (float) $s), $speakerSeconds)));
        $participants = [];
        foreach ($speakerSeconds as $label => $sec) {
            $sec = max(0.0, (float) $sec);
            $pct = (float) round(($sec / $total) * 100, 2);
            $name = (string) $label;
            $pid = 0;
            $vm = $voiceMatching[$label] ?? null;
            if (is_array($vm)) {
                if (! empty($vm['best_participant_name'])) {
                    $name = (string) $vm['best_participant_name'];
                }
                if (is_numeric($vm['best_participant_id'] ?? null)) {
                    $pid = (int) $vm['best_participant_id'];
                }
            }
            $participants[] = [
                'participant_id' => $pid,
                'label' => (string) $label,
                'name' => $name,
                'talk_time' => (int) floor($sec),
                'talk_time_seconds' => $sec,
                'talk_percentage' => $pct,
                'times_spoken' => 0,
            ];
        }

        return [
            'meeting_id' => $meetingId,
            'total_participants' => count($participants),
            'participants' => $participants,
            'crosstalk_percentage' => $crosstalkPct,
            'live_audio_seconds' => $audioCursorSeconds,
            'voice_matching' => $voiceMatching,
            'relay_engine' => 'node',
            'updated_at' => now()->toISOString(),
        ];
    }
}
