{{-- The farm's name in the top bar on every office page, then what each module adds there (Weather: now). --}}
<div class="plaashek-topbar-farm">
    <a href="{{ filament()->getHomeUrl() }}">{{ $farm }}</a>
    @foreach ($views as $view)
        @include($view)
    @endforeach
</div>
