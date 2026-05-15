<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MeetingStartControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingApiUser(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, guard: 'sanctum');

        return $user;
    }

    /** @return array{0: Meeting, 1: MeetingParticipant, 2: MeetingParticipant} */
    private function meetingWithTwoParticipants(User $user): array
    {
        $meeting = Meeting::query()->create([
            'host_id' => $user->id,
            'title' => 'Test',
            'status' => 'pending',
        ]);

        $vecA = array_map(fn ($i) => sin($i * 0.1) * 0.01, range(0, 63));
        $vecB = array_map(fn ($i) => cos($i * 0.13) * 0.01, range(0, 63));

        $p1 = MeetingParticipant::query()->create([
            'meeting_id' => $meeting->id,
            'user_id' => null,
            'name' => 'Alice',
            'voice_embedding' => [
                'voiceprints' => [$vecA],
            ],
        ]);
        $p2 = MeetingParticipant::query()->create([
            'meeting_id' => $meeting->id,
            'user_id' => null,
            'name' => 'Bob',
            'voice_embedding' => [
                'voiceprints' => [$vecB],
            ],
        ]);

        return [$meeting, $p1, $p2];
    }

    public function test_start_accepts_voiceprint_only_in_voiceprints_array(): void
    {
        $user = $this->actingApiUser();
        [$meeting] = $this->meetingWithTwoParticipants($user);

        $this->postJson("/api/meetings/{$meeting->id}/start")
            ->assertOk()
            ->assertJson(['message' => 'Meeting started']);

        $meeting->refresh();
        $this->assertSame('processing', $meeting->status);
    }

    public function test_start_rejects_when_vectors_missing(): void
    {
        $user = $this->actingApiUser();
        $meeting = Meeting::query()->create([
            'host_id' => $user->id,
            'title' => 'Test',
            'status' => 'pending',
        ]);
        MeetingParticipant::query()->create([
            'meeting_id' => $meeting->id,
            'user_id' => null,
            'name' => 'Alice',
            'voice_embedding' => ['type' => 'intro_name_enrollment'],
        ]);
        MeetingParticipant::query()->create([
            'meeting_id' => $meeting->id,
            'user_id' => null,
            'name' => 'Bob',
            'voice_embedding' => null,
        ]);

        $this->postJson("/api/meetings/{$meeting->id}/start")
            ->assertStatus(422)
            ->assertJsonPath('code', 'voiceprints_missing');
    }
}
