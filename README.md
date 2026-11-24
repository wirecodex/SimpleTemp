# SimpleTemp

> **Alpha — v0.1.0.** This module is in early testing. The API may change before a stable release. Feedback and bug reports are welcome.

A system-wide temporary data store for ProcessWire. Save scalars or multidimensional arrays under dotted keys, give them a lifetime, and read them back almost for free — the default driver writes plain PHP files that OPcache keeps compiled in shared memory. Includes stampede-safe `remember()`, nested read/write helpers, page-save expiry hooks, and an admin screen to inspect and clean stored data.

Can be installed standalone or as part of the **SimpleWire** suite.

## Features

- **Two Storage Drivers:** OPcache-backed PHP array files (fastest) or human-readable JSON — per group
- **Dotted Keys & Groups:** `dashboard.stats` is stored as `dashboard/stats.php`; groups nest to any depth
- **Flexible TTLs:** Seconds, human strings (`'2 hours'`, `'1 week'`), `0` for never, or a configurable default
- **Stampede Protection:** `remember()` lets one process rebuild an expired key while others get the stale value
- **Nested Helpers:** `pluck()`, `put()`, `merge()` and `update()` work inside stored arrays, under lock
- **Atomic Writes:** Temp file + rename, so readers never see a half-written file
- **Metadata Without Loading:** Size, created, modified and expiry are read from the file header only
- **Page-Driven Expiry:** Flush a group automatically when matching pages are saved or deleted
- **Auto-Prune:** LazyCron removes expired items on a configurable interval
- **Admin Screen:** OPcache status, a sortable list of stored items, and delete / renew / prune / flush buttons
- **Zero Dependencies:** No Redis, no database table, no external libraries

## Installation

1. Copy the `SimpleTemp` folder to `/site/modules/`
2. Go to **Modules → Refresh** in the ProcessWire admin
3. Install **SimpleTemp**

On install, the module creates `/site/assets/cache/SimpleWire/Temp/`. On uninstall, that directory (and a custom storage path, if configured) is removed with all stored data.

**Requirements:** ProcessWire 3.0.200+, PHP 8.1+. OPcache is recommended — without it the PHP driver still works but loses its speed advantage.

---

## Quick Access

```php
// Via the shorthand function (recommended)
$temp = temp();

// Shorthand for a group
$dash = temp('dashboard');           // same as temp()->group('dashboard')

// Via the explicit module function
$temp = simpletemp();

// Via the ProcessWire API variable
$temp = wire()->simpletemp;
```

---

## Quick Start

```php
// Save for one hour
temp()->set('dashboard.stats', ['visits' => 1520, 'orders' => 37], '1 hour');

// Read (null, or your default, when missing or expired)
$stats = temp()->get('dashboard.stats', []);

// Build once, then serve from cache
$menu = temp()->remember('nav.main', '1 day', function () {
    return wire()->pages->find('parent=1, include=hidden')->explode(['id', 'title', 'url']);
});

// Forget it
temp()->forget('nav.main');
```

---

## How It Works

1. Every key is a dotted path. The last segment is the item name, everything before it is the group:
   `reports.2026.q1` → group `reports.2026`, name `q1`
2. Each item is one file under the storage path, with dots turned into folders:
   `/site/assets/cache/SimpleWire/Temp/reports/2026/q1.php`
3. The file holds a small metadata block (key, driver, created, modified, expires, TTL) and the data
4. Reads check `expires`; expired data is treated as missing, but stays on disk until pruned (so `remember()` can serve it as a stale fallback)
5. Writes go to a temp file first and are renamed into place, then OPcache is invalidated for that file

### Keys

Keys are one or more segments of letters, numbers, `-` or `_`, separated by dots. Anything else throws a `TempException`.

```php
temp()->set('stats', 1);                 // root key
temp()->set('dashboard.stats', 1);       // group "dashboard"
temp()->set('reports.2026.q1', 1);       // group "reports.2026"
temp()->set('user.42.cart', 1);          // numbers are fine
temp()->set('bad key', 1);               // TempException
```

### What can be stored

Scalars (`string`, `int`, `float`, `bool`), `null`, and arrays of any depth containing those. Objects and resources throw a `TempException` — convert them first:

```php
temp()->set('home', $page->getArray());                            // OK
temp()->set('titles', $pages->find('template=post')->explode('title')); // OK
temp()->set('home', $page);                                        // TempException
```

### TTL values

