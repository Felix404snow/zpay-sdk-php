<?php
declare(strict_types=1);
spl_autoload_register(static function (string $name): void {
    if (str_starts_with($name, 'ZPay\\')) require __DIR__ . '/../src/' . str_replace('\\', '/', substr($name, 5)) . '.php';
});
use ZPay\Client;
use ZPay\ZPayError;
function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$calls = [];
$wire = static function ($method, $url, $headers, $payload, $timeout) use (&$calls): array {
    $calls[] = [$method, $url, $headers, $payload];
    return [202, [], '{"status":"pending"}'];
};
$client = new Client('fixture-id', 'fixture-secret', transport: $wire);
$data = ['amount' => 19.9, 'payerName' => 'Cliente', 'idempotencyKey' => 'order-123'];
$result = $client->payments->create($data);
check($result['status'] === 'pending', '202 must not become paid');
check(json_decode($calls[0][3], true)['amount'] === 19.9, 'BRL precision');
check($calls[0][2]['client-secret'] === 'fixture-secret', 'Auth header');
$client->crypto->withdraw(['amount' => .12345678, 'requestId' => 'crypto-order-123', 'address' => 'ltc1q...']);
check(json_decode($calls[1][3], true)['amount'] === .12345678, 'LTC precision');
try { $client->withdrawals->create(['amount' => 10]); throw new RuntimeException('Key accepted'); }
catch (InvalidArgumentException) {}
check(count($calls) === 2, 'Invalid key dispatched');
$client->payments->create($data);
check($calls[0][3] === $calls[2][3], 'Key must remain unchanged');
foreach (['http://example.test', 'https://user:pass@example.test', 'https://example.test?secret=1'] as $url) {
    try { new Client('id', 'secret', baseUrl: $url); throw new RuntimeException('Unsafe URL accepted'); }
    catch (InvalidArgumentException) {}
}
foreach ([429, 503] as $status) {
    $count = 0;
    $failure = new Client('fixture-id', 'fixture-secret', transport: static function () use (&$count, $status): array {
        $count++; return [$status, ['Retry-After' => '60'], '{"error":"fixture-secret","code":"RATE_LIMIT","nested":["fixture-id"]}'];
    });
    try { $failure->withdrawals->create(['amount' => 10, 'idempotencyKey' => 'order-123']); throw new RuntimeException('Failure accepted'); }
    catch (ZPayError $error) {
        check($error->status === $status && $error->retryAfter === '60' && $error->outcomeUnknown, 'Error metadata');
        check(!str_contains($error->getMessage(), 'fixture-secret'), 'Secret leaked');
        check(!str_contains(json_encode($error->details), 'fixture-id'), 'ID leaked');
    }
    check($count === 1, 'Automatic retry');
}
foreach (['<html>Cloudflare</html>', '[]', 'null'] as $raw) {
    $invalid = new Client('id', 'secret', transport: static fn() => [200, [], $raw]);
    try { $invalid->payments->create($data); throw new RuntimeException('Invalid response accepted'); }
    catch (ZPayError $error) { check($error->errorCode === 'INVALID_RESPONSE' && $error->outcomeUnknown, 'Invalid response metadata'); }
}
$timeoutCalls = 0;
$timeout = new Client('id', 'secret', transport: static function () use (&$timeoutCalls): array { $timeoutCalls++; throw new RuntimeException('secret'); });
try { $timeout->withdrawals->create($data); throw new RuntimeException('Timeout accepted'); }
catch (ZPayError $error) { check($error->outcomeUnknown && !str_contains($error->getMessage(), 'secret'), 'Timeout outcome'); }
check($timeoutCalls === 1, 'Timeout retry');
ob_start(); var_dump($client); $debug = ob_get_clean(); check(!str_contains($debug, 'fixture-secret'), 'Debug leaked credentials');
$manifestPath = file_exists(__DIR__ . '/../operations.json') ? __DIR__ . '/../operations.json' : __DIR__ . '/../../operations.json';
$manifest = json_decode(file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
foreach ($manifest as $group => $methods) foreach ($methods as $name => [$verb, $route, $kind]) {
    $args = str_starts_with($kind, 'id') ? ['id/a?x=1'] : [];
    if (in_array($kind, ['body', 'idBody'], true)) $args[] = ['amount' => 12.34, 'idempotencyKey' => 'order-123', 'requestId' => 'crypto-order-123'];
    elseif (in_array($kind, ['query', 'idQuery'], true)) $args[] = ['page' => 2];
    elseif ($kind === 'to') $args[] = '@loja';
    $client->{$group}->{$name}(...$args);
    $last = $calls[array_key_last($calls)];
    check($last[0] === $verb, 'Wrong verb');
    check(parse_url($last[1], PHP_URL_PATH) === '/api/v1' . str_replace('{id}', 'id%2Fa%3Fx%3D1', $route), 'Wrong route');
}
echo "PASS: PHP auth, 46 methods, money precision, stable keys, 202, redaction, timeout and no retries.\n";
