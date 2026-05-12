<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class AdminCreateUser extends Command
{
    protected $signature = 'admin:create-user {email} {password} {--name=Admin}';

    protected $description = 'Create (or update) an admin user for the web admin panel';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $password = (string) $this->argument('password');
        $name = (string) $this->option('name');

        $email = strtolower(trim($email));

        /** @var User $user */
        $user = User::withTrashed()->where('email', $email)->first();

        if (!$user) {
            $user = User::query()->create([
                'email' => $email,
                'name' => $name,
                'password' => Hash::make($password),
            ]);
        } else {
            if ($user->trashed()) {
                $user->restore();
            }
            $user->name = $name ?: $user->name;
            $user->password = Hash::make($password);
            $user->save();
        }

        $user->assignRole('admin');

        $this->info("Admin user ready: {$user->email}");

        return self::SUCCESS;
    }
}

