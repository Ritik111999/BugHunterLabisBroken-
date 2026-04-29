<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessChunkJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        if ($sync) {
            try {
                ProcessChunkJob::dispatchSync(
                    meetingId: $id,
                    filePath: $filePath,
                    chunkIndex: $chunkIndex,
                    mode: 'intro',
                    durationSeconds: $durationSeconds,
                );
            } catch (\Throwable $e) {
                return response()->json([
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                    'meeting_id' => $id,
                    'chunk_index' => $chunkIndex,
                    'mode' => 'intro',
                ], 422);
            }

            return response()->json([
                'status' => 'completed',
                'meeting_id' => $id,
                'chunk_index' => $chunkIndex,
                'mode' => 'intro',
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

