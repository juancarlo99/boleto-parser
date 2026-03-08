<?php

declare(strict_types=1);

namespace BoletoParser\Utils;

use BoletoParser\Exceptions\InvalidBoletoException;

/**
 * Converts between FEBRABAN barcode (44 digits) and linha digitável (47 digits).
 *
 * Linha layout (1-based): [1-4 bank+curr][5-8 free][9 CD1][10-19 free][20 CD2][21-30 free][31 CD3][32 DV][33-36 factor][37-46 value]
 * Barcode:                 [1-3 bank][4 curr][5 DV][6-9 factor][10-19 value][20-44 free 25]
 */
final class BarcodeConverter
{
    private const BARCODE_LENGTH = 44;
    private const LINHA_LENGTH = 47;
    private const BARCODE_DV_POSITION = 4;

    /** Linha digitável indices (0-based). */
    private const LINHA_BANK_CURRENCY_END = 4;
    private const LINHA_FREE_BLOCK1_END = 8;
    private const LINHA_CD1_POS = 8;
    private const LINHA_FREE_BLOCK2_START = 9;
    private const LINHA_FREE_BLOCK2_END = 19;
    private const LINHA_CD2_POS = 19;
    private const LINHA_FREE_BLOCK3_START = 20;
    private const LINHA_FREE_BLOCK3_END = 30;
    private const LINHA_CD3_POS = 30;
    private const LINHA_DV_POS = 31;
    private const LINHA_FACTOR_START = 32;
    private const LINHA_FACTOR_LENGTH = 4;
    private const LINHA_VALUE_START = 36;
    private const LINHA_VALUE_LENGTH = 10;

    /** Barcode indices (0-based). */
    private const BARCODE_FREE_START = 19;
    private const BARCODE_FREE_BLOCK1_LEN = 4;
    private const BARCODE_FREE_BLOCK2_START = 23;
    private const BARCODE_FREE_BLOCK2_LEN = 10;
    private const BARCODE_FREE_BLOCK3_START = 33;
    private const BARCODE_FREE_BLOCK3_LEN = 10;
    private const FREE_FIELD_PADDING = '0';

    /**
     * Convert linha digitável (47 digits) to barcode (44 digits).
     *
     * @throws InvalidBoletoException When length is not 47, characters invalid, or checksum fails.
     */
    public static function linhaDigitavelToBarcode(string $linha): string
    {
        $digits = self::normalizeToDigitArray($linha);
        $digitCount = count($digits);

        if ($digitCount !== self::LINHA_LENGTH) {
            throw InvalidBoletoException::invalidLength($linha, self::LINHA_LENGTH, $digitCount);
        }

        self::validateLinhaBlockChecksums($digits);

        $barcode = self::slice($digits, 0, self::LINHA_BANK_CURRENCY_END)
            . $digits[self::LINHA_DV_POS]
            . self::slice($digits, self::LINHA_FACTOR_START, self::LINHA_FACTOR_LENGTH)
            . self::slice($digits, self::LINHA_VALUE_START, self::LINHA_VALUE_LENGTH)
            . self::FREE_FIELD_PADDING
            . self::slice($digits, self::LINHA_BANK_CURRENCY_END, self::LINHA_FREE_BLOCK1_END - self::LINHA_BANK_CURRENCY_END)
            . self::slice($digits, self::LINHA_FREE_BLOCK2_START, self::LINHA_FREE_BLOCK2_END - self::LINHA_FREE_BLOCK2_START)
            . self::slice($digits, self::LINHA_FREE_BLOCK3_START, self::LINHA_FREE_BLOCK3_END - self::LINHA_FREE_BLOCK3_START);

        if (strlen($barcode) !== self::BARCODE_LENGTH) {
            throw InvalidBoletoException::invalidFormat(
                'Failed to build 44-digit barcode from linha digitável.'
            );
        }

        if (!CheckDigit::validateMod11($barcode, self::BARCODE_DV_POSITION)) {
            throw InvalidBoletoException::checksumFailure('barcode');
        }

        return $barcode;
    }

    /**
     * Convert barcode (44 digits) to linha digitável (47 digits with check digits).
     *
     * @throws InvalidBoletoException When length is not 44, characters invalid, or barcode checksum fails.
     */
    public static function barcodeToLinhaDigitavel(string $barcode): string
    {
        $digits = self::normalizeToDigitArray($barcode);
        $digitCount = count($digits);

        if ($digitCount !== self::BARCODE_LENGTH) {
            throw InvalidBoletoException::invalidLength($barcode, self::BARCODE_LENGTH, $digitCount);
        }

        $barcodeStr = implode('', $digits);
        if (!CheckDigit::validateMod11($barcodeStr, self::BARCODE_DV_POSITION)) {
            throw InvalidBoletoException::checksumFailure('barcode');
        }

        $block1 = self::slice($digits, 0, self::LINHA_BANK_CURRENCY_END)
            . self::slice($digits, self::BARCODE_FREE_START, self::BARCODE_FREE_BLOCK1_LEN);
        $block2 = self::slice($digits, self::BARCODE_FREE_BLOCK2_START, self::BARCODE_FREE_BLOCK2_LEN);
        $block3 = self::slice($digits, self::BARCODE_FREE_BLOCK3_START, self::BARCODE_FREE_BLOCK3_LEN);

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

    /** @param list<string> $digits */
    private static function validateLinhaBlockChecksums(array $digits): void
    {
        $block1 = self::slice($digits, 0, self::LINHA_CD1_POS);
        if ((string) CheckDigit::mod10($block1) !== (string) $digits[self::LINHA_CD1_POS]) {
            throw InvalidBoletoException::checksumFailure('campo 1');
        }
        $block2 = self::slice($digits, self::LINHA_FREE_BLOCK2_START, self::LINHA_FREE_BLOCK2_END - self::LINHA_FREE_BLOCK2_START);
        if ((string) CheckDigit::mod10($block2) !== (string) $digits[self::LINHA_CD2_POS]) {
            throw InvalidBoletoException::checksumFailure('campo 2');
        }
        $block3 = self::slice($digits, self::LINHA_FREE_BLOCK3_START, self::LINHA_FREE_BLOCK3_END - self::LINHA_FREE_BLOCK3_START);
        if ((string) CheckDigit::mod10($block3) !== (string) $digits[self::LINHA_CD3_POS]) {
            throw InvalidBoletoException::checksumFailure('campo 3');
        }
    }
}
