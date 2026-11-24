<?php

declare(strict_types=1);

namespace SimpleWire\Temp;

/**
 * Temp — system-wide temporary data store for ProcessWire.
 *
 * Stores scalars or multidimensional arrays (any depth) under dotted keys:
 * "dashboard.stats" is saved as /site/assets/cache/SimpleWire/Temp/dashboard/stats.php
 *
 * The default PhpDriver writes files that `return` the data, so OPcache keeps
 * them compiled in shared memory and reading them costs close to nothing.
 * The JsonDriver is available for groups that need human-readable files or
 * that are consumed outside PHP.
 *
 * @example temp()->remember('dashboard.stats', '1 hour', fn() => [...])
 * @example temp()->group('reports')->set('2026', $rows, '1 day')
 *
 * @package SimpleWire
 */
class Temp
{
    const LOCK_DIR = '.locks';

    /** @var \ProcessWire\ProcessWire */
    protected $wire;

    protected array $config = [];

    /** @var TempDriver[] Keyed by driver name ('php', 'json') */
    protected array $drivers = [];

    /** @var string[] Groups that use the JSON driver */
    protected array $jsonGroups = [];

    /** @var array [[group, selector], ...] */
    protected array $expireRules = [];

    protected bool $expireHooked = false;

    public function __construct(\ProcessWire\ProcessWire $wire, array $config = [])
    {
        $this->wire   = $wire;
        $this->config = array_merge(static::getDefaults(), $config);

        $this->drivers = [
            'php'  => new PhpDriver(),
            'json' => new JsonDriver((bool) $this->config['temp_jsonPretty']),
        ];

        $this->jsonGroups = array_values(array_filter(array_map(
            'trim',
            preg_split('/[\r\n,]+/', (string) $this->config['temp_jsonGroups']) ?: []
        )));

        $this->registerHooks();
    }

    // ========================================
    // Configurable Interface
    // ========================================

    public static function getDefaults(): array
    {
        return [
            'temp_driver'        => 'PhpDriver',
            'temp_jsonGroups'    => '',
            'temp_defaultTtl'    => 3600,
            'temp_pruneInterval' => 'everyDay',
            'temp_jsonPretty'    => true,
            'temp_path'          => '',
        ];
    }

    // ========================================
    // Core API
    // ========================================

    /**
     * Get data for a key, or $default when missing or expired.
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $hit = $this->fetch($key);
        if (!$hit || $hit['expired']) return $default;
        return $hit['data'];
    }

    /**
     * Save data for a key.
     *
     * @param string               $key
     * @param mixed                $data Scalar, null, or array of any depth (no objects)
     * @param int|string|null      $ttl  Seconds, a string like '2 hours', 0 for never, null for default
     * @return bool
     * @throws TempException
     */
    public function set(string $key, mixed $data, int|string|null $ttl = null): bool
    {
        return $this->write($this->parseKey($key), $data, $this->ttlSeconds($ttl));
    }

    /**
     * Return cached data, or build it with $callback, save it and return it.
     *
     * Only one process rebuilds an expired key at a time. While it rebuilds,
     * other requests get the previous (stale) value instead of waiting.
     *
     * @param string          $key
     * @param int|string|null $ttl
     * @param callable        $callback Receives this Temp instance, returns the data
     * @return mixed
     */
    public function remember(string $key, int|string|null $ttl, callable $callback): mixed
    {
        $hit = $this->fetch($key);
        if ($hit && !$hit['expired']) return $hit['data'];

        $lock = $this->lockOpen($key);
        if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($hit) {
                fclose($lock);
                return $hit['data'];
            }
            flock($lock, LOCK_EX);
        }

