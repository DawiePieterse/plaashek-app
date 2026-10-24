<?php

namespace App\Modules\Kudde\Support;

use App\Models\AuditLog;
use App\Modules\Kudde\Models\Animal;
use App\Modules\Kudde\Models\AnimalEvent;
use App\Modules\Kudde\Models\Litter;
use App\Modules\Kudde\Models\Species;
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

    /** The species the request names, for its tag lookups and new animals; null: any, or the farm's default. */
    private ?Species $species = null;

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
     * The herd for one species: tags are looked up within it and new animals belong to it. Tags are unique per
     * species, so on a farm with sheep and cattle a request that names neither gets a 409 for a tag both use.
     */
    public function of(?Species $species): self
    {
        $herd = clone $this;
        $herd->species = $species;

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
            $animal = HerdState::animalByTag($fields['tag'], $this->species);
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
            $animals = HerdState::animalsByTags($tags, $this->species);
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
        return DB::transaction(fn () => $this->batch(HerdState::animalsByTags($tags, $this->species), $uuid, [
            'kind' => AnimalEvent::TREATMENT,
            'event_date' => $date,
            'note' => trim((string) ($treatment['note'] ?? '')),
            'product' => self::tidy($treatment['product'] ?? ''),
            'dose' => self::tidy($treatment['dose'] ?? ''),
            'withdrawal_days' => $treatment['withdrawal_days'] ?? null,
        ]));
    }

    /**
     * A calving: the cow's birth event and the new calf, as one record or none. Kept for the field app's
     * older outbox records, which send one calf; recordBirth is the general form.
     *
     * @param  array{tag: string, sex: string, name?: string|null, breed?: string|null, sire_tag?: string|null,
     *               castrated?: bool|null}  $calf
     * @return array{dam_event: AnimalEvent, calf: Animal|null, young: list<Animal>, litter: Litter|null, replayed: bool}
     */
    public function recordCalving(string $damTag, string $date, array $calf, string $camp = '', string $note = '', ?string $uuid = null): array
    {
        return $this->recordBirth($damTag, $date, [$calf], null, $camp, $note, $uuid);
    }

    /**
     * A birth: the mother's birth event, plus either the young as animals (a calf, twin lambs) or, for young
     * tagged only at weaning, the litter counted on her, or both. One record or none. The young are made first,
     * through the same path as any new animal, so a taken tag refuses the lot before the mother is given a birth
     * event for young that do not exist. Each young one gets a birth event of its own, dated and placed the same,
     * which is what puts it in that camp from day one.
     *
     * @param  list<array<string, mixed>>  $young  each: tag, sex, name, breed, sire_tag, castrated
     * @param  array{born_alive: int, stillborn?: int|null, sire_tag?: string|null}|null  $litter
     * @return array{dam_event: AnimalEvent, calf: Animal|null, young: list<Animal>, litter: Litter|null, replayed: bool}
     */
    public function recordBirth(string $damTag, string $date, array $young, ?array $litter, string $camp = '', string $note = '', ?string $uuid = null): array
    {
        if ($young === [] && $litter === null) {
            throw new HerdException(__('kudde::ui.birth_needs_young'), 422);
        }

        if ($uuid !== null && ($already = AnimalEvent::query()->where('client_uuid', $uuid)->first()) !== null) {
            $tags = array_map(fn (array $y) => Tags::normalise($y['tag'] ?? null) ?? '', $young);
            $animals = Animal::query()->whereIn('tag', $tags)->where('species_id', $already->animal?->species_id)
                ->where('dam_tag', $already->animal?->tag)->get();
            $made = array_values(array_filter(array_map(fn (string $t) => $animals->first(fn (Animal $a) => mb_strtolower($a->tag) === mb_strtolower($t)), $tags)));

            return ['dam_event' => $already, 'calf' => $made[0] ?? null, 'young' => $made,
                'litter' => Litter::query()->where('birth_event_id', $already->id)->first(), 'replayed' => true];
        }

        return DB::transaction(function () use ($damTag, $date, $young, $litter, $camp, $note, $uuid) {
            $dam = HerdState::animalByTag($damTag, $this->species);
            if ($dam->sex !== Animal::FEMALE) {
                throw new HerdException(__('kudde::ui.male_cannot_calve', ['tag' => $dam->tag]), 422);
            }

            $camp = CampNames::normalise($camp);
            // The young are their mother's species, whatever the request said.
            $herd = $this->of($dam->species);
            $made = [];
            foreach ($young as $i => $one) {
                // The first keeps the single calf's id, so an outbox record from before twins still replays.
                $key = $uuid === null ? null : $uuid.':calf'.($i === 0 ? '' : $i + 1);
                $made[] = $herd->newAnimal(
                    [...$one, 'dam_tag' => $dam->tag, 'birth_date' => $date, 'camp' => $camp],
                    AnimalEvent::BIRTH, Carbon::parse($date), 'Born to '.$dam->tag, $key, alwaysPlace: true,
                );
            }

            $what = [];
            if ($made !== []) {
                $what[] = (count($made) === 1 ? 'Calf ' : 'Young ').implode(', ', array_map(fn (Animal $a) => $a->tag, $made));
            }
            if ($litter !== null) {
                $what[] = 'Litter '.$litter['born_alive'].' alive'.(($litter['stillborn'] ?? 0) > 0 ? ', '.$litter['stillborn'].' stillborn' : '');
            }
            $note = trim($note);
            $damEvent = $this->stamp(new AnimalEvent([
                'animal_id' => $dam->id,
                'kind' => AnimalEvent::BIRTH,
                'event_date' => $date,
                'location' => $camp === '' ? '' : CampNames::ensure($camp)->name,
                'note' => implode('; ', $what).($note === '' ? '' : ' - '.$note),
                'client_uuid' => $uuid,
                ...$this->actor(),
            ]));
            $damEvent->save();
            $damEvent->refresh();
            $dam->touch();

            $counted = $litter === null ? null : Litter::query()->create([
                'dam_id' => $dam->id,
                'birth_event_id' => $damEvent->id,
                'born_on' => $date,
                'born_alive' => (int) $litter['born_alive'],
                'stillborn' => (int) ($litter['stillborn'] ?? 0),
                'sire_tag' => Tags::normalise($litter['sire_tag'] ?? null),
            ]);

            return ['dam_event' => $damEvent, 'calf' => $made[0] ?? null, 'young' => $made, 'litter' => $counted, 'replayed' => false];
        });
    }

    /**
     * A litter weaned: its young become animals, tagged now, with the mother as dam and the litter's birth date,
     * each placed in the camp they are weaned into. The count on the litter closes it. A replay of the same
     * weaning (its capture id) returns what was made the first time; a second, different weaning is refused.
     *
     * @param  list<array<string, mixed>>  $young  each: tag, sex, name, breed, castrated
     * @return array{litter: Litter, young: list<Animal>, replayed: bool}
     */
    public function weanLitter(Litter $litter, string $date, array $young, string $camp = '', int $fostered = 0, ?string $uuid = null): array
    {
        $dam = $litter->dam;
        $mine = fn () => Animal::query()->where('species_id', $dam->species_id)->where('dam_tag', $dam->tag)
            ->whereDate('birth_date', $litter->born_on)->orderBy('id')->get()->all();

        if ($litter->weaned_on !== null) {
            if ($uuid !== null && $litter->wean_uuid === $uuid) {
                return ['litter' => $litter, 'young' => $mine(), 'replayed' => true];
            }
            throw new HerdException(__('kudde::ui.litter_weaned', ['tag' => $dam->tag]), 409);
        }
        if (Carbon::parse($date)->lt($litter->born_on)) {
            throw new HerdException(__('kudde::ui.wean_before_birth'), 422);
        }

        return DB::transaction(function () use ($litter, $dam, $date, $young, $camp, $fostered, $uuid, $mine) {
            $herd = $this->of($dam->species);
            $camp = CampNames::normalise($camp);
            foreach ($young as $i => $one) {
                $herd->newAnimal(
                    [...$one, 'dam_tag' => $dam->tag, 'sire_tag' => $one['sire_tag'] ?? $litter->sire_tag,
                        'birth_date' => $litter->born_on->toDateString(), 'camp' => $camp],
                    AnimalEvent::MOVEMENT, Carbon::parse($date), 'Weaned from '.$dam->tag,
                    $uuid === null ? null : $uuid.':young'.($i + 1),
                );
            }

            $litter->update(['weaned_on' => $date, 'weaned' => count($young), 'fostered' => $fostered, 'wean_uuid' => $uuid]);
            $dam->touch();

            return ['litter' => $litter->refresh(), 'young' => $mine(), 'replayed' => false];
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

        $species = $this->species ?? Species::fallback();
        if (Animal::query()->where('species_id', $species->id)->where('tag', $tag)->exists()) {
            throw new HerdException(__('kudde::ui.tag_in_use', ['tag' => $tag]), 409);
        }

        $animal = Animal::query()->create([
            'species_id' => $species->id,
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
