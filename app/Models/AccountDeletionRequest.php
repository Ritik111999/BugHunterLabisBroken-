<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountDeletionRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const SOURCE_APP = 'app';

    public const SOURCE_WEB = 'web';

    protected $fillable = [
        'user_id',
        'source',
        'email',
        'phone',
        'name',
        'reason',
        'status',
        'resolved_at',
        'resolved_by',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public static function hasPendingForUser(User $user): bool
    {
        $email = strtolower((string) $user->email);

        return static::query()
            ->pending()
            ->where(function ($q) use ($user, $email) {
                $q->where('user_id', $user->id)
                    ->orWhere('email', $email);
            })
            ->exists();
    }
}
