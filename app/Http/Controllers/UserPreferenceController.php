<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class UserPreferenceController extends Controller
{
    /**
     * GET /settings
     */
    public function show(Request $request)
    {
        $user = $request->user();

        $preferences = $user->preferences()->firstOrCreate([]);

        return $this->successResponse('success',$preferences);
    }

    /**
     * PUT /settings
     */
    public function update(Request $request)
    {
        $user = $request->user();

        $preferences = $user->preferences()->firstOrCreate([]);

        $data = $request->validate([
            'language' => 'nullable|string|in:fr,en',
            'dark_mode' => 'nullable|boolean',

            'push_enabled' => 'nullable|boolean',
            'email_notifications' => 'nullable|boolean',
            'sms_notifications' => 'nullable|boolean',
            'transaction_notifications' => 'nullable|boolean',
            'security_alerts' => 'nullable|boolean',

            'daily_report' => 'nullable|boolean',
            'weekly_report' => 'nullable|boolean',
            'monthly_report' => 'nullable|boolean',

            'incident_reports' => 'nullable|boolean',
        ]);

        $preferences->update($data);

        return $this->successResponse('success',$preferences);
    }
}
