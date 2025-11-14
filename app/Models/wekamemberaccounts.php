<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class wekamemberaccounts extends Model
{
    use HasFactory;
    protected $fillable=[
        'sold',
        'description',
        'type',
        'account_status',
        'money_id',
        'user_id',
        'account_number',
        'enterprise_id',
        'blocked_from', 
        'blocked_to',
        'blocked_periocity',
        'blocked_step'
    ];

    public function getSelectedFields(array $fields = [])
    {
        $defaultFields = ['id', 'description', 'account_status', 'account_number'];
        $selectedFields = array_unique(array_merge($defaultFields, $fields));

        return collect($this->attributesToArray())->only($selectedFields);
    }

   public function canBeUnblocked(): bool
    {
        if (
            $this->type !== 'blocked' ||
            !$this->blocked_to ||
            $this->account_status === 'disabled'
        ) {
            return false;
        }

        return Carbon::today()->greaterThanOrEqualTo(Carbon::parse($this->blocked_to));
    }  
    
    public function isavailable(): bool
    {
        if ($this->account_status === 'disabled') {
            return false;
        }else{
            return true;
        }
    }

    public function money()
    {
        return $this->belongsTo(moneys::class, 'money_id');
    }

    public static function findByMemberAndCurrency($member, string $currency)
    {
        return self::where('user_id', $member->id)
        ->whereHas('money', function ($query) use ($currency) {
            $query->where('abreviation', strtoupper($currency));
        })
        ->first();
    }

    /**
     * Calcule la somme des comptes d'un utilisateur par devise
     *
     * @param \App\Models\User $user
     * @return array Exemple : ['CD' => 20000, 'USD' => 500000]
     */
    public static function getBalancesByUser($user): array
    {
        return self::where('user_id', $user->id)
            ->where('account_status', 'enabled')
            ->with('money')
            ->get()
            ->groupBy(fn($account) => $account->money->abreviation)
            ->map(fn($group) => $group->sum('sold'))
            ->toArray();
    }
}
