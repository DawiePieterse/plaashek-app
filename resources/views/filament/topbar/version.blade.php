{{-- Online or not, and which release this farm runs (what support asks first, and how a farmer can tell an update
     arrived). "Online" is the farm's server answering /up, checked every minute: a phone's radio can be on with
     nothing getting through, which is the usual case at the edge of the farm's signal. --}}
<div class="plaashek-status" data-up="{{ url('/up') }}" x-data="{
        on: navigator.onLine,
        check() { fetch(this.$el.dataset.up, { cache: 'no-store' }).then(r => { this.on = r.ok }).catch(() => { this.on = false }) },
    }"
    x-init="setInterval(() => check(), 60000)"
    x-on:online.window="check()" x-on:offline.window="on = false">
    <span class="plaashek-pill" x-bind:class="on ? 'plaashek-pill-on' : 'plaashek-pill-off'" role="status">
        <span class="plaashek-pill-dot"></span>
        <span class="plaashek-pill-text" x-text="on ? @js(__('plaashek.status.online')) : @js(__('plaashek.status.offline'))">{{ __('plaashek.status.online') }}</span>
    </span>
    <span class="plaashek-pill plaashek-pill-version" title="{{ __('plaashek.home.version') }}">{{ $version }}</span>
</div>