        try {
            clearstatcache();
            $hit = $this->fetch($key);
            if ($hit && !$hit['expired']) return $hit['data'];
            $data = $callback($this);
            $this->set($key, $data, $ttl);
            return $data;
        } finally {
            $this->lockClose($lock);
        }
    }

    /**
     * Is there a non-expired value for this key? (reads metadata only)
     */
    public function has(string $key): bool
    {
        $item = $this->info($key);
        return $item !== null && !$item->isExpired();
    }

    /**
     * Delete a key.
     *
     * @return bool True if something was deleted
     */
    public function forget(string $key): bool
    {
        $k = $this->parseKey($key);
        $deleted = false;
        foreach ($this->drivers as $driver) {
            $file = $this->filename($k, $driver);
            if (is_file($file) && $this->unlinkFile($file)) $deleted = true;
        }
        return $deleted;
    }

    /**
     * Delete all keys in a group (including sub-groups), or everything.
     *
     * @param string $group Empty for everything
     * @return int Number of items deleted
     */
    public function flush(string $group = ''): int
    {
        $n = 0;
        foreach ($this->scanFiles($group) as $file) {
            if ($this->unlinkFile($file)) $n++;
        }
        $this->removeEmptyDirs($this->groupPath($group), $group === '');
        return $n;
    }

    /**
     * Get a chainable object for a group.
     *
     * @param string $name e.g. "dashboard" or "reports.2026"
     * @return TempGroup
     * @throws TempException On an invalid group name
     */
    public function group(string $name): TempGroup
    {
        $this->parseKey($name . '.x');
        return new TempGroup($this, $name);
    }

    // ========================================
    // Multidimensional helpers
    // ========================================

    /**
     * Get a nested value from stored array data by dotted path.
     *
     * `temp()->pluck('dashboard.stats', 'sales.byMonth.2026-10', 0)`
     *
     * @param string       $key
     * @param string|array $path Dotted path or array of segments
     * @param mixed        $default
     * @return mixed
     */
    public function pluck(string $key, string|array $path, mixed $default = null): mixed
    {
        $data = $this->get($key);
        if ($data === null) return $default;
        foreach ($this->segments($path) as $segment) {
            if (!is_array($data) || !array_key_exists($segment, $data)) return $default;
            $data = $data[$segment];
        }
        return $data;
    }

    /**
     * Modify stored data with a callback, under lock.
     *
     * @param string          $key
     * @param callable        $callback Receives current data (or null), returns new data
     * @param int|string|null $ttl      Null keeps the existing TTL (or default for new keys)
     * @return mixed The new data
     */
    public function update(string $key, callable $callback, int|string|null $ttl = null): mixed
    {
        $lock = $this->lockOpen($key);
        if ($lock) flock($lock, LOCK_EX);

        try {
            clearstatcache();
            $hit = $this->fetch($key);
            $current = ($hit && !$hit['expired']) ? $hit['data'] : null;
            if ($ttl === null && $hit) $ttl = (int) $hit['meta']['ttl'];
            $data = $callback($current);
            $this->set($key, $data, $ttl);
            return $data;
        } finally {
            $this->lockClose($lock);
        }
    }

    /**
     * Recursively merge an array into stored data (array_replace_recursive).
     *
     * @param string          $key
     * @param array           $data
     * @param int|string|null $ttl Null keeps the existing TTL
     * @return array The merged data
     */
    public function merge(string $key, array $data, int|string|null $ttl = null): array
    {
        return $this->update($key, function ($current) use ($data) {
            return array_replace_recursive(is_array($current) ? $current : [], $data);
        }, $ttl);
    }

    /**
     * Set a nested value by dotted path, creating levels as needed.
     *
     * `temp()->put('dashboard.stats', 'sales.byMonth.2026-11', 1520)`
     *
     * @param string          $key
     * @param string|array    $path
     * @param mixed           $value
     * @param int|string|null $ttl Null keeps the existing TTL
     * @return array The full data
     */
    public function put(string $key, string|array $path, mixed $value, int|string|null $ttl = null): array
    {
        $segments = $this->segments($path);
        return $this->update($key, function ($current) use ($segments, $value) {
            $data = is_array($current) ? $current : [];
            $ref =& $data;
            foreach ($segments as $segment) {
                if (!isset($ref[$segment]) || !is_array($ref[$segment])) $ref[$segment] = [];
                $ref =& $ref[$segment];
            }
            $ref = $value;
            unset($ref);
            return $data;
        }, $ttl);
    }

    // ========================================
    // Metadata API
    // ========================================

    /**
     * Get metadata for a key without loading its data.
     */
    public function info(string $key): ?TempItem
    {
        $file = $this->locate($this->parseKey($key));
        if (!$file) return null;
        $meta = $this->readMeta($file);
        return $meta ? new TempItem($meta) : null;
    }

    /** File size in bytes, or null if missing. */
    public function size(string $key): ?int
    {
        return $this->info($key)?->get('size');
    }

    /**
     * When the key was first created (kept across rewrites).
     *
     * @param string $format Optional wireDate() format; empty returns a timestamp
     */
    public function created(string $key, string $format = ''): int|string|null
    {
        return $this->metaDate($key, 'created', $format);
    }

    /** When the key was last written. */
    public function modified(string $key, string $format = ''): int|string|null
    {
        return $this->metaDate($key, 'modified', $format);
    }

    /** When the key expires (0 = never). */
    public function expires(string $key, string $format = ''): int|string|null
    {
        return $this->metaDate($key, 'expires', $format);
    }

    /** Seconds until expiry: -1 never expires, 0 expired, null missing. */
    public function remaining(string $key): ?int
    {
        return $this->info($key)?->remaining();
    }

    /** Does the key exist but is expired? */
    public function isExpired(string $key): bool
    {
        $item = $this->info($key);
        return $item !== null && $item->isExpired();
    }

    /**
     * Extend the life of a key without changing its data.
     *
     * @param int|string|null $ttl Null reuses the key's original TTL
     * @return bool False if the key does not exist
     */
    public function touch(string $key, int|string|null $ttl = null): bool
    {
        $hit = $this->fetch($key);
        if (!$hit) return false;
        if ($ttl === null) $ttl = (int) $hit['meta']['ttl'];
        return $this->set($key, $hit['data'], $ttl);
    }

    /**
     * Absolute file path for a key (existing file, or where it would be written).
     */
    public function path(string $key): string
    {
        $k = $this->parseKey($key);
        return $this->locate($k) ?: $this->filename($k, $this->driverFor($k['group']));
    }

    // ========================================
    // Management API
    // ========================================

    /**
     * List items (metadata only) in a group or everywhere.
     *
     * Options:
     * - expired: true (only expired), false (only fresh), null (all)
     * - sort:    key|size|created|modified|expires, prefix '-' for descending
     * - limit:   max items (0 = no limit)
     *
     * @param string $group
     * @param array  $options
     * @return TempItem[] Keyed by item key
     */
    public function items(string $group = '', array $options = []): array
    {
        $options = array_merge(['expired' => null, 'sort' => 'key', 'limit' => 0], $options);

        $items = [];
        foreach ($this->scanFiles($group) as $file) {
            $meta = $this->readMeta($file);
            if (!$meta) continue;
            $item = new TempItem($meta);
            if ($options['expired'] !== null && $item->isExpired() !== (bool) $options['expired']) continue;
            $items[$item->key] = $item;
        }

        $sort = ltrim((string) $options['sort'], '-');
        $desc = str_starts_with((string) $options['sort'], '-');
        uasort($items, function (TempItem $a, TempItem $b) use ($sort, $desc): int {
            $r = $sort === 'key' ? strcmp($a->key, $b->key) : ($a->get($sort) <=> $b->get($sort));
            return $desc ? -$r : $r;
        });

        if ($options['limit'] > 0) $items = array_slice($items, 0, (int) $options['limit'], true);
        return $items;
    }

    /**
     * List keys in a group.
     *
     * @return string[]
     */
    public function keys(string $group = ''): array
    {
        return array_keys($this->items($group));
    }

    /**
     * List group names currently holding data.
     *
     * @return string[]
     */
    public function groups(): array
    {
        $groups = [];
        foreach ($this->items() as $item) {
            if ($item->group !== '') $groups[$item->group] = true;
        }
        return array_keys($groups);
    }

    /** Number of stored items in a group or everywhere. */
    public function count(string $group = ''): int
    {
        return iterator_count($this->scanFiles($group));
    }

    /** Total bytes used by a group or everything. */
    public function totalSize(string $group = ''): int
    {
        $total = 0;
        foreach ($this->scanFiles($group) as $file) $total += (int) @filesize($file);
        return $total;
    }

    /**
     * Delete expired items and leftover temp/lock files older than one hour.
     *
     * @return int Number of expired items deleted
     */
    public function prune(string $group = ''): int
    {
        $n = 0;
        foreach ($this->items($group, ['expired' => true]) as $item) {
            if ($this->unlinkFile($item->file)) $n++;
        }

        $dir = $this->groupPath($group);
        if (is_dir($dir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                $path  = $file->getPathname();
                $stale = time() - $file->getMTime() > 3600;
                if ($stale && (str_ends_with($path, '.tmp') || str_ends_with($path, '.lock'))) @unlink($path);
            }
        }

        $this->removeEmptyDirs($dir, $group === '');
        return $n;
    }

    /**
     * Flush a group automatically when pages matching $selector are saved or deleted.
     *
     * Call it from /site/ready.php (or init.php) so the hook exists on every request.
     *
     * @param string $group
     * @param string $selector
     * @return $this
     */
    public function expireOn(string $group, string $selector): static
    {
        $this->expireRules[] = [$group, $selector];

        if (!$this->expireHooked) {
            $handler = function (\ProcessWire\HookEvent $event): void {
                $this->pageChanged($event->arguments(0));
            };
            $this->wire->addHookAfter('Pages::saved', $handler);
            $this->wire->addHookAfter('Pages::deleted', $handler);
            $this->expireHooked = true;
        }

        return $this;
    }

    // ========================================
    // Drivers / paths
    // ========================================

    /**
     * Base storage path (with trailing slash).
     */
    public function basePath(): string
    {
        $path = $this->config['temp_path'] ?: $this->wire->config->paths->cache . 'SimpleWire/Temp/';
        return rtrim((string) $path, '/') . '/';
    }

    /**
     * Which driver a group uses (JSON groups match their sub-groups too).
     */
    public function driverFor(string $group): TempDriver
    {
        foreach ($this->jsonGroups as $jsonGroup) {
            if ($group === $jsonGroup || str_starts_with($group, $jsonGroup . '.')) return $this->drivers['json'];
        }
        return $this->config['temp_driver'] === 'JsonDriver' ? $this->drivers['json'] : $this->drivers['php'];
    }

    /**
     * Get a driver by name ('php' or 'json').
     */
    public function driver(string $name): ?TempDriver
    {
        return $this->drivers[$name] ?? null;
    }

    /**
     * Is this a valid key? Segments of letters, numbers, - or _ separated by dots.
     */
    public function isValidKey(string $key): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]+(\.[A-Za-z0-9_-]+)*$/', $key);
    }

    /**
     * Current config (merged with defaults).
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    // ========================================
    // Internals — reading
    // ========================================

    /**
     * Read a key's full payload, including expired data.
     *
     * @return array|null ['meta' => [...], 'data' => mixed, 'expired' => bool]
     */
    protected function fetch(string $key): ?array
    {
        $file = $this->locate($this->parseKey($key));
        if (!$file) return null;

        $payload = $this->driverForFile($file)->read($file);
        if (!is_array($payload) || !isset($payload['meta']) || !array_key_exists('data', $payload)) return null;

        $expires = (int) $payload['meta']['expires'];
        $payload['expired'] = $expires > 0 && $expires <= time();
        return $payload;
    }

    /**
     * Read metadata only and add file + size.
     */
    protected function readMeta(string $file): ?array
    {
        $meta = $this->driverForFile($file)->readMeta($file);
        if (!is_array($meta) || empty($meta['key'])) return null;
        $meta['file'] = $file;
        $meta['size'] = (int) @filesize($file);
        return $meta;
    }

    // ========================================
    // Internals — writing
    // ========================================

    /**
     * @param array $k Parsed key
     * @throws TempException
     */
    protected function write(array $k, mixed $data, int $ttl): bool
    {
        $this->assertStorable($data, $k['key']);

        $driver = $this->driverFor($k['group']);
        $file   = $this->filename($k, $driver);
        $this->wire->files->mkdir(dirname($file), true);

        $now  = time();
        $prev = null;
        $existing = $this->locate($k);
        if ($existing) $prev = $this->readMeta($existing);

        $meta = [
            'key'      => $k['key'],
            'driver'   => $driver->name(),
            'created'  => $prev ? (int) $prev['created'] : $now,
            'modified' => $now,
            'expires'  => $ttl > 0 ? $now + $ttl : 0,
            'ttl'      => $ttl,
        ];

        $contents = $driver->encode($meta, $data);

        // Atomic write: temp file + rename
        $tmp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($tmp, $contents, LOCK_EX) === false) return false;
        @touch($tmp, $now);
        $this->wire->files->chmod($tmp);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }
        clearstatcache(true, $file);
        $driver->changed($file);

        // Driver changed for this group: remove the copy in the other format(s)
        foreach ($this->drivers as $other) {
            if ($other === $driver) continue;
            $otherFile = $this->filename($k, $other);
            if (is_file($otherFile)) $this->unlinkFile($otherFile);
        }

        return true;
    }

    /**
     * Only scalars, null and arrays (any depth) can be stored.
     *
     * @throws TempException
     */
    protected function assertStorable(mixed $data, string $key): void
    {
        $check = function ($value) use ($key): void {
            if (is_object($value) || is_resource($value)) {
                $type = is_object($value) ? get_class($value) : 'a resource';
                throw new TempException(
                    "SimpleTemp: '$key' contains $type. Only scalars, null and arrays (any depth) can be stored. " .
                    "Convert objects first, e.g. \$page->getArray() or \$pages->explode().",
                    $key
                );
            }
        };

        if (is_array($data)) {
            array_walk_recursive($data, $check);
        } else {
            $check($data);
        }
    }

    // ========================================
    // Internals — keys, files, directories
    // ========================================

    /**
     * @return array ['key' => string, 'group' => string, 'name' => string]
     * @throws TempException
     */
    protected function parseKey(string $key): array
    {
        if (!$this->isValidKey($key)) {
            throw new TempException(
                "SimpleTemp: invalid key '$key'. Use segments of letters, numbers, - or _ separated by dots.",
                $key
            );
        }
        $pos = strrpos($key, '.');
        return [
            'key'   => $key,
            'group' => $pos === false ? '' : substr($key, 0, $pos),
            'name'  => $pos === false ? $key : substr($key, $pos + 1),
        ];
    }

    /**
     * @param string|array $path Dotted path or array of segments
     * @return array
     */
    protected function segments(string|array $path): array
    {
        return is_array($path) ? array_values($path) : explode('.', $path);
    }

    protected function filename(array $k, TempDriver $driver): string
    {
        return $this->basePath() . str_replace('.', '/', $k['key']) . $driver->extension();
    }

    /**
     * Existing file for a key: preferred driver first, then the others.
     */
    protected function locate(array $k): ?string
    {
        $preferred = $this->driverFor($k['group']);
        $drivers = [$preferred];
        foreach ($this->drivers as $driver) {
            if ($driver !== $preferred) $drivers[] = $driver;
        }
        foreach ($drivers as $driver) {
            $file = $this->filename($k, $driver);
            if (is_file($file)) return $file;
        }
        return null;
    }

    /**
     * Driver that owns a file, by extension.
     */
    protected function driverForFile(string $file): TempDriver
    {
        foreach ($this->drivers as $driver) {
            if (str_ends_with($file, $driver->extension())) return $driver;
        }
        return $this->drivers['php'];
    }

    protected function groupPath(string $group): string
    {
        if ($group === '') return $this->basePath();
        $this->parseKey($group . '.x');
        return $this->basePath() . str_replace('.', '/', $group) . '/';
    }

    /**
     * Data files under a group directory.
     *
     * @return \Generator<string>
     */
    protected function scanFiles(string $group = ''): \Generator
    {
        $dir = $this->groupPath($group);
        if (!is_dir($dir)) return;

        $extensions = array_map(fn(TempDriver $d) => $d->extension(), $this->drivers);
        $directory  = new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS);
        $filter     = new \RecursiveCallbackFilterIterator($directory, function (\SplFileInfo $current) use ($extensions): bool {
            if ($current->isDir()) return $current->getFilename() !== self::LOCK_DIR;
            foreach ($extensions as $ext) {
                if (str_ends_with($current->getFilename(), $ext)) return true;
            }
            return false;
        });

        foreach (new \RecursiveIteratorIterator($filter) as $file) {
            yield $file->getPathname();
        }
    }

    protected function removeEmptyDirs(string $dir, bool $keepRoot = false): void
    {
        if (!is_dir($dir)) return;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $path) {
            if ($path->isDir() && $path->getFilename() !== self::LOCK_DIR) @rmdir($path->getPathname());
        }
        if (!$keepRoot) @rmdir($dir);
    }

    protected function unlinkFile(string $file): bool
    {
        $ok = @unlink($file);
        if ($ok) $this->driverForFile($file)->changed($file);
        return $ok;
    }

    // ========================================
    // Internals — TTL, dates, locks, hooks
    // ========================================

    /**
     * @throws TempException
     */
    protected function ttlSeconds(int|string|null $ttl): int
    {
        if ($ttl === null) return max(0, (int) $this->config['temp_defaultTtl']);
        if (is_int($ttl) || ctype_digit($ttl)) return max(0, (int) $ttl);

        $now = time();
        $ts  = strtotime('+' . ltrim(trim($ttl), '+'), $now);
        if ($ts === false) throw new TempException("SimpleTemp: cannot understand TTL '$ttl'");
        return max(0, $ts - $now);
    }

    protected function metaDate(string $key, string $property, string $format): int|string|null
    {
        $item = $this->info($key);
        if (!$item) return null;
        return $format ? $item->date($property, $format) : (int) $item->get($property);
    }

    /**
     * @return resource|null
     */
    protected function lockOpen(string $key)
    {
        $dir = $this->basePath() . self::LOCK_DIR . '/';
        if (!is_dir($dir)) $this->wire->files->mkdir($dir, true);
        $fp = @fopen($dir . md5($key) . '.lock', 'c');
        return $fp ?: null;
    }

    /**
     * @param resource|null $fp
     */
    protected function lockClose($fp): void
    {
        if (!$fp) return;
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    protected function registerHooks(): void
    {
        $interval = (string) $this->config['temp_pruneInterval'];
        if ($interval !== '') {
            $this->wire->addHook("LazyCron::{$interval}", function () {
                $this->prune();
            });
        }
    }

    protected function pageChanged(mixed $page): void
    {
        if (!$page instanceof \ProcessWire\Page) return;
        $done = [];
        foreach ($this->expireRules as [$group, $selector]) {
            if (isset($done[$group])) continue;
            if ($page->matches($selector)) {
                $this->flush($group);
                $done[$group] = true;
            }
        }
    }
}
