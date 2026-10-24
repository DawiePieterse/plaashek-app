<?php

namespace App\Modules\Kudde\Support;

use App\Models\AuditLog;
use App\Modules\Kudde\Models\Animal;
use App\Modules\Kudde\Models\AnimalEvent;
use App\Modules\Weer\Models\WeatherDay;
use App\Modules\Weer\WeatherDays;
use App\Support\CampNames;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Every write to the herd (old Kudde's routers, less the HTTP). The office and, from phase 6, the phones'
 * sync both come through here, so the rules on a new animal, an event and a calving live once.
 *
 * Idempotent on client_uuid throughout, because a phone cannot tell a request that failed from one that
 * landed slowly: it gives up, keeps the record in its outbox and sends it again. Nothing else about an event
 * tells that replay from a real second one (an animal really can be treated twice in a day), so without the
 * capture id the herd quietly gains a duplicate every time the signal is weak.
 */
class Herd
{
    private ?int $userId = null;

    private ?int $workerId = null;

    /** What an animal's status becomes when an event of this kind is recorded. */
    private const STATUS_ON_EVENT = [AnimalEvent::DEATH => Animal::DEAD, AnimalEvent::SALE => Animal::SOLD];

    /**
     * The herd as written by this office user or worker: every record it writes is stamped with them. A copy,
     * because Herd is a singleton and one request's person must never leak into the next's.
     */
    public function by(?int $userId = null, ?int $workerId = null): self
    {
        $herd = clone $this;
        $herd->userId = $userId;
        $herd->workerId = $workerId;

        return $herd;
    }

    /**
     * A new animal, and, if a camp is named, the event that places it there: a movement dated today for an
     * animal entered by hand (where it is now, not a claim about where it was born). Without that a herd
     * keyed in from the office needed one hand-typed movement per head before Move camp could see it.
     *
     * @param  array{tag: string, sex: string, name?: string|null, breed?: string|null, birth_date?: string|null,
     *               sire_tag?: string|null, dam_tag?: string|null, castrated?: bool|null, camp?: string|null}  $fields
     */
    public function createAnimal(array $fields): Animal
    {
        return DB::transaction(fn () => $this->newAnimal($fields, AnimalEvent::MOVEMENT, today(), 'Entered into Kudde', null));
    }

    /**
     * An office correction: only the fields sent are touched, so a phone's "mark as sold" and an office edit
     * made around the same time cannot clobber each other. The tag is never changed here: it is the key every
     * event and every phone's cache knows the animal by.
     *
     * @param  array<string, mixed>  $fields
     */
    public function updateAnimal(Animal $animal, array $fields): Animal
    {
        $allowed = ['name', 'breed', 'sex', 'birth_date', 'sire_tag', 'dam_tag', 'castrated', 'status'];
        $fields = array_intersect_key($fields, array_flip($allowed));
        foreach (['sire_tag', 'dam_tag'] as $key) {
            if (array_key_exists($key, $fields)) {
                $fields[$key] = Tags::normalise($fields[$key]);
            }
        }
        foreach (['name', 'breed'] as $key) {
            if (array_key_exists($key, $fields)) {
                $fields[$key] = (string) $fields[$key];
            }
        }

        $before = $animal->only(array_keys($fields));
        $animal->fill($fields);

        if ($animal->isDirty()) {
            $changed = array_keys($animal->getDirty());
            $animal->save();
            AuditLog::record('kudde.animal.updated', $animal->tag,
                array_intersect_key($before, array_flip($changed)), $animal->only($changed));
        } else {
            $animal->touch();
        }

        return $animal;
    }

    /**
     * Record an event, once, however many times it is sent. A replay is answered with the event already on
     * file (so the phone takes it off its outbox) and changes nothing: a sale replayed after the office set the
     * animal back to alive must not sell it a second time.
     *
     * @param  array{tag: string, kind: string, event_date: string, value?: float|null, note?: string|null,
     *               location?: string|null, product?: string|null, dose?: string|null,
     *               withdrawal_days?: int|null, client_uuid?: string|null}  $fields
     */
    public function recordEvent(array $fields): AnimalEvent
    {
        $uuid = $fields['client_uuid'] ?? null;
        if ($uuid !== null && ($already = AnimalEvent::query()->where('client_uuid', $uuid)->first()) !== null) {
            return $already;
        }

        return DB::transaction(function () use ($fields, $uuid) {
            $animal = HerdState::animalByTag($fields['tag']);
            $kind = $fields['kind'];
            $isTreatment = $kind === AnimalEvent::TREATMENT;

            $event = $this->stamp(new AnimalEvent([
                'animal_id' => $animal->id,
                'kind' => $kind,
                'event_date' => $fields['event_date'],
                'value' => $fields['value'] ?? null,
                'note' => trim((string) ($fields['note'] ?? '')),
                'location' => $this->location($kind, $fields['location'] ?? ''),
                // Dropped, not refused, on any other kind: a form that carried a product across a kind change
                // should not fail the save, nor put a withdrawal on a weighing.
                'product' => $isTreatment ? self::tidy($fields['product'] ?? '') : '',
                'dose' => $isTreatment ? self::tidy($fields['dose'] ?? '') : '',
                'withdrawal_days' => $isTreatment ? ($fields['withdrawal_days'] ?? null) : null,
                'client_uuid' => $uuid,
                ...$this->actor(),
            ]));
            $event->save();
            // Read back, so every column is in the answer (the weather nulls included): the phone stores it.
            $event->refresh();

            $status = self::STATUS_ON_EVENT[$kind] ?? null;
            if ($status !== null && $animal->status !== $status) {
                // updated_at moves too: the animal really did change here.
                $animal->update(['status' => $status]);
            }

            return $event;
        });
    }

    /**
     * The same movement for every listed animal: a camp moving together, as one save.
     *
     * @param  list<string>  $tags
     * @return list<AnimalEvent>
     */
    public function moveAnimals(array $tags, string $date, string $location, string $note = '', ?string $uuid = null): array
    {
        return DB::transaction(function () use ($tags, $date, $location, $note, $uuid) {
            $animals = HerdState::animalsByTags($tags);
            $camp = $this->location(AnimalEvent::MOVEMENT, $location);
            if ($camp === '') {
                throw new HerdException(__('kudde::ui.move_needs_camp'), 422);
            }

            return $this->batch($animals, $uuid, [
                'kind' => AnimalEvent::MOVEMENT, 'event_date' => $date, 'location' => $camp, 'note' => trim($note),
            ]);
        });
    }

    /**
     * The same treatment for every listed animal: dosing day, a group through the crush.
     *
     * @param  list<string>  $tags
     * @param  array{product?: string|null, dose?: string|null, withdrawal_days?: int|null, note?: string|null}  $treatment
     * @return list<AnimalEvent>
     */
    public function treatAnimals(array $tags, string $date, array $treatment, ?string $uuid = null): array
    {
        return DB::transaction(fn () => $this->batch(HerdState::animalsByTags($tags), $uuid, [
            'kind' => AnimalEvent::TREATMENT,
            'event_date' => $date,
            'note' => trim((string) ($treatment['note'] ?? '')),
            'product' => self::tidy($treatment['product'] ?? ''),
            'dose' => self::tidy($treatment['dose'] ?? ''),
            'withdrawal_days' => $treatment['withdrawal_days'] ?? null,
        ]));
    }

    /**
     * A calving: the cow's birth event and the new calf, as one record or none. The calf is made first,
     * through the same path as any new animal, so a taken calf tag refuses the lot before the cow is given a
     * birth event for a calf that does not exist. The calf gets a birth event of its own, dated and placed the
     * same, which is what puts it in that camp from day one.
     *
     * @param  array{tag: string, sex: string, name?: string|null, breed?: string|null, sire_tag?: string|null,
     *               castrated?: bool|null}  $calf
     * @return array{dam_event: AnimalEvent, calf: Animal|null, replayed: bool}
     */
    public function recordCalving(string $damTag, string $date, array $calf, string $camp = '', string $note = '', ?string $uuid = null): array
    {
        if ($uuid !== null && ($already = AnimalEvent::query()->where('client_uuid', $uuid)->first()) !== null) {
            $calfAnimal = Animal::query()->where('tag', Tags::normalise($calf['tag']) ?? '')->first();

            return ['dam_event' => $already, 'calf' => $calfAnimal, 'replayed' => true];
        }

        return DB::transaction(function () use ($damTag, $date, $calf, $camp, $note, $uuid) {
            $dam = HerdState::animalByTag($damTag);
            if ($dam->sex !== Animal::FEMALE) {
                throw new HerdException(__('kudde::ui.male_cannot_calve', ['tag' => $dam->tag]), 422);
            }

            $camp = CampNames::normalise($camp);
            $calfAnimal = $this->newAnimal(
                [...$calf, 'dam_tag' => $dam->tag, 'birth_date' => $date, 'camp' => $camp],
                AnimalEvent::BIRTH, Carbon::parse($date), 'Born to '.$dam->tag,
                $uuid !== null ? $uuid.':calf' : null, alwaysPlace: true,
            );

            $note = trim($note);
            $damEvent = $this->stamp(new AnimalEvent([
                'animal_id' => $dam->id,
                'kind' => AnimalEvent::BIRTH,
                'event_date' => $date,
                'location' => $camp === '' ? '' : CampNames::ensure($camp)->name,
                'note' => 'Calf '.$calfAnimal->tag.($note === '' ? '' : ' - '.$note),
                'client_uuid' => $uuid,
                ...$this->actor(),
            ]));
            $damEvent->save();
            $damEvent->refresh();
            $dam->touch();

            return ['dam_event' => $damEvent, 'calf' => $calfAnimal, 'replayed' => false];
        });
    }

    /**
     * Fills in the weather on recent events recorded before their day reached weather_days (an event captured
     * today is stamped tomorrow morning, after `weer:days`). Returns how many were filled.
     */
    public function stampMissingWeather(int $days = 14): int
    {
        $filled = 0;
        AnimalEvent::query()
            ->whereNull('weather_temp_max')->whereNull('weather_temp_min')->whereNull('weather_rain')
            ->where('event_date', '>=', today()->subDays($days))
            ->get()
            ->each(function (AnimalEvent $event) use (&$filled) {
                if ($this->stamp($event)->isDirty()) {
                    $event->save();
                    $filled++;
                }
            });

        return $filled;
    }

    /**
     * Adds an animal (the caller holds the transaction) and the event that places it, if a camp is named.
     * Refuses a taken tag before anything is written.
     *
     * @param  array<string, mixed>  $fields
     */
    private function newAnimal(array $fields, string $placedKind, Carbon $on, string $note, ?string $uuid, bool $alwaysPlace = false): Animal
    {
        $tag = Tags::normalise($fields['tag'] ?? null) ?? throw new HerdException(__('kudde::ui.tag_required'), 422);

        if (Animal::query()->where('tag', $tag)->exists()) {
            throw new HerdException(__('kudde::ui.tag_in_use', ['tag' => $tag]), 409);
        }

        $animal = Animal::query()->create([
            'tag' => $tag,
            'name' => (string) ($fields['name'] ?? ''),
            'breed' => (string) ($fields['breed'] ?? ''),
            'sex' => $fields['sex'],
            'birth_date' => $fields['birth_date'] ?? null,
            'sire_tag' => Tags::normalise($fields['sire_tag'] ?? null),
            'dam_tag' => Tags::normalise($fields['dam_tag'] ?? null),
            'castrated' => (bool) ($fields['castrated'] ?? false),
            ...$this->actor(),
        ]);

        $camp = CampNames::normalise($fields['camp'] ?? '');
        if ($camp !== '' || $alwaysPlace) {
            $this->stamp(new AnimalEvent([
                'animal_id' => $animal->id,
                'kind' => $placedKind,
                'event_date' => $on->toDateString(),
                'location' => $camp === '' ? '' : CampNames::ensure($camp)->name,
                'note' => $note,
                'client_uuid' => $uuid,
                ...$this->actor(),
            ]))->save();
        }

        return $animal;
    }

    /**
     * The same event for every animal in a batch, written once however many times the batch is replayed.
     * client_uuid is unique per row, so each member's is derived from the batch's; a batch that half landed
     * before the connection dropped then completes on replay without duplicating what did land.
     *
     * @param  list<Animal>  $animals
     * @param  array<string, mixed>  $shared
     * @return list<AnimalEvent>
     */
    private function batch(array $animals, ?string $uuid, array $shared): array
    {
        $already = [];
        if ($uuid !== null) {
            $keys = array_map(fn (Animal $a) => $uuid.':'.$a->id, $animals);
            $already = AnimalEvent::query()->whereIn('client_uuid', $keys)->get()->keyBy('client_uuid')->all();
        }

        // One lookup for the whole batch: the farm and the date are the same for every head.
        $weather = WeatherDays::on($shared['event_date']);
        $events = [];
        foreach ($animals as $animal) {
            $key = $uuid !== null ? $uuid.':'.$animal->id : null;
            if ($key !== null && isset($already[$key])) {
                $events[] = $already[$key];

                continue;
            }
            $event = $this->stamp(new AnimalEvent([...$shared, 'animal_id' => $animal->id, 'client_uuid' => $key, ...$this->actor()]), $weather, true);
            $event->save();
            $events[] = $event->refresh();
        }

        return $events;
    }

    /**
     * The location to store: for the kinds that place an animal, the spelling the office holds (added if
     * new). Other kinds keep whatever was typed.
     */
    private function location(string $kind, ?string $location): string
    {
        $location = CampNames::normalise($location);

        if ($location === '' || ! in_array($kind, AnimalEvent::PLACING_KINDS, true)) {
            return $location;
        }

        return CampNames::ensure($location)->name;
    }

    /** The day's weather at the farm, from weather_days. Missing weather leaves the columns null. */
    private function stamp(AnimalEvent $event, ?WeatherDay $weather = null, bool $looked = false): AnimalEvent
    {
        $weather = $looked ? $weather : WeatherDays::on($event->event_date);

        if ($weather !== null) {
            $event->weather_temp_max = $weather->temp_max;
            $event->weather_temp_min = $weather->temp_min;
            $event->weather_rain = $weather->rain;
        }

        return $event;
    }

    /** @return array{user_id: int|null, worker_id: int|null} */
    private function actor(): array
    {
        return ['user_id' => $this->userId, 'worker_id' => $this->workerId];
    }

    private static function tidy(?string $value): string
    {
        return CampNames::normalise($value);
    }
}
