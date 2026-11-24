<?php

declare(strict_types=1);

namespace SimpleWire\Temp;

/**
 * TempException — thrown on invalid keys, invalid TTLs or data that cannot be stored.
 *
 * @package SimpleWire
 */
class TempException extends \RuntimeException
{
    protected string $key;

    public function __construct(string $message, string $key = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->key = $key;
    }

    /** The key involved, when known. */
    public function getKey(): string
    {
        return $this->key;
    }
}
