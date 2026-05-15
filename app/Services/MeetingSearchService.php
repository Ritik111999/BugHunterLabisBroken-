<?php

namespace App\Services;

use App\Models\Meeting;
use App\Models\MeetingSearchIndex;
use App\Models\MeetingTranscriptSegment;
use App\Models\Transcript;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Semantic search across past meetings (OpenAI embeddings, no LangChain dependency).
 */
class MeetingSearchService
{
    public function __construct(private readonly WechirpAiService $ai) {}

    public function indexMeeting(Meeting $meeting): void
    {
        $userId = (int) $meeting->host_id;
        if ($userId <= 0) {
            return;
        }

        $blob = $this->buildSearchBlob((int) $meeting->id, (string) ($meeting->title ?? ''));
        if (trim($blob) === '') {
            return;
        }

        $embedding = $this->ai->embedText($blob);

        MeetingSearchIndex::query()->updateOrCreate(
            ['meeting_id' => $meeting->id],
            [
                'user_id' => $userId,
                'text_blob' => mb_substr($blob, 0, 50000),
                'embedding' => $embedding,
            ],
        );
    }

    /**
     * @return array<int, array{meeting_id: int, title: string, snippet: string, score: float}>
     */
    public function search(User $user, string $query, int $limit = 12): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $indexes = MeetingSearchIndex::query()
            ->where('user_id', $user->id)
            ->whereNotNull('embedding')
            ->with('meeting:id,title,created_at,ended_at,status')
            ->get();

        if ($indexes->isEmpty()) {
            return $this->fallbackKeywordSearch($user, $query, $limit);
        }

        $queryVec = $this->ai->embedText($query);
        if ($queryVec === null) {
            return $this->fallbackKeywordSearch($user, $query, $limit);
        }

        $scored = [];
        foreach ($indexes as $row) {
            $vec = $row->embedding;
            if (! is_array($vec) || $vec === []) {
                continue;
            }
            $score = $this->cosineSimilarity($queryVec, $vec);
            if ($score <= 0.05) {
                continue;
            }
            $meeting = $row->meeting;
            $scored[] = [
                'meeting_id' => (int) $row->meeting_id,
                'title' => (string) ($meeting?->title ?? 'Meeting'),
                'snippet' => $this->snippetAroundQuery((string) $row->text_blob, $query),
                'score' => round($score, 4),
            ];
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, max(1, min(30, $limit)));
    }

    private function buildSearchBlob(int $meetingId, string $title): string
    {
        $segments = MeetingTranscriptSegment::query()
            ->where('meeting_id', $meetingId)
            ->orderBy('id')
            ->limit(500)
            ->pluck('text')
            ->filter()
            ->all();

        $body = $segments !== []
            ? implode("\n", $segments)
            : Transcript::query()
                ->where('meeting_id', $meetingId)
                ->orderBy('id')
                ->pluck('text')
                ->filter()
                ->implode("\n");

        $title = trim($title);

        return trim($title !== '' ? "Title: {$title}\n\n{$body}" : $body);
    }

    /**
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        if ($n === 0) {
            return 0.0;
        }
        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $na += $a[$i] * $a[$i];
            $nb += $b[$i] * $b[$i];
        }
        if ($na <= 0.0 || $nb <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($na) * sqrt($nb));
    }

    private function snippetAroundQuery(string $blob, string $query): string
    {
        $blob = preg_replace('/\s+/u', ' ', trim($blob)) ?? '';
        $q = preg_replace('/\s+/u', ' ', trim($query)) ?? '';
        if ($blob === '') {
            return '';
        }
        $pos = $q !== '' ? mb_stripos($blob, $q) : false;
        if ($pos === false) {
            return mb_substr($blob, 0, 160).(mb_strlen($blob) > 160 ? '…' : '');
        }
        $start = max(0, (int) $pos - 60);

        return mb_substr($blob, $start, 200).(mb_strlen($blob) > $start + 200 ? '…' : '');
    }

    /**
     * @return array<int, array{meeting_id: int, title: string, snippet: string, score: float}>
     */
    private function fallbackKeywordSearch(User $user, string $query, int $limit): array
    {
        $needle = mb_strtolower($query);
        $rows = MeetingSearchIndex::query()
            ->where('user_id', $user->id)
            ->with('meeting:id,title')
            ->latest('id')
            ->limit(80)
            ->get();

        $out = [];
        foreach ($rows as $row) {
            if (! str_contains(mb_strtolower((string) $row->text_blob), $needle)) {
                continue;
            }
            $out[] = [
                'meeting_id' => (int) $row->meeting_id,
                'title' => (string) ($row->meeting?->title ?? 'Meeting'),
                'snippet' => $this->snippetAroundQuery((string) $row->text_blob, $query),
                'score' => 0.5,
            ];
        }

        return array_slice($out, 0, max(1, min(30, $limit)));
    }
}
