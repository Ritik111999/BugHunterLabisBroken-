<?php

namespace Tests\Feature;

use App\Jobs\ProcessChunkJob;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AudioChunkControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingApiUser(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, guard: 'sanctum');

        return $user;
    }

    private function createMeetingFor(User $user): Meeting
    {
        return Meeting::query()->create([
            'host_id' => $user->id,
            'title' => 'Test meeting',
            'status' => 'live',
        ]);
    }

    public function test_audio_chunk_requires_authentication(): void
    {
        $response = $this->postJson('/api/audio/chunk', [
            'meeting_id' => 1,
            'chunk_index' => 0,
        ]);

        $response->assertUnauthorized();
    }

    public function test_audio_chunk_validates_required_fields(): void
    {
        $this->actingApiUser();

        $this->postJson('/api/audio/chunk', [
            'chunk_index' => 0,
        ])->assertUnprocessable();

        $this->postJson('/api/audio/chunk', [
            'meeting_id' => 1,
        ])->assertUnprocessable();
    }

    public function test_audio_chunk_async_dispatches_process_chunk_job(): void
    {
        Bus::fake();
        Storage::fake('local');

        $user = $this->actingApiUser();
        $meeting = $this->createMeetingFor($user);

        $file = UploadedFile::fake()->create('chunk.webm', 32);

        $response = $this->post('/api/audio/chunk', [
            'meeting_id' => $meeting->id,
            'chunk_index' => 2,
            'audio' => $file,
            'audio_input_profile' => 'bluetooth',
        ]);

        $response->assertAccepted()
            ->assertJson([
                'status' => 'queued',
                'meeting_id' => $meeting->id,
                'chunk_index' => 2,
            ]);

        Bus::assertDispatched(ProcessChunkJob::class, function (ProcessChunkJob $job) use ($meeting) {
            return $job->meetingId === $meeting->id
                && $job->chunkIndex === 2
                && $job->mode === 'meeting'
                && $job->audioInputProfile === 'bluetooth'
                && is_string($job->filePath)
                && $job->filePath !== '';
        });
    }

    public function test_audio_chunk_sync_completes_without_audio_file(): void
    {
        $user = $this->actingApiUser();
        $meeting = $this->createMeetingFor($user);

        $response = $this->postJson('/api/audio/chunk?sync=1', [
            'meeting_id' => $meeting->id,
            'chunk_index' => 0,
        ]);

        $response->assertOk()
            ->assertJson([
                'status' => 'completed',
                'meeting_id' => $meeting->id,
                'chunk_index' => 0,
            ]);
    }

    public function test_audio_chunk_sync_accepts_unknown_profile_as_null(): void
    {
        $user = $this->actingApiUser();
        $meeting = $this->createMeetingFor($user);

        Bus::fake();

        $this->postJson('/api/audio/chunk?sync=1', [
            'meeting_id' => $meeting->id,
            'chunk_index' => 0,
            'audio_input_profile' => 'not-a-real-profile-xyz',
        ])->assertOk();

        Bus::assertDispatched(ProcessChunkJob::class, function (ProcessChunkJob $job) {
            return $job->audioInputProfile === null;
        });
    }
}
