<?php

declare(strict_types=1);

namespace BoletoParser;

use BoletoParser\Exceptions\InvalidBoletoException;
use BoletoParser\Parser\ArrecadacaoParser;
use BoletoParser\Utils\BarcodeConverter;
use BoletoParser\Utils\BoletoExtractor;
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
    private const ARRECADACAO_LENGTH = 48;
    private const ARRECADACAO_FIRST_DIGIT = '8';
    private const BARCODE_DV_POSITION = 4;

    /** Base date for due-date factor (until 21/02/2025). */
    private const BASE_DATE_LEGACY = '1997-10-07';
    /** Base date for due-date factor (from 22/02/2025; factor 1000 = this date). */
    private const BASE_DATE_CURRENT = '2025-02-22';
    private const DUE_DATE_FACTOR_RESET = 1000;

    /** @var array<string, string> Bank code (3 digits) => bank name */
    private const BANK_NAMES = [
        '001' => 'Banco do Brasil S.A.',
        '003' => 'Banco da Amazônia S.A.',
        '004' => 'Banco do Nordeste do Brasil S.A.',
        '007' => 'Banco Nacional de Desenvolvimento Econômico e Social - BNDES',
        '012' => 'Banco Inbursa S.A.',
        '014' => 'State Street Brasil S.A. - Banco Comercial',
        '017' => 'BNY Mellon Banco S.A.',
        '018' => 'Banco Tricury S.A.',
        '021' => 'BANESTES S.A. Banco do Estado do Espírito Santo',
        '024' => 'Banco BANDEPE S.A.',
        '025' => 'Banco Alfa S.A.',
        '029' => 'Banco Itaú Consignado S.A.',
        '033' => 'Banco Santander  (Brasil)  S.A.',
        '036' => 'Banco Bradesco BBI S.A.',
        '037' => 'Banco do Estado do Pará S.A.',
        '040' => 'Banco Cargill S.A.',
        '041' => 'Banco do Estado do Rio Grande do Sul S.A.',
        '047' => 'Banco do Estado de Sergipe S.A.',
        '051' => 'Banco de Desenvolvimento do Espírito Santo S.A.',
        '062' => 'Hipercard Banco Múltiplo S.A.',
        '063' => 'Banco Bradescard S.A.',
        '064' => 'Goldman Sachs do Brasil Banco Múltiplo S.A.',
        '065' => 'Banco Andbank (Brasil) S.A.',
        '066' => 'Banco Morgan Stanley S.A.',
        '069' => 'Banco Crefisa S.A.',
        '070' => 'BRB - Banco de Brasília S.A.',
        '074' => 'Banco J. Safra S.A.',
        '075' => 'Banco ABN AMRO S.A.',
        '076' => 'Banco KDB S.A.',
        '077' => 'Banco Inter S.A.',
        '078' => 'Haitong Banco de Investimento do Brasil S.A.',
        '079' => 'Picpay Bank - Banco Múltiplo S.A.',
        '081' => 'BancoSeguro S.A.',
        '082' => 'Banco Topázio S.A.',
        '083' => 'Banco da China Brasil S.A.',
        '084' => 'Uniprime Norte do Paraná - Coop de Economia e Crédito Mútuo dos Médicos, Profissionais das Ciências',
        '085' => 'Cooperativa Central de Crédito - AILOS',
        '087' => 'Cooperativa Central de Economia e Crédito Mútuo das Unicreds de Santa Catarina e Paraná',
        '089' => 'Cooperativa de Crédito Rural da Região da Mogiana',
        '090' => 'Cooperativa Central de Economia e Crédito Mutuo - SICOOB UNIMAIS',
        '091' => 'Unicred Central do Rio Grande do Sul',
        '092' => 'Brickell S.A. Crédito, Financiamento e Investimento',
        '094' => 'Banco Finaxis S.A.',
        '095' => 'Banco Travelex S.A.',
        '096' => 'Banco B3 S.A.',
        '097' => 'Cooperativa Central de Crédito Noroeste Brasileiro Ltda.',
        '098' => 'CREDIALIANÇA COOPERATIVA DE CRÉDITO RURAL',
        '104' => 'Caixa Econômica Federal',
        '107' => 'Banco BOCOM BBM S.A.',
        '114' => 'Central das Cooperativas de Economia e Crédito Mútuo do Estado do Espírito Santo Ltda.',
        '118' => 'Standard Chartered Bank (Brasil) S/A–Bco Invest.',
        '119' => 'Banco Western Union do Brasil S.A.',
        '120' => 'Banco Rodobens S.A.',
        '121' => 'Banco Agibank S.A.',
        '122' => 'Banco Bradesco BERJ S.A.',
        '124' => 'Banco Woori Bank do Brasil S.A.',
        '125' => 'Banco Genial S.A.',
        '126' => 'BR Partners Banco de Investimento S.A.',
        '128' => 'Braza Bank S.A. Banco de Câmbio',
        '129' => 'UBS Brasil Banco de Investimento S.A.',
        '132' => 'ICBC do Brasil Banco Múltiplo S.A.',
        '136' => 'Unicred do Brasil - Confederação Nacional das Cooperativas Centrais Unicred LTDA',
        '139' => 'Intesa Sanpaolo Brasil S.A. - Banco Múltiplo',
        '144' => 'Ebury Banco de Câmbio S.A.',
        '159' => 'Casa do Crédito S.A. - Sociedade de Crédito ao Microoempreendedor',
        '163' => 'Commerzbank Brasil S.A. - Banco Múltiplo',
        '184' => 'Banco Itaú BBA S.A.',
        '204' => 'Banco Bradesco Cartões S.A.',
        '208' => 'Banco BTG Pactual S.A.',
        '212' => 'Banco Original S.A.',
        '213' => 'Banco Arbi S.A.',
        '217' => 'Banco John Deere S.A.',
        '218' => 'Banco BS2 S.A.',
        '222' => 'Banco Credit Agricole Brasil S.A.',
        '224' => 'Banco Fibra S.A.',
        '233' => 'Banco BMG Soluções Financeiras S.A.',
        '237' => 'Banco Bradesco S.A.',
        '241' => 'Banco Clássico S.A.',
        '243' => 'Banco Master S.A.',
        '246' => 'Banco ABC Brasil S.A.',
        '249' => 'Banco Investcred Unibanco S.A.',
        '250' => 'BCV - Banco de Crédito e Varejo S.A.',
        '254' => 'Paraná Banco S.A.',
        '265' => 'Banco Fator S.A.',
        '266' => 'Banco Cédula S.A.',
        '269' => 'Banco HSBC S.A.',
        '276' => 'Banco Senff S.A.',
        '299' => 'Banco Afinz S.A. Banco Múltiplo',
        '300' => 'Banco de La Nacion Argentina',
        '318' => 'Banco BMG S.A.',
        '320' => 'Bank Of China (Brasil) Banco Múltiplo S.A.',
        '329' => 'QI SOCIEDADE DE CREDITO DIRETO S.A.',
        '330' => 'Banco Bari de Investimentos e Financiamentos S/A',
        '336' => 'Banco C6 S.A.',
        '341' => 'Itaú Unibanco S.A.',
        '348' => 'Banco XP S.A.',
        '359' => 'Zema Credito, Financiamento e Investimento S.A.',
        '366' => 'Banco Société Générale Brasil S.A.',
        '368' => 'Banco CSF S.A.',
        '370' => 'Banco Mizuho do Brasil S.A.',
        '373' => 'UP.P Sociedade de Empréstimo Entre Pessoas S.A.',
        '376' => 'Banco J. P. Morgan S.A.',
        '389' => 'Banco Mercantil do Brasil S.A.',
        '394' => 'Banco Bradesco Financiamentos S.A.',
        '399' => 'Kirton Bank S.A. - Banco Múltiplo',
        '412' => 'Social Bank Banco Múltiplo',
        '418' => 'Zipidin Soluções Digitais Sociedade de Crédito Direto S.A.',
        '422' => 'Banco Safra S.A.',
        '456' => 'Banco MUFG Brasil S.A.',
        '464' => 'Banco Sumitomo Mitsui Brasileiro S.A.',
        '470' => 'CDC SOCIEDADE DE CREDITO DIRETO S.A',
        '473' => 'Banco Caixa Geral - Brasil S.A.',
        '477' => 'Citibank N.A.',
        '478' => 'Gazincred S.A. Sociedade de Credito, Financiamento e Investimento',
        '479' => 'Banco ItauBank S.A',
        '487' => 'Deutsche Bank S.A. - Banco Alemão',
        '488' => 'JPMorgan Chase Bank, National Association',
        '492' => 'ING Bank N.V.',
        '494' => 'Banco de La Republica Oriental del Uruguay',
        '495' => 'Banco de La Provincia de Buenos Aires',
        '496' => 'BBVA Brasil Banco de Investimento S.A.',
        '505' => 'Banco UBS (Brasil) S.A.',
        '516' => 'QISTA S.A. - CREDITO, FINANCIAMENTO E INVESTIMENTO',
        '531' => 'BMP SOCIEDADE DE CREDITO DIRETO S.A',
        '600' => 'Banco Luso Brasileiro S.A.',
        '604' => 'Banco Industrial do Brasil S.A.',
        '610' => 'Banco VR S.A.',
        '611' => 'Banco Paulista S.A.',
        '612' => 'Banco Guanabara S.A.',
        '613' => 'Omni Banco S.A.',
        '623' => 'Banco PAN S.A.',
        '626' => 'Banco C6 Consignado S.A.',
        '630' => 'Banco Bluebank S.A.',
        '633' => 'Banco Rendimento S.A.',
        '634' => 'Banco Triângulo S.A.',
        '637' => 'Banco Sofisa S.A.',
        '641' => 'Banco Alvorada S.A.',
        '643' => 'Banco Pine S.A.',
        '652' => 'Itaú Unibanco Holding S.A.',
        '653' => 'Banco Pleno S.A.',
        '654' => 'Banco Digimais S.A.',
        '655' => 'Banco Votorantim S.A.',
        '658' => 'Banco Porto Real de Investimentos S.A.',
        '707' => 'Banco Daycoval S.A.',
        '712' => 'Ouribank S.A. Banco Múltiplo',
        '720' => 'BANCO RNX S.A',
        '739' => 'Banco Cetelem S.A.',
        '741' => 'Banco Ribeirão Preto S.A.',
        '743' => 'Banco Semear S.A.',
        '745' => 'Banco Citibank S.A.',
        '747' => 'Banco Rabobank International Brasil S.A.',
        '748' => 'Banco Cooperativo Sicredi S.A.',
        '751' => 'Scotiabank Brasil S.A. Banco Múltiplo',
        '752' => 'Banco BNP Paribas Brasil S.A.',
        '753' => 'Novo Banco Continental S.A. - Banco Múltiplo',
        '754' => 'Banco Sistema S.A.',
        '755' => 'Bank of America Merrill Lynch Banco Múltiplo S.A.',
        '756' => 'Banco Cooperativo Sicoob S.A.',
        '757' => 'Banco KEB HANA do Brasil S.A.',
        '936' => 'COOPERATIVA DE ECONOMIA E CREDITO MUTUO DOS APOSENTADOS, PENSIONISTAS E IDOSOS - SICOOB COOPERNAPI',
        '964' => 'BECKER FINANCEIRA S.A CREDITO, FINANCIAMENTO E INVESTIMENTO',
        '965' => 'COOPERATIVA DE ECONOMIA E CREDITO MUTUO E BENEFICIOS SOCIAIS BECOOPER',
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
        if ($length === self::ARRECADACAO_LENGTH) {
            if ($digits[0] === self::ARRECADACAO_FIRST_DIGIT) {
                return self::fromArrecadacao($digits);
            }
            throw InvalidBoletoException::invalidFormat(
                'Arrecadação boleto (48 digits) must start with digit 8.'
            );
        }

        throw InvalidBoletoException::invalidFormat(
            sprintf(
                'Invalid boleto length: expected 44 (barcode), 47 (linha digitável) or 48 (arrecadação) digits, got %d.',
                $length
            )
        );
    }

    /**
     * Extract all valid boletos from arbitrary text (PDF, email, HTML, OCR).
     * Detects linha digitável (47 digits) and arrecadação (48 digits); normalizes spaces, dots and line breaks.
     * Only returns boletos that parse successfully and pass isValid(). Duplicates are removed.
     *
     * @return list<Boleto>
     */
    public static function extractFromText(string $text): array
    {
        return BoletoExtractor::extract($text);
    }

    /**
     * Parse from linha digitável (47 digits).
     * Spaces and dots are allowed and stripped.
     * isValid() is true only when all four validations pass: campo 1/2/3 mod10 and barcode mod11.
     *
     * @throws InvalidBoletoException When length is not 47 or block checksum fails (campo 1/2/3).
     */
    public static function fromLinhaDigitavel(string $linha): Boleto
    {
        $digits = BarcodeConverter::digitsOnly($linha);
        if (strlen($digits) !== self::LINHA_DIGITAVEL_LENGTH) {
            throw InvalidBoletoException::invalidLength($linha, self::LINHA_DIGITAVEL_LENGTH, strlen($digits));
        }
        $result = BarcodeConverter::linhaDigitavelToBarcodeWithValidation($digits);
        if (!$result['valid_campo1'] || !$result['valid_campo2'] || !$result['valid_campo3']) {
            throw InvalidBoletoException::checksumFailure('campo 1');
        }
        $barcode = $result['barcode'];
        $validChecksum = $result['valid_campo1'] && $result['valid_campo2'] && $result['valid_campo3']
            && CheckDigit::validateMod11($barcode, self::BARCODE_DV_POSITION);
        $linhaDigitavel = BarcodeConverter::barcodeToLinhaDigitavelUnsafe($barcode);
        return self::buildBoleto($barcode, $linhaDigitavel, $validChecksum);
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
        $linhaDigitavel = BarcodeConverter::barcodeToLinhaDigitavelUnsafe($digits);
        return self::buildBoleto($digits, $linhaDigitavel, $validChecksum);
    }

    /**
     * Parse arrecadação boleto (48 digits, must start with 8).
     *
     * @throws InvalidBoletoException When length is not 48 or does not start with 8.
     */
    public static function fromArrecadacao(string $input): Boleto
    {
        $result = ArrecadacaoParser::parse($input);
        return new Boleto(
            bankCode: null,
            bankName: null,
            amount: $result['value'],
            currency: 'BRL',
            dueDate: null,
            barcode: $result['reference'],
            linhaDigitavel: $result['reference'],
            validChecksum: $result['valid'],
            segment: $result['segment'],
            reference: $result['reference'],
        );
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
