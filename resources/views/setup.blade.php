<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('plaashek.setup.title') }} · Plaashek</title>
    <style>
        :root { --green: #2f6b3a; }
        body { font-family: system-ui, sans-serif; margin: 0; background: #f4f6f3; color: #1f2a1f; }
        main { max-width: 32rem; margin: 0 auto; padding: 1.5rem 1rem 3rem; }
        h1 { color: var(--green); font-size: 1.5rem; }
        label { display: block; font-weight: 600; margin-top: 1rem; }
        input, select { box-sizing: border-box; width: 100%; padding: .6rem; margin-top: .3rem; font-size: 1rem;
            border: 1px solid #b8c2b5; border-radius: .5rem; background: #fff; }
        .row { display: flex; gap: .75rem; } .row > div { flex: 1; }
        .hint { font-size: .85rem; color: #5a665a; margin-top: .25rem; }
        .error { color: #b42318; font-size: .9rem; margin-top: .25rem; }
        button { margin-top: 1.5rem; width: 100%; padding: .8rem; font-size: 1rem; font-weight: 600; color: #fff;
            background: var(--green); border: 0; border-radius: .5rem; }
        fieldset { border: 0; padding: 0; margin: 1.5rem 0 0; } legend { font-weight: 700; font-size: 1.1rem; }
    </style>
</head>
<body>
<main>
    <h1>{{ __('plaashek.setup.title') }}</h1>
    <p>{{ __('plaashek.setup.intro') }}</p>

    <form method="post" action="{{ route('setup.store') }}">
        @csrf
        <fieldset>
            <legend>{{ __('plaashek.setup.farm') }}</legend>
            <label for="name">{{ __('plaashek.settings.name') }}</label>
            <input id="name" name="name" value="{{ old('name') }}" required maxlength="100">
            @error('name')<div class="error">{{ $message }}</div>@enderror

            <label for="language">{{ __('plaashek.settings.language') }}</label>
            <select id="language" name="language">
                <option value="af" @selected(old('language', 'af') === 'af')>Afrikaans</option>
                <option value="en" @selected(old('language') === 'en')>English</option>
            </select>

            <div class="row">
                <div>
                    <label for="latitude">{{ __('plaashek.settings.latitude') }}</label>
                    <input id="latitude" name="latitude" value="{{ old('latitude') }}" inputmode="decimal">
                </div>
                <div>
                    <label for="longitude">{{ __('plaashek.settings.longitude') }}</label>
                    <input id="longitude" name="longitude" value="{{ old('longitude') }}" inputmode="decimal">
                </div>
            </div>
            <div class="hint">{{ __('plaashek.settings.position_hint') }}</div>
            @error('latitude')<div class="error">{{ $message }}</div>@enderror
            @error('longitude')<div class="error">{{ $message }}</div>@enderror
        </fieldset>

        <fieldset>
            <legend>{{ __('plaashek.setup.owner') }}</legend>
            <label for="owner_name">{{ __('plaashek.setup.owner_name') }}</label>
            <input id="owner_name" name="owner_name" value="{{ old('owner_name') }}" required maxlength="100">
            @error('owner_name')<div class="error">{{ $message }}</div>@enderror

            <label for="owner_email">{{ __('plaashek.setup.owner_email') }}</label>
            <input id="owner_email" name="owner_email" type="email" value="{{ old('owner_email') }}" required>
            @error('owner_email')<div class="error">{{ $message }}</div>@enderror

            <label for="owner_password">{{ __('plaashek.setup.password') }}</label>
            <input id="owner_password" name="owner_password" type="password" required minlength="8" autocomplete="new-password">
            @error('owner_password')<div class="error">{{ $message }}</div>@enderror

            <label for="owner_password_confirmation">{{ __('plaashek.setup.password_again') }}</label>
            <input id="owner_password_confirmation" name="owner_password_confirmation" type="password" required autocomplete="new-password">
        </fieldset>

        <button type="submit">{{ __('plaashek.setup.create') }}</button>
    </form>
</main>
</body>
</html>
