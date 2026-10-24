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
    /** @var array<string, bool>|null */
    private ?array $enabled = null;

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

        if ($this->enabled === null) {
            try {
                $this->enabled = ModuleRow::query()->pluck('enabled', 'code')->map(fn ($v) => (bool) $v)->all();
            } catch (QueryException) {
                // Before the first migration there is no table; nothing paid is on then.
                return false;
            }
        }

        return $this->enabled[$code] ?? false;
    }

    /**
     * Switch the given paid modules on and every other paid module off. Free modules keep a row too, so
     * the farm's export shows everything it has.
     *
     * @param  list<string>  $codes
     */
    public function setEnabled(array $codes): void
    {
        foreach ($this->all() as $module) {
            ModuleRow::query()->updateOrCreate(
                ['code' => $module::code()],
                ['enabled' => $module::free() || in_array($module::code(), $codes, true)],
            );
        }

        $this->enabled = null;
    }
}
