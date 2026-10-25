@php($asset = fn (string $path) => asset('modules/kudde/'.$path).'?v='.$version)
{{-- The Kudde field app, old Kudde's frontend/field/index.html. Added for Plaashek: the daily card scan
     (#signinScreen, js/signin.js) and the signed-in worker in the header; photos hidden until the photo API. --}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Kudde · {{ $page['farm'] }}</title>
<link rel="manifest" href="{{ route('kudde.field.manifest', absolute: false) }}">
<link rel="icon" href="{{ $asset('icons/icon-192.png') }}">
<link rel="apple-touch-icon" href="{{ $asset('icons/icon-180.png') }}">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="Kudde">
<meta name="theme-color" content="#0A2F6B">
<script>window.PLAASHEK = @json($page + ['version' => $version]);</script>
<script src="{{ $asset('vendor/tailwind.js') }}"></script>
<link rel="stylesheet" href="{{ $asset('vendor/fontawesome/css/all.min.css') }}">
<link rel="stylesheet" href="{{ $asset('css/styles.css') }}">
<script src="{{ $asset('js/api.js') }}"></script>
<script src="{{ $asset('js/ptr.js') }}"></script>
<script src="{{ $asset('js/camps.js') }}"></script>
<script src="{{ $asset('js/products.js') }}"></script>
<script src="{{ $asset('vendor/jsqr/jsQR.js') }}"></script>
<script src="{{ $asset('js/scan.js') }}"></script>
<script src="{{ $asset('js/species.js') }}"></script>
<script src="{{ $asset('js/idb.js') }}"></script>
<script src="{{ $asset('js/signin.js') }}"></script>
</head>
<body class="bg-slate-100 min-h-screen app-field">

<div class="kudde-header px-3 pb-3" style="--header-pad: 0.75rem">
  <div class="flex items-center justify-between">
    <div class="min-w-0">
      <div class="font-bold text-lg">Kudde</div>
      <div class="text-xs opacity-80 truncate">{{ $page['farm'] }}</div>
      {{-- Whose day it is. One scan in the morning; "Not you?" hands the phone over. --}}
      <div id="workerChip" class="hidden text-xs mt-1 flex items-center gap-2">
        <span class="font-semibold"><i class="fa-solid fa-id-card"></i> <span id="workerName"></span></span>
        <button id="notYouBtn" type="button" class="underline opacity-80"></button>
      </div>
    </div>
    <div class="text-right text-xs">
      <button id="syncStatus" type="button" class="px-2 py-1 rounded-full bg-white/20 text-white">checking...</button>
      <div id="appVersion" class="opacity-60 mt-1"></div>
    </div>
  </div>
</div>

<div class="p-3 max-w-md mx-auto space-y-3">

  <!-- Search / lookup -->
  <div class="bg-white rounded-xl p-3 shadow space-y-2">
    <div class="flex gap-2">
      <input id="searchInput" type="text" inputmode="search" placeholder="Search by tag or name"
             class="flex-1 min-w-0 border border-slate-300 rounded-lg p-3 text-lg">
      <!-- Read the tag off a QR or barcode instead of typing it. -->
      <button id="scanSearchBtn" type="button" class="btn-outline rounded-lg px-4 text-xl" aria-label="Scan a tag">
        <i class="fa-solid fa-qrcode"></i>
      </button>
    </div>
    <!-- Which animals the list below shows. Alive by default: a dead or
         sold animal is still in the herd book, but after a few seasons a
         list that keeps them all is half clutter, and the Field list is
         read standing next to the live ones. Typing in the search box
         searches every animal regardless, so a sold animal's tag still
         finds it. -->
    <!-- Which species: only on a farm keeping more than one (species.js). -->
    <div id="speciesChips" class="hidden flex rounded-lg bg-slate-100 p-1 text-sm font-semibold" role="group" aria-label="Species"></div>
    <div class="flex rounded-lg bg-slate-100 p-1 text-sm font-semibold" role="group" aria-label="Which animals to list">
      <button id="filterAliveBtn" type="button" class="seg-btn active flex-1">Alive</button>
      <button id="filterAllBtn" type="button" class="seg-btn flex-1">All</button>
    </div>
    <div class="grid grid-cols-2 gap-2">
      <button id="addAnimalBtn" class="kudde-btn-primary rounded-xl py-3 font-bold flex items-center justify-center gap-2">
        <i class="fa-solid fa-plus"></i> Add animal
      </button>
      <button id="moveCampBtn" class="rounded-xl py-3 font-bold flex items-center justify-center gap-2 bg-slate-800 text-white">
        <i class="fa-solid fa-arrows-turn-right"></i> Move camp
      </button>
    </div>
    <!-- Sessions: the crush-side way of working. One animal after another,
         tag and number, Save and next - instead of search, tap, Weight,
         type, Save, Close for each of forty head. -->
    <div class="grid grid-cols-2 gap-2">
      <button id="weighSessionBtn" class="btn-outline rounded-xl py-3 font-bold flex items-center justify-center gap-2">
        <i class="fa-solid fa-weight-scale event-icon-weight"></i> Weigh session
      </button>
      <button id="treatSessionBtn" class="btn-outline rounded-xl py-3 font-bold flex items-center justify-center gap-2">
        <i class="fa-solid fa-syringe event-icon-treatment"></i> Treat session
      </button>
    </div>
  </div>

  <!-- Herd summary -->
  <!-- Both counts come from the cached herd, not the server, so they are
       right offline and the moment something is recorded. "Need weighing"
       is a button: tapping it turns the list into that worklist. -->
  <div class="bg-white rounded-xl p-3 shadow flex justify-around text-center">
    <div>
      <div class="text-2xl font-bold" id="countAlive">0</div>
      <div class="text-xs text-slate-500">Alive</div>
    </div>
    <button id="needsWeighingBtn" type="button" class="count-btn rounded-lg px-3 -my-1 py-1">
      <div class="text-2xl font-bold" id="countNeedsWeighing">0</div>
      <div class="text-xs text-slate-500">Need weighing <i class="fa-solid fa-filter text-[10px]"></i></div>
    </button>
  </div>

  <!-- What this phone recorded today, to check before leaving the kraal.
       Kept on the device (localStorage), so it is there with no signal
       and includes what is still in the outbox. Hidden until there is
       something in it. -->
  <div id="todayCard" class="bg-white rounded-xl p-3 shadow hidden">
    <button id="todayToggle" type="button" class="w-full flex items-center justify-between">
      <span class="font-semibold"><i class="fa-solid fa-clock-rotate-left text-slate-400"></i> Today</span>
      <span class="text-sm text-slate-500"><span id="todayCount">0</span> recorded <i class="fa-solid fa-chevron-down text-xs" id="todayChevron"></i></span>
    </button>
    <div id="todayList" class="hidden mt-2 space-y-1 text-sm max-h-56 overflow-y-auto"></div>
  </div>

  <!-- Animal list -->
  <!-- Grouped by camp, one collapsible section each (see renderAnimalList):
       a herd is hundreds of head, and whoever is standing in a camp wants
       that camp's thirty, not a scroll through all of them. -->
  <div id="animalList" class="space-y-2"></div>
  <div id="emptyState" class="hidden text-center text-slate-400 text-sm py-6">No animals yet</div>
</div>

<!-- Every modal backdrop is a flex box with the panel on m-auto, NOT
     items-center/justify-center with my-6 on the panel. Centring with
     align-items clips the top of a panel taller than the viewport and
     nothing can scroll it back: with the keyboard up on a phone (about
     480px visible) the Add animal title and the required Tag box sat 60px
     above the top edge, unreachable. Auto margins centre a panel that fits
     and collapse to zero when it doesn't, so the top is always there. -->
<!-- Add animal modal -->
<div id="addAnimalModal" class="fixed inset-0 bg-black/50 hidden p-4 z-40 overflow-y-auto">
  <div class="bg-white rounded-xl p-5 w-full max-w-sm m-auto">
    <div class="font-bold text-lg mb-3">Add animal</div>
    <label class="text-xs text-slate-500">Tag number *</label>
    <input id="newTag" type="text" class="w-full border border-slate-300 rounded-lg p-3 text-lg mb-2" autocomplete="off">
    <label class="text-xs text-slate-500">Name</label>
    <input id="newName" type="text" class="w-full border border-slate-300 rounded-lg p-3 text-lg mb-2">
    <!-- Two buttons, no default: a dropdown that opened on Female recorded
         a bull as a cow whenever nobody looked. Steer is a tick under
         Male, since a castrated male is counted apart from a bull. -->
    <label class="text-xs text-slate-500">Sex *</label>
    <div class="flex rounded-lg bg-slate-100 p-1 text-sm font-semibold mb-2" role="group" aria-label="Sex">
      <button id="newFemaleBtn" type="button" class="seg-btn flex-1">Female</button>
      <button id="newMaleBtn" type="button" class="seg-btn flex-1">Male</button>
    </div>
    <label id="newCastratedRow" class="hidden flex items-center gap-2 text-base mb-2">
      <input id="newCastrated" type="checkbox" class="w-5 h-5"> Castrated (steer)
    </label>
    <label class="text-xs text-slate-500">Breed</label>
    <input id="newBreed" type="text" class="w-full border border-slate-300 rounded-lg p-3 text-lg mb-2">
    <label class="text-xs text-slate-500">Birth date</label>
    <input id="newBirthDate" type="date" class="w-full border border-slate-300 rounded-lg p-3 text-lg mb-2">
    <!-- Where it is now. Recorded as its first movement event, so the new
         animal is in a camp from the start and Move camp can see it. -->
    <label class="text-xs text-slate-500">Camp</label>
    <div id="newCampChips" class="chip-row"></div>
    <input id="newCamp" type="text" placeholder="Camp name" class="w-full border border-slate-300 rounded-lg p-3 text-lg mb-2" autocomplete="off">
    <div class="grid grid-cols-2 gap-2 mb-2">
      <div>
        <label class="text-xs text-slate-500">Sire tag</label>
        <input id="newSireTag" type="text" class="w-full border border-slate-300 rounded-lg p-3 text-lg">
      </div>
      <div>
        <label class="text-xs text-slate-500">Dam tag</label>
        <input id="newDamTag" type="text" class="w-full border border-slate-300 rounded-lg p-3 text-lg">
      </div>
    </div>
    <div class="flex gap-2 mt-3">
      <button id="cancelAddAnimalBtn" class="flex-1 rounded-lg py-3 bg-slate-100">Cancel</button>
      <button id="confirmAddAnimalBtn" class="flex-1 kudde-btn-primary rounded-lg py-3 font-semibold">Save</button>
    </div>
  </div>
</div>

<!-- Animal detail modal -->
<div id="animalDetailModal" class="fixed inset-0 bg-black/50 hidden p-4 z-40 overflow-y-auto">
  <div class="bg-white rounded-xl p-5 w-full max-w-sm m-auto">
    <div class="flex items-start justify-between mb-3 gap-2">
      <div class="min-w-0">
        <div class="font-bold text-xl" id="detailTag"></div>
        <div class="text-slate-500 text-sm" id="detailSub"></div>
      </div>
      <div class="flex items-center gap-2 shrink-0">
        <span id="detailStatusPill" class="text-xs font-semibold px-2 py-1 rounded-full"></span>
        <!-- Close lives here, where every sheet on a phone has it, not
             halfway down between the actions and the photos. -->
        <button id="closeDetailBtn" type="button" class="w-9 h-9 rounded-full bg-slate-100 flex items-center justify-center" aria-label="Close">
          <i class="fa-solid fa-xmark"></i>
        </button>
      </div>
    </div>

    <div class="grid grid-cols-3 gap-2 mb-2">
      <button class="action-btn" data-kind="weight"><i class="fa-solid fa-weight-scale event-icon-weight"></i>Weight</button>
      <button class="action-btn" data-kind="treatment"><i class="fa-solid fa-syringe event-icon-treatment"></i>Treatment</button>
      <button class="action-btn" data-kind="movement"><i class="fa-solid fa-arrows-turn-right event-icon-movement"></i>Move</button>
      <!-- On a cow this opens the calving screen (the birth event on the
           cow AND the new calf, in one go); hidden on a male. -->
      <button class="action-btn" id="calvedBtn" data-kind="birth"><i class="fa-solid fa-baby event-icon-birth"></i><span id="calvedLabel">Calved</span></button>
      <!-- A sow with a litter not yet weaned: tag the young now (weaning). -->
      <button class="action-btn hidden" id="weanBtn"><i class="fa-solid fa-tags event-icon-birth"></i>Wean</button>
      <!-- Outlined red: these two take the animal out of the live herd,
           and they sit a thumb-width from Move and Calved. -->
      <button class="action-btn action-btn-danger" data-kind="sale"><i class="fa-solid fa-hand-holding-dollar"></i>Sale</button>
      <button class="action-btn action-btn-danger" data-kind="death"><i class="fa-solid fa-skull"></i>Death</button>
    </div>

    {{-- Photos wait for Plaashek's photo API (phase 7); the markup stays so app.js finds its elements. --}}
    <div class="hidden">
    <div class="flex items-center justify-between mb-2">
      <div class="text-sm font-semibold text-slate-500">Photos</div>
      <button id="takePhotoBtn" class="text-xs kudde-btn-primary rounded-full px-3 py-1.5 font-semibold">
        <i class="fa-solid fa-camera"></i> Take photo
      </button>
    </div>
    <div id="detailPhotos" class="flex gap-2 overflow-x-auto pb-1 mb-3"></div>
    <input id="photoInput" type="file" accept="image/*" capture="environment" class="hidden">
    </div>

    <!-- Family: parents to great-grandparents, offspring, and a mother's births with the days between them.
         Tap an animal on record to open it. -->
    <div id="detailFamily" class="mb-3"></div>

    <div class="text-sm font-semibold text-slate-500 mb-2">History</div>
    <div id="detailEvents" class="space-y-1 text-sm max-h-56 overflow-y-auto"></div>
  </div>
</div>

<!-- Record event modal -->
<div id="eventModal" class="fixed inset-0 bg-black/50 hidden p-4 z-50 overflow-y-auto">
  <div class="bg-white rounded-xl p-5 w-full max-w-sm m-auto">
    <div class="font-bold text-lg mb-3" id="eventModalTitle">Record event</div>
    <label class="text-xs text-slate-500">Date *</label>
    <div class="chip-row" data-date-chips="eventDate"></div>
    <input id="eventDate" type="date" class="w-full border border-slate-300 rounded-lg p-3 text-lg mb-2">
    <div id="eventValueRow" class="hidden">
      <label class="text-xs text-slate-500" id="eventValueLabel">Weight (kg)</label>
      <input id="eventValue" type="number" inputmode="decimal" step="0.1"
             class="w-full border border-slate-300 rounded-lg p-3 text-lg mb-1">
      <!-- The last weighing, and the gain since, so 412 typed as 41 is
           noticed here and not in a report six months on. -->
      <div id="eventValueHint" class="text-xs text-slate-500 min-h-4 mb-2"></div>
    </div>
    <!-- Treatment only. Product, dose and the withdrawal in days off the
         label; chips for the bottles used before. -->
    <div id="eventTreatmentRows" class="hidden">
      <label class="text-xs text-slate-500">Product</label>
      <div id="eventProductChips" class="chip-row"></div>
      <input id="eventProduct" type="text" placeholder="e.g. Ivermectin" autocomplete="off"
             class="w-full border border-slate-300 rounded-lg p-3 text-lg mb-2">
      <div class="grid grid-cols-2 gap-2">
        <div>
          <label class="text-xs text-slate-500">Dose</label>
          <input id="eventDose" type="text" placeholder="e.g. 5 ml" class="w-full border border-slate-300 rounded-lg p-3 text-lg mb-2">
        </div>
        <div>
          <label class="text-xs text-slate-500">Withdrawal (days)</label>
          <input id="eventWithdrawal" type="number" inputmode="numeric" min="0" step="1" class="w-full border border-slate-300 rounded-lg p-3 text-lg mb-2">
        </div>
      </div>
    </div>
    <div id="eventLocationRow" class="hidden">
      <label class="text-xs text-slate-500">Camp</label>
      <div id="eventLocationChips" class="chip-row"></div>
      <input id="eventLocation" placeholder="Camp name" type="text" class="w-full border border-slate-300 rounded-lg p-3 text-lg mb-2">
    </div>
    <label class="text-xs text-slate-500">Note</label>
    <textarea id="eventNote" rows="2" class="w-full border border-slate-300 rounded-lg p-3 text-lg mb-2"></textarea>
    <div class="flex gap-2 mt-3">
      <button id="cancelEventBtn" class="flex-1 rounded-lg py-3 bg-slate-100">Cancel</button>
      <button id="confirmEventBtn" class="flex-1 kudde-btn-primary rounded-lg py-3 font-semibold">Save</button>
    </div>
  </div>
</div>

<!-- Move camp modal -->
<div id="moveModal" class="fixed inset-0 bg-black/50 hidden p-4 z-40 overflow-y-auto">
  <div class="bg-white rounded-xl p-5 w-full max-w-sm m-auto">
    <div id="moveLocationsView">
      <div class="font-bold text-lg mb-1">Move camp</div>
      <div class="text-xs text-slate-500 mb-3">Pick the camp everyone is moving from</div>
      <div id="moveLocationsList" class="space-y-2 max-h-80 overflow-y-auto"></div>
      <div id="moveLocationsEmpty" class="hidden text-slate-400 text-sm py-4 text-center">No camps yet - set them up in Admin, Settings, or type one when recording a move</div>
      <button id="cancelMoveBtn" class="w-full rounded-lg py-3 bg-slate-100 mt-3">Cancel</button>
    </div>
    <div id="moveAnimalsView" class="hidden">
      <div class="font-bold text-lg mb-1">Move from <span id="moveFromLocation"></span></div>
      <div class="text-xs text-slate-500 mb-2">Uncheck any animal that isn't moving</div>
      <div id="moveAnimalsList" class="space-y-1 max-h-40 overflow-y-auto mb-3 border border-slate-200 rounded-lg p-2"></div>
      <label class="text-xs text-slate-500">Date *</label>
      <div class="chip-row" data-date-chips="moveDate"></div>
      <input id="moveDate" type="date" class="w-full border border-slate-300 rounded-lg p-3 text-lg mb-2">
      <label class="text-xs text-slate-500">To camp *</label>
      <div id="moveToChips" class="chip-row"></div>
      <input id="moveToLocation" type="text" placeholder="Camp name" class="w-full border border-slate-300 rounded-lg p-3 text-lg mb-2">
      <label class="text-xs text-slate-500">Note</label>
      <textarea id="moveNote" rows="2" class="w-full border border-slate-300 rounded-lg p-3 text-lg mb-2"></textarea>
      <div class="flex gap-2 mt-3">
        <button id="backMoveBtn" class="flex-1 rounded-lg py-3 bg-slate-100">Back</button>
        <button id="confirmMoveBtn" class="flex-1 kudde-btn-primary rounded-lg py-3 font-semibold">Move</button>
      </div>
    </div>
  </div>
</div>

<!-- Calving modal: the cow calved, and here is the calf. One screen, one
     record on the server (POST /api/calvings) or one outbox entry. -->
<div id="calvingModal" class="fixed inset-0 bg-black/50 hidden p-4 z-50 overflow-y-auto">
  <div class="bg-white rounded-xl p-5 w-full max-w-sm m-auto">
    <div class="font-bold text-lg mb-1"><span id="calvingDam"></span> <span id="calvingTitle">calved</span></div>
    <div class="text-xs text-slate-500 mb-3" id="calvingHint">Records the calving on the cow and adds the calf, with her as the dam.</div>
    <label class="text-xs text-slate-500">Date *</label>
    <div class="chip-row" data-date-chips="calvingDate"></div>
    <input id="calvingDate" type="date" class="w-full border border-slate-300 rounded-lg p-3 text-lg mb-2">
    <!-- Young tagged only at weaning (pigs): the birth is counted on the mother. -->
    <div id="litterFields" class="hidden grid grid-cols-2 gap-2">
      <div>
        <label class="text-xs text-slate-500">Born alive *</label>
        <input id="litterAlive" type="number" inputmode="numeric" min="0" max="40" class="w-full border border-slate-300 rounded-lg p-3 text-lg mb-2">
      </div>
      <div>
        <label class="text-xs text-slate-500">Stillborn</label>
        <input id="litterStillborn" type="number" inputmode="numeric" min="0" max="40" class="w-full border border-slate-300 rounded-lg p-3 text-lg mb-2">
      </div>
    </div>
    <div id="calfFields">
    <label class="text-xs text-slate-500" id="calfTagLabel">Calf tag *</label>
    <input id="calfTag" type="text" autocomplete="off" autocapitalize="characters" class="w-full border border-slate-300 rounded-lg p-3 text-lg mb-2">
    <label class="text-xs text-slate-500" id="calfSexLabel">Calf sex *</label>
    <div class="flex rounded-lg bg-slate-100 p-1 text-sm font-semibold mb-2" role="group" aria-label="Calf sex">
      <button id="calfFemaleBtn" type="button" class="seg-btn flex-1">Heifer</button>
      <button id="calfMaleBtn" type="button" class="seg-btn flex-1">Bull calf</button>
    </div>
    <div class="grid grid-cols-2 gap-2">
      <div>
        <label class="text-xs text-slate-500">Calf name</label>
        <input id="calfName" type="text" class="w-full border border-slate-300 rounded-lg p-3 text-lg mb-2">
      </div>
      <div>
        <label class="text-xs text-slate-500">Sire tag</label>
        <input id="calfSireTag" type="text" class="w-full border border-slate-300 rounded-lg p-3 text-lg mb-2">
      </div>
    </div>
    <label class="text-xs text-slate-500">Breed</label>
    <input id="calfBreed" type="text" class="w-full border border-slate-300 rounded-lg p-3 text-lg mb-2">
    <!-- Twins and triplets: one more row each, a tag and a sex. Same birth, same dam, sire, breed and camp. -->
    <div id="extraYoung" class="space-y-2 mb-2"></div>
    <button id="addYoungBtn" type="button" class="w-full btn-outline rounded-lg py-2 font-semibold mb-2"><i class="fa-solid fa-plus"></i> Twin</button>
    </div>
    <label class="text-xs text-slate-500">Camp</label>
    <div id="calvingCampChips" class="chip-row"></div>
    <input id="calvingCamp" type="text" placeholder="Camp name" class="w-full border border-slate-300 rounded-lg p-3 text-lg mb-2" autocomplete="off">
    <label class="text-xs text-slate-500">Note <span class="text-slate-400">(calving ease, pulled, up and sucking)</span></label>
    <textarea id="calvingNote" rows="2" class="w-full border border-slate-300 rounded-lg p-3 text-lg mb-2"></textarea>
    <div class="flex gap-2 mt-3">
      <button id="cancelCalvingBtn" class="flex-1 rounded-lg py-3 bg-slate-100">Cancel</button>
      <button id="confirmCalvingBtn" class="flex-1 kudde-btn-primary rounded-lg py-3 font-semibold">Save</button>
    </div>
  </div>
</div>

<!-- Weaning a litter: each young one gets a tag now, with the mother as dam. -->
<div id="weanModal" class="fixed inset-0 bg-black/50 hidden p-4 z-50 overflow-y-auto">
  <div class="bg-white rounded-xl p-5 w-full max-w-sm m-auto">
    <div class="font-bold text-lg mb-1">Wean <span id="weanDam"></span>'s litter</div>
    <div class="text-xs text-slate-500 mb-3" id="weanHint"></div>
    <label class="text-xs text-slate-500">Date *</label>
    <div class="chip-row" data-date-chips="weanDate"></div>
    <input id="weanDate" type="date" class="w-full border border-slate-300 rounded-lg p-3 text-lg mb-2">
    <label class="text-xs text-slate-500">Young weaned, a tag each</label>
    <div id="weanRows" class="space-y-2 mb-2"></div>
    <button id="weanAddRowBtn" type="button" class="w-full btn-outline rounded-lg py-2 font-semibold mb-2"><i class="fa-solid fa-plus"></i> Another</button>
    <label class="text-xs text-slate-500">Fostered <span class="text-slate-400">(on minus off)</span></label>
    <input id="weanFostered" type="number" inputmode="numeric" min="-40" max="40" class="w-full border border-slate-300 rounded-lg p-3 text-lg mb-2">
    <label class="text-xs text-slate-500">Camp</label>
    <div id="weanCampChips" class="chip-row"></div>
    <input id="weanCamp" type="text" placeholder="Camp name" class="w-full border border-slate-300 rounded-lg p-3 text-lg mb-2" autocomplete="off">
    <div class="flex gap-2 mt-3">
      <button id="cancelWeanBtn" class="flex-1 rounded-lg py-3 bg-slate-100">Cancel</button>
      <button id="confirmWeanBtn" class="flex-1 kudde-btn-primary rounded-lg py-3 font-semibold">Save</button>
    </div>
  </div>
</div>

{{-- The day's first screen: scan a worker card. Above everything but the camera (z-65), so the app behind it
     cannot be used until someone has scanned in. --}}
<div id="signinScreen" class="fixed inset-0 z-[62] hidden flex-col items-center justify-center p-6 text-center" style="background:#0A2F6B;color:white">
  <i class="fa-solid fa-id-card text-6xl opacity-90 mb-4"></i>
  <div id="signinFarm" class="text-sm opacity-80 mb-1"></div>
  <div id="signinTitle" class="text-2xl font-bold mb-2"></div>
  <div id="signinHint" class="text-sm opacity-90 max-w-xs mb-6"></div>
  <button id="signinScanBtn" type="button" class="w-full max-w-xs rounded-2xl py-5 text-xl font-bold bg-white" style="color:#0A2F6B">
    <i class="fa-solid fa-qrcode"></i> <span id="signinScanLabel"></span>
  </button>
  <div id="signinMessage" class="mt-4 min-h-6 text-base font-semibold max-w-xs bg-white/0" role="status" aria-live="polite"></div>
</div>

<!-- Scanner: the camera, full screen, until a code is read or Cancel. -->
<div id="scanModal" class="fixed inset-0 bg-black hidden flex-col z-[65]">
  <video id="scanVideo" class="flex-1 w-full object-cover" muted playsinline></video>
  <div class="absolute inset-0 pointer-events-none flex items-center justify-center">
    <div class="w-64 h-64 border-4 border-white/70 rounded-2xl"></div>
  </div>
  <div class="absolute top-0 left-0 right-0 p-4 text-center text-white text-sm bg-gradient-to-b from-black/60" id="scanHint">
    Point the camera at the tag's code
  </div>
  <div class="p-4 pb-8 bg-black/80">
    <button id="cancelScanBtn" type="button" class="w-full rounded-xl py-3 bg-white/20 text-white font-semibold">Cancel</button>
  </div>
</div>

<!-- Photo viewer. In-page, because window.open from an installed PWA on
     an iPhone leaves the app for Safari, and the way back is not obvious
     in an orchard. Tap anywhere to close. -->
<div id="photoViewer" class="fixed inset-0 bg-black/90 hidden items-center justify-center z-[60] p-2">
  <img id="photoViewerImg" class="max-w-full max-h-full object-contain" alt="">
  <button id="closePhotoViewerBtn" type="button" class="absolute top-3 right-3 w-10 h-10 rounded-full bg-white/20 text-white text-xl" aria-label="Close">
    <i class="fa-solid fa-xmark"></i>
  </button>
</div>

<!-- Session modal: weigh or treat one animal after another -->
<div id="sessionModal" class="fixed inset-0 bg-black/50 hidden p-4 z-40 overflow-y-auto">
  <div class="bg-white rounded-xl p-5 w-full max-w-sm m-auto">
    <div id="sessionSetupView">
      <div class="font-bold text-lg mb-1" id="sessionSetupTitle">Weigh session</div>
      <div class="text-xs text-slate-500 mb-3">One animal after another: type the tag, the number, Save and next. Every save is recorded straight away, online or not.</div>
      <label class="text-xs text-slate-500">Date *</label>
      <div class="chip-row" data-date-chips="sessionDate"></div>
      <input id="sessionDate" type="date" class="w-full border border-slate-300 rounded-lg p-3 text-lg mb-2">
      <div id="sessionTreatmentRows" class="hidden">
        <div class="text-xs text-slate-500 mb-1">Recorded on every animal in the session:</div>
        <label class="text-xs text-slate-500">Product *</label>
        <div id="sessionProductChips" class="chip-row"></div>
        <input id="sessionProduct" type="text" placeholder="e.g. Ivermectin" autocomplete="off"
               class="w-full border border-slate-300 rounded-lg p-3 text-lg mb-2">
        <div class="grid grid-cols-2 gap-2">
          <div>
            <label class="text-xs text-slate-500">Dose</label>
            <input id="sessionDose" type="text" placeholder="e.g. 5 ml" class="w-full border border-slate-300 rounded-lg p-3 text-lg mb-2">
          </div>
          <div>
            <label class="text-xs text-slate-500">Withdrawal (days)</label>
            <input id="sessionWithdrawal" type="number" inputmode="numeric" min="0" step="1" class="w-full border border-slate-300 rounded-lg p-3 text-lg mb-2">
          </div>
        </div>
        <label class="text-xs text-slate-500">Note</label>
        <input id="sessionNote" type="text" class="w-full border border-slate-300 rounded-lg p-3 text-lg mb-2">
      </div>
      <div class="flex gap-2 mt-3">
        <button id="cancelSessionBtn" class="flex-1 rounded-lg py-3 bg-slate-100">Cancel</button>
        <button id="startSessionBtn" class="flex-1 kudde-btn-primary rounded-lg py-3 font-semibold">Start</button>
      </div>
    </div>
    <div id="sessionCaptureView" class="hidden">
      <div class="flex items-start justify-between mb-3">
        <div>
          <div class="font-bold text-lg" id="sessionTitle">Weighing</div>
          <div class="text-xs text-slate-500" id="sessionSub">0 recorded</div>
        </div>
        <button id="doneSessionBtn" class="rounded-lg px-4 py-2 bg-slate-100 font-semibold">Done</button>
      </div>
      <label class="text-xs text-slate-500">Tag *</label>
      <div class="flex gap-2 mb-1">
        <input id="sessionTag" type="text" list="tagList" autocomplete="off" autocapitalize="characters" enterkeyhint="next"
               class="flex-1 min-w-0 border border-slate-300 rounded-lg p-3 text-2xl font-bold">
        <button id="scanSessionBtn" type="button" class="btn-outline rounded-lg px-4 text-xl" aria-label="Scan a tag">
          <i class="fa-solid fa-qrcode"></i>
        </button>
      </div>
      <div id="sessionAnimal" class="text-sm text-slate-500 min-h-5 mb-2"></div>
      <div id="sessionValueRow">
        <label class="text-xs text-slate-500">Weight (kg) *</label>
        <input id="sessionValue" type="number" inputmode="decimal" step="0.1" enterkeyhint="done"
               class="w-full border border-slate-300 rounded-lg p-3 text-2xl font-bold mb-1">
        <div id="sessionValueHint" class="text-xs text-slate-500 min-h-4 mb-2"></div>
      </div>
      <button id="sessionSaveBtn" class="w-full kudde-btn-primary rounded-xl py-4 text-lg font-bold mt-1">Save and next</button>
      <div class="text-sm font-semibold text-slate-500 mt-4 mb-1">This session</div>
      <div id="sessionLog" class="space-y-1 text-sm max-h-40 overflow-y-auto"></div>
    </div>
  </div>
</div>
<!-- Every alive tag, for the session's tag box to complete from. -->
<datalist id="tagList"></datalist>

<script src="{{ $asset('js/app.js') }}"></script>
</body>
</html>
