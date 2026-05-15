<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\User;
use App\Support\MeetingRelayTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MeetingLiveTokenControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_token_requires_auth_and_returns_short_lived_token(): void
    {
        Cache::flush();
        $user = User::factory()->create();
        $meeting = Meeting::create([
            'host_id' => $user->id,
            'title' => 'Test',
            'status' => 'pending',
        ]);

        Sanctum::actingAs($user);

        $res = $this->postJson("/api/meetings/{$meeting->id}/live-token");
        $res->assertOk();
        $res->assertJsonStructure(['meeting_id', 'token', 'expires_in']);
        $this->assertSame((int) $meeting->id, (int) $res->json('meeting_id'));

        $resolved = MeetingRelayTokenService::resolve((string) $res->json('token'));
        $this->assertSame((int) $meeting->id, $resolved['meeting_id']);
        $this->assertSame((int) $user->id, $resolved['user_id']);
    }
}
