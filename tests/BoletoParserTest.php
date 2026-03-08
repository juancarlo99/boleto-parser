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
        $barcode = '34196877700000260001790010104351004791020150';
        $boleto = BoletoParser::fromBarcode($barcode);

        $this->assertInstanceOf(Boleto::class, $boleto);
        $this->assertSame('341', $boleto->getBankCode());
        $this->assertSame('Itaú Unibanco S.A.', $boleto->getBankName());
        $this->assertSame(260.0, $boleto->getAmount());
        $this->assertSame('BRL', $boleto->getCurrency());
        $this->assertSame($barcode, $boleto->getBarcode());
        $this->assertNotNull($boleto->getDueDate());
        $this->assertTrue($boleto->isValid());
    }

    public function testParseAutoDetectsBarcode(): void
    {
        $barcode = '34196877700000260001790010104351004791020150';
        $boleto = BoletoParser::parse($barcode);

        $this->assertSame('341', $boleto->getBankCode());
        $this->assertSame(260.0, $boleto->getAmount());
    }

    public function testParseAutoDetectsBarcodeFormat(): void
    {
        $barcode = '34196877700000260001790010104351004791020150';
        $boleto = BoletoParser::parse($barcode);

        $this->assertSame('341', $boleto->getBankCode());
        $this->assertSame('Itaú Unibanco S.A.', $boleto->getBankName());
        $this->assertSame(260.0, $boleto->getAmount());
        $this->assertSame('BRL', $boleto->getCurrency());
        $this->assertTrue($boleto->isValid());
    }

    public function testParseWithFormattedBarcode(): void
    {
        $input = '34196 87770 00002 60001 79001 01043 51004 79102 0150';
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
        $barcode = '34196877700000260001790010104351004791020150';
        $linha = BarcodeConverter::barcodeToLinhaDigitavel($barcode);
        $back = BarcodeConverter::linhaDigitavelToBarcode($linha);
        $this->assertSame($barcode, $back);
    }

    public function testLinhaToBarcodeConversion(): void
    {
        $barcode = '34196877700000260001790010104351004791020150';
        $linha = BarcodeConverter::barcodeToLinhaDigitavel($barcode);
        $back = BarcodeConverter::linhaDigitavelToBarcode($linha);
        $this->assertSame(44, strlen($back));
        $this->assertSame($barcode, $back);
    }

    public function testAmountExtraction(): void
    {
        $barcode = '34196877700000260001790010104351004791020150';
        $boleto = BoletoParser::fromBarcode($barcode);
        $this->assertSame(260.0, $boleto->getAmount());
    }

    public function testBankDetection(): void
    {
        $banks = [
            '00193373700000001000500940144816060680935031' => 'Banco do Brasil S.A.',
            '34196877700000260001790010104351004791020150' => 'Itaú Unibanco S.A.',
        ];
        foreach ($banks as $barcode => $expectedName) {
            $boleto = BoletoParser::fromBarcode($barcode);
            $this->assertSame($expectedName, $boleto->getBankName(), "Failed for barcode: $barcode");
        }
    }

    public function testToArray(): void
    {
        $barcode = '34196877700000260001790010104351004791020150';
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
        $barcode = '34196877700000260001790010104351004791020150';
        $this->assertTrue(CheckDigit::validateMod11($barcode, 4));
    }

    public function testInvalidBarcodeChecksumReturnsInvalidBoleto(): void
    {
        $invalidBarcode = '34196877700000260001790010104351004791020151'; // last digit tampered
        $boleto = BoletoParser::fromBarcode($invalidBarcode);
        $this->assertFalse($boleto->isValid());
        $this->assertSame('341', $boleto->getBankCode());
        $this->assertSame(260.0, $boleto->getAmount());
    }

    public function testFromLinhaDigitavelValidWhenAllFourChecksPass(): void
    {
        $barcode = '34196877700000260001790010104351004791020150';
        $linha = BarcodeConverter::barcodeToLinhaDigitavel($barcode);
        $boleto = BoletoParser::fromLinhaDigitavel($linha);
        $this->assertTrue($boleto->isValid(), 'Linha with valid campo1/2/3 mod10 and barcode mod11 must be valid');
        $this->assertSame('341', $boleto->getBankCode());
        $this->assertSame(260.0, $boleto->getAmount());
    }

    public function testFromLinhaDigitavelInvalidBarcodeDvReturnsInvalidBoleto(): void
    {
        $linha = '23792656029000510380027000114705113810000018238';
        $boleto = BoletoParser::fromLinhaDigitavel($linha);
        $this->assertSame('237', $boleto->getBankCode());
        $this->assertSame(182.38, $boleto->getAmount());
        $this->assertFalse($boleto->isValid(), 'Linha with invalid barcode DV (mod11) must yield isValid false');
    }

    public function testFromLinhaDigitavelWithInvalidChecksumThrows(): void
    {
        $this->expectException(InvalidBoletoException::class);
        $this->expectExceptionMessage('checksum');
        $validBarcode = '34196877700000260001790010104351004791020150';
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
        $input = '34196 87770 00002 60001 79001 01043 51004 79102 0150';
        $boleto = BoletoParser::parse($input);
        $this->assertSame(260.0, $boleto->getAmount());
    }

    public function testDueDateFactorZeroReturnsNull(): void
    {
        $barcodeWithZeroFactor = '34195000000000260000179010104351004791020150';
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

    public function testBancoInterLayoutBLinhaToBarcode(): void
    {
        $linha = '07790.00116 01001.305208 87011.703169 2 00000000000000';
        $digits = preg_replace('/\D/', '', $linha);
        $this->assertSame(47, strlen($digits));
        $barcode = BarcodeConverter::linhaDigitavelToBarcode($linha);
        $this->assertSame(44, strlen($barcode));
        $this->assertSame('077', substr($barcode, 0, 3));
        $this->assertSame('07792000000000000000001101001305208701170316', $barcode);
    }

    public function testParse48DigitsArrecadacao(): void
    {
        $arrecadacao = '806000000000000000000000000000000000000000000000';
        $this->assertSame(48, strlen($arrecadacao));
        $boleto = BoletoParser::parse($arrecadacao);
        $this->assertInstanceOf(Boleto::class, $boleto);
        $this->assertNull($boleto->getBankCode());
        $this->assertNull($boleto->getBankName());
        $this->assertSame('BRL', $boleto->getCurrency());
        $this->assertSame($arrecadacao, $boleto->getBarcode());
        $this->assertSame($arrecadacao, $boleto->getLinhaDigitavel());
        $this->assertTrue($boleto->isValid());
    }

    public function testFromArrecadacaoInvalidChecksumReturnsInvalidBoleto(): void
    {
        $valid = '806000000000000000000000000000000000000000000000';
        $invalid = substr($valid, 0, 11) . '9' . substr($valid, 12);
        $boleto = BoletoParser::fromArrecadacao($invalid);
        $this->assertFalse($boleto->isValid());
        $this->assertNull($boleto->getBankCode());
    }

    public function testParse48DigitsWrongLengthThrows(): void
    {
        $this->expectException(InvalidBoletoException::class);
        BoletoParser::fromArrecadacao(str_repeat('1', 47));
    }

    public function testZeroValueBoletoBancario(): void
    {
        $barcode = '34195000000000260000179010104351004791020150';
        $boleto = BoletoParser::fromBarcode($barcode);
        $this->assertSame(260.0, $boleto->getAmount());
        $barcodeZero = '34195000000000000000179010104351004791020150';
        $boletoZero = BoletoParser::fromBarcode($barcodeZero);
        $this->assertSame(0.0, $boletoZero->getAmount());
    }

    public function testArrecadacaoValueIndicatorSixYieldsZeroAmount(): void
    {
        $arrecadacao = '806000000000000000000000000000000000000001823872';
        $this->assertSame(48, strlen($arrecadacao));
        $boleto = BoletoParser::fromArrecadacao($arrecadacao);
        $this->assertSame(0.0, $boleto->getAmount());
    }

    public function testArrecadacaoExample83620(): void
    {
        $arrecadacao = '836200000015021300481009005551501003015000000000';
        $this->assertSame(48, strlen($arrecadacao));
        $boleto = BoletoParser::parse($arrecadacao);
        $this->assertNull($boleto->getBankCode());
        $this->assertSame('3', $boleto->getSegment());
        $this->assertSame($arrecadacao, $boleto->getReference());
        $arr = $boleto->toArray();
        $this->assertSame('arrecadacao', $arr['type']);
        $this->assertSame(0.0, $boleto->getAmount());
    }

    public function testParse48DigitsNotStartingWith8Throws(): void
    {
        $this->expectException(InvalidBoletoException::class);
        $this->expectExceptionMessage('start with digit 8');
        BoletoParser::parse('9' . str_repeat('0', 47));
    }

    public function testExtractFromTextFindsOneBoletoInFormattedText(): void
    {
        $text = "Pagamento boleto:\n\n34191.79040 10104.351030 47910.201509 6 87770000026000\n\nObrigado.";
        $boletos = BoletoParser::extractFromText($text);
        $this->assertCount(1, $boletos);
        $this->assertInstanceOf(Boleto::class, $boletos[0]);
        $this->assertSame('341', $boletos[0]->getBankCode());
        $this->assertSame(260.0, $boletos[0]->getAmount());
        $this->assertTrue($boletos[0]->isValid());
    }

    public function testExtractFromTextFindsOneBoletoFromRaw47Digits(): void
    {
        $text = 'Seu boleto: 34191790401010435103047910201509687770000026000 envie até o vencimento.';
        $boletos = BoletoParser::extractFromText($text);
        $this->assertCount(1, $boletos);
        $this->assertSame('341', $boletos[0]->getBankCode());
    }

    public function testExtractFromTextDeduplicatesRepeatedBoletos(): void
    {
        $linha = '34191790401010435103047910201509687770000026000';
        $text = "Boleto 1: $linha\nBoleto 2: $linha\nMesmo boleto acima.";
        $boletos = BoletoParser::extractFromText($text);
        $this->assertCount(1, $boletos);
    }

    public function testExtractFromTextIgnoresInvalidCandidates(): void
    {
        $text = 'Números inválidos: 11111111111111111111111111111111111111111111111 e 99999999999999999999999999999999999999999999999999';
        $boletos = BoletoParser::extractFromText($text);
        $this->assertCount(0, $boletos);
    }

    public function testExtractFromTextReturnsOnlyValidBoletos(): void
    {
        $validLinha = '34191790401010435103047910201509687770000026000';
        $invalidLinha = '23792656029000510380027000114705113810000018238';
        $text = "Válido: $validLinha Inválido (DV errado): $invalidLinha";
        $boletos = BoletoParser::extractFromText($text);
        $this->assertCount(1, $boletos);
        $this->assertSame('341', $boletos[0]->getBankCode());
    }

    public function testExtractFromTextFindsArrecadacao48Digits(): void
    {
        $arrecadacao = '806000000000000000000000000000000000000000000000';
        $text = "Concessionária: $arrecadacao Pagamento até o vencimento.";
        $boletos = BoletoParser::extractFromText($text);
        $this->assertCount(1, $boletos);
        $this->assertNull($boletos[0]->getBankCode());
        $this->assertSame($arrecadacao, $boletos[0]->getReference());
        $this->assertTrue($boletos[0]->isValid());
    }
}
