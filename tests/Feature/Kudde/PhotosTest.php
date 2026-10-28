<?php

// Photos of an animal: uploaded once however often a phone retries, kept on the private disk, shown by signed link.

use App\Models\User;
use App\Models\Worker;
use App\Modules\Kudde\Filament\Resources\Animals\Pages\ViewAnimal;
use App\Modules\Kudde\Filament\Resources\Animals\RelationManagers\PhotosRelationManager;
use App\Modules\Kudde\Models\Animal;
use App\Modules\Kudde\Models\Photo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake(Photo::DISK, ['serve' => true]);
    kudde();
    addAnimal('A1');
});

function upload(array $fields = []): TestResponse
{
    return test()->post(kapi('/animals/A1/photos'), array_merge([
        'photo' => UploadedFile::fake()->image('a1.jpg', 800, 600), 'client_uuid' => 'cap-1',
    ], $fields), ['Accept' => 'application/json']);
}

it('stores a photo once, however many times the phone sends it', function () {
    $first = upload(['note' => 'left side'])->assertOk()->json();
    upload()->assertOk()->assertJsonPath('id', $first['id']);

    $photo = Photo::query()->sole();
    expect($photo->note)->toBe('left side')->and($photo->taken_on->toDateString())->toBe(today()->toDateString())
        ->and($photo->path)->toStartWith('photos/kudde/'.$photo->animal_id.'/')
        ->and($first['url'])->toContain('expiration=');
    Storage::disk(Photo::DISK)->assertExists($photo->path);
    expect(Storage::disk(Photo::DISK)->allFiles())->toHaveCount(1);
});

it('refuses what is not a photo, and a viewer', function () {
    upload(['photo' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')])->assertStatus(422);

    $this->actingAs(User::factory()->role(User::VIEWER)->create());
    upload()->assertForbidden();
    expect(Photo::query()->count())->toBe(0);
});

it('lists the photos with links, newest first', function () {
    upload(['client_uuid' => 'old', 'taken_on' => daysFromToday(-30)])->assertOk();
    upload(['client_uuid' => 'new'])->assertOk();

    expect(array_column($this->getJson(kapi('/animals/A1/photos'))->assertOk()->json(), 'client_uuid'))->toBe(['new', 'old']);
});

it('takes photos from a worker\'s phone, stamped with the worker and the phone', function () {
    $worker = Worker::query()->create(['number' => '001', 'first_name' => 'Jan', 'last_name' => 'Mathebula']);
    $phone = pairPhone();

    $this->post('/kudde/field/api/animals/A1/photos', ['photo' => UploadedFile::fake()->image('a1.jpg'), 'client_uuid' => 'f-1'],
        phoneHeaders($phone, scanOn($phone, '001')))->assertOk();

    expect(Photo::query()->sole()->worker_id)->toBe($worker->id)->and(Photo::query()->sole()->device_id)->not->toBeNull();
});

it('removes a photo with its file, from the office page', function () {
    upload()->assertOk();
    $photo = Photo::query()->sole();

    Livewire::test(PhotosRelationManager::class, ['ownerRecord' => Animal::query()->sole(), 'pageClass' => ViewAnimal::class])
        ->assertCanSeeTableRecords([$photo])
        ->callTableAction('remove', $photo);

    expect(Photo::query()->count())->toBe(0);
    Storage::disk(Photo::DISK)->assertMissing($photo->path);
    $this->assertDatabaseHas('audit_log', ['action' => 'kudde.photo.removed', 'subject' => 'A1']);
});
