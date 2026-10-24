<?php

// The worker register under Farm settings and the cards it prints.

use App\Filament\Resources\Workers\Pages\CreateWorker;
use App\Filament\Resources\Workers\Pages\EditWorker;
use App\Filament\Resources\Workers\Pages\ListWorkers;
use App\Filament\Resources\Workers\WorkerResource;
use App\Models\User;
use App\Models\Worker;
use App\Support\WorkerCard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function officeUser(string $role): User
{
    return User::query()->create(['name' => ucfirst($role), 'email' => $role.'@example.com', 'password' => 'password', 'role' => $role]);
}

function worker(string $number, array $fields = []): Worker
{
    return Worker::query()->create(array_merge(['number' => $number, 'first_name' => 'Thabo', 'last_name' => 'Maluleke'], $fields));
}

it('adds a worker from the office and logs it', function () {
    $this->actingAs(farm());

    Livewire::test(CreateWorker::class)
        ->fillForm(['number' => ' 001 ', 'first_name' => 'Johannes', 'last_name' => 'Mathebula', 'preferred_name' => 'Jan', 'cell' => '082 555 0101'])
        ->call('create')
        ->assertHasNoFormErrors();

    $worker = Worker::query()->sole();
    expect([$worker->number, $worker->displayName(), $worker->active])->toBe(['001', 'Jan Mathebula', true]);
    $this->assertDatabaseHas('audit_log', ['action' => 'worker.created', 'subject' => '001']);
});

it('refuses a number already in use, or one a camera would misread', function () {
    $this->actingAs(farm());
    worker('A12');

    Livewire::test(CreateWorker::class)->fillForm(['number' => 'a12', 'first_name' => 'X'])->call('create')
        ->assertHasFormErrors(['number' => 'unique']);
    Livewire::test(CreateWorker::class)->fillForm(['number' => '12 3', 'first_name' => 'X'])->call('create')
        ->assertHasFormErrors(['number' => 'regex']);
});

it('switches a worker off rather than deleting, and logs who did', function () {
    $this->actingAs(farm());
    $w = worker('001');

    Livewire::test(EditWorker::class, ['record' => $w->getKey()])->fillForm(['active' => false])->call('save')->assertHasNoFormErrors();

    expect($w->refresh()->active)->toBeFalse();
    $this->assertDatabaseHas('audit_log', ['action' => 'worker.updated', 'subject' => '001']);
    expect(WorkerResource::canDelete($w))->toBeFalse();
});

it('lists active workers first and finds them by name or number', function () {
    $this->actingAs(farm());
    $a = worker('002');
    $b = worker('10', ['first_name' => 'Sipho']);
    $c = worker('9');
    $gone = worker('3', ['active' => false]);

    Livewire::test(ListWorkers::class)
        ->assertCanSeeTableRecords([$a, $c, $b], inOrder: true)
        ->assertCanNotSeeTableRecords([$gone])
        ->searchTable('Sipho')
        ->assertCanSeeTableRecords([$b])
        ->assertCanNotSeeTableRecords([$a]);
});

it('lets a manager keep the register and a viewer only look', function () {
    farm();
    $this->actingAs(officeUser(User::MANAGER));
    Livewire::test(CreateWorker::class)->assertSuccessful();

    $this->actingAs(officeUser(User::VIEWER));
    Livewire::test(ListWorkers::class)->assertSuccessful();
    $this->get('/admin/workers/create')->assertForbidden();
});

it('keeps the photo private and removes a replaced one', function () {
    Storage::fake(Worker::PHOTO_DISK);
    $this->actingAs(farm());
    $old = UploadedFile::fake()->image('old.jpg')->storeAs(Worker::PHOTO_DIRECTORY, 'old.jpg', Worker::PHOTO_DISK);
    $w = worker('001', ['photo' => $old]);

    $w->update(['photo' => Worker::PHOTO_DIRECTORY.'/new.jpg']);

    Storage::disk(Worker::PHOTO_DISK)->assertMissing($old);
    expect(file_exists(public_path('storage/'.$old)))->toBeFalse();
});

it('prints the chosen workers as cards whose QR holds the number', function () {
    Storage::fake(Worker::PHOTO_DISK);
    $this->actingAs(farm(['name' => 'Bekfontein']));
    $photo = UploadedFile::fake()->image('p.jpg')->storeAs(Worker::PHOTO_DIRECTORY, 'p.jpg', Worker::PHOTO_DISK);
    $a = worker('10', ['preferred_name' => 'Jan', 'photo' => $photo]);
    $b = worker('9');
    worker('11');

    $html = $this->get(route('workers.cards', ['ids' => $a->id.','.$b->id]))->assertOk()->getContent();

    expect(substr_count($html, 'class="card '))->toBe(2)
        ->and(strpos($html, 'data-number="9"'))->toBeLessThan(strpos($html, 'data-number="10"'))
        ->and($html)->toContain('Bekfontein', 'Jan Maluleke', '#10', '<svg', 'data:image/jpeg;base64,')
        ->and($html)->not->toContain('data-number="11"');
});

it('draws the QR from the number alone', function () {
    // The same SVG for the same number, whoever the worker is: nothing but the number goes into the code.
    expect(WorkerCard::qrSvg('001'))->toBe(WorkerCard::qrSvg('001'))
        ->and(WorkerCard::qrSvg('001'))->not->toBe(WorkerCard::qrSvg('002'))
        ->and(WorkerCard::qrSvg('001'))->toStartWith('<svg');
});

it('needs a signed-in office user for the cards', function () {
    farm();
    $this->get('/workers/cards?ids=1')->assertRedirect('/admin/login');
});

it('puts the workers under Farm settings in the menu', function () {
    $this->actingAs(farm());

    $this->get('/admin/workers')->assertOk()->assertSee('Farm settings')->assertSee('Workers');
});
