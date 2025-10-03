<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class HashExistingPasswords extends Command
{
    protected $signature = 'users:hash-passwords {--force-no-check}';
    protected $description = 'Hash existing plaintext passwords if they are not hashed yet';

    public function handle()
    {
        $users = User::all();
        $count = 0;
        foreach ($users as $user) {
            $pw = $user->password;

            // heuristique simple pour détecter si password est deja hashé (bcrypt hashes commencent par $2y$ ou $2b$)
            if (! $this->isBcrypt($pw) || $this->option('force-no-check')) {
                // si tu es sûr que c'est en clair -> hash
                $user->password = Hash::make($pw);
                $user->save();
                $count++;
            }
        }

        $this->info("Processed: {$count} users.");
    }

    protected function isBcrypt($value)
    {
        return is_string($value) && (str_starts_with($value, '$2y$') || str_starts_with($value, '$2b$'));
    }
}
