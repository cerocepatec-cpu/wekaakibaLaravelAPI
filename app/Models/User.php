<?php

namespace App\Models;

use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Models\MobileMoneyProviders;
use Spatie\Permission\Traits\HasRoles;

// use Laravel\Sanctum\HasApiTokens;
//use Laravel\Passport\HasApiTokens;

class User extends Authenticatable implements CanResetPassword
{
    use  HasFactory, HasApiTokens;
    use Notifiable;
    use HasRoles;
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
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
        'adress'
    ];

    public function tokens(){
        return $this->morphMany(PersonalAccessToken::class,'tokenable');
    }

    public function getAuthIdentifierName()
    {
        return 'user_name'; // or 'email' if you prefer
    }
    
    public function getEmailForPasswordReset()
    {
        return $this->email;
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

    public static function getUserBy($keyword,$criteria)
    {
        return self::where($criteria,$keyword)->first();
    }

    public function requests(){
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


    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'email_verified_at',
        'laravel_through_key',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];
}
