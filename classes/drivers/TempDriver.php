<?php

declare(strict_types=1);

namespace SimpleWire\Temp;

/**
 * TempDriver — interface all SimpleTemp storage drivers must implement.
 *
 * A driver only decides how one payload is encoded to, and decoded from, a file.
 * Paths, atomic writes, locking, TTL and metadata are handled by Temp.
 *
 * A payload is always: ['meta' => [...], 'data' => mixed]
 *
 * @package SimpleWire
 */
interface TempDriver
{
    /**
     * Short driver name stored in item metadata (e.g. 'php', 'json').
     */
    public function name(): string;

    /**
     * File extension including the dot (e.g. '.php', '.json').
     */
    public function extension(): string;

    /**
     * Encode a payload to file contents.
     *
     * @param array $meta Item metadata
     * @param mixed $data Scalar, null or array of any depth
     * @return string
     * @throws TempException When the data cannot be encoded
     */
    public function encode(array $meta, mixed $data): string;

    /**
     * Read a full payload from a file.
     *
     * @param string $file Absolute path
     * @return array|null ['meta' => [...], 'data' => mixed] or null when unreadable
     */
    public function read(string $file): ?array;

    /**
     * Read metadata only, as cheaply as possible (without loading the data).
     *
     * @param string $file Absolute path
     * @return array|null
     */
    public function readMeta(string $file): ?array;

    /**
     * Called after a file was written or deleted (e.g. to clear OPcache).
     *
     * @param string $file Absolute path
     */
    public function changed(string $file): void;
}
