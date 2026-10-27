<?php
declare(strict_types=1);
namespace ZPay;

final class ZPayError extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly string $errorCode = 'ZPAY_ERROR',
        public readonly mixed $details = null,
        public readonly ?string $retryAfter = null,
        public readonly bool $outcomeUnknown = false,
    ) { parent::__construct($message); }
}
