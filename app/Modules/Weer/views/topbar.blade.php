{{-- The weather now, after the farm's name in the office's top bar. Fetched once the page is up, so a slow
     Open-Meteo never holds the office up; nothing at all is shown if there is no answer. --}}
<a href="{{ route('weer.page') }}" class="plaashek-topbar-weather" x-data="{ w: null }"
   data-now="{{ route('weer.now', absolute: false) }}"
   x-init="fetch($el.dataset.now, { headers: { Accept: 'application/json' } })
       .then(r => r.status === 200 ? r.json() : null).then(j => { w = j }).catch(() => {})"
   x-show="w" x-cloak>
    <span x-text="w && w.icon"></span>
    <span class="font-semibold" x-text="w && (w.temp + '°')"></span>
    <span class="plaashek-topbar-weather-word" x-text="w && w.text"></span>
</a>
