<?php

declare(strict_types=1);

namespace ProcessWire;

if (!function_exists('ProcessWire\simpletemp')) {
    /**
     * Return the Temp singleton instance.
     *
     * @return \SimpleWire\Temp\Temp
     */
    function simpletemp(): \SimpleWire\Temp\Temp
    {
        return wire()->simpletemp;
    }
}

if (!function_exists('ProcessWire\temp')) {
    /**
     * Return the Temp singleton instance, or a group of it.
     *
     * Usage:
     *   temp()->get('dashboard.stats')
     *   temp()->remember('dashboard.stats', '1 hour', fn() => [...])
     *   temp('dashboard')->get('stats')          → same as temp()->group('dashboard')
     *
     * @param string $group Optional group name
     * @return \SimpleWire\Temp\Temp|\SimpleWire\Temp\TempGroup
     */
    function temp(string $group = ''): \SimpleWire\Temp\Temp|\SimpleWire\Temp\TempGroup
    {
        $temp = wire()->simpletemp;
        return $group === '' ? $temp : $temp->group($group);
    }
}
