<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class funds extends Model
{
    use HasFactory;
    protected $fillable = [
        'sold',
        'description',
        'money_id',
        'user_id',
        'principal',
        'enterprise_id',
        'type',
        'fund_status'
    ];

     public function isavailable(): bool
    {
        if ($this->fund_status !== 'enabled') {
            return false;
        }else{
            return true;
        }
    }  
    
    public function haveTheSameMoneyWith($moneyId): bool
    {
        if ($this->money_id !==$moneyId) {
            return false;
        }else{
            return true;
        }
    } 
    
    public function canMakeOperation($user): bool
    {
        if ($this->user_id !==$user->id) {
            return false;
        }else{
            return true;
        }
    }

    public static function getAutomaticFund(int $moneyId)
    {
        $user = Auth::user();
        if (!$user) {
            return null;
        }

        $enterprise_id = app(\App\Http\Controllers\Controller::class)->getEse($user->id)['id'] ?? null;
        if (!$enterprise_id) {
            return null;
        }

        return self::where('type', 'automatic')
            ->where('enterprise_id', $enterprise_id)
            ->where('money_id', $moneyId)
            ->where('fund_status', 'enabled')
            ->first();
    }

    public static function myFundWithMoney($moneyId,$userId){
         return self::where('user_id', $userId)
        ->where('money_id', $moneyId)
        ->where('fund_status', 'enabled')
        ->first();
    }
}
