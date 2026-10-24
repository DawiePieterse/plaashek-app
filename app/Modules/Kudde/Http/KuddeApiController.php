<?php

namespace App\Modules\Kudde\Http;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Camp;
use App\Models\User;
use App\Modules\Kudde\Models\Animal;
use App\Modules\Kudde\Models\AnimalEvent;
use App\Modules\Kudde\Support\AnimalClass;
use App\Modules\Kudde\Support\Herd;
use App\Modules\Kudde\Support\HerdException;
use App\Modules\Kudde\Support\HerdState;
use App\Modules\Kudde\Support\Tags;
use App\Support\CampNames;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Kudde's JSON API, old Kudde's routers on the Herd and HerdState classes. Signed-in office users only until
 * the field shell (phase 6) gives phones their own sign-in; a viewer reads and exports but never writes.
 */
class KuddeApiController extends Controller
{
    /**
     * Past this many days without a weighing, an alive animal needs attention. Six months: a farmer weighing
     * every muster or two isn't nagged, and an animal that fell through the cracks is still caught.
     */
    public const STALE_WEIGHT_DAYS = 182;

    /** Most events any one request returns. Nothing asks for more than a screenful. */
    private const MAX_EVENT_LIMIT = 500;

    /** Distinct products the pickers offer: a farm's medicine cupboard is a dozen bottles. */
    private const RECENT_PRODUCTS = 20;

    public function __construct(private Herd $herd) {}

    // --- Animals -------------------------------------------------------------------------------------

    /** q matches the start of a tag or anywhere in a name: the two things a farmer has in hand. */
    public function animals(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', Rule::in(Animal::STATUSES)], 'q' => ['nullable', 'string']]);
        $state = HerdState::load();
        $needle = mb_strtolower((string) $request->query('q', ''));
        $status = $request->query('status');

        $animals = $state->animals
            ->filter(fn (Animal $a) => $status === null || $a->status === $status)
            ->filter(fn (Animal $a) => $needle === '' || str_starts_with(mb_strtolower($a->tag), $needle)
                || str_contains(mb_strtolower($a->name), $needle));

        return response()->json($animals->map(fn (Animal $a) => $state->describe($a))->values());
    }

    public function animal(string $tag): JsonResponse
    {
        $animal = HerdState::animalByTag($tag);

        return response()->json(HerdState::load()->describe($animal));
    }

    public function storeAnimal(Request $request): JsonResponse
    {
        $this->mayWrite($request);
        $animal = $this->herd->createAnimal($request->validate($this->animalRules() + [
            'tag' => ['required', 'string', 'max:50'],
            'sex' => ['required', Rule::in(Animal::SEXES)],
            'camp' => ['nullable', 'string', 'max:100'],
        ]), $request->user()?->id);

        return response()->json($animal->refresh());
    }

    public function updateAnimal(Request $request, string $tag): JsonResponse
    {
        $this->mayWrite($request);
        $animal = HerdState::animalByTag($tag);
        $fields = $request->validate($this->animalRules() + [
            'sex' => ['sometimes', Rule::in(Animal::SEXES)],
            'status' => ['sometimes', Rule::in(Animal::STATUSES)],
        ]);

        return response()->json($this->herd->updateAnimal($animal, $fields, $request->user()?->id));
    }

    /** One animal's history, newest first; the id breaks ties so a muster's events keep their order. */
    public function animalEvents(string $tag): JsonResponse
    {
        $animal = HerdState::animalByTag($tag);

        return response()->json($animal->events()->orderByDesc('event_date')->orderByDesc('id')->get());
    }

    // --- Events --------------------------------------------------------------------------------------

    public function storeEvent(Request $request): JsonResponse
    {
        $this->mayWrite($request);
        $fields = $request->validate([
            'tag' => ['required', 'string'],
            'kind' => ['required', Rule::in(AnimalEvent::KINDS)],
            'event_date' => ['required', 'date_format:Y-m-d'],
            'value' => ['nullable', 'numeric'],
            'note' => ['nullable', 'string', 'max:1000'],
            'location' => ['nullable', 'string', 'max:100'],
            'client_uuid' => ['nullable', 'string', 'max:80'],
        ] + $this->treatmentRules());

        // A weight event with no weight is worse than none: the "needs weighing" list goes by the date of
        // the last weighing, so a blank one silences it for six months while recording nothing. The apps
        // send parseFloat(input) || null, so an empty box, "abc" and a typed 0 all arrive as null.
        if ($fields['kind'] === AnimalEvent::WEIGHT && (float) ($fields['value'] ?? 0) <= 0) {
            throw ValidationException::withMessages(['value' => __('kudde::ui.weight_needs_kg')]);
        }

        return response()->json($this->herd->recordEvent($fields, $request->user()?->id));
    }

