<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MeetingSearchService;
use Illuminate\Http\Request;

class MeetingSearchController extends Controller
{
    public function search(Request $request, MeetingSearchService $search)
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:500'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);

        $results = $search->search(
            $request->user(),
            (string) $validated['q'],
            (int) ($validated['limit'] ?? 12),
        );

        return response()->json([
            'query' => $validated['q'],
            'results' => $results,
        ]);
    }
}
