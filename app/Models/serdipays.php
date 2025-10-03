<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class serdipays extends Model
{
    use HasFactory;
    protected $fillable = [
        'token',
        'email',
        'password',
        'merchantCode',
        'c2b_fees',
        'b2c_fees',
        'b2b_fees',
        'ussd_fees',
        'additional_fees',
        'merchant_payment_endpoint',
        'client_payment_endpoint',
        'merchant_pin',
        'merchant_api_id',
        'withdraw_by_agent_fees',
        'transfert_money_fees',
        'enterprise_id'
    ];
}
