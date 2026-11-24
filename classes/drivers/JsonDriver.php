<?php

declare(strict_types=1);

namespace SimpleWire\Temp;

/**
 * JsonDriver — stores payloads as human-readable JSON files.
 *
 * Slower than PhpDriver (every read parses the file), but the files can be
 * inspected by hand or consumed by tools outside PHP.
 *
 * @package SimpleWire
 */
class JsonDriver implements TempDriver
{
    protected bool $pretty;

    public function __construct(bool $pretty = true)
    {
        $this->pretty = $pretty;
    }

    // ========================================
    // TempDriver implementation
    // ========================================

    public function name(): string
    {
        return 'json';
    }

    public function extension(): string
    {
        return '.json';
    }

    public function encode(array $meta, mixed $data): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;
        if ($this->pretty) $flags |= JSON_PRETTY_PRINT;

        $contents = json_encode(['meta' => $meta, 'data' => $data], $flags);
        if ($contents === false) {
            throw new TempException("SimpleTemp: cannot JSON-encode '{$meta['key']}': " . json_last_error_msg());
        }

        return $contents;
    }

    public function read(string $file): ?array
    {
        $json = @file_get_contents($file);
        if ($json === false) return null;
        $payload = json_decode($json, true);
        return is_array($payload) ? $payload : null;
    }

    public function readMeta(string $file): ?array
    {
        $meta = $this->read($file)['meta'] ?? null;
        return is_array($meta) ? $meta : null;
    }

    public function changed(string $file): void
    {
        // Nothing cached
    }
}
