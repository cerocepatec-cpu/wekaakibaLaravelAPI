<?php

namespace App\Helpers;

use Propaganistas\LaravelPhone\PhoneNumber;

class PhoneHelper
{
    /**
     * Vérifie si un numéro est valide (détection automatique du pays)
     */
    public static function isValidPhone(string $phone): bool
    {
        try {
            // Si le numéro a un indicatif (ex: +243, +33...), la librairie le détecte automatiquement
            $number = PhoneNumber::make($phone)->ofCountry(null);
            return $number->isValid();
        } catch (\Throwable $th) {
            return false;
        }
    }

    /**
     * Formate le numéro au format E.164 standard (+243970000000)
     */
    public static function formatPhone(string $phone): ?string
    {
        try {
            $number = PhoneNumber::make($phone)->ofCountry(null);
            return $number->formatE164();
        } catch (\Throwable $th) {
            return null;
        }
    }

    /**
     * Retourne le code pays détecté (ex: CD, FR, US)
     */
    public static function getCountry(string $phone): ?string
    {
        try {
            $number = PhoneNumber::make($phone)->ofCountry(null);
            return $number->getCountry();
        } catch (\Throwable $th) {
            return null;
        }
    }
}
