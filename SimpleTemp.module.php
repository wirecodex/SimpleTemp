<?php

declare(strict_types=1);

namespace ProcessWire;

/** @property \ProcessWire\ProcessWire $wire */
class SimpleTemp extends WireData implements Module, ConfigurableModule
{
    /** Max items listed in the "Stored Data" admin table */
    const ADMIN_LIMIT = 500;

    /** @var \SimpleWire\Temp\Temp */
    protected $temp;

    public static function getModuleInfo(): array
    {
        return [
            'title'    => 'SimpleTemp',
            'version'  => '0.1.0',
            'summary'  => 'System-wide temporary data store with OPcache-backed PHP files or JSON.',
            'icon'     => 'bolt',
            'author'   => 'WireCodex',
            'autoload' => true,
            'singular' => true,
            'requires' => 'ProcessWire>=3.0.200,PHP>=8.1',
        ];
    }

    // ========================================
    // Lifecycle
    // ========================================

    public function init(): void
    {
        spl_autoload_register(function (string $class): void {
            $prefix = 'SimpleWire\\Temp\\';
            if (!str_starts_with($class, $prefix)) return;
            $relative = substr($class, strlen($prefix));
            // Support drivers/ subfolder: e.g. SimpleWire\Temp\PhpDriver → classes/drivers/PhpDriver.php
            $file = __DIR__ . '/classes/' . str_replace('\\', '/', $relative) . '.php';
            if (!file_exists($file)) {
                $file = __DIR__ . '/classes/drivers/' . $relative . '.php';
            }
            if (file_exists($file)) require_once $file;
        });

        $config = array_merge(
            \SimpleWire\Temp\Temp::getDefaults(),
            (array) $this->wire('modules')->getConfig($this)
        );

        $this->temp = new \SimpleWire\Temp\Temp($this->wire, $config);

        $this->wire('simpletemp', $this->temp);

        require_once __DIR__ . '/functions.php';
    }

    // ========================================
    // Install / Uninstall
    // ========================================

    public function ___install(): void
    {
        $path = $this->wire('config')->paths->cache . 'SimpleWire/Temp/';
        if (!is_dir($path)) {
            wireMkdir($path, true);
        }
    }

    public function ___uninstall(): void
    {
        // Remove the default storage directory and a custom one, if configured
        $paths = [$this->wire('config')->paths->cache . 'SimpleWire/Temp/'];
        if ($this->temp) $paths[] = $this->temp->basePath();

        foreach (array_unique($paths) as $path) {
            if (is_dir($path)) wireRmdir($path, true);
        }
    }

    // ========================================
    // Config UI
    // ========================================