| Value | Meaning |
| --- | --- |
| `null` | Use the configured **Default TTL** (3600 seconds out of the box) |
| `0` | Never expires |
| `int` / numeric string | Seconds, e.g. `300` or `'300'` |
| string | Any `strtotime()` offset: `'30 minutes'`, `'2 hours'`, `'1 day'`, `'1 week'` |

An invalid string such as `'soon'` throws a `TempException`.

---

## Reading and Writing

```php
temp()->set(string $key, mixed $data, int|string|null $ttl = null): bool
temp()->get(string $key, mixed $default = null): mixed
temp()->has(string $key): bool          // non-expired value exists (reads metadata only)
temp()->forget(string $key): bool       // true if something was deleted
temp()->touch(string $key, $ttl = null): bool  // extend lifetime, keep data
```

`touch()` with `null` reuses the key's original TTL; pass a value to change it.

### remember()

```php
$data = temp()->remember(string $key, int|string|null $ttl, callable $callback): mixed
```

Returns the stored value when fresh. Otherwise it takes a lock, calls `$callback` (which receives the `Temp` instance), stores the result and returns it.

While one request is rebuilding an expired key, other requests **do not wait** — they immediately get the previous (stale) value. Only when there is no previous value at all do they wait for the lock and then read the fresh result. This keeps slow builders from piling up under load.

```php
$top = temp()->remember('shop.topProducts', '15 minutes', function () {
    return wire()->pages->find('template=product, sort=-sales, limit=10')
        ->explode(['id', 'title', 'url', 'price']);
});
```

---

## Working With Nested Arrays

Stored arrays can be read and modified by dotted path. Write helpers (`update`, `merge`, `put`) run under the same per-key lock as `remember()`, so concurrent requests cannot overwrite each other's changes. With `$ttl = null` they **keep the key's existing TTL**.

```php
temp()->set('dashboard.stats', [
    'sales' => ['byMonth' => ['2026-10' => 1200]],
], '1 day');

// Read one nested value (with default)
temp()->pluck('dashboard.stats', 'sales.byMonth.2026-10', 0);   // 1200
temp()->pluck('dashboard.stats', ['sales', 'byMonth', '2026-12'], 0); // 0

// Write one nested value, creating levels as needed
temp()->put('dashboard.stats', 'sales.byMonth.2026-11', 1520);

// Recursive merge (array_replace_recursive)
temp()->merge('dashboard.stats', ['sales' => ['total' => 2720]]);

// Anything else: read-modify-write under lock
temp()->update('counters.downloads', function ($current) {
    return ($current ?? 0) + 1;
}, 0);
```

Path segments are split on dots; pass an array of segments when a segment itself contains a dot.

---

## Groups

`group()` returns a `TempGroup` — a chainable object whose per-key methods take names relative to the group.

```php
$dash = temp()->group('dashboard');   // or temp('dashboard')

$dash->set('stats', $stats, '1 hour');    // key: dashboard.stats
$dash->get('stats');
$dash->remember('chart', '10 minutes', fn() => buildChart());

// Sub-groups
$q1 = temp('reports')->group('2026');     // group: reports.2026
$q1->set('q1', $rows);                    // key:   reports.2026.q1

// Group-wide operations (include sub-groups)
$dash->keys();         // ['dashboard.chart', 'dashboard.stats']
$dash->count();        // 2
$dash->totalSize();    // bytes
$dash->prune();        // delete expired items in this group
$dash->flush();        // delete everything in this group
```

`TempGroup` mirrors every per-key method of `Temp`: `get`, `set`, `remember`, `has`, `forget`, `pluck`, `update`, `merge`, `put`, `touch`, `info`, `size`, `created`, `modified`, `expires`, `remaining`, `isExpired`, `path`.

---

## Expire on Page Changes

Flush a group automatically when pages matching a selector are saved or deleted. Register the rule in `/site/ready.php` (or `init.php`) so the hook exists on every request, including admin saves:

```php
// /site/ready.php
temp()->expireOn('nav', 'template=basic-page|section');
temp()->expireOn('shop', 'template=product');

// Or through a group
temp('blog')->expireOn('template=post');
```

Each group is flushed at most once per page save, even if several rules match.

---

## Metadata and Inspection

Metadata is read from the file header, so these calls never load or compile the data itself.

```php
temp()->size('dashboard.stats');                    // bytes, or null
temp()->created('dashboard.stats');                 // timestamp (kept across rewrites)
temp()->modified('dashboard.stats', 'Y-m-d H:i');   // formatted with wireDate()
temp()->expires('dashboard.stats', 'relative');     // e.g. "in 45 minutes"
temp()->remaining('dashboard.stats');               // seconds, -1 = never, 0 = expired, null = missing
temp()->isExpired('dashboard.stats');               // exists but expired
temp()->path('dashboard.stats');                    // absolute file path

$item = temp()->info('dashboard.stats');            // TempItem or null
```

