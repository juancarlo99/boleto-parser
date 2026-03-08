<?php

declare(strict_types=1);

namespace BoletoParser;

use DateTimeImmutable;

/**
 * Immutable value object with parsed boleto data.
 *
 * When parsed from barcode with invalid checksum, isValid() is false and getLinhaDigitavel()
 * returns the same 44-digit string as getBarcode().
 */
final class Boleto
{
    public function __construct(
        private readonly string $bankCode,
        private readonly string $bankName,
        private readonly float $amount,
        private readonly string $currency,
        private readonly ?DateTimeImmutable $dueDate,
        private readonly string $barcode,
        private readonly string $linhaDigitavel,
        private readonly bool $validChecksum,
    ) {
    }

    public function getBankCode(): string
    {
        return $this->bankCode;
    }

    public function getBankName(): string
    {
        return $this->bankName;
    }

    public function getAmount(): float
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getDueDate(): ?DateTimeImmutable
    {
        return $this->dueDate;
    }

    /**
     * Due date as string Y-m-d, or null if not available.
     */
    public function getDueDateString(): ?string
    {
        return $this->dueDate?->format('Y-m-d');
    }

    public function getBarcode(): string
    {
        return $this->barcode;
    }

    public function getLinhaDigitavel(): string
    {
        return $this->linhaDigitavel;
    }

    /** Whether the barcode/linha checksum validated (módulo 10/11). */
    public function isValid(): bool
    {
        return $this->validChecksum;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'bank_code' => $this->bankCode,
            'bank_name' => $this->bankName,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'due_date' => $this->getDueDateString(),
            'barcode' => $this->barcode,
            'linha_digitavel' => $this->linhaDigitavel,
            'valid_checksum' => $this->validChecksum,
        ];
    }
}