    public static function getModuleConfigInputfields(array $data): InputfieldWrapper
    {
        $modules = wire()->modules;
        $data    = array_merge(\SimpleWire\Temp\Temp::getDefaults(), $data);

        /** @var \SimpleWire\Temp\Temp|null $temp */
        $temp = wire()->simpletemp;
        if ($temp) static::processAdminActions($temp);

        /** @var InputfieldWrapper $wrapper */
        $wrapper = $modules->get('InputfieldWrapper');

        // ---- Storage Settings ----

        /** @var \ProcessWire\InputfieldFieldset $fieldset */
        $fieldset        = $modules->get('InputfieldFieldset');
        $fieldset->label = 'Storage Settings';
        $fieldset->icon  = 'hdd-o';

        /** @var \ProcessWire\InputfieldRadios $field */
        $field              = $modules->get('InputfieldRadios');
        $field->name        = 'temp_driver';
        $field->label       = 'Default Driver';
        $field->description = 'Storage format used for all groups not listed as JSON groups';
        $field->addOption('PhpDriver', 'PHP array file (OPcache, fastest)');
        $field->addOption('JsonDriver', 'JSON (readable by other tools)');
        $field->value       = $data['temp_driver'];
        $field->optionColumns = 1;
        $field->columnWidth = 50;
        $fieldset->add($field);

        /** @var \ProcessWire\InputfieldTextarea $field */
        $field              = $modules->get('InputfieldTextarea');
        $field->name        = 'temp_jsonGroups';
        $field->label       = 'Groups That Use JSON';
        $field->description = 'One group per line. Sub-groups are included (reports also covers reports.2026).';
        $field->value       = $data['temp_jsonGroups'];
        $field->rows        = 3;
        $field->columnWidth = 50;
        $fieldset->add($field);

        /** @var \ProcessWire\InputfieldCheckbox $field */
        $field              = $modules->get('InputfieldCheckbox');
        $field->name        = 'temp_jsonPretty';
        $field->label       = 'Pretty-Print JSON';
        $field->description = 'Indent JSON files for readability';
        $field->checked     = (bool) $data['temp_jsonPretty'];
        $field->columnWidth = 50;
        $fieldset->add($field);

        /** @var \ProcessWire\InputfieldText $field */
        $field              = $modules->get('InputfieldText');
        $field->name        = 'temp_path';
        $field->label       = 'Custom Storage Path';
        $field->description = 'Absolute path. Leave empty for /site/assets/cache/SimpleWire/Temp/';
        $field->notes       = 'Keep it outside the web root or in a folder that blocks PHP execution.';
        $field->value       = $data['temp_path'];
        $field->columnWidth = 50;
        $fieldset->add($field);

        $wrapper->add($fieldset);

        // ---- Expiry Settings ----

        /** @var \ProcessWire\InputfieldFieldset $fieldset */
        $fieldset        = $modules->get('InputfieldFieldset');
        $fieldset->label = 'Expiry Settings';
        $fieldset->icon  = 'clock-o';

        /** @var \ProcessWire\InputfieldInteger $field */
        $field              = $modules->get('InputfieldInteger');
        $field->name        = 'temp_defaultTtl';
        $field->label       = 'Default TTL (seconds)';
        $field->description = 'Used when no TTL is passed. 0 = never expires.';
        $field->value       = (int) $data['temp_defaultTtl'];
        $field->min         = 0;
        $field->columnWidth = 50;
        $fieldset->add($field);

        /** @var \ProcessWire\InputfieldSelect $field */
        $field              = $modules->get('InputfieldSelect');
        $field->name        = 'temp_pruneInterval';
        $field->label       = 'Prune Expired Items';
        $field->description = 'How often LazyCron deletes expired items';
        $field->addOption('', 'Never');
        $field->addOption('everyHour', 'Every hour');
        $field->addOption('every6Hours', 'Every 6 hours');
        $field->addOption('everyDay', 'Every day');
        $field->addOption('everyWeek', 'Every week');
        $field->value       = $data['temp_pruneInterval'];
        $field->columnWidth = 50;
        $fieldset->add($field);

        $wrapper->add($fieldset);

        if (!$temp) return $wrapper;

        // ---- Status ----

        /** @var \ProcessWire\InputfieldMarkup $field */
        $field        = $modules->get('InputfieldMarkup');
        $field->label = 'Status';
        $field->icon  = 'info-circle';
        $field->value = static::renderStatus($temp);
        $wrapper->add($field);

        // ---- Stored Data ----

        /** @var \ProcessWire\InputfieldMarkup $field */
        $field        = $modules->get('InputfieldMarkup');
        $field->label = 'Stored Data';
        $field->icon  = 'database';
        $field->value = static::renderItems($temp);
        $wrapper->add($field);

        return $wrapper;
    }

    // ========================================
    // Admin helpers
    // ========================================

    /**
     * Handle buttons from the "Stored Data" section.
     */
    protected static function processAdminActions(\SimpleWire\Temp\Temp $temp): void
    {
        static $done = false;
        $input  = wire()->input;
        $action = (string) $input->post('simpletemp_action');
        if ($done || $action === '') return;
        $done = true;

        wire()->session->CSRF->validate();

        $keys = array_filter(
            array_map('strval', (array) $input->post('simpletemp_keys')),
            fn(string $key) => $temp->isValidKey($key)
        );

        switch ($action) {
            case 'delete':
                $n = count(array_filter($keys, fn(string $key) => $temp->forget($key)));
                wire()->message(sprintf('SimpleTemp: deleted %d item(s).', $n));
                break;
            case 'touch':
                $n = count(array_filter($keys, fn(string $key) => $temp->touch($key)));
                wire()->message(sprintf('SimpleTemp: renewed %d item(s).', $n));
                break;
            case 'prune':
                wire()->message(sprintf('SimpleTemp: pruned %d expired item(s).', $temp->prune()));
                break;
            case 'flush':
                wire()->message(sprintf('SimpleTemp: deleted %d item(s).', $temp->flush()));
                break;
        }
    }

