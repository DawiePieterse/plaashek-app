<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Modules\ModuleRegistry;
use Illuminate\Console\Command;

/**
 * Switches the farm's paid modules on or off (PLAN.md section 6): the ones named are on, every other paid
 * module off. Free modules are always on.
 */
class FarmModules extends Command
{
    protected $signature = 'farm:modules {codes? : Comma-separated module codes, e.g. kudde,werkers; empty for none}';

    protected $description = "Switch the farm's paid modules on or off";

    public function handle(ModuleRegistry $modules): int
    {
        $codes = array_values(array_filter(array_map('trim', explode(',', (string) $this->argument('codes')))));

        foreach ($codes as $code) {
            if ($modules->find($code) === null) {
                $this->error("Unknown module: {$code}");

                return self::FAILURE;
            }
        }

        $modules->setEnabled($codes);
        AuditLog::record('modules.changed', implode(',', $codes));

        foreach ($modules->all() as $module) {
            $code = $module::code();
            $licence = $modules->licence($code)?->licence;
            $this->line(sprintf('%-10s %-4s %s', $code, $modules->isEnabled($code) ? 'on' : 'off', $module::free() ? 'free' : ($licence ?? '')));
        }

        return self::SUCCESS;
    }
}
