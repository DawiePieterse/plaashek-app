<?php

namespace App\Console\Commands;

use App\Modules\ModuleRegistry;
use App\Services\FarmSetup;
use App\Support\Farm;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Sets up the farm in a fresh install, for hosting with a command line (PLAN.md section 6). Hosts without
 * one use the first-run setup page, which asks the same.
 */
class FarmCreate extends Command
{
    protected $signature = 'farm:create';

    protected $description = 'Set up the farm: name, language, position, the owner and the modules it starts with';

    public function handle(FarmSetup $setup, ModuleRegistry $modules): int
    {
        if ($setup->isSetUp()) {
            $this->error('This install already has a farm. Each farm has its own install and database.');

            return self::FAILURE;
        }

        $paid = [];
        foreach ($modules->all() as $module) {
            if (! $module::free()) {
                $paid[$module::code()] = __($module::nameKey(), [], 'en');
            }
        }

        $coordinate = fn (float $limit) => fn (string $value) => $value === '' || (is_numeric($value) && abs((float) $value) <= $limit)
            ? null : 'A number in decimal degrees, e.g. -24.6544';

        $farm = [
            'name' => text('Farm name', required: true),
            'language' => select('Language on screen', ['af' => 'Afrikaans', 'en' => 'English'], default: 'af'),
            'latitude' => text('Latitude (blank to set it later)', validate: $coordinate(90)),
            'longitude' => text('Longitude (blank to set it later)', validate: $coordinate(180)),
            'owner_name' => text("Owner's name", required: true),
            'owner_email' => text("Owner's email address", required: true,
                validate: fn (string $value) => filter_var($value, FILTER_VALIDATE_EMAIL) ? null : 'Not a valid email address.'),
            'modules' => array_values($paid === [] ? [] : multiselect('Paid modules to switch on (Weather is free and always on)', $paid)),
        ];

        if (! in_array($farm['language'], Farm::LANGUAGES, true) || ! confirm("Create {$farm['name']}?")) {
            return self::FAILURE;
        }

        [$owner, $password] = $setup->create($farm);

        $this->info("Done. The owner signs in at /admin as {$owner->email} with the password: {$password}");
        $this->comment('They should change it right away under their profile.');

        return self::SUCCESS;
    }
}
