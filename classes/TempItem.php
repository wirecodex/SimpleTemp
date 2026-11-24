<?php

declare(strict_types=1);

namespace SimpleWire\Temp;

/**
 * TempItem — metadata for one stored item (the data itself is not loaded).
 *
 * Plain PHP data class: no ProcessWire dependency, no DI required.
 *
 * @property-read string $key       Full key, e.g. "dashboard.stats"
 * @property-read string $group     Group part, e.g. "dashboard" ('' for root keys)
 * @property-read string $name      Last segment, e.g. "stats"
 * @property-read string $driver    'php' or 'json'
 * @property-read string $file      Absolute path
 * @property-read int    $size      Bytes
 * @property-read int    $created   Timestamp of first write (kept across rewrites)
 * @property-read int    $modified  Timestamp of last write
 * @property-read int    $expires   Timestamp, 0 = never
 * @property-read int    $ttl       TTL in seconds used on last write
 * @property-read bool   $expired
 * @property-read int    $remaining Seconds left, -1 never expires, 0 expired
 * @property-read int    $age       Seconds since last write
 * @property-read string $sizeStr   Human-readable size
 *
 * @package SimpleWire
 */
class TempItem
{
    protected array $data = [];

    public function __construct(array $meta)
    {
        $key = (string) ($meta['key'] ?? '');
        $pos = strrpos($key, '.');

        $this->data = [
            'key'      => $key,
            'group'    => $pos === false ? '' : substr($key, 0, $pos),
            'name'     => $pos === false ? $key : substr($key, $pos + 1),
            'driver'   => (string) ($meta['driver'] ?? 'php'),
            'file'     => (string) ($meta['file'] ?? ''),
            'size'     => (int) ($meta['size'] ?? 0),
            'created'  => (int) ($meta['created'] ?? 0),
            'modified' => (int) ($meta['modified'] ?? 0),
            'expires'  => (int) ($meta['expires'] ?? 0),
            'ttl'      => (int) ($meta['ttl'] ?? 0),
        ];
    }

    // ========================================
    // Property access
    // ========================================

    public function __get(string $key): mixed
    {
        return $this->get($key);
    }

    public function __isset(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * Get a stored or computed property.
     *
     * @param string $key
     * @return mixed
     */
    public function get(string $key): mixed
    {
        return match ($key) {
            'expired'   => $this->isExpired(),
            'remaining' => $this->remaining(),
            'age'       => time() - $this->data['modified'],
            'sizeStr'   => \ProcessWire\wireBytesStr($this->data['size']),
            default     => $this->data[$key] ?? null,
        };
    }

    // ========================================
    // Computed helpers
    // ========================================

    /** Is the item past its expiry time? */
    public function isExpired(): bool
    {
        $expires = $this->data['expires'];
        return $expires > 0 && $expires <= time();
    }

    /** Seconds until expiry: -1 never expires, 0 expired. */
    public function remaining(): int
    {
        $expires = $this->data['expires'];
        if (!$expires) return -1;
        return max(0, $expires - time());
    }

    /**
     * Formatted date for created, modified or expires.
     *
     * @param string $property 'created', 'modified' or 'expires'
     * @param string $format   wireDate() format, e.g. 'Y-m-d H:i' or 'relative'
     * @return string Empty when the timestamp is 0
     */
    public function date(string $property, string $format = 'Y-m-d H:i:s'): string
    {
        $ts = (int) ($this->data[$property] ?? 0);
        return $ts ? (string) \ProcessWire\wireDate($format, $ts) : '';
    }

    /**
     * Plain array of all metadata, including computed values.
     *
     * @return array
     */
    public function toArray(): array
    {
        return array_merge($this->data, [
            'expired'   => $this->isExpired(),
            'remaining' => $this->remaining(),
            'age'       => $this->get('age'),
            'sizeStr'   => $this->get('sizeStr'),
        ]);
    }
}
