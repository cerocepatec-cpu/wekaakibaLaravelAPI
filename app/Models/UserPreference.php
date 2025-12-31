<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserPreference extends Model
{
    protected $fillable = [
        'language',
        'dark_mode',
        'push_enabled',
        'email_notifications',
        'sms_notifications',
        'transaction_notifications',
        'security_alerts',
        'daily_report',
        'weekly_report',
        'monthly_report',
        'incident_reports',
    ];

    protected $casts = [
        'dark_mode' => 'boolean',
        'push_enabled' => 'boolean',
        'email_notifications' => 'boolean',
        'sms_notifications' => 'boolean',
        'transaction_notifications' => 'boolean',
        'security_alerts' => 'boolean',
        'daily_report' => 'boolean',
        'weekly_report' => 'boolean',
        'monthly_report' => 'boolean',
        'incident_reports' => 'boolean',
    ];
}

