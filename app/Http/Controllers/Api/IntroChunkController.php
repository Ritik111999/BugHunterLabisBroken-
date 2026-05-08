<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessChunkJob;
use App\Models\MeetingParticipant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class IntroChunkController extends Controller
{
    /**
     * POST /api/meetings/{id}/intro/chunk
     *
     * Intro enrollment chunk: creates participants ONLY when a real name is detected.
     */
    public function store(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'chunk_index' => ['required', 'integer', 'min:0'],
            'audio' => ['required', 'file', 'max:51200'],
            'duration_seconds' => ['nullable', 'numeric', 'min:1', 'max:60'],
        ]);

        $chunkIndex = (int) $validated['chunk_index'];
        $durationSeconds = isset($validated['duration_seconds']) ? (float) $validated['duration_seconds'] : null;
        // Default async: intro analysis needs Deepgram + ffmpeg + optional SpeechBrain.
        // Use ?sync=1 from the demo (or tooling) to run inline without a queue worker.
        $sync = filter_var($request->query('sync', false), FILTER_VALIDATE_BOOL);

        $filePath = $this->storeChunkFile($request, $id, $chunkIndex);
        Log::info('intro_chunk_received', [
            'meeting_id' => $id,
            'chunk_index' => $chunkIndex,
            'sync' => (bool) $sync,
            'duration_seconds' => $durationSeconds,
            'file_path' => $filePath,
            'size_bytes' => (int) ($request->file('audio')?->getSize() ?? 0),
            'ext' => (string) ($request->file('audio')?->getClientOriginalExtension() ?? ''),
        ]);

        if ($sync) {
            $startedAt = now();
            try {
                ProcessChunkJob::dispatchSync(
                    meetingId: $id,
                    filePath: $filePath,
                    chunkIndex: $chunkIndex,
                    mode: 'intro',
                    durationSeconds: $durationSeconds,
                );
            } catch (\Throwable $e) {
                Log::warning('intro_chunk_failed', [
                    'meeting_id' => $id,
                    'chunk_index' => $chunkIndex,
                    'sync' => (bool) $sync,
                    'message' => $e->getMessage(),
                ]);
                return response()->json([
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                    'meeting_id' => $id,
                    'chunk_index' => $chunkIndex,
                    'mode' => 'intro',
                ], 422);
            }

            // Return newly enrolled participants so the client UI can update instantly.
            // We consider "enrolled" any participant updated/created by intro enrollment.
            $enrolled = MeetingParticipant::query()
                ->where('meeting_id', $id)
                ->where(function ($q) use ($startedAt) {
                    $q->where('created_at', '>=', $startedAt->copy()->subSeconds(2))
                        ->orWhere('updated_at', '>=', $startedAt->copy()->subSeconds(2));
                })
                ->get(['id', 'name', 'voice_embedding'])
                ->values()
                ->map(fn ($p) => [
                    'id' => (int) $p->id,
                    'name' => (string) $p->name,
                    'voice_embedding' => $p->voice_embedding,
                ]);

            return response()->json([
                'status' => 'completed',
                'meeting_id' => $id,
                'chunk_index' => $chunkIndex,
                'mode' => 'intro',
                'enrolled_participants' => $enrolled,
            ], 200);
        }

        ProcessChunkJob::dispatch(
            meetingId: $id,
            filePath: $filePath,
            chunkIndex: $chunkIndex,
            mode: 'intro',
            durationSeconds: $durationSeconds,
        )->onQueue('default');

        return response()->json([
            'status' => 'queued',
            'meeting_id' => $id,
            'chunk_index' => $chunkIndex,
            'mode' => 'intro',
        ], 202);
    }

    private function storeChunkFile(Request $request, int $meetingId, int $chunkIndex): string
    {
        $file = $request->file('audio');

        $extension = $file->getClientOriginalExtension();
        $extension = $extension !== '' ? $extension : 'bin';

        $directory = "meeting_chunks/{$meetingId}";
        $filename = "intro_{$chunkIndex}." . $extension;

        return Storage::putFileAs($directory, $file, $filename);
    }
}

