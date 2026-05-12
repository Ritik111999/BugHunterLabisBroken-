<?php

namespace App\Services;

use App\Models\AccountDeletionRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class AccountDeletionService
{
    public function hasPendingForUserOrEmail(User $user): bool
    {
        $email = strtolower($user->email);

        return AccountDeletionRequest::query()
            ->pending()
            ->where(function ($q) use ($user, $email) {
                $q->where('user_id', $user->id)
                    ->orWhere('email', $email);
            })
            ->exists();
    }

    public function hasPendingForEmail(string $email, ?int $userId = null): bool
    {
        $email = strtolower($email);

        return AccountDeletionRequest::query()
            ->pending()
            ->where(function ($q) use ($email, $userId) {
                $q->where('email', $email);
                if ($userId !== null) {
                    $q->orWhere('user_id', $userId);
                }
            })
            ->exists();
    }

    public function createAppRequest(User $user, ?string $reason): AccountDeletionRequest
    {
        if ($this->hasPendingForUserOrEmail($user)) {
            throw new RuntimeException('A pending account deletion request already exists.');
        }

        $reason = $this->sanitizeOptionalText($reason);

        $request = AccountDeletionRequest::create([
            'user_id' => $user->id,
            'source' => AccountDeletionRequest::SOURCE_APP,
            'email' => strtolower($user->email),
            'phone' => $user->phone,
            'name' => $user->name,
            'reason' => $reason,
            'status' => AccountDeletionRequest::STATUS_PENDING,
        ]);

        $user->tokens()->delete();

        return $request;
    }

    /**
     * @param  array{name: string, email: string, phone?: string|null, reason?: string|null}  $data
     */
    public function createWebRequest(array $data): AccountDeletionRequest
    {
        $email = strtolower(trim($data['email']));

        $user = User::query()->where('email', $email)->first();

        if (!$user) {
            throw new RuntimeException('We could not find an active account with this email address.');
        }

        if ($this->hasPendingForEmail($email, $user->id)) {
            throw new RuntimeException('A pending deletion request for this email already exists.');
        }

        $request = AccountDeletionRequest::create([
            'user_id' => $user->id,
            'source' => AccountDeletionRequest::SOURCE_WEB,
            'email' => $email,
            'phone' => $this->sanitizeOptionalText($data['phone'] ?? null),
            'name' => $this->sanitizeName($data['name']),
            'reason' => $this->sanitizeOptionalText($data['reason'] ?? null),
            'status' => AccountDeletionRequest::STATUS_PENDING,
        ]);

        $user->tokens()->delete();

        return $request;
    }

    public function cancelPendingForUser(User $user): bool
    {
        $request = AccountDeletionRequest::query()
            ->where('user_id', $user->id)
            ->pending()
            ->orderByDesc('id')
            ->first();

        if (!$request) {
            return false;
        }

        $request->update([
            'status' => AccountDeletionRequest::STATUS_CANCELLED,
            'resolved_at' => now(),
            'resolved_by' => null,
        ]);

        return true;
    }

    /**
     * @return array{status: string, requested_at: ?string, source: ?string, message: string}
     */
    public function statusPayloadForUser(User $user): array
    {
        $latest = AccountDeletionRequest::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->first();

        if (!$latest) {
            return [
                'status' => 'none',
                'requested_at' => null,
                'source' => null,
                'message' => 'No account deletion request found.',
            ];
        }

        return [
            'status' => $latest->status,
            'requested_at' => $latest->created_at?->toIso8601String(),
            'source' => $latest->source,
            'message' => 'Deletion request status retrieved.',
        ];
    }

    public function approve(AccountDeletionRequest $deletionRequest, User $admin): void
    {
        if (!$deletionRequest->isPending()) {
            throw new RuntimeException('Only pending requests can be approved.');
        }

        DB::transaction(function () use ($deletionRequest, $admin) {
            $user = $this->resolveTargetUser($deletionRequest);

            if ($user && !$user->trashed()) {
                $this->softDeleteUserAccount($user);
            }

            $deletionRequest->update([
                'status' => AccountDeletionRequest::STATUS_APPROVED,
                'resolved_at' => now(),
                'resolved_by' => $admin->id,
            ]);
        });
    }

    public function reject(AccountDeletionRequest $deletionRequest, User $admin): void
    {
        if (!$deletionRequest->isPending()) {
            throw new RuntimeException('Only pending requests can be rejected.');
        }

        $deletionRequest->update([
            'status' => AccountDeletionRequest::STATUS_REJECTED,
            'resolved_at' => now(),
            'resolved_by' => $admin->id,
        ]);
    }

    /**
     * Admin "Delete user": same outcome as approve while pending; if already approved, only removes
     * the account when a matching user still exists (recovery path).
     */
    public function deleteUserForRequest(AccountDeletionRequest $deletionRequest, User $admin): void
    {
        if ($deletionRequest->isPending()) {
            $this->approve($deletionRequest, $admin);

            return;
        }

        if ($deletionRequest->status !== AccountDeletionRequest::STATUS_APPROVED) {
            throw new RuntimeException('Request must be pending or approved to delete the user.');
        }

        $user = $this->resolveTargetUser($deletionRequest);

        if ($user && !$user->trashed()) {
            DB::transaction(function () use ($user) {
                $this->softDeleteUserAccount($user);
            });
        }
    }

    private function resolveTargetUser(AccountDeletionRequest $deletionRequest): ?User
    {
        if ($deletionRequest->user_id) {
            return User::query()->find($deletionRequest->user_id);
        }

        return User::query()->where('email', $deletionRequest->email)->first();
    }

    private function softDeleteUserAccount(User $user): void
    {
        $user->tokens()->delete();
        DB::table('sessions')->where('user_id', $user->id)->delete();

        $user->name = 'Deleted user';
        $user->phone = null;
        $user->email = 'deleted.'.$user->id.'.'.Str::lower(Str::random(12)).'@users.invalid';
        $user->status = 'deleted';
        $user->save();

        $user->delete();
    }

    private function sanitizeOptionalText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim(strip_tags($value));

        return $value === '' ? null : $value;
    }

    private function sanitizeName(string $name): string
    {
        $name = trim(strip_tags($name));

        return $name === '' ? 'Unknown' : $name;
    }
}
