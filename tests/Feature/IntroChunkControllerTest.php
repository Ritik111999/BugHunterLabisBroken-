<?php

namespace Tests\Feature;

use App\Jobs\ProcessChunkJob;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IntroChunkControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingApiUser(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, guard: 'sanctum');

        return $user;
    }

    public function test_sync_intro_dispatches_job_with_assistant_utterance_for_capacitor(): void
    {
        config(['services.wechirp.native_shell_ua_token' => 'WeChirpCapacitorShell']);

        $user = $this->actingApiUser();
        $meeting = Meeting::query()->create([
            'host_id' => $user->id,
            'title' => 'Intro test',
            'status' => 'pending',
        ]);

        Bus::fake();

        $response = $this->withHeader('User-Agent', 'Mozilla/5.0 WeChirpCapacitorShell')
            ->post("/api/meetings/{$meeting->id}/intro/chunk?sync=1", [
                'chunk_index' => 0,
                'duration_seconds' => 6,
                'audio' => UploadedFile::fake()->create('intro.wav', 100, 'audio/wav'),
                'assistant_utterance' => 'My name is Ritik',
            ]);

        $response->assertOk();
        $response->assertJsonPath('intro_assistant_applied', true);
        $response->assertJsonPath('intro_assistant_rejected', false);

        Bus::assertDispatched(ProcessChunkJob::class, function (ProcessChunkJob $job) {
            return $job->mode === 'intro'
                && $job->assistantUtterance === 'My name is Ritik';
        });
    }

    public function test_sync_intro_heard_includes_assistant_when_cache_empty(): void
    {
        config(['services.wechirp.native_shell_ua_token' => 'WeChirpCapacitorShell']);

        $user = $this->actingApiUser();
        $meeting = Meeting::query()->create([
            'host_id' => $user->id,
            'title' => 'Intro test',
            'status' => 'pending',
        ]);

        Bus::fake();

        Cache::put('meeting_'.$meeting->id.'_stats', [
            'speaker_text' => [],
        ], now()->addHour());

        $response = $this->withHeader('User-Agent', 'Mozilla/5.0 WeChirpCapacitorShell')
            ->post("/api/meetings/{$meeting->id}/intro/chunk?sync=1", [
                'chunk_index' => 0,
                'duration_seconds' => 6,
                'audio' => UploadedFile::fake()->create('intro.wav', 100, 'audio/wav'),
                'assistant_utterance' => 'My name is Alex',
            ]);

        $response->assertOk();
        $response->assertJsonPath('intro_assistant_applied', true);
        $heard = $response->json('heard');
        $this->assertIsArray($heard);
        $this->assertNotEmpty($heard);
        $this->assertStringContainsString('Alex', (string) ($heard[0]['text'] ?? ''));
    }
}