    protected static function renderStatus(\SimpleWire\Temp\Temp $temp): string
    {
        $sanitizer = wire()->sanitizer;

        $rows = [];
        $rows['Storage path']  = '<code>' . $sanitizer->entities($temp->basePath()) . '</code>';
        $rows['Items']         = $temp->count() . ' (' . wireBytesStr($temp->totalSize()) . ')';
        $rows['Expired items'] = (string) count($temp->items('', ['expired' => true]));

        $opcache = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
        if (!function_exists('opcache_get_status') || !ini_get('opcache.enable')) {
            $rows['OPcache'] = '<strong>Disabled</strong> – the PHP driver still works but every read compiles the file, so it loses its speed advantage.';
        } elseif (!is_array($opcache)) {
            $rows['OPcache'] = 'Enabled (status not available, possibly restricted by opcache.restrict_api)';
        } else {
            $mem = $opcache['memory_usage'];
            $rows['OPcache'] = sprintf(
                'Enabled · used %s · free %s · wasted %s%%',
                wireBytesStr((int) $mem['used_memory']),
                wireBytesStr((int) $mem['free_memory']),
                round((float) $mem['current_wasted_percentage'], 1)
            );
        }

        $rows['validate_timestamps'] = ini_get('opcache.validate_timestamps')
            ? sprintf('On (revalidate_freq %ss)', (int) ini_get('opcache.revalidate_freq'))
            : 'Off – handled: SimpleTemp invalidates on write and detects files rewritten from CLI';

        $out = '<table class="uk-table uk-table-small uk-table-divider" style="margin:0">';
        foreach ($rows as $label => $value) {
            $out .= '<tr><th style="width:200px">' . $sanitizer->entities($label) . "</th><td>$value</td></tr>";
        }
        return $out . '</table>';
    }

    protected static function renderItems(\SimpleWire\Temp\Temp $temp): string
    {
        $sanitizer = wire()->sanitizer;
        $items     = $temp->items('', ['limit' => self::ADMIN_LIMIT]);
        $total     = $temp->count();

        if (!count($items)) return '<p>Nothing stored yet.</p>' . static::renderButtons(false);

        /** @var MarkupAdminDataTable $table */
        $table = wire()->modules->get('MarkupAdminDataTable');
        $table->setEncodeEntities(false);
        $table->setSortable(true);
        $table->headerRow([
            '<input type="checkbox" class="simpletemp-all" title="Select all">',
            'Key',
            'Driver',
            'Size',
            'Created',
            'Modified',
            'Expires',
        ]);

        foreach ($items as $item) {
            $key     = $sanitizer->entities($item->key);
            $expires = $item->expires ? wireRelativeTimeStr($item->expires) : 'never';
            if ($item->isExpired()) $expires = "<span style=\"color:#c00\">expired ($expires)</span>";
            $table->row([
                "<input type='checkbox' name='simpletemp_keys[]' value='$key' class='simpletemp-key'>",
                "<code>$key</code>",
                $item->driver,
                '<span data-sort="' . $item->size . '">' . $item->sizeStr . '</span>',
                $item->date('created'),
                $item->date('modified'),
                $expires,
            ]);
        }

        $out = $table->render();
        if ($total > count($items)) {
            $out .= '<p class="detail">' . sprintf('Showing %d of %d items.', count($items), $total) . '</p>';
        }
        $out .= static::renderButtons(true);
        $out .= "<script>
            document.addEventListener('change', function(e) {
                if (!e.target.classList.contains('simpletemp-all')) return;
                document.querySelectorAll('.simpletemp-key').forEach(function(cb) { cb.checked = e.target.checked; });
            });
        </script>";

        return $out;
    }

    protected static function renderButtons(bool $hasItems): string
    {
        $buttons = [];
        if ($hasItems) {
            $buttons[] = ['delete', 'Delete selected', ''];
            $buttons[] = ['touch', 'Renew selected', ''];
        }
        $buttons[] = ['prune', 'Delete expired', ''];
        $buttons[] = ['flush', 'Delete everything', "return confirm('Delete ALL SimpleTemp data?')"];

        $out = '<p style="margin-top:1em">';
        foreach ($buttons as [$value, $label, $onclick]) {
            $on = $onclick ? " onclick=\"$onclick\"" : '';
            $out .= "<button type='submit' name='simpletemp_action' value='$value' class='ui-button ui-state-default ui-priority-secondary'$on>"
                . "<span class='ui-button-text'>$label</span></button> ";
        }

        return $out . '</p><p class="detail">These buttons also save the settings above.</p>';
    }
}
