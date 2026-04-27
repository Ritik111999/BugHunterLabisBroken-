<?php

namespace App\Models;

use App\Models\Meeting;
use App\Models\ParticipantStat;
use App\Models\SpeakerMapping;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MeetingParticipant extends Model
{
    use HasFactory;

    protected $fillable = [
        'meeting_id',
        'user_id',
        'name',
        'voice_embedding',
    ];

    protected $casts = [
        'voice_embedding' => 'array',
        'participants_count' => 'integer',
    ];
public function participants()
    {
        return $this->hasMany(MeetingParticipant::class, 'meeting_id');
    }
    public function meeting()
    {
        return $this->belongsTo(Meeting::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function stats()
    {
        return $this->hasOne(ParticipantStat::class, 'participant_id');
    }

    public function mappings()
    {
        return $this->hasMany(SpeakerMapping::class, 'participant_id');
    }
}