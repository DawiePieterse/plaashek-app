@php
    use App\Modules\Kudde\Filament\Resources\Animals\AnimalResource;
    /** @var array<string, mixed> $tree */
    // The classic pedigree: parents in the first column spanning four rows each, grandparents two, great-grandparents one.
    $cells = [];
    $place = function (?array $node, string $role, int $gen, int $row, int $span) use (&$place, &$cells) {
        $cells[] = compact('node', 'role', 'gen', 'row', 'span');
        if ($gen < 3) {
            $place($node['dam'] ?? null, 'dam', $gen + 1, $row, intdiv($span, 2));
            $place($node['sire'] ?? null, 'sire', $gen + 1, $row + intdiv($span, 2), intdiv($span, 2));
        }
    };
    $place($tree['dam'], 'dam', 1, 1, 4);
    $place($tree['sire'], 'sire', 1, 5, 4);
    $gaps = array_values(array_filter(array_column($tree['births'], 'interval_days'), fn ($d) => $d !== null));
@endphp

<div class="plaashek-family">
    @if ($tree['dam'] || $tree['sire'])
        <div class="plaashek-pedigree">
            @foreach ($cells as $c)
                <div style="grid-column: {{ $c['gen'] }}; grid-row: {{ $c['row'] }} / span {{ $c['span'] }}">
                    @if ($c['node'] === null)
                        <div class="plaashek-ped plaashek-ped-empty">{{ __('kudde::ui.columns.'.$c['role']) }}</div>
                    @elseif ($c['node']['on_record'])
                        <a class="plaashek-ped" href="{{ AnimalResource::getUrl('view', ['record' => $c['node']['id']]) }}">
                            <strong>{{ $c['node']['tag'] }}</strong>
                            <span>{{ __('kudde::ui.columns.'.$c['role']) }} · {{ ucfirst($c['node']['class']) }}</span>
                        </a>
                    @else
                        <div class="plaashek-ped plaashek-ped-off">
                            <strong>{{ $c['node']['tag'] }}</strong>
                            <span>{{ __('kudde::ui.columns.'.$c['role']) }} · {{ __('kudde::ui.animals.not_on_record') }}</span>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @else
        <p class="plaashek-muted">{{ __('kudde::ui.animals.no_parents') }}</p>
    @endif

    @if ($tree['births'] !== [])
        <h4 class="plaashek-family-h">
            {{ __('kudde::ui.animals.births') }}
            @if ($gaps !== [])
                · {{ __('kudde::ui.animals.average_interval', ['days' => (int) round(array_sum($gaps) / count($gaps))]) }}
            @endif
        </h4>
        @foreach ($tree['births'] as $birth)
            <div class="plaashek-list-row">
                <span>
                    {{ $birth['date'] }}
                    <span class="plaashek-muted">
                        {{ implode(', ', $birth['young']) }}
                        @if ($birth['litter'])
                            {{ $birth['young'] ? '·' : '' }}
                            {{ __('kudde::ui.animals.litter', ['alive' => $birth['litter']['born_alive']]) }}
                            @if ($birth['litter']['stillborn'] > 0)
                                ({{ __('kudde::ui.animals.stillborn', ['count' => $birth['litter']['stillborn']]) }})
                            @endif
                            @if ($birth['litter']['weaned'] !== null)
                                · {{ __('kudde::ui.animals.weaned', ['count' => $birth['litter']['weaned']]) }}
                            @endif
                        @endif
                    </span>
                </span>
                <span class="plaashek-muted">{{ $birth['interval_days'] !== null ? __('kudde::ui.animals.days', ['days' => $birth['interval_days']]) : '' }}</span>
            </div>
        @endforeach
    @endif

    @if ($tree['offspring'] !== [])
        <h4 class="plaashek-family-h">{{ __('kudde::ui.animals.offspring') }} ({{ count($tree['offspring']) }})</h4>
        @foreach ($tree['offspring'] as $young)
            <div class="plaashek-list-row">
                <a href="{{ AnimalResource::getUrl('view', ['record' => $young['id']]) }}" class="plaashek-link">
                    <strong>{{ $young['tag'] }}</strong> {{ $young['name'] }}
                </a>
                <span class="plaashek-muted">
                    {{ ucfirst($young['class']) }} · {{ $young['birth_date'] ?? '-' }}
                    @if ($young['last_weight'] !== null) · {{ \Illuminate\Support\Number::format($young['last_weight'], 1, locale: app()->getLocale()) }} kg @endif
                    @if ($young['status'] !== 'alive') · {{ __('kudde::ui.status.'.$young['status']) }} @endif
                </span>
            </div>
        @endforeach
    @endif
</div>
