<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('roles') || !Schema::hasTable('role_user') || !Schema::hasTable('users')) {
            return;
        }

        $admin = Role::query()->firstOrCreate(['name' => 'admin']);
        $user = Role::query()->firstOrCreate(['name' => 'user']);

        // Assign all existing users the default "user" role (if they don't have any roles yet).
        // Note: User may use SoftDeletes; `deleted_at` is added in a later migration — avoid that scope here.
        User::query()
            ->withoutGlobalScopes()
            ->whereDoesntHave('roles')
            ->chunkById(200, function ($users) use ($user) {
                foreach ($users as $u) {
                    $u->roles()->syncWithoutDetaching([$user->id]);
                }
            });

        // Avoid lockout: if nobody has admin yet, give it to the earliest user.
        $hasAdmin = User::query()
            ->withoutGlobalScopes()
            ->whereHas('roles', fn ($q) => $q->where('name', 'admin'))
            ->exists();
        if (!$hasAdmin) {
            $firstUser = User::query()->withoutGlobalScopes()->orderBy('id')->first();
            if ($firstUser) {
                $firstUser->roles()->syncWithoutDetaching([$admin->id]);
            }
        }
    }

    public function down(): void
    {
        // no-op (role assignments are data, not schema)
    }
};

