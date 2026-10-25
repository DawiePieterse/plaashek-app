@php
    /** @var \Illuminate\Support\Collection<int, \App\Modules\Kudde\Models\AnimalEvent> $events */
@endphp
@forelse ($events as $e)
    <div class="plaashek-list-row">
        <span>
            <strong>{{ __('kudde::ui.kind.'.$e->kind) }}</strong>
            @if ($e->value !== null) · {{ \Illuminate\Support\Number::format($e->value, 1, locale: app()->getLocale()) }} kg @endif
            @if ($e->location !== '') · {{ $e->location }} @endif
            @if ($e->product !== '') · {{ $e->product }} {{ $e->dose }} @endif
            @if ($e->note !== '') <span class="plaashek-muted">· {{ $e->note }}</span> @endif
        </span>
        <span class="plaashek-muted" style="white-space: nowrap">
            {{ $e->event_date->toDateString() }}
            @if ($who = $e->worker?->displayName() ?? $e->user?->name) · {{ $who }} @endif
        </span>
    </div>
@empty
    <p class="plaashek-muted">{{ __('kudde::ui.animals.no_history') }}</p>
@endforelse