### TempItem Properties

| Property | Type | Description |
| --- | --- | --- |
| `key` | string | Full key, e.g. `dashboard.stats` |
| `group` | string | Group part, e.g. `dashboard` (`''` for root keys) |
| `name` | string | Last segment, e.g. `stats` |
| `driver` | string | `php` or `json` |
| `file` | string | Absolute file path |
| `size` | int | File size in bytes |
| `sizeStr` | string | Human-readable size, e.g. `1.2 kB` |
| `created` | int | Timestamp of the first write |
| `modified` | int | Timestamp of the last write |
| `expires` | int | Expiry timestamp, `0` = never |
| `ttl` | int | TTL in seconds used on the last write |
| `expired` | bool | Whether the item is past its expiry |
| `remaining` | int | Seconds left, `-1` never expires, `0` expired |
| `age` | int | Seconds since the last write |

**Helper methods:**

```php
$item->isExpired();                    // bool
$item->remaining();                    // int
$item->date('modified', 'Y-m-d H:i');  // formatted date ('' when 0)
$item->toArray();                      // all properties as an array
```

### Listing Items

```php
// All items (metadata only), sorted by key
$items = temp()->items();

// Options
$items = temp()->items('dashboard', [
    'expired' => false,      // true = only expired, false = only fresh, null = all (default)
    'sort'    => '-size',    // key | size | created | modified | expires, '-' prefix = descending
    'limit'   => 20,         // 0 = no limit (default)
]);

temp()->keys('reports');     // ['reports.2026.q1', ...]
temp()->groups();            // ['dashboard', 'reports.2026', ...]
temp()->count();             // number of items
temp()->totalSize();         // bytes used
```

---

## Cleaning Up

```php
temp()->forget('dashboard.stats');   // one key
temp()->flush('dashboard');          // one group (and its sub-groups)
temp()->flush();                     // everything
temp()->prune();                     // expired items + stale temp/lock files older than 1 hour
temp()->prune('reports');            // only within a group
```

`prune()` runs automatically through LazyCron on the configured interval (daily by default).

---

## Storage Drivers

Drivers live in `classes/drivers/` and implement the `TempDriver` interface. A driver only encodes and decodes a payload; paths, locking, atomic writes and TTLs are handled by `Temp`.

### PhpDriver (default)

Writes a PHP file that `return`s the payload:

```php
<?php /*SimpleTemp {"key":"dashboard.stats","driver":"php","created":1763900000,...}*/
return array (
  'meta' => array ( ... ),
  'data' => array ( 'visits' => 1520, 'orders' => 37 ),
);
```

- Reads are an `include`, served from OPcache shared memory — no parsing, no unserialize
- The first line carries the metadata as JSON, so listing and `info()` never compile the data
- OPcache is invalidated after every write and delete
- If OPcache runs with `validate_timestamps=0` and a file is rewritten by another PHP process (e.g. a CLI cron), the driver notices the mtime mismatch and recompiles

### JsonDriver

Writes `{"meta": {...}, "data": ...}` as a `.json` file.

- Human-readable; can be consumed by JavaScript, shell scripts or other tools
- Optional pretty printing (module setting)
- Floats keep their decimal point (`1.0` stays a float)
- Every read parses the file, so it is slower than PhpDriver for large data

### Choosing per group

Set the **Default Driver** for everything, then list groups in **Groups That Use JSON** to override it. A listed group also covers its sub-groups:

```
reports
exports.public
```

With the setting above, `reports.2026.q1` and `exports.public.feed` are stored as JSON; everything else uses the default driver.

**Switching drivers:** nothing is migrated up front. A key is still found in its old format and is converted (and the old file removed) the next time it is written.

**Direct driver access:**

```php
temp()->driverFor('reports');   // TempDriver used by a group
temp()->driver('json');         // TempDriver by name: 'php' or 'json'
```

---

## Module Configuration

Navigate to Admin → Modules → Site → SimpleTemp → Configure.

### Storage Settings

| Setting | Default | Description |
| --- | --- | --- |
| **Default Driver** | `PhpDriver` | `PhpDriver` (OPcache, fastest) or `JsonDriver` (readable) |
| **Groups That Use JSON** | — | One group per line; sub-groups included |
| **Pretty-Print JSON** | `true` | Indent JSON files |
| **Custom Storage Path** | — | Absolute path; empty = `/site/assets/cache/SimpleWire/Temp/` |

