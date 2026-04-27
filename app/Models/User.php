<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];
   public function getAvatarAttribute($value)
    {
        if (!$value) {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_URL)) {
            return $value;
        }

        return asset('storage/' . $value);
    }
    public function hostedMeetings()
{
    return $this->hasMany(Meeting::class, 'host_id');
}

public function meetingParticipants()
{
    return $this->hasMany(MeetingParticipant::class);
}
}