    /** Recent events across the herd, newest first: the office's activity feed. */
    public function events(Request $request): JsonResponse
    {
        $request->validate([
            'kind' => ['nullable', Rule::in(AnimalEvent::KINDS)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_EVENT_LIMIT],
        ]);

        return response()->json(AnimalEvent::query()
            ->when($request->query('kind'), fn ($q, $kind) => $q->where('kind', $kind))
            ->orderByDesc('event_date')->orderByDesc('id')
            ->limit((int) $request->query('limit', '50'))
            ->get());
    }

    public function moveAnimals(Request $request): JsonResponse
    {
        $this->mayWrite($request);
        $fields = $request->validate($this->batchRules() + ['location' => ['nullable', 'string', 'max:100']]);

        return response()->json($this->herd->moveAnimals(self::tags($fields), $fields['event_date'],
            (string) ($fields['location'] ?? ''), (string) ($fields['note'] ?? ''), $fields['client_uuid'] ?? null,
            $request->user()?->id));
    }

    public function treatAnimals(Request $request): JsonResponse
    {
        $this->mayWrite($request);
        $fields = $request->validate($this->batchRules() + $this->treatmentRules());

        return response()->json($this->herd->treatAnimals(self::tags($fields), $fields['event_date'], $fields,
            $fields['client_uuid'] ?? null, $request->user()?->id));
    }

    public function storeCalving(Request $request): JsonResponse
    {
        $this->mayWrite($request);
        $fields = $request->validate([
            'dam_tag' => ['required', 'string'],
            'event_date' => ['required', 'date_format:Y-m-d'],
            'calf' => ['required', 'array'],
            'calf.tag' => ['required', 'string', 'max:50'],
            'calf.sex' => ['required', Rule::in(Animal::SEXES)],
            'calf.name' => ['nullable', 'string', 'max:100'],
            'calf.breed' => ['nullable', 'string', 'max:100'],
            'calf.sire_tag' => ['nullable', 'string', 'max:50'],
            'calf.castrated' => ['nullable', 'boolean'],
            'camp' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:900'],
            'client_uuid' => ['nullable', 'string', 'max:70'],
        ]);

        return response()->json($this->herd->recordCalving($fields['dam_tag'], $fields['event_date'], $fields['calf'],
            (string) ($fields['camp'] ?? ''), (string) ($fields['note'] ?? ''), $fields['client_uuid'] ?? null,
            $request->user()?->id));
    }

    /**
     * The products given before, most recently used first, each with the dose and withdrawal it was last
     * given with: the next treatment with the same bottle is one tap. Nothing to set up.
     */
    public function products(): JsonResponse
    {
        $products = [];
        AnimalEvent::query()->where('kind', AnimalEvent::TREATMENT)->where('product', '!=', '')
            ->orderByDesc('event_date')->orderByDesc('id')
            ->get(['product', 'dose', 'withdrawal_days'])
            ->each(function (AnimalEvent $e) use (&$products) {
                $products[CampNames::fold($e->product)] ??= ['product' => $e->product, 'dose' => $e->dose, 'withdrawal_days' => $e->withdrawal_days];

                return count($products) < self::RECENT_PRODUCTS;
            });

        return response()->json(array_values($products));
    }

    /** Every camp with the alive animals in it: what the "move whole camp" picker shows. */
    public function locations(): JsonResponse
    {
        return response()->json(array_map(fn (array $group) => [
            'location' => $group[0],
            'animals' => array_map(fn (Animal $a) => ['tag' => $a->tag, 'name' => $a->name], $group[1]),
        ], HerdState::load()->campGroups()));
    }

    // --- Camps (core master data; managed from Kudde until the core's own office page) -----------------

    public function camps(Request $request): JsonResponse
    {
        $counts = $this->aliveCounts();

        return response()->json(Camp::query()->inFarmOrder()
            ->when(! $request->boolean('include_archived'), fn ($q) => $q->where('archived', false))
            ->get()->map(fn (Camp $c) => $this->presentCamp($c, $counts)));
    }

