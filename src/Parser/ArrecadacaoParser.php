<?php

declare(strict_types=1);

namespace BoletoParser\Parser;

use BoletoParser\Exceptions\InvalidBoletoException;
use BoletoParser\Utils\BarcodeConverter;
use BoletoParser\Utils\CheckDigit;

/**
 * FEBRABAN boleto de arrecadação (48 digits).
 *
 * Structure (1-based):
 * - 1: product identifier (8)
 * - 2: segment
 * - 3: value indicator (6 or 7 → mod10 for blocks; 8 or 9 → mod11)
 * - 4: general DV
 * - 5-15: block 1 (10 data + 1 DV)
 * - 16-26: block 2 (10 data + 1 DV)
 * - 27-37: block 3 (10 data + 1 DV)
 * - 38-48: block 4 (10 data + 1 DV)
 *
 * @phpstan-type ArrecadacaoResult array{type: 'arrecadacao', segment: string, value: float, reference: string, valid: bool}
 */
final class ArrecadacaoParser
{
    private const LENGTH = 48;
    private const PRODUCT_ID = '8';
    private const GENERAL_DV_POSITION = 3;
    /** Block size 11 (10 data + 1 DV). Block starts at index 4, then 15, 26, 37. */
    private const BLOCK_DATA_LEN = 10;
    private const BLOCK_LEN = 11;
    private const BLOCK_STARTS = [4, 15, 26, 37];
    /** Value from block 4 data (indices 37-46) when value indicator != 6. */
    private const VALUE_START = 37;
    private const VALUE_LENGTH = 10;
    private const VALUE_DECIMAL_PLACES = 2;

    /**
     * Parse 48-digit arrecadação input and return structured result.
     *
     * @return ArrecadacaoResult
     * @throws InvalidBoletoException When length is not 48, first digit is not 8, or invalid characters.
     */
    public static function parse(string $input): array
    {
        $digits = BarcodeConverter::digitsOnly($input);
        if (strlen($digits) !== self::LENGTH) {
            throw InvalidBoletoException::invalidLength($input, self::LENGTH, strlen($digits));
        }
        if ($digits[0] !== self::PRODUCT_ID) {
            throw InvalidBoletoException::invalidFormat(
                'Arrecadação boleto must start with digit 8.'
            );
        }

        $valueIndicator = (int) $digits[2];
        if ($valueIndicator !== 6 && $valueIndicator !== 7 && $valueIndicator !== 8 && $valueIndicator !== 9) {
            throw InvalidBoletoException::invalidFormat(
                'Arrecadação value indicator (3rd digit) must be 6, 7, 8 or 9.'
            );
        }

        $blocksValid = self::validateBlocks($digits, $valueIndicator);
        $generalDvValid = self::validateGeneralDv($digits);
        $valid = $blocksValid && $generalDvValid;

        $segment = $digits[1];
        $value = self::extractValue($digits, $valueIndicator);
        $reference = $digits;

        return [
            'type' => 'arrecadacao',
            'segment' => $segment,
            'value' => $value,
            'reference' => $reference,
            'valid' => $valid,
        ];
    }

    private static function validateBlocks(string $digits, int $valueIndicator): bool
    {
        $useMod10 = $valueIndicator === 6 || $valueIndicator === 7;
        foreach (self::BLOCK_STARTS as $start) {
            $blockData = substr($digits, $start, self::BLOCK_DATA_LEN);
            $blockDv = (int) $digits[$start + self::BLOCK_DATA_LEN];
            if ($useMod10) {
                if (CheckDigit::mod10($blockData) !== $blockDv) {
                    return false;
                }
            } else {
                $blockWithDv = substr($digits, $start, self::BLOCK_LEN);
                if (!CheckDigit::validateMod11($blockWithDv, self::BLOCK_DATA_LEN)) {
                    return false;
                }
            }
        }
        return true;
    }

    private static function validateGeneralDv(string $digits): bool
    {
        $valueIndicator = (int) $digits[2];
        $withoutDv = substr($digits, 0, self::GENERAL_DV_POSITION) . substr($digits, self::GENERAL_DV_POSITION + 1);
        $expected = ($valueIndicator === 6 || $valueIndicator === 7)
            ? CheckDigit::mod10($withoutDv)
            : CheckDigit::mod11($withoutDv);
        return (int) $digits[self::GENERAL_DV_POSITION] === $expected;
    }

    private static function extractValue(string $digits, int $valueIndicator): float
    {
        if ($valueIndicator === 6) {
            return 0.0;
        }
        $valueStr = substr($digits, self::VALUE_START, self::VALUE_LENGTH);
        $valueCents = (int) $valueStr;
        return (float) ($valueCents / (10 ** self::VALUE_DECIMAL_PLACES));
    }
}
