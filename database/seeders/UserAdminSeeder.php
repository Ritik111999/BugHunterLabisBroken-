<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserAdminSeeder extends Seeder
{
    /**
     * Default panel login (change after first production deploy):
     * - Admin: admin@wechirp.local / admin12345
     * - Demo user: user@wechirp.local / user12345
     *
     * Re-run to reset passwords: php artisan db:seed --class=UserAdminSeeder
     */
    public function run(): void
    {
        // Normal user
        $this->upsertUser('user@wechirp.local', 'WeChirp User', 'user12345');

        // Admin user (must have admin role for /admin middleware)
        $admin = $this->upsertUser('admin@wechirp.local', 'WeChirp Admin', 'admin12345');
        $admin->assignRole('admin');
    }

    private function upsertUser(string $email, string $name, string $plainPassword): User
    {
        $email = strtolower($email);

        $user = User::withTrashed()->where('email', $email)->first();
        if ($user && $user->trashed()) {
            $user->restore();
        }

        if (!$user) {
            $user = User::query()->create([
                'email' => $email,
                'name' => $name,
                'password' => Hash::make($plainPassword),
            ]);

            return $user;
        }

        $user->name = $name;
        $user->password = Hash::make($plainPassword);
        $user->save();

        return $user;
    }
}

