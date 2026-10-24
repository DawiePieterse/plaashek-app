<style>
    /* Home tiles and the top bar's farm, weather and version. Plain CSS: the panel's Tailwind build only has
       the classes Filament itself uses. */
    .plaashek-tiles { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; }
    @media (min-width: 48rem) { .plaashek-tiles { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
    .plaashek-tile { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: .35rem;
        aspect-ratio: 1 / 1; padding: 1rem; border-radius: 1rem; text-align: center;
        background: var(--color-white, #fff); border: 1px solid rgb(0 0 0 / .08); box-shadow: 0 1px 2px rgb(0 0 0 / .05);
        transition: transform .1s ease, box-shadow .1s ease; }
    .plaashek-tile:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgb(0 0 0 / .08); }
    .dark .plaashek-tile { background: rgb(255 255 255 / .05); border-color: rgb(255 255 255 / .1); }
    .plaashek-tile-label { font-weight: 700; font-size: 1.125rem; }
    .plaashek-tile-icon { font-size: 2.75rem; line-height: 1; }
    .plaashek-tile-value { font-weight: 800; font-size: 1.75rem; line-height: 1.1; }
    .plaashek-tile-caption { font-size: .875rem; opacity: .7; }
    /* The farm and its weather sit right after the menu button at every width. Filament's own top-bar logo
       area shows only on wide screens (and repeated the farm's name there), so it is hidden. */
    .fi-topbar > .fi-topbar-open-sidebar-btn, .fi-topbar > .fi-topbar-close-sidebar-btn { order: -1; }
    .fi-topbar > .fi-topbar-start { display: none !important; }
    .plaashek-topbar-farm { display: flex; align-items: center; gap: .75rem; min-width: 0; margin-inline: .5rem auto; }
    .plaashek-topbar-farm > a:first-child { font-weight: 700; font-size: 1.125rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .plaashek-topbar-weather { display: inline-flex; align-items: center; gap: .35rem; white-space: nowrap; font-size: .875rem; }
    .plaashek-topbar-weather-word { opacity: .8; }
    @media (max-width: 40rem) { .plaashek-topbar-weather-word { display: none; } }
    .plaashek-status { display: flex; align-items: center; gap: .4rem; margin-inline-end: .5rem; }
    .plaashek-pill { display: inline-flex; align-items: center; gap: .35rem; padding: .15rem .55rem; border-radius: 999px;
        font-size: .75rem; font-weight: 600; white-space: nowrap; line-height: 1.4; }
    .plaashek-pill-dot { width: .5rem; height: .5rem; border-radius: 999px; background: currentColor; }
    .plaashek-pill-on { color: #15803d; background: rgb(22 163 74 / .12); }
    .plaashek-pill-off { color: #b91c1c; background: rgb(220 38 38 / .14); }
    .dark .plaashek-pill-on { color: #4ade80; }
    .dark .plaashek-pill-off { color: #f87171; }
    .plaashek-pill-version { background: rgb(127 127 127 / .14); font-weight: 500; font-variant-numeric: tabular-nums; }
    /* A phone keeps the dot and the version; the word goes, the colour says it. */
    @media (max-width: 40rem) { .plaashek-pill-text { display: none; } .plaashek-pill { padding: .2rem .45rem; } }
    .plaashek-two-col { display: grid; grid-template-columns: minmax(0, 1fr); gap: 1.5rem; }
    @media (min-width: 64rem) { .plaashek-two-col { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    .plaashek-list-row { display: flex; justify-content: space-between; gap: .75rem; padding: .4rem 0; font-size: .875rem;
        border-bottom: 1px solid rgb(127 127 127 / .15); }
    .plaashek-list-row:last-child { border-bottom: 0; }
    .plaashek-muted { opacity: .65; }
    /* With FarmChart's maintainAspectRatio off, the chart fills this: one height for every chart card. */
    .fi-wi-chart-canvas-ctn { position: relative; height: 18rem; }
</style>
