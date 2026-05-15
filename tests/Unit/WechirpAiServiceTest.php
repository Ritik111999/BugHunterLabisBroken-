<?php

namespace Tests\Unit;

use App\Services\WechirpAiService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WechirpAiServiceTest extends TestCase
{
    public function test_summarize_meeting_parses_structured_json(): void
    {
        config(['meeting_analytics.openai_api_key' => 'test-key']);

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'title' => 'Sprint planning',
                                'summary' => 'Team aligned on scope.',
                                'keywords' => ['roadmap'],
                                'topics' => ['Q2 goals'],
                                'action_items' => ['Ship beta'],
                                'decisions' => ['Use Deepgram'],
                                'follow_ups' => ['Review metrics'],
                                'questions' => ['Budget?'],
                                'sentiment' => ['label' => 'positive', 'score' => 0.8],
                            ]),
                        ],
                    ],
                ],
            ]),
        ]);

        $ai = app(WechirpAiService::class);
        $out = $ai->summarizeMeeting('Alice: We should ship the beta next month.');

        $this->assertSame('Sprint planning', $out['title'] ?? '');
        $this->assertStringContainsString('aligned', $out['summary'] ?? '');
        $this->assertContains('Ship beta', $out['action_items'] ?? []);
        $this->assertContains('Use Deepgram', $out['decisions'] ?? []);
        $this->assertSame('positive', $out['sentiment']['label'] ?? '');
    }

    public function test_embed_returns_null_without_api_key(): void
    {
        config(['meeting_analytics.openai_api_key' => '']);

        $ai = app(WechirpAiService::class);
        $this->assertNull($ai->embedText('hello'));
    }
}
