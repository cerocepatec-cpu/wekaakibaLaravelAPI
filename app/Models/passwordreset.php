<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Carbon\Carbon;

class PasswordReset extends Model
{
    use HasFactory;

    protected $table = "password_resets";

    protected $fillable = [
        'email',
        'token',
        'code',
        'created_at',
    ];

    public $timestamps = false;

    // 🚨 Ajoute ces deux lignes essentielles :
    protected $primaryKey = null;
    public $incrementing = false;

    /**
     * Vérifie si le code est expiré
     */
    public function isExpired($minutes = 15)
    {
        return $this->created_at < now()->subMinutes($minutes);
    }

    /**
     * Génère un OTP sécurisé et l'enregistre dans la table
     */
    public static function generateOTP($email, $minutes = 15)
    {
        // Supprime les anciens OTP expirés pour cet email
        self::where('email', $email)
            ->where('created_at', '<', Carbon::now()->subMinutes($minutes))
            ->delete();

        // Génération d'un code unique
        do {
            $code = rand(100000, 999999);
            $exists = self::where('code', $code)
                ->where('created_at', '>=', Carbon::now()->subMinutes($minutes))
                ->exists();
        } while ($exists);

        // Génération du token sécurisé
        $token = bcrypt(Str::random(60));

        // Stockage : suppression puis insertion
        $record = self::where('email', $email)->first();

        if ($record) {
            $record->delete();
        }

        $record = self::create([
            'email' => $email,
            'code' => $code,
            'token' => $token,
            'created_at' => now(),
        ]);

        return $record;
    }
}
