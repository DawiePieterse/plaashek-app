@php($lists = $this->lists())
<x-filament-widgets::widget>
    <div class="plaashek-two-col">
        <x-filament::section :heading="__('kudde::ui.lists.withdrawal')">
            @forelse ($lists['withdrawal'] as $row)
                <div class="plaashek-list-row">
                    <span><strong>{{ $row['tag'] }}</strong> {{ $row['name'] }}</span>
                    <span class="plaashek-muted">{{ __('kudde::ui.lists.until', ['date' => $row['until']]) }}</span>
                </div>
            @empty
                <div class="plaashek-muted">{{ __('kudde::ui.lists.none_in_withdrawal') }}</div>
            @endforelse
        </x-filament::section>
        <x-filament::section :heading="__('kudde::ui.lists.recent')">
            @forelse ($lists['recent'] as $row)
                <div class="plaashek-list-row">
                    <span><span class="plaashek-muted">{{ $row['date'] }}</span> <strong>{{ $row['tag'] }}</strong> {{ $row['kind'] }}
                        @if ($row['detail'])<span class="plaashek-muted">· {{ $row['detail'] }}</span>@endif</span>
                    <span class="plaashek-muted">{{ $row['by'] }}</span>
                </div>
            @empty
                <div class="plaashek-muted">{{ __('kudde::ui.lists.nothing_yet') }}</div>
            @endforelse
        </x-filament::section>
    </div>
</x-filament-widgets::widget>
