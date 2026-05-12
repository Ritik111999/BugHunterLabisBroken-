<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use App\Models\Meeting;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SystemController extends Controller
{
    public function dashboard()
    {
        $stats = [
            'users' => User::query()->count(),
            'meetings' => Meeting::query()->count(),
            'open_tickets' => ContactMessage::query()->where('status', 'open')->count(),
            'active_subs' => UserSubscription::query()->where('status', 'active')->count(),
        ];

        $recentMeetings = Meeting::query()->with('host')->orderByDesc('started_at')->limit(5)->get();
        $recentTickets = ContactMessage::query()->orderByDesc('id')->limit(5)->get();

        // Basic health indicators using existing tables (no external integrations).
        $failedJobs = DB::table('failed_jobs')->count();

        return view('admin.manage.system.dashboard', [
            'title' => 'Admin Dashboard',
            'stats' => $stats,
            'recentMeetings' => $recentMeetings,
            'recentTickets' => $recentTickets,
            'failedJobs' => $failedJobs,
        ]);
    }

    public function logs()
    {
        $path = storage_path('logs/laravel.log');
        $lines = [];

        if (is_file($path)) {
            // Read last ~300 lines without loading huge file into memory.
            $fp = fopen($path, 'r');
            if ($fp) {
                $buffer = '';
                $pos = -1;
                $lineCount = 0;
                $chunkSize = 4096;
                fseek($fp, 0, SEEK_END);
                $size = ftell($fp);

                while ($size + $pos > 0 && $lineCount < 300) {
                    $readSize = min($chunkSize, $size + $pos);
                    $pos -= $readSize;
                    fseek($fp, $pos, SEEK_END);
                    $chunk = fread($fp, $readSize);
                    $buffer = $chunk.$buffer;
                    $lineCount = substr_count($buffer, "\n");
                }

                fclose($fp);
                $lines = array_slice(preg_split("/\\r?\\n/", $buffer), -300);
            }
        }

        return view('admin.manage.system.logs', [
            'title' => 'System Logs',
            'logPath' => $path,
            'lines' => $lines,
        ]);
    }

    public function performance()
    {
        $now = now();
        $since24h = $now->copy()->subHours(24);

        $stats = [
            'users' => User::query()->count(),
            'meetings_24h' => Meeting::query()->where('created_at', '>=', $since24h)->count(),
            'meetings_total' => Meeting::query()->count(),
            'subs_24h' => UserSubscription::query()->where('created_at', '>=', $since24h)->count(),
            'subs_total' => UserSubscription::query()->count(),
            'open_tickets' => ContactMessage::query()->where('status', 'open')->count(),
            'failed_jobs' => DB::table('failed_jobs')->count(),
            'queued_jobs' => DB::table('jobs')->count(),
        ];

        $logPath = storage_path('logs/laravel.log');
        $logSize = is_file($logPath) ? filesize($logPath) : 0;

        return view('admin.manage.system.performance', [
            'title' => 'Performance',
            'stats' => $stats,
            'logSize' => $logSize,
            'since24h' => $since24h,
        ]);
    }
}

