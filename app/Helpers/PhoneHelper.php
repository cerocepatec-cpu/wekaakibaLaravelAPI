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

     /**
     * Retourne le numéro sans le code pays.
     * 
     * @param string $phone Numéro complet (+243991516369)
     * @param bool $withZero true → préfixer avec 0, false → sans 0
     * @return string|null
     */
    public static function removeCountryCode(string $phone, bool $withZero = true): ?string
    {
        try {
            // On récupère le numéro au format national (ex: 0991516369)
            $number = PhoneNumber::make($phone)->ofCountry(null)->formatNational();

            // On nettoie les espaces ou séparateurs éventuels
            $clean = preg_replace('/\D/', '', $number);

            // Si on veut sans le zéro initial
            if (!$withZero && str_starts_with($clean, '0')) {
                $clean = substr($clean, 1);
            }

            return $clean;
        } catch (\Throwable $th) {
            return null;
        }
    }
}
