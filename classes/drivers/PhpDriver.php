<?php

declare(strict_types=1);

namespace SimpleWire\Temp;

/**
 * PhpDriver — stores payloads as PHP files that `return` an array.
 *
 * OPcache keeps the compiled files in shared memory, so reading them costs
 * close to nothing. Metadata is written as a JSON comment on the first line,
 * so it can be read without compiling the data.
 *
 * File format:
 *   <?php /*SimpleTemp {"key":"dashboard.stats",...}*\/
 *   return array ( 'meta' => ..., 'data' => ... );
 *
 * @package SimpleWire
 */
class PhpDriver implements TempDriver
{
    const META_TAG = 'SimpleTemp';

    // ========================================
    // TempDriver implementation
    // ========================================

    public function name(): string
    {
        return 'php';
    }

    public function extension(): string
    {
        return '.php';
    }

    public function encode(array $meta, mixed $data): string
    {
        return '<?php /*' . self::META_TAG . ' ' . json_encode($meta) . "*/\n"
            . 'return ' . var_export(['meta' => $meta, 'data' => $data], true) . ";\n";
    }

    public function read(string $file): ?array
    {
        $payload = $this->includeFile($file);
        if (!is_array($payload) || !isset($payload['meta'])) return null;

        // If the file was rewritten by another PHP SAPI (e.g. CLI cron) and OPcache does not
        // validate timestamps, it can still hold the old compile. Mtime is set to the write time,
        // so a mismatch means stale bytecode: invalidate and include again.
        $mtime = (int) @filemtime($file);
        if ($mtime && $mtime !== (int) $payload['meta']['modified']) {
            $this->changed($file);
            $payload = $this->includeFile($file);
        }

        return is_array($payload) ? $payload : null;
    }

    public function readMeta(string $file): ?array
    {
        $fp = @fopen($file, 'rb');
        if (!$fp) return null;
        $line = (string) fgets($fp, 8192);
        fclose($fp);

        $prefix = '<?php /*' . self::META_TAG . ' ';
        if (!str_starts_with($line, $prefix)) return null;

        $meta = json_decode(substr(rtrim($line), strlen($prefix), -2), true);
        return is_array($meta) ? $meta : null;
    }

    public function changed(string $file): void
    {
        if (function_exists('opcache_invalidate')) @opcache_invalidate($file, true);
    }

    // ========================================
    // Internals
    // ========================================

    protected function includeFile(string $file): mixed
    {
        try {
            return @include $file;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
