<?php

namespace App\Modules;

use App\Models\Module as ModuleRow;
use Illuminate\Database\QueryException;

/**
 * Which modules this build has (config/plaashek.php) and which of them the farm has switched on (the
 * modules table). Free modules are always on.
 */
class ModuleRegistry
{
    /** @var array<string, ModuleRow>|null */
    private ?array $rows = null;

    /** @return list<class-string<Module>> */
    public function all(): array
    {
        /** @var list<class-string<Module>> $modules */
        $modules = config('plaashek.modules', []);

        return $modules;
    }

    /** @return class-string<Module>|null */
    public function find(string $code): ?string
    {
        foreach ($this->all() as $module) {
            if ($module::code() === $code) {
                return $module;
            }
        }

        return null;
    }

    public function isEnabled(string $code): bool
    {
        $module = $this->find($code);

        if ($module === null) {
            return false;
        }

        if ($module::free()) {
            return true;
        }

        return $this->row($code)->enabled ?? false;
    }

    /**
     * Whether the module may save new records today: on, and its licence active, or in grace up to and including
     * its last day. A suspended module is still on (the farm sees and exports everything) but saves nothing.
     * Free modules always may.
     */
    public function isWritable(string $code): bool
    {
        $module = $this->find($code);
        if ($module === null || ! $this->isEnabled($code)) {
            return false;
        }
        if ($module::free()) {
            return true;
        }

        $row = $this->row($code);

        return match ($row?->licence) {
            null, ModuleRow::ACTIVE => true,
            ModuleRow::GRACE => $row->grace_until === null || ! $row->grace_until->lt(today()),
            default => false,
        };
    }

    /** The paid module's licence row (status, since, grace until), or null for a free or never-switched one. */
    public function licence(string $code): ?ModuleRow
    {
        $module = $this->find($code);

        return $module === null || $module::free() ? null : $this->row($code);
    }

    /**
     * Sets a paid module's licence (ADR 0004: by hand, after the EFT shows). Active, grace and suspended keep it
     * on; cancelled switches it off. Grace starts its 14 days when it is first set, so running the command again
     * does not stretch it.
     */
    public function setLicence(string $code, string $licence): ModuleRow
    {
        $module = $this->find($code);
        if ($module === null || $module::free() || ! in_array($licence, ModuleRow::LICENCES, true)) {
            throw new \InvalidArgumentException("No licence {$licence} for {$code}");
        }

        $row = ModuleRow::query()->firstOrNew(['code' => $code]);
        if ($row->licence !== $licence) {
            $row->licence_since = today();
            $row->grace_until = $licence === ModuleRow::GRACE ? today()->addDays(ModuleRow::GRACE_DAYS) : null;
        }
        $row->licence = $licence;
        $row->enabled = $licence !== ModuleRow::CANCELLED;
        $row->save();
        $this->rows = null;

        return $row;
    }

    /**
     * Switch the given paid modules on and every other paid module off. Free modules keep a row too, so
     * the farm's export shows everything it has. A paid module switched on starts active, unless it already has a
     * licence; one switched off is cancelled, so the two never disagree.
     *
     * @param  list<string>  $codes
     */
    public function setEnabled(array $codes): void
    {
        foreach ($this->all() as $module) {
            $code = $module::code();
            $on = $module::free() || in_array($code, $codes, true);
            $row = ModuleRow::query()->firstOrNew(['code' => $code]);
            // A module the farm never had stays without a licence: "cancelled" would say it once paid.
            if (! $module::free() && ($row->exists ? $row->enabled !== $on : $on)) {
                $licence = $on ? ($row->licence === null || $row->licence === ModuleRow::CANCELLED ? ModuleRow::ACTIVE : $row->licence) : ModuleRow::CANCELLED;
                if ($licence !== $row->licence) {
                    $row->licence = $licence;
                    $row->licence_since = today();
                    $row->grace_until = null;
                }
            }
            $row->enabled = $on;
            $row->save();
        }

        $this->rows = null;
    }

    private function row(string $code): ?ModuleRow
    {
        if ($this->rows === null) {
            try {
                $this->rows = ModuleRow::query()->get()->keyBy('code')->all();
            } catch (QueryException) {
                // Before the first migration there is no table; nothing paid is on then.
                return null;
            }
        }

        return $this->rows[$code] ?? null;
    }
}
