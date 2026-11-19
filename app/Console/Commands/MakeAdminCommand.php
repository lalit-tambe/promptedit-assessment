<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class MakeAdminCommand extends Command
{
    protected $signature = 'user:make-admin {email : The email address of the user to make an admin}';

    protected $description = 'Grant admin privileges (is_admin = true) to a user by email address.';

    public function handle(): int
    {
        $email = trim((string) $this->argument('email'));

        $user = User::query()
            ->where('email', $email)
            ->first();

        if ($user === null) {
            $this->error("User with email [{$email}] not found.");

            return self::FAILURE;
        }

        if ($user->is_admin) {
            $this->info("User [{$user->email}] is already an admin.");

            return self::SUCCESS;
        }

        $user->is_admin = true;
        $user->save();

        $this->info("User [{$user->email}] is now an admin.");

        return self::SUCCESS;
    }
}
