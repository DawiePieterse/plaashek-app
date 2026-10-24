@php($asset = fn (string $path) => asset('modules/weer/'.$path).'?v='.$version)
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{{ __('weer::ui.title') }} · {{ $page['farm']['name'] }}</title>
<link rel="manifest" href="{{ route('weer.manifest', absolute: false) }}">
<link rel="icon" href="{{ $asset('icons/icon-192.png') }}">
<link rel="apple-touch-icon" href="{{ $asset('icons/icon-180.png') }}">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="{{ __('weer::ui.title') }}">
<meta name="theme-color" content="#2f6b3a">
<script src="{{ $asset('vendor/tailwind.js') }}"></script>
<link rel="stylesheet" href="{{ $asset('vendor/fontawesome/css/all.min.css') }}">
<link rel="stylesheet" href="{{ $asset('css/styles.css') }}">
<script src="{{ $asset('vendor/html2canvas/html2canvas.min.js') }}"></script>
<script src="{{ $asset('vendor/jspdf/jspdf.umd.min.js') }}"></script>
<script>window.PLAASHEK = @json($page);</script>
<script type="module" src="{{ $asset('js/app.js') }}"></script>
</head>
<body class="min-h-screen pb-8">

<header class="app-header px-4 py-3">
  <div class="max-w-7xl mx-auto flex items-start justify-between gap-3">
    <div>
      <div class="font-bold text-2xl leading-tight"><i class="fa-solid fa-cloud-sun"></i> {{ __('weer::ui.title') }}</div>
      <div class="text-sm text-white/80 mt-0.5">{{ $page['farm']['name'] }} · <span id="headerDate">&nbsp;</span></div>
    </div>
    {{-- Centred on one line: the weather text and the three buttons are different heights, and top-aligned the
         text sat above the icons. The buttons get equal boxes, big enough for a thumb. --}}
    <div class="flex items-center gap-1 h-9">
      <div id="headerWeather" class="text-right text-sm font-semibold text-white/90 leading-none mr-1"></div>
      <button id="refreshBtn" class="header-btn" title="{{ __('weer::ui.refresh') }}"><i class="fa-solid fa-arrows-rotate"></i></button>
      <button id="settingsBtn" class="header-btn" title="{{ __('weer::ui.settings') }}"><i class="fa-solid fa-gear"></i></button>
      <a href="/admin" class="header-btn" title="{{ __('weer::ui.back') }}"><i class="fa-solid fa-house"></i></a>
    </div>
  </div>
</header>
<div id="offlineBanner" class="offline-banner hidden"><i class="fa-solid fa-wifi"></i> {{ __('weer::ui.offline') }}</div>

