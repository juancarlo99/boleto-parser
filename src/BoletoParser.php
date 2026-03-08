<?php

declare(strict_types=1);

namespace BoletoParser;

use BoletoParser\Exceptions\InvalidBoletoException;
use BoletoParser\Utils\BarcodeConverter;
use BoletoParser\Utils\CheckDigit;
use DateTimeImmutable;

/**
 * Parser for Brazilian bank slip (boleto bancário) FEBRABAN format.
 *
 * Accepts either linha digitável (47 digits) or barcode (44 digits).
 */
final class BoletoParser
{
    private const BARCODE_LENGTH = 44;
    private const LINHA_DIGITAVEL_LENGTH = 47;
    private const BARCODE_DV_POSITION = 4;

    /** Base date for due-date factor (until 21/02/2025). */
    private const BASE_DATE_LEGACY = '1997-10-07';
    /** Base date for due-date factor (from 22/02/2025). */
    private const BASE_DATE_CURRENT = '2022-05-29';
    private const DUE_DATE_FACTOR_RESET = 1000;

    /** @var array<string, string> Bank code (3 digits) => bank name */
    private const BANK_NAMES = [
        '001' => 'Banco do Brasil',
        '033' => 'Santander',
        '104' => 'Caixa',
        '237' => 'Bradesco',
        '341' => 'Itaú',
        '756' => 'Sicoob',
    ];

    /** @var array<string, string> Currency code (1 digit) => ISO currency */
    private const CURRENCY_CODES = [
        '9' => 'BRL',
    ];

    /** Barcode field positions (0-based, length). */
    private const BARCODE_BANK_LENGTH = 3;
    private const BARCODE_CURRENCY_POS = 3;
    private const BARCODE_FACTOR_START = 5;
    private const BARCODE_FACTOR_LENGTH = 4;
    private const BARCODE_VALUE_START = 9;
    private const BARCODE_VALUE_LENGTH = 10;
    private const VALUE_DECIMAL_PLACES = 2;

    /**
     * Parse boleto from linha digitável (47 digits) or barcode (44 digits).
     * Format is detected by length after stripping spaces and dots.
     *
     * @throws InvalidBoletoException When input is empty, wrong length, has invalid characters, or fails checksum (linha only).
     */
    public static function parse(string $input): Boleto
    {
        $digits = BarcodeConverter::digitsOnly($input);
        $length = strlen($digits);

        if ($length === self::BARCODE_LENGTH) {
            return self::fromBarcode($digits);
        }
        if ($length === self::LINHA_DIGITAVEL_LENGTH) {
            return self::fromLinhaDigitavel($digits);
        }

        throw InvalidBoletoException::invalidFormat(
            sprintf('Invalid boleto length: expected 44 (barcode) or 47 (linha digitável) digits, got %d.', $length)
        );
    }

    /**
     * Parse from linha digitável (47 digits).
     * Spaces and dots are allowed and stripped.
     *
     * @throws InvalidBoletoException When length is not 47 or block/barcode checksum fails.
     */
    public static function fromLinhaDigitavel(string $linha): Boleto
    {
        $digits = BarcodeConverter::digitsOnly($linha);
        if (strlen($digits) !== self::LINHA_DIGITAVEL_LENGTH) {
            throw InvalidBoletoException::invalidLength($linha, self::LINHA_DIGITAVEL_LENGTH, strlen($digits));
        }
        $barcode = BarcodeConverter::linhaDigitavelToBarcode($linha);
        return self::buildBoleto($barcode, BarcodeConverter::barcodeToLinhaDigitavel($barcode), true);
    }

    /**
     * Parse from barcode (44 digits).
     * Spaces are allowed and stripped. Invalid checksum still returns a Boleto with isValid() === false.
     *
     * @throws InvalidBoletoException When length is not 44.
     */
    public static function fromBarcode(string $barcode): Boleto
    {
        $digits = BarcodeConverter::digitsOnly($barcode);
        if (strlen($digits) !== self::BARCODE_LENGTH) {
            throw InvalidBoletoException::invalidLength($barcode, self::BARCODE_LENGTH, strlen($digits));
        }
        $validChecksum = CheckDigit::validateMod11($digits, self::BARCODE_DV_POSITION);
        $linhaDigitavel = $validChecksum
            ? BarcodeConverter::barcodeToLinhaDigitavel($digits)
            : $digits;
        return self::buildBoleto($digits, $linhaDigitavel, $validChecksum);
    }

    /**
     * @param non-empty-string $barcode 44-digit barcode
     * @param non-empty-string $linhaDigitavel 47-digit linha or 44-digit when checksum invalid
     */
    private static function buildBoleto(string $barcode, string $linhaDigitavel, bool $validChecksum): Boleto
    {
        $bankCode = substr($barcode, 0, self::BARCODE_BANK_LENGTH);
        $currencyCode = $barcode[self::BARCODE_CURRENCY_POS];
        $factor = (int) substr($barcode, self::BARCODE_FACTOR_START, self::BARCODE_FACTOR_LENGTH);
        $valueCents = substr($barcode, self::BARCODE_VALUE_START, self::BARCODE_VALUE_LENGTH);
        $amount = (float) ($valueCents / (10 ** self::VALUE_DECIMAL_PLACES));
        $currency = self::CURRENCY_CODES[$currencyCode] ?? 'BRL';
        $dueDate = self::factorToDueDate($factor);
        $bankName = self::BANK_NAMES[$bankCode] ?? 'Banco ' . $bankCode;

        return new Boleto(
            bankCode: $bankCode,
            bankName: $bankName,
            amount: $amount,
            currency: $currency,
            dueDate: $dueDate,
            barcode: $barcode,
            linhaDigitavel: $linhaDigitavel,
            validChecksum: $validChecksum,
        );
    }

    /** FEBRABAN due-date factor: days from base date. Factor 0 = no due date. */
    private static function factorToDueDate(int $factor): ?DateTimeImmutable
    {
        if ($factor === 0) {
            return null;
        }
        $useCurrentBase = $factor >= self::DUE_DATE_FACTOR_RESET;
        $base = new DateTimeImmutable($useCurrentBase ? self::BASE_DATE_CURRENT : self::BASE_DATE_LEGACY);
        $days = $useCurrentBase ? $factor - self::DUE_DATE_FACTOR_RESET : $factor;
        return $base->modify("+{$days} days");
    }
}
