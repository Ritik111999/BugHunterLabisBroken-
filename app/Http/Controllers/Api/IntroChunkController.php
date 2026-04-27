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
        ]);

        $chunkIndex = (int) $validated['chunk_index'];
        $sync = filter_var($request->query('sync', true), FILTER_VALIDATE_BOOL);

        $filePath = $this->storeChunkFile($request, $id, $chunkIndex);

        if ($sync) {
            try {
                (new ProcessChunkJob(
                    meetingId: $id,
                    filePath: $filePath,
                    chunkIndex: $chunkIndex,
                    mode: 'intro',
                ))->handle();

                return response()->json([
                    'status' => 'processed',
                    'meeting_id' => $id,
                    'chunk_index' => $chunkIndex,
                    'mode' => 'intro',
                ], 200);
            } catch (\Throwable $e) {
                return response()->json([
                    'status' => 'failed',
                    'meeting_id' => $id,
                    'chunk_index' => $chunkIndex,
                    'mode' => 'intro',
                    'error' => $e->getMessage(),
                ], 200);
            }
        }

        ProcessChunkJob::dispatch(
            meetingId: $id,
            filePath: $filePath,
            chunkIndex: $chunkIndex,
            mode: 'intro',
        )->onQueue('audio');

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

