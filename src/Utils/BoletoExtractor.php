<?php

declare(strict_types=1);

namespace BoletoParser\Utils;

use BoletoParser\Boleto;
use BoletoParser\BoletoParser;
use BoletoParser\Exceptions\InvalidBoletoException;

/**
 * Extracts boleto candidates from arbitrary text (PDF, email, HTML, OCR).
 *
 * Responsibilities: regex detection, normalization, deduplication.
 */
final class BoletoExtractor
{
    /** Linha digitável with FEBRABAN formatting (5.5 5.6 5.6 1 14). */
    private const PATTERN_LINHA_FORMATTED = '/\d{5}\.\d{5}\s+\d{5}\.\d{6}\s+\d{5}\.\d{6}\s+\d\s+\d{14}/';

    /** Linha digitável or barcode-like: exactly 47 digits. */
    private const PATTERN_47_DIGITS = '/\d{47}/';

    /** Arrecadação: exactly 48 digits. */
    private const PATTERN_48_DIGITS = '/\d{48}/';

    private const VALID_LENGTH_LINHA = 47;
    private const VALID_LENGTH_ARRECADACAO = 48;

    /**
     * Extract all valid boletos from text. Only returns boletos that parse and pass isValid().
     *
     * @return list<Boleto>
     */
    public static function extract(string $text): array
    {
        $candidates = self::extractCandidateDigitStrings($text);
        $boletos = [];
        $seenKeys = [];

        foreach ($candidates as $digits) {
            try {
                $boleto = BoletoParser::parse($digits);
            } catch (InvalidBoletoException) {
                continue;
            }

            if (!$boleto->isValid()) {
                continue;
            }

            $key = self::boletoSignature($boleto);
            if (isset($seenKeys[$key])) {
                continue;
            }
            $seenKeys[$key] = true;
            $boletos[] = $boleto;
        }

        return $boletos;
    }

    /**
     * Find all regex matches, normalize to digits, return unique 47- or 48-digit strings.
     *
     * @return list<string>
     */
    public static function extractCandidateDigitStrings(string $text): array
    {
        $digitStrings = [];

        foreach (self::allPatterns() as $pattern) {
            $matches = self::findMatches($text, $pattern);
            foreach ($matches as $match) {
                $normalized = self::normalizeToDigits($match);
                if (self::isValidCandidateLength(strlen($normalized))) {
                    $digitStrings[$normalized] = true;
                }
            }
        }

        return array_keys($digitStrings);
    }

    /** @return list<string> Regex patterns for boleto detection */
    private static function allPatterns(): array
    {
        return [
            self::PATTERN_LINHA_FORMATTED,
            self::PATTERN_48_DIGITS,
            self::PATTERN_47_DIGITS,
        ];
    }

    /**
     * Run regex and return full match strings (without offsets).
     *
     * @return list<string>
     */
    private static function findMatches(string $text, string $pattern): array
    {
        if (preg_match_all($pattern, $text, $m) === false || $m[0] === []) {
            return [];
        }
        return $m[0];
    }

    /**
     * Remove every character except digits.
     */
    public static function normalizeToDigits(string $input): string
    {
        return preg_replace('/\D/', '', $input);
    }

    private static function isValidCandidateLength(int $length): bool
    {
        return $length === self::VALID_LENGTH_LINHA || $length === self::VALID_LENGTH_ARRECADACAO;
    }

    /** Unique key for deduplication (barcode or reference). */
    private static function boletoSignature(Boleto $boleto): string
    {
        return $boleto->getBarcode();
    }
}
