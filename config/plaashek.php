<?php

use App\Modules\Kudde\KuddeModule;
use App\Modules\Weer\WeerModule;

return [

    /*
     * Every module this build knows, in the order the office shows them. A module is a service provider
     * extending App\Modules\Module; the farm's `modules` table says which ones it has switched on (PLAN.md
     * section 4.1). Free modules are on for every farm whatever that table says.
     */
    'modules' => [
        WeerModule::class,
        KuddeModule::class,
    ],

    // The release this install runs, shown in the office's top bar: the VERSION file the build script writes
    // into the zip (date and commit), or "dev" in a clone.
    'version' => is_file(base_path('VERSION')) ? trim((string) file_get_contents(base_path('VERSION'))) : 'dev',

    // The support account farm:create adds to every farm, so Plaashek can help without borrowing a login.
    'staff_email' => env('PLAASHEK_STAFF_EMAIL', 'staff@plaashek.co.za'),

    'open_meteo' => [
        // Blank: Open-Meteo's free API (about 10 000 calls a day), which Plaashek uses while it is in development
        // and marketing (owner's call, 24 October 2026). Set a key once farms pay: the commercial API's
        // `customer-` hosts are then used instead (PLAN.md section 2.3). Nothing else changes.
        'key' => env('OPEN_METEO_API_KEY'),
        'timeout' => 30,
    ],

];
