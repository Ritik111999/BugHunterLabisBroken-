<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessAudioChunkJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AudioChunkController extends Controller
{
    /**
     * POST /api/audio/chunk
     *
     * Accepts small audio chunks (3–5s) and dispatches async processing.
     */
    public function storeChunk(Request $request): JsonResponse
    {
        $validated = $this->validateChunkRequest($request);

        $meetingId = (int) $validated['meeting_id'];
        $chunkIndex = (int) $validated['chunk_index'];

        $filePath = null;
        if ($request->hasFile('audio')) {
            $filePath = $this->storeChunkFile($request, $meetingId, $chunkIndex);
        }

        ProcessAudioChunkJob::dispatch(
            meetingId: $meetingId,
            filePath: $filePath,
            chunkIndex: $chunkIndex,
        )->onQueue('default');

        return response()->json([
            'status' => 'queued',
            'meeting_id' => $meetingId,
            'chunk_index' => $chunkIndex,
        ], 202);
    }

    /**
     * @return array{meeting_id:int,chunk_index:int}
     * @throws ValidationException
     */
    private function validateChunkRequest(Request $request): array
    {
        return $request->validate([
            'meeting_id' => ['required', 'integer', 'min:1'],
            'chunk_index' => ['required', 'integer', 'min:0'],
            // Friday behavior: audio optional for now
            'audio' => ['sometimes', 'file', 'max:51200'],
        ]);
    }

    private function storeChunkFile(Request $request, int $meetingId, int $chunkIndex): string
    {
        $file = $request->file('audio');

        $extension = $file->getClientOriginalExtension();
        $extension = $extension !== '' ? $extension : 'bin';

        $directory = "meeting_chunks/{$meetingId}";
        $filename = "chunk_{$chunkIndex}." . $extension;

        return Storage::putFileAs($directory, $file, $filename);
    }
}