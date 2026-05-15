<?php

namespace App\Jobs;

use App\Models\Meeting;
use App\Models\MeetingAnalytic;
use App\Models\Transcript;
use App\Services\WechirpAiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Post-meeting LLM summary via WechirpAiService (OpenAI structured JSON).
 */
class SummarizeMeetingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $meetingId) {}

    public function handle(WechirpAiService $ai): void
    {
        $meeting = Meeting::query()->find($this->meetingId);
        if (! $meeting) {
            return;
        }

        $fullText = Transcript::query()
            ->where('meeting_id', $meeting->id)
            ->orderBy('id')
            ->pluck('text')
            ->filter()
            ->implode("\n");

        if (trim($fullText) === '') {
            return;
        }

        $parsed = $ai->summarizeMeeting($fullText);

        $summary = trim((string) ($parsed['summary'] ?? ''));
        $keywords = is_array($parsed['keywords'] ?? null) ? $parsed['keywords'] : [];
        $actionItems = is_array($parsed['action_items'] ?? null) ? $parsed['action_items'] : [];
        $sentiment = is_array($parsed['sentiment'] ?? null) ? $parsed['sentiment'] : ['label' => 'neutral', 'score' => 0.5];

        if ($summary === '') {
            $summary = mb_substr(trim($fullText), 0, 500);
        }

        $insights = array_filter([
            'title' => $parsed['title'] ?? null,
            'topics' => $parsed['topics'] ?? [],
            'decisions' => $parsed['decisions'] ?? [],
            'follow_ups' => $parsed['follow_ups'] ?? [],
            'questions' => $parsed['questions'] ?? [],
        ], static fn ($v) => $v !== null && $v !== [] && $v !== '');

        $analytic = MeetingAnalytic::query()->firstOrNew(['meeting_id' => $meeting->id]);
        $analytic->summary = $summary;
        $analytic->keywords = array_values(array_slice($keywords, 0, 12));
        $analytic->action_items = array_values(array_slice($actionItems, 0, 12));
        $analytic->sentiment = $sentiment;
        if ($insights !== []) {
            $analytic->insights = $insights;
        }
        $analytic->save();

        $title = trim((string) ($parsed['title'] ?? ''));
        if ($title !== '' && trim((string) ($meeting->title ?? '')) === '') {
            $meeting->title = mb_substr($title, 0, 200);
            $meeting->save();
        }
    }
}
