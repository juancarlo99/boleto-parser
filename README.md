# boleto-parser

Biblioteca PHP para interpretar **linha digitável** ou **código de barras** de boleto bancário brasileiro (padrão FEBRABAN) e extrair dados estruturados.

- **PHP 8.1+**
- Sem dependências externas
- PSR-12, SOLID, tipos estritos
- Pronta para Composer e PHPUnit

## Instalação

```bash
composer require juan/boleto-parser
```

## Uso

### Parse automático (linha ou barcode)

```php
<?php

use BoletoParser\BoletoParser;

$input = '34198877700000260001790010104351004791020150'; // ou com espaços
$boleto = BoletoParser::parse($input);

echo $boleto->getBankName();   // Itaú
echo $boleto->getAmount();     // 260.0
echo $boleto->getDueDateString(); // ex.: 2024-05-20
echo $boleto->getBarcode();    // 44 dígitos
echo $boleto->getLinhaDigitavel(); // 47 dígitos
echo $boleto->isValid();       // true se checksum OK
```

### Por tipo de entrada

```php
$boleto = BoletoParser::fromLinhaDigitavel($linha);  // 47 dígitos
$boleto = BoletoParser::fromBarcode($barcode);       // 44 dígitos
$boleto = BoletoParser::fromArrecadacao($input);     // 48 dígitos
```

### API do objeto Boleto

| Método | Retorno |
|--------|---------|
| `getBankCode()` | Código do banco (ex: `341`) ou `null` (arrecadação) |
| `getBankName()` | Nome do banco (ex: `Itaú`) ou `null` (arrecadação) |
| `getAmount()` | Valor em float (ex: `260.0`) |
| `getCurrency()` | Moeda (ex: `BRL`) |
| `getDueDate()` | `DateTimeImmutable\|null` (data de vencimento) |
| `getDueDateString()` | Data em `Y-m-d` ou `null` |
| `getBarcode()` | Código de barras (44 dígitos) |
| `getLinhaDigitavel()` | Linha digitável (47 dígitos) ou 48 para arrecadação |
| `isValid()` | `true` se checksum válido |
| `getSegment()` | Segmento (arrecadação; `null` em boleto bancário) |
| `getReference()` | Referência/código completo (arrecadação; `null` em boleto bancário) |
| `toArray()` | Array associativo com todos os campos (inclui `type`/`segment`/`reference` em arrecadação) |

### Exemplo de saída (toArray)

```php
[
    'bank_code' => '341',
    'bank_name' => 'Itaú',
    'amount' => 260.0,
    'currency' => 'BRL',
    'due_date' => '2024-05-20',
    'barcode' => '34198877700000260001790010104351004791020150',
    'linha_digitavel' => '...',
    'valid_checksum' => true,
]
```

## Formatos suportados

1. **Linha digitável (47 dígitos)** — boleto bancário  
   Com ou sem formatação (pontos e espaços são ignorados).  
   Exemplo: `00190.50095 40144.816069 06809.350314 3 37370000000100`

2. **Código de barras (44 dígitos)** — boleto bancário  
   Apenas números; espaços são permitidos e removidos.  
   Exemplo: `34198877700000260001790010104351004791020150`

3. **Arrecadação (48 dígitos)** — contas de luz, água, tributos etc.  
   Sempre começa com o dígito **8**. Estrutura FEBRABAN: posição 1 = 8, 2 = segmento, 3 = indicador de valor (6 ou 7 → mod10 nos blocos; 8 ou 9 → mod11), 4 = DV geral, 5-15 = bloco 1 (10 dados + 1 DV), 16-26 = bloco 2, 27-37 = bloco 3, 38-48 = bloco 4.  
   Para arrecadação, `getBankCode()` e `getBankName()` retornam `null`; use `getSegment()`, `getReference()` e `toArray()` inclui `type` = `arrecadacao`. Valor extraído quando o indicador (3.º dígito) for 7, 8 ou 9 (quando for 6, o valor retornado é 0).

