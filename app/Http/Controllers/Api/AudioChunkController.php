<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessChunkJob;
use App\Support\MeetingAudioInputProfile;
use App\Support\MeetingAudioStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AudioChunkController extends Controller
{
    /**
     * POST /api/audio/chunk
     *
     * Meeting-mode chunks: runs the same Python analyzer + stats pipeline as intro chunks.
     * Use ?sync=1 (Studio HTTP mic path) to process inline; otherwise queued on the audio queue.
     */
    public function storeChunk(Request $request): JsonResponse
    {
        $validated = $this->validateChunkRequest($request);

        $meetingId = (int) $validated['meeting_id'];
        $chunkIndex = (int) $validated['chunk_index'];
        $durationSeconds = isset($validated['duration_seconds']) ? (float) $validated['duration_seconds'] : null;
        $audioInputProfile = MeetingAudioInputProfile::normalize($validated['audio_input_profile'] ?? null);
        $sync = filter_var($request->query('sync', false), FILTER_VALIDATE_BOOL);

        $filePath = null;
        if ($request->hasFile('audio')) {
            $filePath = $this->storeChunkFile($request, $meetingId, $chunkIndex);
        }

        if ($sync) {
            try {
                ProcessChunkJob::dispatchSync(
                    meetingId: $meetingId,
                    filePath: $filePath,
                    chunkIndex: $chunkIndex,
                    mode: 'meeting',
                    durationSeconds: $durationSeconds,
                    audioInputProfile: $audioInputProfile,
                );
            } catch (\Throwable $e) {
                Log::warning('audio_chunk_sync_failed', [
                    'meeting_id' => $meetingId,
                    'chunk_index' => $chunkIndex,
                    'message' => $e->getMessage(),
                ]);

                return response()->json([
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                    'meeting_id' => $meetingId,
                    'chunk_index' => $chunkIndex,
                ], 422);
            }

            return response()->json([
                'status' => 'completed',
                'meeting_id' => $meetingId,
                'chunk_index' => $chunkIndex,
            ], 200);
        }

        ProcessChunkJob::dispatch(
            meetingId: $meetingId,
            filePath: $filePath,
            chunkIndex: $chunkIndex,
            mode: 'meeting',
            durationSeconds: $durationSeconds,
            audioInputProfile: $audioInputProfile,
        )->onQueue((string) config('wechirp.queue.audio', 'audio'));

        return response()->json([
            'status' => 'queued',
            'meeting_id' => $meetingId,
            'chunk_index' => $chunkIndex,
        ], 202);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validateChunkRequest(Request $request): array
    {
        return $request->validate([
            'meeting_id' => ['required', 'integer', 'min:1'],
            'chunk_index' => ['required', 'integer', 'min:0'],
            'audio' => ['sometimes', 'file', 'max:51200'],
            'duration_seconds' => ['nullable', 'numeric', 'min:0.5', 'max:120'],
            'audio_input_profile' => ['nullable', 'string', 'max:32'],
        ]);
    }

    private function storeChunkFile(Request $request, int $meetingId, int $chunkIndex): string
    {
        $file = $request->file('audio');
        $extension = $file->getClientOriginalExtension();
        $extension = $extension !== '' ? $extension : 'bin';
        $filename = "chunk_{$chunkIndex}.".$extension;

        return MeetingAudioStorage::putUploadedChunk($file, $meetingId, $filename);
    }
}
