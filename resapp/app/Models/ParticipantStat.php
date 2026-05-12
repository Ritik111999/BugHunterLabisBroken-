<?php

namespace App\Models;

use App\Models\Meeting;
use App\Models\MeetingParticipant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ParticipantStat extends Model
{
    use HasFactory;

    protected $fillable = [
        'meeting_id',
        'participant_id',
        'talk_time',
        'talk_percentage',
        'interruptions',
        'times_spoken',
    ];

    public function meeting()
    {
        return $this->belongsTo(Meeting::class);
    }

    public function participant()
    {
        return $this->belongsTo(MeetingParticipant::class, 'participant_id');
    }
}