<?php
declare(strict_types=1);
namespace ZPay;

abstract class Resource
{
    public function __construct(protected readonly Client $client) {}
    protected function id(string $value): string
    {
        if (trim($value) === '' || $value === '.' || $value === '..') throw new \InvalidArgumentException('Identificador inválido.');
        return rawurlencode($value);
    }
    protected function keyed(array $data, string $field): array
    {
        $key = $data[$field] ?? null;
        if (!is_string($key) || trim($key) === '' || strlen($key) > 120) throw new \InvalidArgumentException("Informe $field estável, com até 120 caracteres.");
        if ($field === 'requestId' && !preg_match('/^[a-zA-Z0-9_:.-]{8,120}$/D', $key)) throw new \InvalidArgumentException('requestId inválido.');
        return $data;
    }
}
