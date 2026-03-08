<?php

declare(strict_types=1);

namespace BoletoParser\Utils;

use BoletoParser\Exceptions\InvalidBoletoException;

/**
 * Converts between FEBRABAN barcode (44 digits) and linha digitável (47 digits).
 *
 * Supports two linha layouts:
 * - Layout A (e.g. Itaú): block1 = 8 data + CD, block2 = 10 + CD, block3 = 11 + CD. Free = 4+10+11.
 * - Layout B (e.g. Bradesco): block1 = 9 data + CD, block2 = 10 + CD, block3 = 10 + CD. Free = 5+10+10.
 */
final class BarcodeConverter
{
    private const BARCODE_LENGTH = 44;
    private const LINHA_LENGTH = 47;
    private const BARCODE_DV_POSITION = 4;
    private const LINHA_DV_POS = 32;
    private const LINHA_FACTOR_START = 33;
    private const LINHA_FACTOR_LENGTH = 4;
    private const LINHA_VALUE_START = 37;
    private const LINHA_VALUE_LENGTH = 10;

    /** Layout A: block1 8 data, block2 10, block3 11. */
    private const LAYOUT_A_BLOCK1_DATA_LEN = 8;
    private const LAYOUT_A_CD1_POS = 8;
    private const LAYOUT_A_BLOCK2_START = 9;
    private const LAYOUT_A_BLOCK2_LEN = 10;
    private const LAYOUT_A_CD2_POS = 19;
    private const LAYOUT_A_BLOCK3_START = 20;
    private const LAYOUT_A_BLOCK3_LEN = 11;
    private const LAYOUT_A_CD3_POS = 31;
    private const LAYOUT_A_FREE1_LEN = 4;
    private const LAYOUT_A_FREE2_START = 9;
    private const LAYOUT_A_FREE2_LEN = 10;
    private const LAYOUT_A_FREE3_START = 20;
    private const LAYOUT_A_FREE3_LEN = 11;

    /** Layout B: block1 9 data, block2 10, block3 10. */
    private const LAYOUT_B_BLOCK1_DATA_LEN = 9;
    private const LAYOUT_B_CD1_POS = 9;
    private const LAYOUT_B_BLOCK2_START = 10;
    private const LAYOUT_B_BLOCK2_LEN = 10;
    private const LAYOUT_B_CD2_POS = 20;
    private const LAYOUT_B_BLOCK3_START = 21;
    private const LAYOUT_B_BLOCK3_LEN = 10;
    private const LAYOUT_B_CD3_POS = 31;
    private const LAYOUT_B_FREE1_LEN = 5;
    private const LAYOUT_B_FREE2_START = 10;
    private const LAYOUT_B_FREE2_LEN = 10;
    private const LAYOUT_B_FREE3_START = 21;
    private const LAYOUT_B_FREE3_LEN = 10;

    /** Barcode indices (0-based). Free field 25 digits. */
    private const BARCODE_FREE_START = 19;
    /** Layout A: free split 4+10+11 */
    private const BARCODE_FREE_BLOCK1_LEN_A = 4;
    private const BARCODE_FREE_BLOCK2_START_A = 23;
    private const BARCODE_FREE_BLOCK2_LEN = 10;
    private const BARCODE_FREE_BLOCK3_START_A = 33;
    private const BARCODE_FREE_BLOCK3_LEN_A = 11;
    /** Layout B: free split 5+10+10 */
    private const BARCODE_FREE_BLOCK1_LEN_B = 5;
    private const BARCODE_FREE_BLOCK2_START_B = 24;
    private const BARCODE_FREE_BLOCK3_START_B = 34;
    private const BARCODE_FREE_BLOCK3_LEN_B = 10;
    private const LINHA_BANK_CURRENCY_END = 4;

    /** Bank codes (3 digits) that use layout B (9+10+10) in linha digitável. */
    private const LAYOUT_B_BANK_CODES = ['077', '237'];

    /**
     * Convert linha digitável (47 digits) to barcode (44 digits).
     * Tries layout B (9+10+10) first, then layout A (8+10+11).
     *
     * @throws InvalidBoletoException When length is not 47, characters invalid, or checksum fails.
     */
    public static function linhaDigitavelToBarcode(string $linha): string
    {
        $result = self::linhaDigitavelToBarcodeWithValidation($linha);
        if (!$result['valid_campo1'] || !$result['valid_campo2'] || !$result['valid_campo3']) {
            throw InvalidBoletoException::checksumFailure('campo 1');
        }
        return $result['barcode'];
    }

