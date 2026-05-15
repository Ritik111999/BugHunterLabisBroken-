<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Meeting;
use App\Models\MeetingAudio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MeetingController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $from = $request->query('from');
        $to = $request->query('to');

        $meetings = Meeting::query()
            ->with(['host'])
            ->when($q !== '', function ($query) use ($q) {
                $query->where('title', 'like', "%{$q}%")
                    ->orWhereHas('host', fn ($u) => $u->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%"));
            })
            ->when($from, fn ($query) => $query->whereDate('started_at', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('started_at', '<=', $to))
            ->orderByDesc('started_at')
            ->paginate(20)
            ->withQueryString();

        return view('admin.manage.meetings.index', [
            'title' => 'Meetings',
            'meetings' => $meetings,
            'q' => $q,
            'from' => $from,
            'to' => $to,
        ]);
    }

    public function show(Meeting $meeting)
    {
        $meeting->load([
            'host',
            'participants.user',
            'analytic',
            'participantStats.participant',
            'transcripts',
            'speakerSegments',
            'speakerMappings.participant.user',
            'audios',
        ]);

        return view('admin.manage.meetings.show', [
            'title' => 'Meeting',
            'meeting' => $meeting,
        ]);
    }

    public function exportTranscript(Meeting $meeting): StreamedResponse
    {
        $meeting->load(['host', 'transcripts']);

        $filename = "meeting-{$meeting->id}-transcript.txt";

        return response()->streamDownload(function () use ($meeting) {
            echo "Meeting #{$meeting->id}\n";
            echo "Title: ".($meeting->title ?? '')."\n";
            echo "Host: ".($meeting->host?->email ?? '')."\n";
            echo "Started: ".(optional($meeting->started_at)->toDateTimeString() ?? '')."\n";
            echo "Ended: ".(optional($meeting->ended_at)->toDateTimeString() ?? '')."\n";
            echo "\n--- Transcript ---\n\n";

            foreach ($meeting->transcripts as $t) {
                $start = $t->start_time ?? '';
                $end = $t->end_time ?? '';
                $text = (string) ($t->text ?? '');
                $prefix = ($start !== '' || $end !== '') ? "[{$start}-{$end}] " : '';
                echo $prefix.$text."\n";
            }
        }, $filename, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function downloadAudio(Meeting $meeting, MeetingAudio $audio)
    {
        if ((int) $audio->meeting_id !== (int) $meeting->id) {
            abort(404);
        }

        $path = (string) ($audio->file_path ?? '');
        if ($path === '') {
            abort(404);
        }

        // Common cases: stored relative to storage/app, sometimes prefixed with "storage/".
        $candidates = array_values(array_unique(array_filter([
            ltrim($path, '/'),
            ltrim(preg_replace('#^storage/#', '', $path), '/'),
        ])));

        foreach ($candidates as $candidate) {
            foreach (['meeting_audio', 'local', 's3'] as $diskName) {
                if (Storage::disk($diskName)->exists($candidate)) {
                    return Storage::disk($diskName)->download($candidate);
                }
            }
        }

        abort(404);
    }

    public function export(Request $request): StreamedResponse
    {
        // Export page (separate from download)
        $q = trim((string) $request->query('q', ''));
        $from = $request->query('from');
        $to = $request->query('to');

        return view('admin.manage.meetings.export', [
            'title' => 'Meeting export',
            'q' => $q,
            'from' => $from,
            'to' => $to,
        ]);
    }

    public function exportDownload(Request $request): StreamedResponse
    {
        $q = trim((string) $request->query('q', ''));
        $from = $request->query('from');
        $to = $request->query('to');

        $query = Meeting::query()
            ->with('host')
            ->when($q !== '', function ($query) use ($q) {
                $query->where('title', 'like', "%{$q}%")
                    ->orWhereHas('host', fn ($u) => $u->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%"));
            })
            ->when($from, fn ($query) => $query->whereDate('started_at', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('started_at', '<=', $to))
            ->orderByDesc('started_at');

        $filename = 'meetings-report.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['id', 'title', 'host_email', 'started_at', 'ended_at', 'duration', 'status']);

            $query->chunkById(500, function ($rows) use ($out) {
                foreach ($rows as $m) {
                    fputcsv($out, [
                        $m->id,
                        $m->title,
                        $m->host?->email,
                        optional($m->started_at)->toDateTimeString(),
                        optional($m->ended_at)->toDateTimeString(),
                        $m->duration,
                        $m->status,
                    ]);
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function exportAnalytics(Request $request): StreamedResponse
    {
        // Analytics export page (separate from download)
        $q = trim((string) $request->query('q', ''));
        $from = $request->query('from');
        $to = $request->query('to');

        return view('admin.manage.meetings.export-analytics', [
            'title' => 'Analytics export',
            'q' => $q,
            'from' => $from,
            'to' => $to,
        ]);
    }

    public function exportAnalyticsDownload(Request $request): StreamedResponse
    {
        $q = trim((string) $request->query('q', ''));
        $from = $request->query('from');
        $to = $request->query('to');

        $query = Meeting::query()
            ->with(['host', 'analytic'])
            ->when($q !== '', function ($query) use ($q) {
                $query->where('title', 'like', "%{$q}%")
                    ->orWhereHas('host', fn ($u) => $u->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%"));
            })
            ->when($from, fn ($query) => $query->whereDate('started_at', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('started_at', '<=', $to))
            ->orderByDesc('started_at');

        $filename = 'meeting-analytics-report.csv';

        $toScalar = function ($val): string {
            if ($val === null) {
                return '';
            }
            if (is_string($val) || is_numeric($val)) {
                return (string) $val;
            }
            return json_encode($val, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        };

        return response()->streamDownload(function () use ($query, $toScalar) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'meeting_id',
                'title',
                'host_email',
                'started_at',
                'ended_at',
                'status',
                'total_speakers',
                'crosstalk_percentage',
                'keywords',
                'summary',
                'action_items',
                'sentiment',
            ]);

            $query->chunkById(300, function ($rows) use ($out, $toScalar) {
                foreach ($rows as $m) {
                    $a = $m->analytic;
                    fputcsv($out, [
                        $m->id,
                        $m->title,
                        $m->host?->email,
                        optional($m->started_at)->toDateTimeString(),
                        optional($m->ended_at)->toDateTimeString(),
                        $m->status,
                        $a?->total_speakers,
                        $a?->crosstalk_percentage,
                        $toScalar($a?->keywords),
                        $toScalar($a?->summary),
                        $toScalar($a?->action_items),
                        $toScalar($a?->sentiment),
                    ]);
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function exportMeeting(Meeting $meeting): StreamedResponse
    {
        $meeting->load(['host', 'participants', 'audios', 'transcripts', 'analytic']);

        $filename = "meeting-{$meeting->id}.csv";

        return response()->streamDownload(function () use ($meeting) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'id',
                'title',
                'host_email',
                'started_at',
                'ended_at',
                'duration',
                'status',
                'participants_count',
                'audios_count',
                'transcripts_count',
                'total_speakers',
                'crosstalk_percentage',
            ]);

            $a = $meeting->analytic;
            fputcsv($out, [
                $meeting->id,
                $meeting->title,
                $meeting->host?->email,
                optional($meeting->started_at)->toDateTimeString(),
                optional($meeting->ended_at)->toDateTimeString(),
                $meeting->duration,
                $meeting->status,
                $meeting->participants->count(),
                $meeting->audios->count(),
                $meeting->transcripts->count(),
                $a?->total_speakers,
                $a?->crosstalk_percentage,
            ]);

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function exportMeetingAnalytics(Meeting $meeting): StreamedResponse
    {
        $meeting->load(['host', 'analytic']);

        $filename = "meeting-{$meeting->id}-analytics.csv";

        $toScalar = function ($val): string {
            if ($val === null) {
                return '';
            }
            if (is_string($val) || is_numeric($val)) {
                return (string) $val;
            }
            return json_encode($val, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        };

        return response()->streamDownload(function () use ($meeting, $toScalar) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'meeting_id',
                'title',
                'host_email',
                'started_at',
                'ended_at',
                'status',
                'total_speakers',
                'crosstalk_percentage',
                'keywords',
                'summary',
                'action_items',
                'sentiment',
            ]);

            $a = $meeting->analytic;
            fputcsv($out, [
                $meeting->id,
                $meeting->title,
                $meeting->host?->email,
                optional($meeting->started_at)->toDateTimeString(),
                optional($meeting->ended_at)->toDateTimeString(),
                $meeting->status,
                $a?->total_speakers,
                $a?->crosstalk_percentage,
                $toScalar($a?->keywords),
                $toScalar($a?->summary),
                $toScalar($a?->action_items),
                $toScalar($a?->sentiment),
            ]);

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function destroy(Meeting $meeting)
    {
        // Delete meeting and related rows via model relations (FK cascade may exist too).
        $meeting->participants()->delete();
        $meeting->audios()->delete();
        $meeting->transcripts()->delete();
        $meeting->speakerSegments()->delete();
        $meeting->speakerMappings()->delete();
        $meeting->participantStats()->delete();
        $meeting->analytic()->delete();
        $meeting->delete();

        return redirect()->route('admin.meetings.index')->with('status', 'Meeting deleted.');
    }
}

