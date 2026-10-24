{{-- Fills latitude and longitude from the phone's own position. Needs HTTPS (every farm has it) and the
     visitor's permission; a refusal or a PC without a GPS just leaves the boxes as they were. The logic sits
     in x-data on a plain element, where Blade compiles @js; a component's attributes are not compiled. --}}
<div class="flex flex-wrap items-center gap-3"
     x-data="{
        busy: false,
        error: '',
        locate() {
            this.error = '';
            if (! navigator.geolocation) { this.error = @js(__('plaashek.settings.location_unavailable')); return; }
            this.busy = true;
            navigator.geolocation.getCurrentPosition(
                (p) => {
                    $wire.set('data.latitude', p.coords.latitude.toFixed(4));
                    $wire.set('data.longitude', p.coords.longitude.toFixed(4));
                    this.busy = false;
                },
                () => { this.error = @js(__('plaashek.settings.location_refused')); this.busy = false; },
                { enableHighAccuracy: true, timeout: 20000 },
            );
        },
     }">
    <x-filament::button color="gray" icon="heroicon-o-map-pin" x-bind:disabled="busy" x-on:click="locate()">
        {{ __('plaashek.settings.use_my_location') }}
    </x-filament::button>
    <span class="text-sm text-gray-500" x-show="busy">{{ __('plaashek.settings.locating') }}</span>
    <span class="text-sm text-danger-600" x-show="error" x-text="error"></span>
</div>
