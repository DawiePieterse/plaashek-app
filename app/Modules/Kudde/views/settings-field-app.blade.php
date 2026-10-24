@php($url = route('kudde.field'))
{{-- Black on white whatever the office's theme: a phone camera reads a QR by its contrast. Inline styles,
     because the panel's Tailwind build has only Filament's own classes. --}}
<div style="display:flex;flex-wrap:wrap;align-items:center;gap:1rem">
    <div style="width:10rem;height:10rem;padding:.5rem;background:#fff;border-radius:.5rem;flex:none">
        <div style="color:#000">{!! str_replace('<svg ', '<svg style="width:100%;height:100%;display:block" fill="#000" ', \App\Support\Qr::svg($url)) !!}</div>
    </div>
    <a href="{{ $url }}" target="_blank" style="font-weight:600;text-decoration:underline;word-break:break-all">{{ $url }}</a>
</div>
