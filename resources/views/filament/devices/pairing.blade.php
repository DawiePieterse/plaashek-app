@php
    /** @var string $code @var string $url @var string $qr @var int $minutes */
@endphp
<div style="display: flex; flex-direction: column; align-items: center; gap: 1rem; text-align: center">
    <p>{{ __('plaashek.devices.pair_hint') }}</p>
    <div style="width: 14rem; height: 14rem; background: #fff; padding: .5rem; border-radius: .5rem">{!! $qr !!}</div>
    <div>
        <div class="plaashek-muted">{{ __('plaashek.devices.or_type') }}</div>
        <div data-pairing-code style="font-family: ui-monospace, monospace; font-size: 2rem; font-weight: 800; letter-spacing: .25rem">{{ substr($code, 0, 4) }} {{ substr($code, 4) }}</div>
        <div class="plaashek-muted">{{ url("/field") }}</div>
    </div>
    <p class="plaashek-muted">{{ __('plaashek.devices.expires', ['minutes' => $minutes]) }}</p>
</div>
