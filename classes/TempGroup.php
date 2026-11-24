<?php

declare(strict_types=1);

namespace SimpleWire\Temp;

/**
 * TempGroup — chainable access to one SimpleTemp group.
 *
 * Every per-key method takes a name relative to the group, so
 * `$dash->get('stats')` reads the key "dashboard.stats".
 *
 * @example $dash = temp()->group('dashboard');
 * @example $dash->remember('stats', '1 hour', fn() => [...]);
 *
 * @package SimpleWire
 */
class TempGroup
{
    protected Temp $temp;
    protected string $groupName;

    public function __construct(Temp $temp, string $name)
    {
        $this->temp      = $temp;
        $this->groupName = $name;
    }

    /** Group name, e.g. "reports.2026". */
    public function name(): string
    {
        return $this->groupName;
    }

    // ========================================
    // Per-key API (names are relative to the group)
    // ========================================

    public function get(string $name, mixed $default = null): mixed
    {
        return $this->temp->get($this->k($name), $default);
    }

    public function set(string $name, mixed $data, int|string|null $ttl = null): bool
    {
        return $this->temp->set($this->k($name), $data, $ttl);
    }

    public function remember(string $name, int|string|null $ttl, callable $callback): mixed
    {
        return $this->temp->remember($this->k($name), $ttl, $callback);
    }

    public function has(string $name): bool
    {
        return $this->temp->has($this->k($name));
    }

    public function forget(string $name): bool
    {
        return $this->temp->forget($this->k($name));
    }

    public function pluck(string $name, string|array $path, mixed $default = null): mixed
    {
        return $this->temp->pluck($this->k($name), $path, $default);
    }

    public function update(string $name, callable $callback, int|string|null $ttl = null): mixed
    {
        return $this->temp->update($this->k($name), $callback, $ttl);
    }

    public function merge(string $name, array $data, int|string|null $ttl = null): array
    {
        return $this->temp->merge($this->k($name), $data, $ttl);
    }

    public function put(string $name, string|array $path, mixed $value, int|string|null $ttl = null): array
    {
        return $this->temp->put($this->k($name), $path, $value, $ttl);
    }

    public function touch(string $name, int|string|null $ttl = null): bool
    {
        return $this->temp->touch($this->k($name), $ttl);
    }

    public function info(string $name): ?TempItem
    {
        return $this->temp->info($this->k($name));
    }

    public function size(string $name): ?int
    {
        return $this->temp->size($this->k($name));
    }

    public function created(string $name, string $format = ''): int|string|null
    {
        return $this->temp->created($this->k($name), $format);
    }

    public function modified(string $name, string $format = ''): int|string|null
    {
        return $this->temp->modified($this->k($name), $format);
    }

    public function expires(string $name, string $format = ''): int|string|null
    {
        return $this->temp->expires($this->k($name), $format);
    }

    public function remaining(string $name): ?int
    {
        return $this->temp->remaining($this->k($name));
    }

    public function isExpired(string $name): bool
    {
        return $this->temp->isExpired($this->k($name));
    }

    public function path(string $name): string
    {
        return $this->temp->path($this->k($name));
    }

    // ========================================
    // Group-wide API
    // ========================================

    /** @return TempItem[] */
    public function items(array $options = []): array
    {
        return $this->temp->items($this->groupName, $options);
    }

    /** @return string[] */
    public function keys(): array
    {
        return $this->temp->keys($this->groupName);
    }

    public function count(): int
    {
        return $this->temp->count($this->groupName);
    }

    public function totalSize(): int
    {
        return $this->temp->totalSize($this->groupName);
    }

    public function prune(): int
    {
        return $this->temp->prune($this->groupName);
    }

    public function flush(): int
    {
        return $this->temp->flush($this->groupName);
    }

    /**
     * Flush this group when pages matching $selector are saved or deleted.
     *
     * @param string $selector
     * @return $this
     */
    public function expireOn(string $selector): static
    {
        $this->temp->expireOn($this->groupName, $selector);
        return $this;
    }

    /**
     * Sub-group: temp()->group('reports')->group('2026') → "reports.2026"
     *
     * @param string $name
     * @return TempGroup
     */
    public function group(string $name): TempGroup
    {
        return $this->temp->group($this->k($name));
    }

    // ========================================
    // Internals
    // ========================================

    protected function k(string $name): string
    {
        return $this->groupName . '.' . $name;
    }
}