### Expiry Settings

| Setting | Default | Description |
| --- | --- | --- |
| **Default TTL (seconds)** | `3600` | Used when no TTL is passed; `0` = never expires |
| **Prune Expired Items** | `everyDay` | LazyCron interval: never, hourly, every 6 hours, daily, weekly |

### Status and Stored Data

Below the settings the configuration screen shows:

- **Status** — storage path, item count and size, expired items, OPcache memory usage and `validate_timestamps` mode
- **Stored Data** — a sortable table of up to 500 items (key, driver, size, created, modified, expires) with buttons to **Delete selected**, **Renew selected**, **Delete expired** and **Delete everything**

---

## Complete Examples

### Dashboard Statistics

```php
// /site/templates/dashboard.php
namespace ProcessWire;

$stats = temp('dashboard')->remember('stats', '10 minutes', function () {
    $pages = wire()->pages;
    return [
        'orders'    => $pages->count('template=order'),
        'customers' => $pages->count('template=customer'),
        'revenue'   => array_sum($pages->findRaw('template=order', 'total')),
    ];
});

echo "Orders: {$stats['orders']} · Revenue: {$stats['revenue']}";
```

### Navigation Cache That Clears Itself

```php
// /site/ready.php
temp()->expireOn('nav', 'template=basic-page|section, include=all');
```

```php
// /site/templates/_main.php
$nav = temp()->remember('nav.main', 0, function () {
    return wire()->pages->get(1)->children()->explode(['title', 'url']);
});

foreach ($nav as $item) {
    echo "<a href='{$item['url']}'>{$item['title']}</a>";
}
```

The menu never expires on its own (`0`), but any save of a matching page rebuilds it on the next request.

### Counters and Rolling Statistics

```php
// Count downloads per file and per day, kept for 30 days
temp()->update('stats.downloads', function ($data) use ($file) {
    $data ??= [];
    $day = date('Y-m-d');
    $data[$day][$file->basename] = ($data[$day][$file->basename] ?? 0) + 1;
    return $data;
}, '30 days');

$today = temp()->pluck('stats.downloads', [date('Y-m-d'), $file->basename], 0);
```

### External API Response

```php
$rates = temp()->remember('api.exchangeRates', '1 hour', function () {
    return client('https://api.example.com')->get('/rates')->json();
});
```

Combined with [SimpleClient](../SimpleClient/README.md). If the API is slow, only one request waits on it per hour — the rest keep getting the previous rates.

### Sharing Data With JavaScript (JSON group)

With `public` listed under **Groups That Use JSON** and a custom storage path inside a web-accessible folder:

```php
temp()->set('public.events', $pages->find('template=event, date>=today')->explode(['title', 'date', 'url']), '1 hour');
// → {storage}/public/events.json
```

### Reports Overview

```php
foreach (temp('reports')->items(['sort' => '-modified']) as $item) {
    printf(
        "%s · %s · updated %s · %s\n",
        $item->key,
        $item->sizeStr,
        $item->date('modified', 'relative'),
        $item->expired ? 'expired' : 'expires ' . $item->date('expires', 'relative')
    );
}
```

---

## API Reference

### Temp — Core

| Method | Returns | Description |
| --- | --- | --- |
| `get(string $key, mixed $default = null)` | `mixed` | Value, or `$default` when missing or expired |
| `set(string $key, mixed $data, $ttl = null)` | `bool` | Save a value |
| `remember(string $key, $ttl, callable $callback)` | `mixed` | Cached value or build, save and return it |
| `has(string $key)` | `bool` | Non-expired value exists |
| `forget(string $key)` | `bool` | Delete a key |
| `flush(string $group = '')` | `int` | Delete a group or everything; returns count |
| `group(string $name)` | `TempGroup` | Chainable group object |

### Temp — Nested Arrays

| Method | Returns | Description |
| --- | --- | --- |
| `pluck(string $key, string\|array $path, mixed $default = null)` | `mixed` | Nested value by path |
| `put(string $key, string\|array $path, mixed $value, $ttl = null)` | `array` | Set nested value; returns full data |
| `merge(string $key, array $data, $ttl = null)` | `array` | Recursive merge; returns full data |
| `update(string $key, callable $callback, $ttl = null)` | `mixed` | Read-modify-write under lock |

### Temp — Metadata

