<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class OTPService
{
    public function generateOtp(int $userId, string $context): string
    {
        $otp = (string) rand(100000, 999999);

        Cache::put(
            $this->key($userId, $context),
            $otp,
            now()->addMinutes(5)
        );

        return $otp;
    }

    public function verifyOtp(int $userId, string $context, string $otp): bool
    {
        $cached = Cache::get($this->key($userId, $context));

        if (!$cached || $otp !== $cached) {
            return false;
        }

        Cache::forget($this->key($userId, $context));
        return true;
    }

     private function key(int $userId, string $context): string
    {
        return "otp_{$userId}_{$context}";
    }
}
