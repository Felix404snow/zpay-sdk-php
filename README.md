# Z.PAY SDK PHP · 0.1.0

PHP 8.1+, extensões cURL e JSON. Namespace `ZPay`, autoload PSR-4, sem bibliotecas de execução externas. As 46 operações cobrem os mesmos recursos do SDK JavaScript.

## Instalação via Composer e GitHub

**Ainda não publicado no Packagist.** Adicione o repositório público ao Composer e instale a versão:

```sh
composer config repositories.zpay vcs https://github.com/Felix404snow/zpay-sdk-php
composer require isnouu/zpay-sdk:^0.1
```

Fontes e versões: https://github.com/Felix404snow/zpay-sdk-php.
Para usar uma cópia local, configure um repositório `path`:

```sh
composer config repositories.zpay path ./caminho/zpay-sdk-php
composer require isnouu/zpay-sdk:@dev
```

O caminho deve apontar para a pasta que contém este `composer.json`. Não use `composer require isnouu/zpay-sdk` como se esta versão já estivesse no registro público.

## Uso

```php
<?php
require __DIR__ . '/vendor/autoload.php';

$client = new \ZPay\Client(getenv('ZPAY_CLIENT_ID'), getenv('ZPAY_CLIENT_SECRET'));
$payment = $client->payments->create([
    'amount' => 19.90,
    'payerName' => 'Cliente',
    'description' => 'Pedido 123',
    'idempotencyKey' => 'pedido-123',
]);
$current = $client->payments->get($payment['paymentId']);
echo $current['status'];
```

Credenciais exclusivamente no servidor. Respostas são arrays associativos com os nomes originais da API. Não confunda `$client->withdrawals` (saque para chave cadastrada) com `$client->cashouts` (envio Pix para terceiro).

## Recursos

Os grupos são propriedades do cliente: `account`, `payments`, `donations`, `checkouts`, `withdrawals`, `cashouts`, `refunds`, `transfers`, `boletos`, `billPayments`, `meds`, `crypto`, `subaccounts`, `splits`. Métodos e parâmetros explícitos estão em `src/Resources/`.

Exemplos: `$client->account->fees()`, `$client->donations->list(['page' => 1])`, `$client->subaccounts->fund($id, $dados)`, `$client->crypto->createPayment($dados)`, `$client->crypto->swap($dados)` e `$client->crypto->operation($id)`.

```php
$charge = $client->crypto->createPayment([
    'requestId' => 'ltc-pedido-123', 'amount' => 50,
    'currency' => 'brl', 'payCurrency' => 'ltc',
]);
// Exiba payAddress e o payAmount exato, com oito casas LTC.

// Execute somente após confirmação do usuário sobre carteira, rede e cotação.
$swap = $client->crypto->swap([
    'requestId' => 'swap-pedido-456', 'amount' => 100,
    'address' => $carteiraConfirmadaPeloUsuario,
    'confirmNotCoinbase' => true, 'acceptProviderRate' => true,
    // 'totpCode' => $codigoDaConta,
]);
```

Recebimento novo: LTC. Swap: saldo BRL → USDT BEP20, não LTC → USDT. A Purin determina a cotação no envio. Custos, KYC, telefone, 2FA e limites são exigidos pela API. Saldos USDT legados permanecem separados.

## Erros e referências

```php
try {
    $result = $client->withdrawals->create(['amount' => 50, 'idempotencyKey' => 'saque-pedido-123']);
} catch (\ZPay\ZPayError $error) {
    error_log($error->status . ' ' . $error->errorCode);
    if ($error->outcomeUnknown) {
        $result = $client->withdrawals->get('saque-pedido-123');
    }
}
```

`ZPayError` expõe `status`, `errorCode`, `details`, `retryAfter`, `outcomeUnknown`. Salve a referência antes de enviar. Timeout ou resposta incerta não autoriza novo envio ou outra chave. Não há retry, polling ou paginação automática. HTTP 202 e `pending` não significam pagamento concluído. Checkouts/splits não oferecem idempotência de criação nesta versão; reembolsos usam a transação original.

Valores são em reais, exceto quantidade LTC e os campos de centavos dos splits. O cliente não converte unidades. Parâmetros do construtor: `baseUrl` (padrão `https://zpaysolution.com/api/v1`), `timeout` em segundos (padrão 30) e `transport` opcional para testes. HTTPS e certificados são verificados; redirecionamentos são bloqueados.

Teste local: `php sdk/php/tests/client.php` na raiz da Z.PAY, ou `composer test` nesta pasta. Os testes usam fixtures, sem movimentação financeira.
