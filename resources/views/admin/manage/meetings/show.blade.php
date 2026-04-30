@extends('admin.layouts.app')

@section('content')
    @php
        $durationSeconds = null;
        if (is_numeric($meeting->duration) && (int) $meeting->duration > 0) {
            $durationSeconds = (int) $meeting->duration;
        } elseif ($meeting->started_at && $meeting->ended_at) {
            $durationSeconds = (int) $meeting->started_at->diffInSeconds($meeting->ended_at);
        }

        $formatDuration = function (?int $seconds): string {
            if (!$seconds || $seconds <= 0) {
                return '—';
            }
            $h = intdiv($seconds, 3600);
            $m = intdiv($seconds % 3600, 60);
            $s = $seconds % 60;
            return $h > 0
                ? sprintf('%02d:%02d:%02d', $h, $m, $s)
                : sprintf('%02d:%02d', $m, $s);
        };

        $normalizedMappings = $meeting->speakerMappings
            ->map(function ($m) {
                $label = (string) ($m->speaker_label ?? '');
                $norm = preg_replace('/^chunk\\d+_/', '', $label) ?? $label;
                return (object) [
                    'raw_label' => $label,
                    'speaker_label' => $norm,
                    'participant' => $m->participant,
                    'confidence' => $m->confidence,
                ];
            })
            ->groupBy(function ($m) {
                $pid = (int) ($m->participant?->id ?? 0);
                return $m->speaker_label . '|' . $pid;
            })
            ->map(function ($group) {
                // Keep the highest confidence row
                return $group->sortByDesc(fn ($x) => is_numeric($x->confidence) ? (float) $x->confidence : -INF)->first();
            })
            ->values();

        $segments = $meeting->speakerSegments;
        if ($segments->count() === 0) {
            // Derive segments from transcript lines like: "[speaker_0] hello ..."
            $segments = $meeting->transcripts
                ->filter(fn ($t) => !is_null($t->start_time) && !is_null($t->end_time))
                ->map(function ($t) {
                    $text = (string) ($t->text ?? '');
                    $label = null;
                    if (preg_match('/^\\s*\\[([^\\]]+)\\]\\s*/', $text, $m)) {
                        $label = trim((string) ($m[1] ?? ''));
                    }
                    return (object) [
                        'speaker_label' => $label !== '' ? $label : '—',
                        'start_time' => (float) $t->start_time,
                        'end_time' => (float) $t->end_time,
                        'is_overlap' => false,
                    ];
                })
                ->values();
        }
    @endphp

    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">Meeting #{{ $meeting->id }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $meeting->title ?: '—' }}</p>
        </div>

        <div class="flex flex-wrap gap-2">
            <a href="{{ route('admin.meetings.index') }}"
                class="inline-flex h-11 items-center justify-center rounded-lg border border-gray-200 bg-white px-5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-white/5">
                Back
            </a>
            <a href="{{ route('admin.meetings.export_one', $meeting) }}"
                class="inline-flex h-11 items-center justify-center rounded-lg bg-brand-500 px-5 text-sm font-medium text-white hover:bg-brand-600">
                Export meeting CSV
            </a>
            <a href="{{ route('admin.meetings.export_one_analytics', $meeting) }}"
                class="inline-flex h-11 items-center justify-center rounded-lg border border-gray-200 bg-white px-5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-white/5">
                Export analytics CSV
            </a>
            <form method="POST" action="{{ route('admin.meetings.destroy', $meeting) }}"
                onsubmit="return confirm('Delete this meeting and related data?');">
                @csrf
                @method('DELETE')
                <button type="submit"
                    class="inline-flex h-11 items-center justify-center rounded-lg border border-error-200 bg-white px-5 text-sm font-medium text-error-700 hover:bg-error-50 dark:border-error-500/30 dark:bg-gray-900 dark:text-error-300 dark:hover:bg-error-500/10">
                    Delete
                </button>
            </form>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
        <div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
            <div class="mb-3 text-sm font-medium text-gray-700 dark:text-gray-200">Details</div>
            <div class="space-y-2 text-sm">
                <div class="flex justify-between gap-3">
                    <span class="text-gray-500 dark:text-gray-400">Host</span>
                    <span class="font-medium text-gray-800 dark:text-white/90">{{ $meeting->host?->email ?: '—' }}</span>
                </div>
                <div class="flex justify-between gap-3">
                    <span class="text-gray-500 dark:text-gray-400">Started</span>
                    <span class="font-medium text-gray-800 dark:text-white/90">{{ optional($meeting->started_at)->toDateTimeString() ?: '—' }}</span>
                </div>
                <div class="flex justify-between gap-3">
                    <span class="text-gray-500 dark:text-gray-400">Ended</span>
                    <span class="font-medium text-gray-800 dark:text-white/90">{{ optional($meeting->ended_at)->toDateTimeString() ?: '—' }}</span>
                </div>
                <div class="flex justify-between gap-3">
                    <span class="text-gray-500 dark:text-gray-400">Duration</span>
                    <span class="font-medium text-gray-800 dark:text-white/90">{{ $formatDuration($durationSeconds) }}</span>
                </div>
                <div class="flex justify-between gap-3">
                    <span class="text-gray-500 dark:text-gray-400">Status</span>
                    <span class="font-medium text-gray-800 dark:text-white/90">{{ $meeting->status }}</span>
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
            <div class="mb-3 text-sm font-medium text-gray-700 dark:text-gray-200">Analytics</div>
            @if ($meeting->analytic)
                <div class="space-y-3 text-sm text-gray-700 dark:text-gray-200">
                    <div><span class="text-gray-500 dark:text-gray-400">Speakers:</span> {{ $meeting->analytic->total_speakers }}</div>
                    <div><span class="text-gray-500 dark:text-gray-400">Crosstalk %:</span> {{ $meeting->analytic->crosstalk_percentage }}</div>
                    <div class="whitespace-pre-wrap"><span class="text-gray-500 dark:text-gray-400">Summary:</span> {{ $meeting->analytic->summary }}</div>
                    <div class="whitespace-pre-wrap"><span class="text-gray-500 dark:text-gray-400">Action items:</span> {{ is_array($meeting->analytic->action_items) ? implode("\n", $meeting->analytic->action_items) : ($meeting->analytic->action_items ?? '—') }}</div>
                </div>
            @else
                <div class="text-sm text-gray-500 dark:text-gray-400">No analytics row found for this meeting.</div>
            @endif
        </div>
    </div>

    <div class="mt-6 rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
        <div class="mb-4 text-sm font-medium text-gray-700 dark:text-gray-200">Participants</div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="py-2 pr-4">Name</th>
                        <th class="py-2 pr-4">User</th>
                        <th class="py-2 pr-4">Talk %</th>
                        <th class="py-2 pr-4">Interruptions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($meeting->participants as $p)
                        @php($stat = $meeting->participantStats->firstWhere('participant_id', $p->id))
                        <tr>
                            <td class="py-3 pr-4 font-medium text-gray-800 dark:text-white/90">{{ $p->name }}</td>
                            <td class="py-3 pr-4 text-gray-700 dark:text-gray-200">{{ $p->user?->email ?: '—' }}</td>
                            <td class="py-3 pr-4 text-gray-700 dark:text-gray-200">{{ $stat?->talk_percentage ?? '—' }}</td>
                            <td class="py-3 pr-4 text-gray-700 dark:text-gray-200">{{ $stat?->interruptions ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-6 grid grid-cols-1 gap-5 lg:grid-cols-2">
        <div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
            <div class="mb-4 flex items-center justify-between">
                <div class="text-sm font-medium text-gray-700 dark:text-gray-200">Transcript</div>
                <a href="{{ route('admin.meetings.export_transcript', $meeting) }}"
                    class="inline-flex items-center rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white hover:bg-brand-600">
                    Download TXT
                </a>
            </div>

            <div class="text-sm text-gray-500 dark:text-gray-400">
                Entries: <span class="font-medium text-gray-800 dark:text-white/90">{{ $meeting->transcripts->count() }}</span>
            </div>

            <div class="mt-4 max-h-[360px] overflow-auto rounded-lg border border-gray-200 bg-gray-50 p-4 text-xs text-gray-700 dark:border-gray-800 dark:bg-white/5 dark:text-gray-200">
                @forelse ($meeting->transcripts->take(80) as $t)
                    <div class="mb-2 whitespace-pre-wrap">
                        @if(!is_null($t->start_time) || !is_null($t->end_time))
                            <span class="text-gray-500 dark:text-gray-400">[{{ $t->start_time }}-{{ $t->end_time }}]</span>
                        @endif
                        {{ $t->text }}
                    </div>
                @empty
                    <div>No transcript entries found.</div>
                @endforelse
                @if ($meeting->transcripts->count() > 80)
                    <div class="mt-3 text-gray-500 dark:text-gray-400">Showing first 80 entries. Use “Download TXT” for full transcript.</div>
                @endif
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
            <div class="mb-4 text-sm font-medium text-gray-700 dark:text-gray-200">Audio files</div>
            <div class="space-y-3">
                @forelse ($meeting->audios as $audio)
                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 px-4 py-3 dark:border-gray-800">
                        <div class="text-sm text-gray-700 dark:text-gray-200">
                            <div class="font-medium text-gray-800 dark:text-white/90">Audio #{{ $audio->id }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">{{ $audio->file_path }}</div>
                        </div>
                        <a href="{{ route('admin.meetings.download_audio', [$meeting, $audio]) }}"
                            class="inline-flex items-center rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-white/5">
                            Download
                        </a>
                    </div>
                @empty
                    <div class="text-sm text-gray-500 dark:text-gray-400">No audio files found.</div>
                @endforelse
            </div>
        </div>
    </div>

    <div class="mt-6 grid grid-cols-1 gap-5 lg:grid-cols-2">
        <div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
            <div class="mb-4 text-sm font-medium text-gray-700 dark:text-gray-200">Speaker mappings</div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="py-2 pr-4">Label</th>
                            <th class="py-2 pr-4">Participant</th>
                            <th class="py-2 pr-4">User</th>
                            <th class="py-2 pr-4">Confidence</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($normalizedMappings as $m)
                            <tr>
                                <td class="py-3 pr-4 font-medium text-gray-800 dark:text-white/90">{{ $m->speaker_label }}</td>
                                <td class="py-3 pr-4 text-gray-700 dark:text-gray-200">{{ $m->participant?->name ?: '—' }}</td>
                                <td class="py-3 pr-4 text-gray-700 dark:text-gray-200">{{ $m->participant?->user?->email ?: '—' }}</td>
                                <td class="py-3 pr-4 text-gray-700 dark:text-gray-200">{{ $m->confidence ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td class="py-3 text-gray-500 dark:text-gray-400" colspan="4">No speaker mappings.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
            <div class="mb-4 text-sm font-medium text-gray-700 dark:text-gray-200">Speaker segments</div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="py-2 pr-4">Label</th>
                            <th class="py-2 pr-4">Start</th>
                            <th class="py-2 pr-4">End</th>
                            <th class="py-2 pr-4">Overlap</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($segments->take(200) as $s)
                            <tr>
                                <td class="py-3 pr-4 font-medium text-gray-800 dark:text-white/90">{{ $s->speaker_label }}</td>
                                <td class="py-3 pr-4 text-gray-700 dark:text-gray-200">{{ $s->start_time }}</td>
                                <td class="py-3 pr-4 text-gray-700 dark:text-gray-200">{{ $s->end_time }}</td>
                                <td class="py-3 pr-4 text-gray-700 dark:text-gray-200">{{ $s->is_overlap ? 'yes' : 'no' }}</td>
                            </tr>
                        @empty
                            <tr><td class="py-3 text-gray-500 dark:text-gray-400" colspan="4">No speaker segments.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($segments->count() > 200)
                <div class="mt-3 text-sm text-gray-500 dark:text-gray-400">Showing first 200 segments.</div>
            @endif
        </div>
    </div>
@endsection