| Method | Returns | Description |
| --- | --- | --- |
| `info(string $key)` | `TempItem\|null` | Metadata object |
| `size(string $key)` | `int\|null` | File size in bytes |
| `created(string $key, string $format = '')` | `int\|string\|null` | First write time |
| `modified(string $key, string $format = '')` | `int\|string\|null` | Last write time |
| `expires(string $key, string $format = '')` | `int\|string\|null` | Expiry time (`0` = never) |
| `remaining(string $key)` | `int\|null` | Seconds left (`-1` never, `0` expired) |
| `isExpired(string $key)` | `bool` | Exists but expired |
| `touch(string $key, $ttl = null)` | `bool` | Extend lifetime, keep data |
| `path(string $key)` | `string` | Absolute file path |

### Temp — Management

| Method | Returns | Description |
| --- | --- | --- |
| `items(string $group = '', array $options = [])` | `TempItem[]` | Items keyed by key |
| `keys(string $group = '')` | `string[]` | Keys in a group |
| `groups()` | `string[]` | Groups currently holding data |
| `count(string $group = '')` | `int` | Number of items |
| `totalSize(string $group = '')` | `int` | Bytes used |
| `prune(string $group = '')` | `int` | Delete expired items; returns count |
| `expireOn(string $group, string $selector)` | `Temp` | Flush group when matching pages change |
| `basePath()` | `string` | Storage directory |
| `driverFor(string $group)` | `TempDriver` | Driver used by a group |
| `driver(string $name)` | `TempDriver\|null` | Driver by name (`php`, `json`) |
| `isValidKey(string $key)` | `bool` | Key syntax check |
| `getConfig()` | `array` | Effective configuration |

### Global Functions

| Function | Returns | Description |
| --- | --- | --- |
| `temp()` | `Temp` | Get the Temp instance |
| `temp(string $group)` | `TempGroup` | Shorthand for `temp()->group($group)` |
| `simpletemp()` | `Temp` | Get the Temp instance |

### Classes

| Class | File | Description |
| --- | --- | --- |
| `SimpleWire\Temp\Temp` | `classes/Temp.php` | Core store |
| `SimpleWire\Temp\TempGroup` | `classes/TempGroup.php` | Chainable group access |
| `SimpleWire\Temp\TempItem` | `classes/TempItem.php` | Item metadata |
| `SimpleWire\Temp\TempException` | `classes/TempException.php` | Invalid key, TTL or data |
| `SimpleWire\Temp\TempDriver` | `classes/drivers/TempDriver.php` | Driver interface |
| `SimpleWire\Temp\PhpDriver` | `classes/drivers/PhpDriver.php` | OPcache-backed PHP files |
| `SimpleWire\Temp\JsonDriver` | `classes/drivers/JsonDriver.php` | JSON files |

---

## Troubleshooting

### `TempException: invalid key`

Keys may only contain letters, numbers, `-`, `_` and dots between segments. Spaces, slashes, empty segments (`a..b`) and leading/trailing dots are rejected. Sanitize dynamic parts first, e.g. `wire()->sanitizer->name($value)`.

### `TempException: ... contains ProcessWire\Page`

Only scalars, `null` and arrays can be stored. Use `$page->getArray()`, `$pages->explode([...])` or `$pages->findRaw(...)` to turn objects into arrays.

### Values seem to expire too early or never

- `set()` without a TTL uses the module's **Default TTL** (3600 seconds by default) — pass `0` for "never"
- `put()`, `merge()` and `update()` with `$ttl = null` keep the key's existing TTL
- Check with `temp()->info($key)->toArray()`

### Expired items are not being removed

- Expired items are hidden from `get()` immediately but stay on disk until pruned
- Confirm **Prune Expired Items** is not set to *Never*
- LazyCron needs page views to fire; call `temp()->prune()` from a real cron job on low-traffic sites

### PHP driver is not faster than JSON

Check the **Status** section of the module configuration. If OPcache is disabled for the current SAPI (it is often disabled for CLI via `opcache.enable_cli`), every read compiles the file.

### Changes made from CLI are not visible on the web

SimpleTemp handles this: PHP files carry their write time, and a mismatch with the file's mtime triggers an OPcache invalidation and re-read. If you edit stored files by hand, use `temp()->touch($key)` or delete and rewrite the key.

### Debugging

```php
bd(temp()->info('dashboard.stats')->toArray());   // TracyDebugger
bd(temp()->items('', ['expired' => true]));
echo temp()->path('dashboard.stats');
```

---

## License

This module is released under the MIT License.
