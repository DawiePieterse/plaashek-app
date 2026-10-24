<?php

namespace App\Modules\Kudde\Filament\Widgets;

use App\Modules\Kudde\Models\Animal;
use App\Modules\Kudde\Models\AnimalEvent;
use App\Modules\Kudde\Support\HerdState;
use App\Modules\Kudde\Support\Tags;
use Filament\Widgets\Widget;

/**
 * Two short lists beside the charts: who must not go to the sale yet (in withdrawal, soonest clear first), and
 * the last things recorded, with who recorded them.
 */
class HerdWatchList extends Widget
{
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'kudde::widgets.watch-list';

    /** @return array{withdrawal: list<array{tag: string, name: string, until: string}>, recent: list<array{date: string, tag: string|null, kind: string, detail: string, by: string|null}>} */
    public function lists(): array
    {
        $state = HerdState::load();
        $withdrawal = $state->alive()->filter(fn (Animal $a) => isset($state->withdrawal[$a->id]))
            ->sort(fn (Animal $a, Animal $b) => $state->withdrawal[$a->id]->getTimestamp() <=> $state->withdrawal[$b->id]->getTimestamp() ?: Tags::compare($a->tag, $b->tag))
            ->take(12)
            ->map(fn (Animal $a) => ['tag' => $a->tag, 'name' => $a->name, 'until' => $state->withdrawal[$a->id]->translatedFormat('j M')])
            ->values()->all();

        $recent = AnimalEvent::query()->with(['animal', 'worker', 'user'])->orderByDesc('event_date')->orderByDesc('id')->limit(12)->get()
            ->map(fn (AnimalEvent $e) => [
                'date' => $e->event_date->translatedFormat('j M'),
                'tag' => $e->animal?->tag,
                'kind' => __('kudde::ui.kind.'.$e->kind),
                'detail' => trim(implode(' · ', array_filter([
                    $e->value !== null ? $e->value.' kg' : null, $e->product ?: null, $e->location ?: null,
                ]))),
                'by' => $e->worker?->displayName() ?? $e->user?->name,
            ])->all();

        return ['withdrawal' => $withdrawal, 'recent' => $recent];
    }
}
