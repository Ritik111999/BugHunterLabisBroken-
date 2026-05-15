<?php

namespace App\Services;

use App\Models\MeetingParticipant;

/**
 * Match a live ECAPA embedding against enrolled meeting participants (Node gateway path).
 */
class LiveVoiceprintMatcher
{
    /**
     * @param  array<int, float>  $embedding
     * @return array{matched: bool, best_participant_id: ?int, best_participant_name: string, best_score: float, threshold: float}|null
     */
    public function matchForMeeting(int $meetingId, array $embedding, string $label = ''): ?array
    {
        $enrolled = $this->loadEnrolledVectors($meetingId);
        if ($enrolled === []) {
            return null;
        }

        $threshold = (float) config('meeting_voice.voiceprint.threshold', 0.78);
        $margin = (float) config('meeting_voice.voiceprint.margin', 0.06);
        if (count($enrolled) >= 2) {
            $margin = (float) config('meeting_voice.voiceprint.margin_2p', 0.012);
        }

        $bestPid = null;
        $bestScore = -1.0;
        $secondScore = -1.0;
        $bestName = '';

        foreach ($enrolled as $row) {
            $score = $this->cosineSimilarity($embedding, $row['vector']);
            if ($score > $bestScore) {
                $secondScore = $bestScore;
                $bestScore = $score;
                $bestPid = $row['participant_id'];
                $bestName = $row['name'];
            } elseif ($score > $secondScore) {
                $secondScore = $score;
            }
        }

        $matched = $bestPid !== null
            && $bestScore >= $threshold
            && ($bestScore - max(-1.0, $secondScore)) >= $margin;

        return [
            'matched' => $matched,
            'best_participant_id' => $matched ? $bestPid : null,
            'best_participant_name' => $matched ? $bestName : '',
            'best_score' => (float) $bestScore,
            'threshold' => $threshold,
            'label' => $label,
        ];
    }

    /**
     * @return array<int, array{participant_id: int, name: string, vector: array<int, float>}>
     */
    private function loadEnrolledVectors(int $meetingId): array
    {
        $rows = MeetingParticipant::query()
            ->where('meeting_id', $meetingId)
            ->get(['id', 'name', 'voice_embedding']);

        $out = [];
        foreach ($rows as $p) {
            $name = trim((string) ($p->name ?? ''));
            if ($name === '' || preg_match('/^(Speaker\s+\d+|speaker_\d+)$/i', $name)) {
                continue;
            }
            $vec = $this->vectorFromEmbedding($p->voice_embedding);
            if ($vec === null) {
                continue;
            }
            $out[] = [
                'participant_id' => (int) $p->id,
                'name' => $name,
                'vector' => $vec,
            ];
        }

        return $out;
    }

    /**
     * @param  mixed  $ve
     * @return array<int, float>|null
     */
    private function vectorFromEmbedding(mixed $ve): ?array
    {
        if (! is_array($ve)) {
            return null;
        }
        $raw = $ve['voiceprint'] ?? null;
        if (! is_array($raw)) {
            $list = $ve['voiceprints'] ?? null;
            if (is_array($list) && isset($list[0]) && is_array($list[0])) {
                $raw = $list[0];
            }
        }
        if (! is_array($raw)) {
            return null;
        }
        $vec = [];
        foreach ($raw as $v) {
            if (is_numeric($v)) {
                $vec[] = (float) $v;
            }
        }

        return count($vec) >= 32 ? $vec : null;
    }

    /** @param array<int,float> $a @param array<int,float> $b */
    private function cosineSimilarity(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        if ($n <= 0) {
            return 0.0;
        }
        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $x = (float) $a[$i];
            $y = (float) $b[$i];
            $dot += $x * $y;
            $na += $x * $x;
            $nb += $y * $y;
        }
        $den = sqrt(max(1e-12, $na)) * sqrt(max(1e-12, $nb));

        return $den > 0 ? ($dot / $den) : 0.0;
    }
}
