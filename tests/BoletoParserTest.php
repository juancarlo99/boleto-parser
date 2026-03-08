<?php

declare(strict_types=1);

namespace BoletoParser\Tests;

use BoletoParser\Boleto;
use BoletoParser\BoletoParser;
use BoletoParser\Exceptions\InvalidBoletoException;
use BoletoParser\Utils\BarcodeConverter;
use BoletoParser\Utils\CheckDigit;
use PHPUnit\Framework\TestCase;

final class BoletoParserTest extends TestCase
{
    public function testParseFromBarcodeExtractsStructuredData(): void
    {
        $barcode = '34193877700000260001790010104351004791020150';
        $boleto = BoletoParser::fromBarcode($barcode);

        $this->assertInstanceOf(Boleto::class, $boleto);
        $this->assertSame('341', $boleto->getBankCode());
        $this->assertSame('Itaú', $boleto->getBankName());
        $this->assertSame(260.0, $boleto->getAmount());
        $this->assertSame('BRL', $boleto->getCurrency());
        $this->assertSame($barcode, $boleto->getBarcode());
        $this->assertNotNull($boleto->getDueDate());
        $this->assertTrue($boleto->isValid());
    }

    public function testParseAutoDetectsBarcode(): void
    {
        $barcode = '34193877700000260001790010104351004791020150';
        $boleto = BoletoParser::parse($barcode);

        $this->assertSame('341', $boleto->getBankCode());
        $this->assertSame(260.0, $boleto->getAmount());
    }

    public function testParseAutoDetectsLinhaDigitavel(): void
    {
        $barcodeBB = '00193373700000001000500940144816060680935031';
        $boleto = BoletoParser::parse($barcodeBB);

        $this->assertSame('001', $boleto->getBankCode());
        $this->assertSame('Banco do Brasil', $boleto->getBankName());
        $this->assertSame(1.0, $boleto->getAmount());
        $this->assertSame('BRL', $boleto->getCurrency());
        $this->assertTrue($boleto->isValid());
    }

    public function testParseWithFormattedBarcode(): void
    {
        $input = '34193 87770 00002 60001 79001 01043 51004 79102 0150';
        $boleto = BoletoParser::parse($input);

        $this->assertSame('341', $boleto->getBankCode());
        $this->assertSame(260.0, $boleto->getAmount());
    }

    public function testInvalidLengthThrows(): void
    {
        $this->expectException(InvalidBoletoException::class);
        BoletoParser::parse('12345');
    }

    public function testInvalidCharactersThrows(): void
    {
        $this->expectException(InvalidBoletoException::class);
        BoletoParser::parse('34191.79001 01043.510047 91020.150008 8 87770026abc');
    }

    public function testBarcodeConversionRoundtrip(): void
    {
        $barcode = '34193877700000260001790010104351004791020150';
        $linha = BarcodeConverter::barcodeToLinhaDigitavel($barcode);
        $back = BarcodeConverter::linhaDigitavelToBarcode($linha);
        $this->assertSame($barcode, $back);
    }

    public function testLinhaToBarcodeConversion(): void
    {
        $barcode = '34193877700000260001790010104351004791020150';
        $linha = BarcodeConverter::barcodeToLinhaDigitavel($barcode);
        $back = BarcodeConverter::linhaDigitavelToBarcode($linha);
        $this->assertSame(44, strlen($back));
        $this->assertSame($barcode, $back);
    }

    public function testAmountExtraction(): void
    {
        $barcode = '34193877700000260001790010104351004791020150';
        $boleto = BoletoParser::fromBarcode($barcode);
        $this->assertSame(260.0, $boleto->getAmount());
    }

    public function testBankDetection(): void
    {
        $banks = [
            '00193373700000001000500940144816060680935031' => 'Banco do Brasil',
            '34193877700000260001790010104351004791020150' => 'Itaú',
        ];
        foreach ($banks as $barcode => $expectedName) {
            $boleto = BoletoParser::fromBarcode($barcode);
            $this->assertSame($expectedName, $boleto->getBankName(), "Failed for barcode: $barcode");
        }
    }

    public function testToArray(): void
    {
        $barcode = '34193877700000260001790010104351004791020150';
        $boleto = BoletoParser::fromBarcode($barcode);
        $arr = $boleto->toArray();

        $this->assertArrayHasKey('bank_code', $arr);
        $this->assertArrayHasKey('bank_name', $arr);
        $this->assertArrayHasKey('amount', $arr);
        $this->assertArrayHasKey('due_date', $arr);
        $this->assertArrayHasKey('barcode', $arr);
        $this->assertArrayHasKey('linha_digitavel', $arr);
        $this->assertArrayHasKey('valid_checksum', $arr);
        $this->assertSame(260.0, $arr['amount']);
    }

    public function testCheckDigitMod10(): void
    {
        $this->assertSame(9, CheckDigit::mod10('00190500'));
        $this->assertTrue(CheckDigit::validateMod10('001905009'));
    }

    public function testCheckDigitMod11(): void
    {
        $barcode = '34193877700000260001790010104351004791020150';
        $this->assertTrue(CheckDigit::validateMod11($barcode, 4));
    }

    public function testInvalidBarcodeChecksumReturnsInvalidBoleto(): void
    {
        $invalidBarcode = '34193877700000260001790010104351004791020151'; // last digit tampered
        $boleto = BoletoParser::fromBarcode($invalidBarcode);
        $this->assertFalse($boleto->isValid());
        $this->assertSame('341', $boleto->getBankCode());
        $this->assertSame(260.0, $boleto->getAmount());
    }

    public function testFromLinhaDigitavelWithInvalidChecksumThrows(): void
    {
        $this->expectException(InvalidBoletoException::class);
        $this->expectExceptionMessage('checksum');
        $validBarcode = '34193877700000260001790010104351004791020150';
        $linha = BarcodeConverter::barcodeToLinhaDigitavel($validBarcode);
        $badLinha = substr($linha, 0, 30) . '9' . substr($linha, 31);
        BoletoParser::fromLinhaDigitavel($badLinha);
    }

    public function testEmptyStringThrows(): void
    {
        $this->expectException(InvalidBoletoException::class);
        BoletoParser::parse('');
    }

    public function testWhitespaceOnlyThrows(): void
    {
        $this->expectException(InvalidBoletoException::class);
        BoletoParser::parse('   ');
    }

    public function testWrongLength45Throws(): void
    {
        $this->expectException(InvalidBoletoException::class);
        $this->expectExceptionMessage('expected 44');
        BoletoParser::parse(str_repeat('1', 45));
    }

    public function testDigitsOnlyAcceptsSpacesAndDots(): void
    {
        $input = '34193 87770 00002 60001 79001 01043 51004 79102 0150';
        $boleto = BoletoParser::parse($input);
        $this->assertSame(260.0, $boleto->getAmount());
    }

    public function testDueDateFactorZeroReturnsNull(): void
    {
        $barcodeWithZeroFactor = '341950000000026000179010104351004791020150';
        $boleto = BoletoParser::fromBarcode($barcodeWithZeroFactor);
        $this->assertNull($boleto->getDueDate());
        $this->assertNull($boleto->getDueDateString());
    }

    public function testCheckDigitMod10EmptyStringReturnsZero(): void
    {
        $this->assertSame(0, CheckDigit::mod10(''));
    }

    public function testCheckDigitValidateMod10EmptyReturnsFalse(): void
    {
        $this->assertFalse(CheckDigit::validateMod10(''));
        $this->assertFalse(CheckDigit::validateMod10('1'));
    }
}
