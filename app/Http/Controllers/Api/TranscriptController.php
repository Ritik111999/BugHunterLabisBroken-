<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Meeting;
use App\Models\Transcript;

class TranscriptController extends Controller
{
    public function list(int $id)
    {
        $meeting = Meeting::query()
            ->whereKey($id)
            ->where('host_id', auth()->id())
            ->firstOrFail();

        $items = Transcript::query()
            ->where('meeting_id', $meeting->id)
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (Transcript $t) => [
                'id' => (int) $t->id,
                'text' => (string) $t->text,
                'start_time' => (float) $t->start_time,
                'end_time' => (float) $t->end_time,
                'created_at' => $t->created_at?->toISOString(),
            ])
            ->values();

        return response()->json([
            'meeting_id' => (int) $meeting->id,
            'items' => $items,
        ]);
    }
}

