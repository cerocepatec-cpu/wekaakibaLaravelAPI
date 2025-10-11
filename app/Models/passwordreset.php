<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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

    public function isExpired($minutes = 15)
    {
        return $this->created_at < now()->subMinutes($minutes);
    }
}