    public function storeCamp(Request $request): JsonResponse
    {
        $this->mayWrite($request);
        $name = CampNames::normalise($request->validate(['name' => ['required', 'string', 'max:100']])['name']);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => __('kudde::ui.camp_needs_name')]);
        }

        $existing = CampNames::find($name);
        if ($existing !== null && ! $existing->archived) {
            throw new HerdException(__('kudde::ui.camp_exists', ['name' => $existing->name]), 409);
        }
        // Restores an archived camp under its old row, so the records that named it and this stay one camp.
        $camp = CampNames::ensure($name);
        AuditLog::record('camp.created', $camp->name);

        return response()->json($this->presentCamp($camp, $this->aliveCounts()));
    }

    public function updateCamp(Request $request, Camp $camp): JsonResponse
    {
        $this->mayWrite($request);
        $fields = $request->validate(['name' => ['sometimes', 'string', 'max:100'], 'archived' => ['sometimes', 'boolean']]);
        $before = $camp->only(['name', 'archived']);

        if (array_key_exists('name', $fields)) {
            $name = CampNames::normalise($fields['name']);
            if ($name === '') {
                throw ValidationException::withMessages(['name' => __('kudde::ui.camp_needs_name')]);
            }
            $other = CampNames::find($name);
            if ($other !== null && $other->id !== $camp->id) {
                throw new HerdException(__('kudde::ui.camp_exists', ['name' => $other->name]), 409);
            }
            // A rename rewrites no records: history keeps the name it was recorded under.
            $camp->name = $name;
        }

        $counts = $this->aliveCounts();
        if (($fields['archived'] ?? false) && ! $camp->archived) {
            $held = $counts[CampNames::fold($before['name'])] ?? 0;
            if ($held > 0) {
                throw new HerdException(trans_choice('kudde::ui.camp_holds', $held, ['name' => $before['name'], 'count' => $held]), 409);
            }
        }
        if (array_key_exists('archived', $fields)) {
            $camp->archived = (bool) $fields['archived'];
        }

        if ($camp->isDirty()) {
            $camp->save();
            AuditLog::record('camp.updated', $camp->name, $before, $camp->only(['name', 'archived']));
        }

        return response()->json($this->presentCamp($camp, $counts));
    }

    /** The whole order in one call, so two quick taps cannot leave two camps at one position. */
    public function orderCamps(Request $request): JsonResponse
    {
        $this->mayWrite($request);
        $ids = array_map('intval', $request->validate(['ids' => ['present', 'array'], 'ids.*' => ['integer']])['ids']);
        $camps = Camp::query()->get()->keyBy('id');

        foreach ($ids as $id) {
            if (! $camps->has($id)) {
                throw new HerdException(__('kudde::ui.no_camp'), 404);
            }
        }

        DB::transaction(function () use ($ids, $camps) {
            foreach ($ids as $position => $id) {
                $camps[$id]->update(['sort_order' => $position]);
            }
            // Anything not listed (an archived camp, say) keeps a place after them.
            $camps->except($ids)->sortBy([['sort_order', 'asc'], ['id', 'asc']])->values()
                ->each(fn (Camp $c, int $i) => $c->update(['sort_order' => count($ids) + $i]));
        });

        return $this->camps(new Request(['include_archived' => true]));
    }

    // --- Dashboard and export ------------------------------------------------------------------------

    public function dashboard(): JsonResponse
    {
        $state = HerdState::load();
        $alive = $state->alive();

        $herdCounts = array_fill_keys(Animal::STATUSES, 0);
        foreach ($state->animals as $animal) {
            $herdCounts[$animal->status]++;
        }

        // Named, not bare: "sale - 9/6/2026" without the animal is close to useless to whoever reads it.
        $recent = AnimalEvent::query()->with('animal:id,tag,name')
            ->orderByDesc('event_date')->orderByDesc('id')->limit(10)->get()
            ->map(fn (AnimalEvent $e) => $e->toArray() + ['tag' => $e->animal?->tag, 'name' => $e->animal?->name]);

        $cutoff = today()->subDays(self::STALE_WEIGHT_DAYS);
        $needsWeighing = $alive->filter(fn (Animal $a) => ! isset($state->weights[$a->id]) || $state->weights[$a->id][1]->lt($cutoff));

        // The ones that must not go to the sale this week, soonest clear first.
        $inWithdrawal = $alive->filter(fn (Animal $a) => isset($state->withdrawal[$a->id]))
            ->sort(fn (Animal $a, Animal $b) => $state->withdrawal[$a->id]->getTimestamp() <=> $state->withdrawal[$b->id]->getTimestamp()
                ?: Tags::compare($a->tag, $b->tag))
            ->map(fn (Animal $a) => ['tag' => $a->tag, 'name' => $a->name, 'until' => $state->withdrawal[$a->id]->toDateString()]);

        // So many cows, so many heifers: every class present, at zero if need be, so the card is stable.
        $classCounts = array_fill_keys(AnimalClass::CLASSES, 0);
        foreach ($alive as $animal) {
            $classCounts[$state->classOf($animal)]++;
        }

        return response()->json([
            'herd_counts' => $herdCounts,
            'class_counts' => $classCounts,
            'camp_counts' => array_map(fn (array $g) => ['camp' => $g[0], 'alive' => count($g[1])], $state->campGroups()),
            'in_withdrawal' => $inWithdrawal->values(),
            'total_alive' => $herdCounts[Animal::ALIVE],
            'recent_events' => $recent,
            'needs_weighing' => $needsWeighing->values(),
        ]);
    }

    public function exportAnimals(): StreamedResponse
    {
        $state = HerdState::load();

        return $this->csv('kudde-animals', ['tag', 'name', 'breed', 'sex', 'class', 'castrated', 'birth_date', 'sire_tag',
            'dam_tag', 'status', 'camp', 'last_weight_kg', 'last_weight_date', 'withdrawal_until'],
            $state->animals->map(function (Animal $a) use ($state) {
                $d = $state->describe($a);

                return [$a->tag, $a->name, $a->breed, $a->sex, $d['class'], $a->castrated ? 'yes' : '',
                    $a->birth_date?->toDateString(), $a->sire_tag, $a->dam_tag, $a->status, $d['camp'],
                    $d['last_weight'] === null ? null : number_format($d['last_weight'], 1, '.', ''),
                    $d['last_weight_date'], $d['withdrawal_until']];
            }));
    }

    /** Every event, oldest first, with the animal's tag and name on each row so the file reads on its own. */
    public function exportEvents(): StreamedResponse
    {
        $one = fn (?float $v) => $v === null ? null : number_format($v, 1, '.', '');

        return $this->csv('kudde-events', ['tag', 'name', 'kind', 'event_date', 'weight_kg', 'camp', 'product', 'dose',
            'withdrawal_days', 'note', 'temp_min_c', 'temp_max_c', 'rain_mm', 'recorded_at'],
            AnimalEvent::query()->with('animal:id,tag,name')->orderBy('event_date')->orderBy('id')->get()
                ->map(fn (AnimalEvent $e) => [$e->animal?->tag, $e->animal?->name, $e->kind, $e->event_date->toDateString(),
                    $one($e->value), $e->location, $e->product, $e->dose, $e->withdrawal_days, $e->note,
                    $one($e->weather_temp_min), $one($e->weather_temp_max), $one($e->weather_rain),
                    $e->created_at->format('Y-m-d\TH:i:s')]));
    }

    // --- Helpers -------------------------------------------------------------------------------------

    /** @return array<string, list<mixed>> */
    private function animalRules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:100'],
            'breed' => ['nullable', 'string', 'max:100'],
            'birth_date' => ['nullable', 'date_format:Y-m-d'],
            'sire_tag' => ['nullable', 'string', 'max:50'],
            'dam_tag' => ['nullable', 'string', 'max:50'],
            'castrated' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, list<mixed>> */
    private function treatmentRules(): array
    {
        return [
            'product' => ['nullable', 'string', 'max:100'],
            'dose' => ['nullable', 'string', 'max:50'],
            'withdrawal_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
        ];
    }

    /** @return array<string, list<mixed>> */
    private function batchRules(): array
    {
        return [
            'tags' => ['present', 'array'],
            'tags.*' => ['string'],
            'event_date' => ['required', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:1000'],
            'client_uuid' => ['nullable', 'string', 'max:60'],
        ];
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return list<string>
     */
    private static function tags(array $fields): array
    {
        $tags = array_values(array_filter((array) $fields['tags'], fn ($t) => Tags::normalise((string) $t) !== null));
        if ($tags === []) {
            throw new HerdException(__('kudde::ui.needs_a_tag'), 400);
        }

        return array_map('strval', $tags);
    }

    private function mayWrite(Request $request): void
    {
        $user = $request->user();
        abort_if(! $user instanceof User || $user->role === User::VIEWER, 403);
    }

    /**
     * Alive animals per camp, keyed by the folded name so a count finds its camp whatever was typed.
     *
     * @return array<string, int>
     */
    private function aliveCounts(): array
    {
        $where = HerdState::currentLocations();
        $counts = [];
        foreach (Animal::query()->where('status', Animal::ALIVE)->pluck('id') as $id) {
            if (($where[$id] ?? '') !== '') {
                $key = CampNames::fold($where[$id]);
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<string, mixed>
     */
    private function presentCamp(Camp $camp, array $counts): array
    {
        return $camp->toArray() + ['alive_count' => $counts[CampNames::fold($camp->name)] ?? 0];
    }

    /**
     * UTF-8 with a byte-order mark, because that is what makes Excel on a Windows PC read Afrikaans camp names
     * correctly; every other reader ignores it. Commas, which every tool the vet and accountant use expects.
     *
     * @param  list<string>  $header
     * @param  iterable<array<int, mixed>>  $rows
     */
    private function csv(string $name, array $header, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\u{FEFF}");
            fputcsv($out, $header, ',', '"', '', "\r\n");
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($v) => $v ?? '', $row), ',', '"', '', "\r\n");
            }
            fclose($out);
        }, $name.'-'.today()->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=utf-8']);
    }
}