    /**
     * Convert linha digitável (47 digits) to barcode (44 digits) and return per-campo validity.
     * Uses layout B for banks 077/237, layout A otherwise. Does not throw on invalid campo DVs.
     *
     * @return array{barcode: string, valid_campo1: bool, valid_campo2: bool, valid_campo3: bool}
     * @throws InvalidBoletoException When length is not 47 or characters invalid.
     */
    public static function linhaDigitavelToBarcodeWithValidation(string $linha): array
    {
        $digits = self::normalizeToDigitArray($linha);
        $digitCount = count($digits);

        if ($digitCount !== self::LINHA_LENGTH) {
            throw InvalidBoletoException::invalidLength($linha, self::LINHA_LENGTH, $digitCount);
        }

        $bankCode = self::slice($digits, 0, 3);
        $isLayoutB = in_array($bankCode, self::LAYOUT_B_BANK_CODES, true);

        if ($isLayoutB) {
            $valid1 = CheckDigit::validateMod10(
                self::slice($digits, 0, self::LAYOUT_B_BLOCK1_DATA_LEN + 1)
            );
            $valid2 = CheckDigit::validateMod10(
                self::slice($digits, self::LAYOUT_B_BLOCK2_START, self::LAYOUT_B_BLOCK2_LEN + 1)
            );
            $valid3 = CheckDigit::validateMod10(
                self::slice($digits, self::LAYOUT_B_BLOCK3_START, self::LAYOUT_B_BLOCK3_LEN + 1)
            );
            $barcode = self::buildBarcodeFromLinhaDigitsLayoutB($digits);
        } else {
            $valid1 = CheckDigit::validateMod10(
                self::slice($digits, 0, self::LAYOUT_A_BLOCK1_DATA_LEN + 1)
            );
            $valid2 = CheckDigit::validateMod10(
                self::slice($digits, self::LAYOUT_A_BLOCK2_START, self::LAYOUT_A_BLOCK2_LEN + 1)
            );
            $valid3 = CheckDigit::validateMod10(
                self::slice($digits, self::LAYOUT_A_BLOCK3_START, self::LAYOUT_A_BLOCK3_LEN + 1)
            );
            $barcode = self::buildBarcodeFromLinhaDigitsLayoutA($digits);
        }

        return [
            'barcode' => $barcode,
            'valid_campo1' => $valid1,
            'valid_campo2' => $valid2,
            'valid_campo3' => $valid3,
        ];
    }

    /** @param list<string> $digits 47 linha digits */
    private static function buildBarcodeFromLinhaDigitsLayoutB(array $digits): string
    {
        return self::slice($digits, 0, self::LINHA_BANK_CURRENCY_END)
            . $digits[self::LINHA_DV_POS]
            . self::slice($digits, self::LINHA_FACTOR_START, self::LINHA_FACTOR_LENGTH)
            . self::slice($digits, self::LINHA_VALUE_START, self::LINHA_VALUE_LENGTH)
            . self::slice($digits, self::LINHA_BANK_CURRENCY_END, self::LAYOUT_B_FREE1_LEN)
            . self::slice($digits, self::LAYOUT_B_FREE2_START, self::LAYOUT_B_FREE2_LEN)
            . self::slice($digits, self::LAYOUT_B_FREE3_START, self::LAYOUT_B_FREE3_LEN);
    }

    /** @param list<string> $digits 47 linha digits */
    private static function buildBarcodeFromLinhaDigitsLayoutA(array $digits): string
    {
        return self::slice($digits, 0, self::LINHA_BANK_CURRENCY_END)
            . $digits[self::LINHA_DV_POS]
            . self::slice($digits, self::LINHA_FACTOR_START, self::LINHA_FACTOR_LENGTH)
            . self::slice($digits, self::LINHA_VALUE_START, self::LINHA_VALUE_LENGTH)
            . self::slice($digits, self::LINHA_BANK_CURRENCY_END, self::LAYOUT_A_FREE1_LEN)
            . self::slice($digits, self::LAYOUT_A_FREE2_START, self::LAYOUT_A_FREE2_LEN)
            . self::slice($digits, self::LAYOUT_A_FREE3_START, self::LAYOUT_A_FREE3_LEN);
    }

