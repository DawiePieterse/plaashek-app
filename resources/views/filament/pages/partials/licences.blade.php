@php
    /** @var list<array{name: string, status: string, since: string|null, grace_until: string|null, writable: bool}> $licences */
@endphp
@foreach ($licences as $row)
    <div class="plaashek-list-row">
        <span><strong>{{ $row['name'] }}</strong> {{ $row['status'] }}
            @unless ($row['writable']) <span class="plaashek-muted">· {{ __('plaashek.licences.read_only') }}</span> @endunless
        </span>
        <span class="plaashek-muted">
            @if ($row['since']) {{ __('plaashek.licences.since', ['date' => $row['since']]) }} @endif
            @if ($row['grace_until']) · {{ __('plaashek.licences.grace_until', ['date' => $row['grace_until']]) }} @endif
        </span>
    </div>
@endforeach
