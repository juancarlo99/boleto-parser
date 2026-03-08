<?php

declare(strict_types=1);

namespace BoletoParser\Utils;

/**
 * FEBRABAN check digit algorithms (módulo 10 and módulo 11).
 *
 * All methods expect a string of digits only. Behaviour is undefined for non-digit input.
 */
final class CheckDigit
{
    /** Weights for módulo 10 (alternate 2, 1 from right to left). */
    private const MOD10_WEIGHT_FIRST = 2;
    private const MOD10_WEIGHT_SECOND = 1;

    /** Weight range for módulo 11 (9 down to 2, then restart at 9). */
    private const MOD11_WEIGHT_MIN = 2;
    private const MOD11_WEIGHT_MAX = 9;

    /**
     * Compute módulo 10 check digit for linha digitável blocks.
     * Weights alternate 2 and 1 from right to left; sum digits if product > 9.
     *
     * @param string $number Digits only (no spaces). Empty string returns 0.
     */
    public static function mod10(string $number): int
    {
        if ($number === '') {
            return 0;
        }
        $digits = array_map('intval', str_split(strrev($number)));
        $sum = 0;
        foreach ($digits as $i => $digit) {
            $weight = ($i % 2 === 0) ? self::MOD10_WEIGHT_FIRST : self::MOD10_WEIGHT_SECOND;
            $product = $digit * $weight;
            $sum += $product > 9 ? $product - 9 : $product;
        }
        $remainder = $sum % 10;
        return $remainder === 0 ? 0 : 10 - $remainder;
    }

    /**
     * Compute módulo 11 check digit for barcode (FEBRABAN).
     * Weights 9, 8, 7, 6, 5, 4, 3, 2 from left to right, repeating.
     * Remainder 0 or 1 yields DV = 0.
     *
     * @param string $number Digits only. Empty string returns 0.
     */
    public static function mod11(string $number): int
    {
        if ($number === '') {
            return 0;
        }
        $digits = array_map('intval', str_split($number));
        $weight = self::MOD11_WEIGHT_MAX;
        $sum = 0;
        foreach ($digits as $digit) {
            $sum += $digit * $weight;
            $weight = $weight <= self::MOD11_WEIGHT_MIN ? self::MOD11_WEIGHT_MAX : $weight - 1;
        }
        $remainder = $sum % 11;
        return match ($remainder) {
            0, 1 => 0,
            default => 11 - $remainder,
        };
    }

    /**
     * Return true if the last digit of $number equals mod10 of the preceding digits.
     * Returns false for empty string or single digit.
     */
    public static function validateMod10(string $number): bool
    {
        if ($number === '' || strlen($number) < 2) {
            return false;
        }
        $body = substr($number, 0, -1);
        $expected = (int) substr($number, -1);
        return self::mod10($body) === $expected;
    }

    /**
     * Return true if the digit at $position (0-based) equals mod11 of the rest.
     * Returns false if string too short.
     *
     * @param int $position 0-based index of the check digit (default 4 for barcode).
     */
    public static function validateMod11(string $number, int $position = 4): bool
    {
        if ($number === '' || strlen($number) <= $position) {
            return false;
        }
        $withoutDv = substr($number, 0, $position) . substr($number, $position + 1);
        $expected = (int) $number[$position];
        return self::mod11($withoutDv) === $expected;
    }
}
