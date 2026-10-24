<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{{ __('plaashek.workers.cards') }} · {{ $farm }}</title>
<style>
  body { font-family: Arial, sans-serif; margin: 0; background: #eee; }
  .toolbar { padding: 16px; text-align: center; }
  .toolbar button { padding: 10px 24px; font-size: 16px; cursor: pointer; border: 1px solid #333; border-radius: 8px; background: #f0f0f0; }
  .toolbar .hint { margin-top: 8px; font-size: 13px; color: #555; }
  /* Plain flow layout, not flex or grid, so the print engine paginates cards across A4 sheets: flex and grid
     containers are not fragmentation-aware and can cut a row of cards across a page break. */
  .sheet { padding: 2mm; font-size: 0; }
  /* Boord's card, so it fits the holders the farm already has: 58 x 78 mm portrait, 1 mm inside the holder on
     every edge. Every size is in mm and adds up on purpose (budget at .card-qr); a browser's px is 1/96 in only
     in theory, and the point is that the print comes out the size it says. */
  .card {
    display: inline-block; vertical-align: top;
    width: 58mm; height: 78mm; box-sizing: border-box; margin: 1mm; padding: 2.5mm;
    border: 1px dashed #999; border-radius: 2mm; background: white; overflow: hidden;
    page-break-inside: avoid; break-inside: avoid; font-size: 16px;
  }
  .card-inner { display: flex; flex-direction: column; align-items: center; height: 100%; }
  .card-farm { flex: none; font-size: 8pt; color: #666; text-align: center; line-height: 1.1; height: 3.5mm; }
  /* Photo and name side by side in a short strip, leaving the height to the QR: the part used every day, in
     sunlight, at arm's length. */
  .card-head { flex: none; display: flex; align-items: center; width: 100%; height: 17mm; margin-top: 1mm; }
  .card-photo { width: 17mm; height: 17mm; flex: none; border-radius: 1.5mm; overflow: hidden; background: #ddd;
    display: flex; align-items: center; justify-content: center; color: #999; font-size: 22px; }
  .card-photo img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .card-id { flex: 1; min-width: 0; padding-left: 2mm; overflow: hidden; }
  /* Two lines of a long name at most, so the number never leaves the strip. */
  .card-name { font-size: 8.5pt; font-weight: bold; line-height: 1.15; max-height: 7mm; overflow: hidden; }
  .card-number { font-size: 16pt; font-weight: bold; letter-spacing: 1px; color: #111; line-height: 1.1;
    margin-top: 0.5mm; white-space: nowrap; overflow: hidden; }
  /* Pinned to the bottom so every card has its QR in the same place. Height inside the padding (73 mm):
     3.5 farm + 1 + 17 head + 48 QR = 69.5 mm. */
  .card-qr { flex: none; margin-top: auto; }
  .card-qr svg { display: block; width: 48mm; height: 48mm; }
  .card-qr svg path { fill: #000; }
  .card.inactive .card-number::after { content: " ✕"; color: #b00; }
  .empty { padding: 24px; text-align: center; color: #666; font-size: 16px; }
  /* 3 across (180 mm) and 3 down (240 mm) inside these margins: nine to an A4 page. */
  @page { size: A4; margin: 8mm; }
  @media print {
    .no-print { display: none; }
    body { background: white; }
    .sheet { padding: 0; }
  }
</style>
</head>
<body>
<div class="toolbar no-print">
  <button type="button" onclick="window.print()">{{ __('plaashek.workers.print') }}</button>
  <div class="hint">{!! nl2br(e(__('plaashek.workers.print_hint'))) !!}</div>
</div>
<div class="sheet">
  @forelse ($workers as $worker)
    <div class="card {{ $worker->active ? '' : 'inactive' }}" data-number="{{ $worker->number }}"><div class="card-inner">
      <div class="card-farm">{{ $farm }}</div>
      <div class="card-head">
        <div class="card-photo">
          @if ($photo = $worker->photoDataUri())
            <img src="{{ $photo }}" alt="">
          @else
            &#9787;
          @endif
        </div>
        <div class="card-id">
          <div class="card-name">{{ $worker->displayName() }}</div>
          <div class="card-number">#{{ $worker->number }}</div>
        </div>
      </div>
      <div class="card-qr">{!! \App\Support\WorkerCard::qrSvg($worker->number) !!}</div>
    </div></div>
  @empty
    <div class="empty">{{ __('plaashek.workers.none_chosen') }}</div>
  @endforelse
</div>
</body>
</html>