    /**
     * Convert barcode (44 digits) to linha digitável (47 digits with check digits).
     *
     * @throws InvalidBoletoException When length is not 44, characters invalid, or barcode checksum fails.
     */
    public static function barcodeToLinhaDigitavel(string $barcode): string
    {
        $digits = self::normalizeToDigitArray($barcode);
        if (count($digits) !== self::BARCODE_LENGTH) {
            throw InvalidBoletoException::invalidLength($barcode, self::BARCODE_LENGTH, count($digits));
        }
        $barcodeStr = implode('', $digits);
        if (!CheckDigit::validateMod11($barcodeStr, self::BARCODE_DV_POSITION)) {
            throw InvalidBoletoException::checksumFailure('barcode');
        }
        return self::buildLinhaFromBarcodeDigits($digits);
    }

    /**
     * Build 47-digit linha from 44-digit barcode without validating barcode mod11.
     * Use when barcode may have invalid DV but you still need the canonical linha form.
     *
     * @param list<string> $digits 44 digits (e.g. from normalizeToDigitArray)
     */
    public static function barcodeToLinhaDigitavelUnsafe(string $barcode): string
    {
        $digits = self::normalizeToDigitArray($barcode);
        if (count($digits) !== self::BARCODE_LENGTH) {
            throw InvalidBoletoException::invalidLength($barcode, self::BARCODE_LENGTH, count($digits));
        }
        return self::buildLinhaFromBarcodeDigits($digits);
    }

    /**
     * @param list<string> $digits 44 barcode digits
     */
    private static function buildLinhaFromBarcodeDigits(array $digits): string
    {
        $bankCode = self::slice($digits, 0, 3);
        $isLayoutB = in_array($bankCode, self::LAYOUT_B_BANK_CODES, true);

        if ($isLayoutB) {
            $free1Len = self::BARCODE_FREE_BLOCK1_LEN_B;
            $free2Start = self::BARCODE_FREE_BLOCK2_START_B;
            $free3Start = self::BARCODE_FREE_BLOCK3_START_B;
            $free3Len = self::BARCODE_FREE_BLOCK3_LEN_B;
        } else {
            $free1Len = self::BARCODE_FREE_BLOCK1_LEN_A;
            $free2Start = self::BARCODE_FREE_BLOCK2_START_A;
            $free3Start = self::BARCODE_FREE_BLOCK3_START_A;
            $free3Len = self::BARCODE_FREE_BLOCK3_LEN_A;
        }

        $block1 = self::slice($digits, 0, self::LINHA_BANK_CURRENCY_END)
            . self::slice($digits, self::BARCODE_FREE_START, $free1Len);
        $block2 = self::slice($digits, $free2Start, self::BARCODE_FREE_BLOCK2_LEN);
        $block3 = self::slice($digits, $free3Start, $free3Len);

        $cd1 = (string) CheckDigit::mod10($block1);
        $cd2 = (string) CheckDigit::mod10($block2);
        $cd3 = (string) CheckDigit::mod10($block3);

        return $block1 . $cd1 . $block2 . $cd2 . $block3 . $cd3
            . $digits[self::BARCODE_DV_POSITION]
            . self::slice($digits, 5, self::LINHA_FACTOR_LENGTH)
            . self::slice($digits, 9, self::LINHA_VALUE_LENGTH);
    }

    /**
     * Strip spaces and dots; return digits as array of single-char strings.
     *
     * @return list<string>
     * @throws InvalidBoletoException When input contains invalid characters.
     */
    public static function normalizeToDigitArray(string $input): array
    {
        if (preg_match('/[^0-9\s.]/', $input) === 1) {
            throw InvalidBoletoException::invalidCharacters($input);
        }
        $digitsOnly = preg_replace('/\D/', '', $input);
        return $digitsOnly === '' ? [] : str_split($digitsOnly);
    }

    /**
     * Strip spaces and dots; return digits as string.
     *
     * @throws InvalidBoletoException When input contains invalid characters.
     */
    public static function digitsOnly(string $input): string
    {
        if (preg_match('/[^0-9\s.]/', $input) === 1) {
            throw InvalidBoletoException::invalidCharacters($input);
        }
        return preg_replace('/\D/', '', $input);
    }

    /** @param list<string> $digits */
    private static function slice(array $digits, int $start, int $length): string
    {
        return implode('', array_slice($digits, $start, $length));
    }
}
