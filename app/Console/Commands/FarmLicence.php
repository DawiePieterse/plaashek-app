<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Module as ModuleRow;
use App\Modules\ModuleRegistry;
use Illuminate\Console\Command;

/**
 * Sets a paid module's licence (PLAN.md section 6; ADR 0004: invoiced over WhatsApp, paid by EFT, flipped by
 * hand). grace keeps everything working 14 days, then the module is read-only; suspended is read-only now;
 * cancelled switches it off. Free modules have no licence.
 */
class FarmLicence extends Command
{
    protected $signature = 'farm:licence {module : The module code, e.g. kudde} {status? : active, grace, suspended or cancelled; empty to show}';

    protected $description = "Set or show a paid module's licence";

    public function handle(ModuleRegistry $modules): int
    {
        $code = (string) $this->argument('module');
        $module = $modules->find($code);
        if ($module === null) {
            $this->error("Unknown module: {$code}");

            return self::FAILURE;
        }
        if ($module::free()) {
            $this->error("{$code} is free: it has no licence and is always on.");

            return self::FAILURE;
        }

        $status = $this->argument('status');
        if ($status !== null) {
            if (! in_array($status, ModuleRow::LICENCES, true)) {
                $this->error('Status must be one of: '.implode(', ', ModuleRow::LICENCES));

                return self::FAILURE;
            }
            $before = $modules->licence($code)?->licence;
            $modules->setLicence($code, $status);
            AuditLog::record('licence.changed', $code, ['licence' => $before], ['licence' => $status]);
        }

        $row = $modules->licence($code);
        $this->line(sprintf('%-10s %-10s since %s%s, %s', $code, $row->licence ?? 'none', $row?->licence_since?->toDateString() ?? '-',
            $row?->grace_until !== null ? ', grace until '.$row->grace_until->toDateString() : '',
            $modules->isWritable($code) ? 'saving' : ($modules->isEnabled($code) ? 'read-only' : 'off')));

        return self::SUCCESS;
    }
}
