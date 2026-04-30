<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\Role;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Models\UserSubscription;
use App\Models\SubscriptionPlan;

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

public function subscriptions()
{
    return $this->hasMany(UserSubscription::class);
}

public function activeSubscription()
{
    return $this->hasOne(UserSubscription::class)
        ->where('status', 'active')
        ->where('expires_at', '>', now())
        ->orderByDesc('expires_at');
}

public function currentPlan(): ?SubscriptionPlan
{
    $sub = $this->activeSubscription()->with('plan')->first();
    if ($sub && $sub->plan) {
        return $sub->plan;
    }
    return SubscriptionPlan::query()->where('code', 'basic')->first();
}

public function roles()
{
    return $this->belongsToMany(Role::class);
}

public function hasRole(string $name): bool
{
    return $this->roles()->where('name', $name)->exists();
}

public function assignRole(string $name): void
{
    $role = Role::query()->firstOrCreate(['name' => $name]);
    $this->roles()->syncWithoutDetaching([$role->id]);
}
}
