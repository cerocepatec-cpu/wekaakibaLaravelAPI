<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class HashPins extends Command
{
    protected $signature = 'users:hash-pins';
    protected $description = 'Hache tous les PIN non hachés des utilisateurs';

    public function handle()
    {
        $users = User::all();
        $count = 0;

        foreach ($users as $user) {
            $pin = $user->pin;

            // Vérifie que le PIN est défini et qu’il n’est pas déjà haché
            if (!empty($pin) && !$this->isHashed($pin)) {
                $user->pin = Hash::make($pin);
                $user->save();
                $count++;
            }
        }

        $this->info("✅ Hachage terminé : $count PIN(s) mis à jour.");
    }

    /**
     * Détermine si une valeur est déjà un hash bcrypt valide.
     */
    private function isHashed($value)
    {
        // Un hash bcrypt fait toujours 60 caractères et commence par $2y$ ou $2a$
        return preg_match('/^\$2[ayb]\$.{56}$/', $value);
    }
}
