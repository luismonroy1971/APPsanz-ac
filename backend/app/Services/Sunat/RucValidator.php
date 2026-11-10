<?php

namespace App\Services\Sunat;

class RucValidator
{
    private const WEIGHTS = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];

    /** Valida formato y dígito verificador (módulo 11) de un RUC peruano. */
    public static function isValid(?string $ruc): bool
    {
        if (! $ruc || ! preg_match('/^(10|15|16|17|20)\d{9}$/', $ruc)) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $sum += (int) $ruc[$i] * self::WEIGHTS[$i];
        }
        $check = 11 - ($sum % 11);
        $check = $check === 10 ? 0 : ($check === 11 ? 1 : $check);

        return $check === (int) $ruc[10];
    }

    /** Completa un RUC de 10 dígitos con su dígito verificador (útil para datos de prueba). */
    public static function withCheckDigit(string $base10): string
    {
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $sum += (int) $base10[$i] * self::WEIGHTS[$i];
        }
        $check = 11 - ($sum % 11);
        $check = $check === 10 ? 0 : ($check === 11 ? 1 : $check);

        return $base10.$check;
    }
}