O `parse()` detecta automaticamente o formato: 44 dígitos → barcode; 47 → linha digitável; 48 e primeiro dígito 8 → arrecadação. Entrada vazia ou só espaços resulta em exceção.

## Validação

- **Comprimento:** 44 (barcode), 47 (linha digitável) ou 48 (arrecadação) dígitos, após remover espaços e pontos.
- **Caracteres:** permitidos apenas dígitos, espaços e pontos. Qualquer outro caractere lança exceção.
- **Linha digitável (47 dígitos):** `isValid()` só retorna `true` quando as **quatro** validações passam:
  - **Campo 1** (dígitos 1–9 + DV) → módulo 10  
  - **Campo 2** (dígitos 10–20 + DV) → módulo 10  
  - **Campo 3** (dígitos 21–31 + DV) → módulo 10  
  - **Código de barras** (DV na posição 5) → módulo 11  
- **Barcode (44 dígitos):** validação pelo módulo 11 do barcode. Se o checksum for inválido, o parser ainda retorna um `Boleto`, mas `isValid()` será `false`.
- **Arrecadação (48 dígitos):** validação por blocos (mod10 ou mod11 conforme o 3.º dígito) e DV geral.

Em caso de falha de comprimento ou caractere inválido é lançada `BoletoParser\Exceptions\InvalidBoletoException`. Falha de checksum nos **campos** da linha digitável também lança exceção; no **barcode** apenas marca o boleto como inválido.

## Extração de boletos em texto

Para detectar boletos dentro de texto arbitrário (PDF, e-mail, HTML, OCR):

```php
use BoletoParser\BoletoParser;

$text = "Pagamento boleto:\n\n34191.79040 10104.351030 47910.201509 6 87770000026000\n\nObrigado.";
$boletos = BoletoParser::extractFromText($text);
// $boletos é uma lista de Boleto (apenas válidos e sem duplicados)

foreach ($boletos as $boleto) {
    echo $boleto->getBankName() . ' - ' . $boleto->getAmount() . "\n";
}
```

- Detecta **linha digitável (47 dígitos)** e **arrecadação (48 dígitos)**, com ou sem formatação (espaços, pontos e quebras de linha são removidos).
- Só retorna boletos que **parseiam** e passam em **`isValid()`**.
- Remove **duplicados** (mesmo boleto repetido no texto aparece uma vez).
- Implementado em `BoletoParser\Utils\BoletoExtractor` (regex, normalização e deduplicação).

## Conversão linha ↔ barcode

```php
use BoletoParser\Utils\BarcodeConverter;

$barcode = BarcodeConverter::linhaDigitavelToBarcode($linha);
$linha   = BarcodeConverter::barcodeToLinhaDigitavel($barcode);
```

## Bancos mapeados

| Código | Banco        |
|--------|--------------|
| 001    | Banco do Brasil |
| 033    | Santander    |
| 104    | Caixa        |
| 237    | Bradesco     |
| 341    | Itaú         |
| 756    | Sicoob       |

Outros códigos retornam `"Banco {código}"`.

## Utilitários

**Dígito verificador** (`CheckDigit`):

```php
use BoletoParser\Utils\CheckDigit;

CheckDigit::mod10('00190500');   // int
CheckDigit::mod11('00193737...'); // int
CheckDigit::validateMod10('001905009');
CheckDigit::validateMod11($barcode, 4);
```

**Extração em texto** (`BoletoExtractor`): regex, normalização e deduplicação de candidatos. Use `BoletoParser::extractFromText($text)` para obter a lista de boletos; para só obter as strings de dígitos candidatas, use `BoletoExtractor::extractCandidateDigitStrings($text)`.

## Testes

```bash
composer install
./vendor/bin/phpunit
```

## Licença

MIT. Ver ficheiro [LICENSE](LICENSE).
