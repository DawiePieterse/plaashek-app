<?php

use App\Models\Module;
use App\Modules\ModuleRegistry;

it('keeps the free Weather module on whatever the table says', function () {
    farm();
    Module::query()->where('code', 'weer')->update(['enabled' => false]);

    expect(app(ModuleRegistry::class)->isEnabled('weer'))->toBeTrue()
        ->and(app(ModuleRegistry::class)->isEnabled('kudde'))->toBeFalse();
});

it('switches modules from the command line, and refuses unknown ones', function () {
    farm();

    $this->artisan('farm:modules', ['codes' => 'kudde'])->assertFailed();
    $this->artisan('farm:modules', ['codes' => ''])->expectsOutputToContain('weer')->assertSuccessful();
});

it('gives the support account a fresh password and logs it', function () {
    farm();

    $this->artisan('farm:staff-password')->expectsOutputToContain('staff@plaashek.co.za')->assertSuccessful();
    $this->assertDatabaseHas('audit_log', ['action' => 'staff.password']);
});
