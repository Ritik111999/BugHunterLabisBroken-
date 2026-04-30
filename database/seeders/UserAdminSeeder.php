<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserAdminSeeder extends Seeder
{
    public function run(): void
    {
        // Normal user
        User::query()->updateOrCreate(
            ['email' => 'user@wechirp.local'],
            [
                'name' => 'WeChirp User',
                'password' => Hash::make('user12345'),
            ]
        );

        // Admin user
        $admin = User::query()->updateOrCreate(
            ['email' => 'admin@wechirp.local'],
            [
                'name' => 'WeChirp Admin',
                'password' => Hash::make('admin12345'),
            ]
        );

        // Ensure admin role is attached for /admin access
        $admin->assignRole('admin');
    }
}

