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
```

### API do objeto Boleto

| Método | Retorno |
|--------|---------|
| `getBankCode()` | Código do banco (ex: `341`) |
| `getBankName()` | Nome do banco (ex: `Itaú`) |
| `getAmount()` | Valor em float (ex: `260.0`) |
| `getCurrency()` | Moeda (ex: `BRL`) |
| `getDueDate()` | `DateTimeImmutable\|null` (data de vencimento) |
| `getDueDateString()` | Data em `Y-m-d` ou `null` |
| `getBarcode()` | Código de barras (44 dígitos) |
| `getLinhaDigitavel()` | Linha digitável (47 dígitos) |
| `isValid()` | `true` se checksum válido |
| `toArray()` | Array associativo com todos os campos |

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

1. **Linha digitável (47 dígitos)**  
   Com ou sem formatação (pontos e espaços são ignorados).  
   Exemplo: `00190.50095 40144.816069 06809.350314 3 37370000000100`

2. **Código de barras (44 dígitos)**  
   Apenas números; espaços são permitidos e removidos.  
   Exemplo: `34198877700000260001790010104351004791020150`

O `parse()` detecta automaticamente o formato pelo tamanho (44 ou 47 dígitos após normalização). Entrada vazia ou só espaços resulta em zero dígitos e exceção de formato/comprimento.

## Validação

- **Comprimento:** 44 dígitos (barcode) ou 47 (linha digitável), após remover espaços e pontos.
- **Caracteres:** permitidos apenas dígitos, espaços e pontos. Qualquer outro caractere lança exceção.
- **Módulo 10** nos três primeiros blocos da linha digitável (ao converter ou ao usar `fromLinhaDigitavel`).
- **Módulo 11** no código de barras (dígito na posição 5). Em barcode com checksum inválido, o parser ainda retorna um `Boleto`, mas `isValid()` será `false` e `getLinhaDigitavel()` retornará a mesma string de 44 dígitos do barcode.

Em caso de falha de comprimento ou caractere inválido é lançada `BoletoParser\Exceptions\InvalidBoletoException`. Falha de checksum na **linha digitável** também lança exceção; no **barcode** apenas marca o boleto como inválido.

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

## Utilitários (dígito verificador)

```php
use BoletoParser\Utils\CheckDigit;

CheckDigit::mod10('00190500');   // int
CheckDigit::mod11('00193737...'); // int
CheckDigit::validateMod10('001905009');
CheckDigit::validateMod11($barcode, 4);
```

## Testes

```bash
composer install
./vendor/bin/phpunit
```

## Licença

MIT. Ver ficheiro [LICENSE](LICENSE).
