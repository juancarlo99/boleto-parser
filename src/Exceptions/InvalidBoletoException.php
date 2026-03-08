<?php

declare(strict_types=1);

namespace BoletoParser\Exceptions;

use Exception;

/**
 * Thrown when boleto input is invalid (length, characters or checksum).
 */
final class InvalidBoletoException extends Exception
{
    public static function invalidLength(string $input, int $expected, int $actual): self
    {
        $preview = strlen($input) > 50 ? substr($input, 0, 47) . '...' : $input;
        return new self(
            sprintf(
                'Invalid boleto length: expected %d digits, got %d. Input: "%s"',
                $expected,
                $actual,
                $preview
            )
        );
    }

    public static function invalidCharacters(string $input): self
    {
        return new self(
            'Invalid boleto: only digits, spaces and dots are allowed. ' .
            'Input contains invalid character(s).'
        );
    }

    public static function checksumFailure(string $field): self
    {
        return new self(
            sprintf('Boleto checksum validation failed for: %s', $field)
        );
    }

    public static function invalidFormat(string $message): self
    {
        return new self($message);
    }
}
