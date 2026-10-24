{{-- Big squares, two across on a phone and three on a tablet, as the owner sketched them: name, a symbol and
     the one number, the whole tile a tap target for a thumb. --}}
<x-filament-widgets::widget>
    <div class="plaashek-tiles">
        @foreach ($this->tiles() as $tile)
            <a href="{{ $tile->url }}" class="plaashek-tile" data-tile="{{ $tile->label }}">
                <div class="plaashek-tile-label">{{ $tile->label }}</div>
                <div class="plaashek-tile-icon" aria-hidden="true">{{ $tile->icon }}</div>
                <div class="plaashek-tile-value">{{ $tile->value }}</div>
                <div class="plaashek-tile-caption">{{ $tile->caption }}</div>
            </a>
        @endforeach
    </div>
</x-filament-widgets::widget>
