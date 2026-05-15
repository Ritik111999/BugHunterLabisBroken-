<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Post-meeting intelligence via OpenAI (structured JSON + embeddings).
 * Deepgram handles all speech-to-text; this service never transcribes audio.
 */
class WechirpAiService
{
    public function isConfigured(): bool
    {
        return $this->apiKey() !== '';
    }

    /**
     * Otter-style meeting intelligence from full transcript text.
     *
     * @return array{
     *   title?: string,
     *   summary?: string,
     *   keywords?: array<int, string>,
     *   topics?: array<int, string>,
     *   action_items?: array<int, string>,
     *   decisions?: array<int, string>,
     *   follow_ups?: array<int, string>,
     *   questions?: array<int, string>,
     *   sentiment?: array{label?: string, score?: float}
     * }
     */
    public function summarizeMeeting(string $transcript): array
    {
        $transcript = trim($transcript);
        if ($transcript === '' || ! $this->isConfigured()) {
            return [];
        }

        $maxChars = max(2000, (int) config('wechirp.ai.max_transcript_chars', 14000));
        $transcript = mb_substr($transcript, 0, $maxChars);

        $schema = <<<'JSON'
{
  "title": "short meeting title (max 12 words)",
  "summary": "2-4 sentence executive summary",
  "keywords": ["up to 8 short topics or entities"],
  "topics": ["3-6 discussion themes"],
  "action_items": ["concrete tasks with owner when stated"],
  "decisions": ["decisions made"],
  "follow_ups": ["items to revisit later"],
  "questions": ["open questions left unanswered"],
  "sentiment": {"label": "positive|neutral|negative", "score": 0.0}
}
JSON;

        $prompt = "You are WeChirp, a professional meeting assistant (Otter-quality).\n"
            . "Analyze the transcript and return JSON matching this schema exactly:\n{$schema}\n\n"
            . "Transcript:\n{$transcript}";

        try {
            $res = Http::timeout(90)
                ->withToken($this->apiKey())
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $this->chatModel(),
                    'temperature' => 0.25,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => 'Return valid JSON only. No markdown.'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ]);
            $res->throw();
            $content = (string) data_get($res->json(), 'choices.0.message.content', '');
            $decoded = json_decode($content, true);

            return is_array($decoded) ? $this->normalizeInsights($decoded) : [];
        } catch (\Throwable $e) {
            Log::warning('wechirp_ai_summarize_failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @return array<int, float>|null
     */
    public function embedText(string $text): ?array
    {
        $text = trim($text);
        if ($text === '' || ! $this->isConfigured()) {
            return null;
        }

        try {
            $res = Http::timeout(45)
                ->withToken($this->apiKey())
                ->post('https://api.openai.com/v1/embeddings', [
                    'model' => $this->embeddingModel(),
                    'input' => mb_substr($text, 0, 8000),
                ]);
            $res->throw();
            $vec = data_get($res->json(), 'data.0.embedding');

            return is_array($vec) ? array_map('floatval', $vec) : null;
        } catch (\Throwable $e) {
            Log::warning('wechirp_ai_embed_failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    public function chatModel(): string
    {
        return trim((string) (config('meeting_analytics.openai_model')
            ?: config('wechirp.ai.model')
            ?: env('OPENAI_MODEL', 'gpt-4o-mini')));
    }

    public function embeddingModel(): string
    {
        return trim((string) (config('meeting_analytics.openai_embedding_model')
            ?: config('wechirp.ai.embedding_model')
            ?: 'text-embedding-3-small'));
    }

    private function apiKey(): string
    {
        return trim((string) (config('meeting_analytics.openai_api_key')
            ?: env('OPENAI_API_KEY', '')
            ?: (Setting::query()->where('key', 'ai.openai_api_key')->value('value') ?? '')));
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{
     *   title?: string,
     *   summary?: string,
     *   keywords?: array<int, string>,
     *   topics?: array<int, string>,
     *   action_items?: array<int, string>,
     *   decisions?: array<int, string>,
     *   follow_ups?: array<int, string>,
     *   questions?: array<int, string>,
     *   sentiment?: array{label?: string, score?: float}
     * }
     */
    private function normalizeInsights(array $raw): array
    {
        $strings = static fn ($key, int $max) => array_values(array_slice(
            array_filter(array_map(static fn ($v) => trim((string) $v), is_array($raw[$key] ?? null) ? $raw[$key] : [])),
            0,
            $max,
        ));

        $title = trim((string) ($raw['title'] ?? ''));
        $summary = trim((string) ($raw['summary'] ?? ''));
        $sentiment = $raw['sentiment'] ?? null;
        $sentimentOut = null;
        if (is_array($sentiment)) {
            $label = strtolower(trim((string) ($sentiment['label'] ?? 'neutral')));
            if (! in_array($label, ['positive', 'neutral', 'negative'], true)) {
                $label = 'neutral';
            }
            $score = (float) ($sentiment['score'] ?? 0.5);
            $sentimentOut = ['label' => $label, 'score' => max(0.0, min(1.0, $score))];
        }

        $out = [
            'keywords' => $strings('keywords', 12),
            'topics' => $strings('topics', 8),
            'action_items' => $strings('action_items', 12),
            'decisions' => $strings('decisions', 10),
            'follow_ups' => $strings('follow_ups', 10),
            'questions' => $strings('questions', 10),
        ];
        if ($title !== '') {
            $out['title'] = mb_substr($title, 0, 200);
        }
        if ($summary !== '') {
            $out['summary'] = $summary;
        }
        if ($sentimentOut !== null) {
            $out['sentiment'] = $sentimentOut;
        }

        return $out;
    }
}
