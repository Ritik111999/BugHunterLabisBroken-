<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MeetingStatsUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $meetingId;

    /** @var array<int,array{name:string,percentage:int}> */
    public array $participants;

    public int $crosstalkPercentage;

    /**
     * @param array<int,array{name:string,percentage:int}> $participants
     */
    public function __construct(int $meetingId, array $participants, int $crosstalkPercentage)
    {
        $this->meetingId = $meetingId;
        $this->participants = $participants;
        $this->crosstalkPercentage = $crosstalkPercentage;
    }

    public function broadcastOn(): Channel
    {
        return new Channel("meeting.{$this->meetingId}");
    }

    public function broadcastAs(): string
    {
        return 'stats.updated';
    }

    /**
     * Payload shape required by the client.
     *
     * @return array{participants:array<int,array{name:string,percentage:int}>,crosstalk:int}
     */
    public function broadcastWith(): array
    {
        return [
            'participants' => $this->participants,
            'crosstalk' => $this->crosstalkPercentage,
        ];
    }
}

