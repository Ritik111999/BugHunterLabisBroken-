<?php

namespace App\Models;

use App\Models\Meeting;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MeetingAnalytic extends Model
{
    use HasFactory;

    protected $fillable = [
        'meeting_id',
        'crosstalk_percentage',
        'total_speakers',
        'keywords',
        'summary',
        'action_items',
        'sentiment',
        'insights',
    ];

    protected $casts = [
        'keywords' => 'array',
        'action_items' => 'array',
        'sentiment' => 'array',
        'insights' => 'array',
    ];

    public function meeting()
    {
        return $this->belongsTo(Meeting::class);
    }
}