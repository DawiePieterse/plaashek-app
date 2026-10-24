<?php

namespace App\Http\Controllers;

use App\Models\Worker;
use App\Support\Farm;
use App\Support\NaturalOrder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The card sheet: one card per chosen worker, Boord's print-badge layout in mm, nine to an A4 page. Opened
 * from the Workers list with the selection in ?ids=, printed from the browser.
 */
class WorkerCardController extends Controller
{
    public function __invoke(Request $request, Farm $farm): View
    {
        $ids = array_filter(array_map('intval', explode(',', (string) $request->query('ids', ''))));

        $workers = Worker::query()->whereIn('id', $ids)->get()
            ->sort(fn (Worker $a, Worker $b) => NaturalOrder::compare($a->number, $b->number))
            ->values();

        return view('workers.cards', ['workers' => $workers, 'farm' => $farm->name()]);
    }
}
