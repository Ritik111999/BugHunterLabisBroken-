<?php

namespace App\Jobs;

use App\Models\Meeting;
use App\Services\MeetingSearchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class IndexMeetingSearchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $meetingId) {}

    public function handle(MeetingSearchService $search): void
    {
        $meeting = Meeting::query()->find($this->meetingId);
        if (! $meeting) {
            return;
        }
        $search->indexMeeting($meeting);
    }
}
