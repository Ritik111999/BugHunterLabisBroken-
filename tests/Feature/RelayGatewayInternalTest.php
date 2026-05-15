<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\User;
use App\Support\MeetingRelayTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RelayGatewayInternalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.relay_gateway.internal_secret' => 'test-relay-secret']);
    }

    public function test_internal_routes_reject_missing_secret(): void
    {
        $this->postJson('/api/internal/relay/validate-auth', [
            'meeting_id' => 1,
            'token' => 'x',
        ])->assertUnauthorized();
    }

    public function test_validate_auth_accepts_live_token(): void
    {
        Cache::flush();
        $user = User::factory()->create();
        $meeting = Meeting::create([
            'host_id' => $user->id,
            'title' => 'T',
            'status' => 'processing',
        ]);
        $issued = MeetingRelayTokenService::issue((int) $meeting->id, (int) $user->id);

        $this->withHeader('X-Relay-Secret', 'test-relay-secret')
            ->postJson('/api/internal/relay/validate-auth', [
                'meeting_id' => $meeting->id,
                'token' => $issued['token'],
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    public function test_persist_updates_stats(): void
    {
        $user = User::factory()->create();
        $meeting = Meeting::create([
            'host_id' => $user->id,
            'title' => 'T',
            'status' => 'processing',
            'started_at' => now(),
        ]);

        $this->withHeader('X-Relay-Secret', 'test-relay-secret')
            ->postJson('/api/internal/relay/persist', [
                'meeting_id' => $meeting->id,
                'speaker_seconds' => ['speaker_0' => 12.5],
                'overlap_seconds' => 0,
                'audio_cursor_seconds' => 15,
                'lines' => [
                    ['label' => 'speaker_0', 'name' => 'speaker_0', 'text' => 'Hello team'],
                ],
            ])
            ->assertOk();

        $this->assertDatabaseHas('meeting_analytics', [
            'meeting_id' => $meeting->id,
        ]);
    }

    public function test_voice_chunk_queues_job(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $meeting = Meeting::create([
            'host_id' => $user->id,
            'title' => 'T',
            'status' => 'processing',
        ]);

        $pcm = str_repeat("\0\0", 8000);

        $this->withHeader('X-Relay-Secret', 'test-relay-secret')
            ->postJson('/api/internal/relay/voice-chunk', [
                'meeting_id' => $meeting->id,
                'speaker_label' => 'speaker_0',
                'pcm_base64' => base64_encode($pcm),
                'sample_rate' => 16000,
            ])
            ->assertOk()
            ->assertJsonPath('queued', true);

        Queue::assertPushed(\App\Jobs\ProcessLiveRelayVoiceChunkJob::class);
    }
}
