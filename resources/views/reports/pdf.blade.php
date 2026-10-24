<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="UTF-8">
<style>
    /* DejaVu Sans: Dompdf's font with Afrikaans' ê, ë and ô. Small type, so a herd fits on few pages. */
    body { font-family: "DejaVu Sans", sans-serif; font-size: 8.5pt; color: #111; margin: 0; }
    .head { border-bottom: 2px solid #2f6b3a; padding-bottom: 6px; margin-bottom: 10px; }
    .farm { font-size: 9pt; color: #2f6b3a; font-weight: bold; }
    h1 { font-size: 14pt; margin: 2px 0; }
    .meta { color: #555; font-size: 8pt; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #eef3ee; text-align: left; font-weight: bold; padding: 4px 5px; border-bottom: 1px solid #9bb59f; }
    td { padding: 3px 5px; border-bottom: 1px solid #e3e3e3; vertical-align: top; }
    tr:nth-child(even) td { background: #fafafa; }
    td.num { text-align: right; }
    .empty { padding: 20px; text-align: center; color: #777; }
    .foot { margin-top: 8px; color: #777; font-size: 7.5pt; }
</style>
</head>
<body>
<div class="head">
    <div class="farm">{{ $farm }}</div>
    <h1>{{ $title }}</h1>
    <div class="meta">
        @if ($range){{ $range }} · @endif{{ __('plaashek.reports.generated', ['when' => $generated]) }}
    </div>
</div>
@if ($rows === [])
    <div class="empty">{{ __('plaashek.reports.no_rows') }}</div>
@else
    <table>
        <thead><tr>@foreach ($columns as $label)<th>{{ $label }}</th>@endforeach</tr></thead>
        <tbody>
        @foreach ($rows as $row)
            <tr>@foreach ($columns as $key => $label)
                @php($v = $row[$key] ?? null)
                <td @class(['num' => is_int($v) || is_float($v)])>{{ is_float($v) ? number_format($v, 1, app()->getLocale() === 'af' ? ',' : '.', ' ') : $v }}</td>
            @endforeach</tr>
        @endforeach
        </tbody>
    </table>
    <div class="foot">{{ trans_choice('plaashek.reports.row_count', count($rows), ['count' => count($rows)]) }}</div>
@endif
</body>
</html>
