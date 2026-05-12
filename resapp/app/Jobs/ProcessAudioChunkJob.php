<?php

namespace App\Jobs;

use App\Events\MeetingStatsUpdated;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Friday-style chunk processor:
 * - No DB reads/writes
 * - Cache/Redis only under key: meeting_{meetingId}_stats
 * - Mock AI result (speaker, duration, overlap)
 * - Broadcast stats.updated on meeting.{meetingId}
 */
class ProcessAudioChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $meetingId;
    public ?string $filePath;
    public int $chunkIndex;

    public function __construct(int $meetingId, ?string $filePath = null, int $chunkIndex = 0)
    {
        $this->meetingId = $meetingId;
        $this->filePath = $filePath;
        $this->chunkIndex = $chunkIndex;
    }

    public function handle(): void
    {
        $statsKey = $this->statsCacheKey($this->meetingId);
        $lockKey = $statsKey . '_lock';

        $lock = Cache::lock($lockKey, 10);

        try {
            $lock->block(5, function () use ($statsKey) {
                $ai = $this->getAIResult();

                $stats = Cache::get($statsKey, [
                    'total_time' => 0,
                    'users' => [],
                    'crosstalk' => 0,
                ]);

                $stats = $this->applyChunkToStats($stats, $ai);

                Cache::put($statsKey, $stats, now()->addHours(6));

                [$participants, $crosstalkPercentage] = $this->calculatePercentages($stats);

                event(new MeetingStatsUpdated(
                    meetingId: $this->meetingId,
                    participants: $participants,
                    crosstalkPercentage: $crosstalkPercentage,
                ));
            });
        } catch (Throwable $e) {
            throw $e;
        } finally {
            optional($lock)->release();
        }
    }

    private function statsCacheKey(int $meetingId): string
    {
        return "meeting_{$meetingId}_stats";
    }

    /**
     * Mock AI result for chunk-level diarization/overlap detection.
     *
     * @return array{speaker:string,duration:int,is_overlap:bool}
     */
    private function getAIResult(): array
    {
        $defaultSpeakers = ['Rajat', 'Amit'];

        $current = Cache::get($this->statsCacheKey($this->meetingId), []);
        $knownSpeakers = array_keys(Arr::get($current, 'users', []));
        $speakerPool = count($knownSpeakers) > 0 ? $knownSpeakers : $defaultSpeakers;

        $speaker = (string) $speakerPool[array_rand($speakerPool)];
        $duration = 3;
        $isOverlap = random_int(0, 9) < 2; // ~20%

        return [
            'speaker' => $speaker,
            'duration' => $duration,
            'is_overlap' => (bool) $isOverlap,
        ];
    }

    /**
     * @param array{total_time:int|float,users:array<string,int|float>,crosstalk:int|float} $stats
     * @param array{speaker:string,duration:int,is_overlap:bool} $ai
     * @return array{total_time:int|float,users:array<string,int|float>,crosstalk:int|float}
     */
    private function applyChunkToStats(array $stats, array $ai): array
    {
        $duration = (int) $ai['duration'];
        $speaker = (string) $ai['speaker'];
        $isOverlap = (bool) $ai['is_overlap'];

        $stats['total_time'] = ((int) ($stats['total_time'] ?? 0)) + $duration;
        $stats['users'] = is_array($stats['users'] ?? null) ? $stats['users'] : [];
        $stats['users'][$speaker] = ((int) ($stats['users'][$speaker] ?? 0)) + $duration;
        $stats['crosstalk'] = ((int) ($stats['crosstalk'] ?? 0)) + ($isOverlap ? $duration : 0);

        return $stats;
    }

    /**
     * @param array{total_time:int|float,users:array<string,int|float>,crosstalk:int|float} $stats
     * @return array{0:array<int,array{name:string,percentage:int}>,1:int}
     */
    private function calculatePercentages(array $stats): array
    {
        $total = max(1, (int) ($stats['total_time'] ?? 0));

        $participants = [];
        foreach (($stats['users'] ?? []) as $name => $seconds) {
            $participants[] = [
                'name' => (string) $name,
                'percentage' => (int) round(((int) $seconds / $total) * 100),
            ];
        }

        $crosstalkSeconds = (int) ($stats['crosstalk'] ?? 0);
        $crosstalkPercentage = (int) round(($crosstalkSeconds / $total) * 100);

        return [$participants, $crosstalkPercentage];
    }
}

