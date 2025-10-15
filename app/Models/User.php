<?php

namespace App\Models;

use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use App\Models\MobileMoneyProviders;

class User extends Authenticatable implements CanResetPassword
{
    use HasFactory, HasApiTokens, Notifiable, HasRoles;

    /**
     * Champs remplissables
     */
    protected $fillable = [
        'user_name',
        'email',
        'email_verified_at',
        'user_phone',
        'password',
        'user_type',
        'status',
        'note',
        'avatar',
        'uuid',
        'full_name',
        'pin',
        'collector',
        'sponsored_by',
        'collection_percentage',
        'mobile_access',
        'can_withdraw_on_mobile',
        'can_withdraw_by_agent',
        'adress',
    ];

    /**
     * Champs cachés à la sérialisation
     */
    protected $hidden = [
        'password',
        'remember_token',
        'email_verified_at',
        'laravel_through_key',
        'pin',
    ];

    /**
     * Casts
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    /**
     * Attributs calculés ajoutés automatiquement à JSON
     */
    protected $appends = ['pin_set', 'weak_pin'];

    // =====================================================
    // 🔐 ATTRIBUTS DÉRIVÉS : DÉTECTION DU PIN
    // =====================================================

    /**
     * Indique si l’utilisateur a un PIN configuré
     */
    public function getPinSetAttribute(): bool
    {
        $pin = $this->attributes['pin'] ?? null;

        // Un hash bcrypt fait généralement 60 caractères
        // Si c’est vide ou trop court, PIN non configuré
        return !empty($pin) && strlen($pin) > 20;
    }

    /**
     * Indique si le PIN est potentiellement faible
     */
    public function getWeakPinAttribute(): bool
    {
        $pin = $this->attributes['pin'] ?? null;

        // Si pas de PIN, pas de faiblesse à signaler
        if (empty($pin)) {
            return false;
        }

        // Si le PIN semble stocké en clair (ex: "0000" ou "1234")
        // → la longueur du champ est trop courte pour un hash bcrypt
        return strlen($pin) < 30;
    }

    // =====================================================
    // 🔗 RELATIONS
    // =====================================================

    public function tokens()
    {
        return $this->morphMany(PersonalAccessToken::class, 'tokenable');
    }

    public function usersenterprise()
    {
        return $this->hasMany(UsersEnterprise::class, 'user_id', 'id');
    }

    public function mobileMoneyProviders()
    {
        return $this->belongsToMany(MobileMoneyProviders::class, 'users_mobile_money_providers')
            ->withPivot('phone_number', 'status')
            ->withTimestamps();
    }

    public function getMobileMoneyProviderConfigDetails(string $telecom)
    {
        return $this->mobileMoneyProviderConfigs()
            ->whereHas('provider', function ($query) use ($telecom) {
                $query->where('provider', $telecom);
            })
            ->with('provider')
            ->first();
    }

    public static function getUserBy($keyword, $criteria)
    {
        return self::where($criteria, $keyword)->first();
    }

    public function requests()
    {
        return $this->hasMany(requests::class);
    }

    public static function findByIdentifier($login)
    {
        return self::where(function ($query) use ($login) {
                $query->where('user_name', $login)
                      ->orWhere('user_mail', $login);
            })
            ->where('status', 'enabled')
            ->first();
    }

    public function getSelectedFields(array $fields = [])
    {
        $defaultFields = ['id', 'user_name', 'full_name', 'user_mail', 'user_phone', 'status', 'uuid', 'avatar', 'user_type'];
        $selectedFields = array_unique(array_merge($defaultFields, $fields));

        return collect($this->attributesToArray())->only($selectedFields);
    }

    // =====================================================
    // ⚙️ MÉTHODES AUTH
    // =====================================================

    public function getAuthIdentifierName()
    {
        return 'user_name';
    }

    public function getEmailForPasswordReset()
    {
        return $this->email;
    }
}