<main class="p-4 max-w-7xl mx-auto space-y-4">

  <!-- The farm: forecast, rain, frost -->
  <section class="card" id="farmCard">
    <div id="farmBody"><div class="text-sm text-slate-500"><i class="fa-solid fa-spinner fa-spin"></i> {{ __('weer::ui.loading') }}</div></div>
  </section>

  <!-- Chart -->
  <section class="card">
    <div class="flex items-center justify-between flex-wrap gap-2 mb-1">
      <div class="font-semibold">{{ __('weer::ui.chart_title') }}</div>
      <button id="pdfBtn" class="text-slate-400 hover:text-slate-600" title="{{ __('weer::ui.pdf') }}"><i class="fa-solid fa-file-pdf"></i></button>
    </div>
    <div id="loadStatus" class="text-xs text-slate-500 mb-3 min-h-[1rem]"></div>

    <div class="mb-3 pb-3 border-b space-y-2">
      <div id="fieldSlots" class="space-y-2"></div>
      <div class="text-xs text-slate-500">{{ __('weer::ui.measurements_hint') }}</div>
    </div>

    <div class="flex flex-wrap items-center gap-x-5 gap-y-2 mb-3">
      <label class="inline-flex items-center gap-1.5 text-sm"><input type="checkbox" id="forecastCb"> {{ __('weer::ui.show_forecast') }}</label>
      <label id="climateModelWrap" class="hidden text-sm">{{ __('weer::ui.climate_model') }}
        <select id="climateModel" class="border border-slate-300 rounded p-1 text-sm ml-1"></select>
      </label>
    </div>

    <div class="mb-3 pb-3 border-b">
      <div class="flex items-center gap-2 flex-wrap mb-2">
        <span class="text-sm font-medium">{{ __('weer::ui.years') }}</span>
        <button class="btn !py-0.5 !text-xs" data-years="this">{{ __('weer::ui.this_year') }}</button>
        <button class="btn !py-0.5 !text-xs" data-years="last">{{ __('weer::ui.last_year') }}</button>
        <button class="btn !py-0.5 !text-xs" data-years="last5">{{ __('weer::ui.last_5') }}</button>
        <button class="btn !py-0.5 !text-xs" data-years="clear">{{ __('weer::ui.clear') }}</button>
        <button id="yearsToggle" class="text-xs text-slate-500 hover:text-slate-700 ml-auto"></button>
      </div>
      <div id="yearPicker" class="space-y-1"></div>
    </div>

    <div id="warnings" class="space-y-1 mb-2"></div>

    <div id="chartExport">
      <div id="chartSubtitle" class="text-xs text-slate-500 mb-1"></div>
      <div id="chart" class="overflow-x-auto"></div>
      <div id="legend" class="mt-2"></div>
    </div>
  </section>

  <!-- Summary -->
  <section class="card space-y-3">
    <div>
      <div class="font-semibold mb-1">{{ __('weer::ui.summary') }}</div>
      <div class="text-xs text-slate-500 mb-2">{{ __('weer::ui.summary_hint') }}</div>
      <div id="summary" class="overflow-x-auto text-sm"></div>
    </div>
    <div id="quickRead"></div>
  </section>

  <footer class="text-xs text-slate-500 text-center space-y-1">
    <div><a class="underline" href="https://open-meteo.com/" target="_blank" rel="noopener">{{ __('weer::ui.data_credit') }}</a></div>
    <div id="appVersion"></div>
  </footer>
</main>

<!-- Field picker -->
<dialog id="fieldDialog">
  <div class="p-4 space-y-3">
    <div class="flex items-center justify-between">
      <div class="font-semibold" id="fieldDialogTitle"></div>
      <button class="text-slate-400 hover:text-slate-600" data-close><i class="fa-solid fa-xmark"></i></button>
    </div>
    <input id="fieldSearch" type="search" placeholder="{{ __('weer::ui.search_fields') }}" class="w-full border border-slate-300 rounded-lg p-2 text-sm">
    <div id="sourceChips" class="flex flex-wrap gap-1"></div>
    <div id="fieldList" class="max-h-[55vh] overflow-y-auto -mx-1 px-1"></div>
  </div>
</dialog>

<!-- Settings -->
<dialog id="settingsDialog">
  <div class="p-4 space-y-4">
    <div class="flex items-center justify-between">
      <div class="font-semibold">{{ __('weer::ui.units') }}</div>
      <button class="text-slate-400 hover:text-slate-600" data-close><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm">
      <label>{{ __('weer::ui.temperature') }}
        <select id="unitTemp" class="block w-full border border-slate-300 rounded p-1.5 mt-1">
          <option value="celsius">°C</option><option value="fahrenheit">°F</option>
        </select></label>
      <label>{{ __('weer::ui.wind_speed') }}
        <select id="unitWind" class="block w-full border border-slate-300 rounded p-1.5 mt-1">
          <option value="kmh">km/h</option><option value="ms">m/s</option><option value="mph">mph</option><option value="kn">kn</option>
        </select></label>
      <label>{{ __('weer::ui.precipitation') }}
        <select id="unitPrecip" class="block w-full border border-slate-300 rounded p-1.5 mt-1">
          <option value="mm">mm</option><option value="inch">in</option>
        </select></label>
    </div>
    <div class="border-t pt-3 text-sm space-y-2">
      <div>{{ __('weer::ui.cache_hint') }}</div>
      <div class="flex items-center gap-3"><button id="clearCacheBtn" class="btn"><i class="fa-solid fa-trash"></i> {{ __('weer::ui.clear_cache') }}</button>
        <span id="cacheInfo" class="text-xs text-slate-500"></span></div>
    </div>
  </div>
</dialog>

</body>
</html>
