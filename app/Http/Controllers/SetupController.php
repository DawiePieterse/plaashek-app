<?php

namespace App\Http\Controllers;

use App\Services\FarmSetup;
use App\Support\Farm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The first-run setup page: the same questions as `farm:create`, for hosting without a command line. Only
 * reachable while the install has no farm yet.
 */
class SetupController extends Controller
{
    public function create(FarmSetup $setup): View
    {
        abort_if($setup->isSetUp(), 404);

        return view('setup');
    }

    public function store(Request $request, FarmSetup $setup): RedirectResponse
    {
        abort_if($setup->isSetUp(), 404);

        $input = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'language' => ['required', Rule::in(Farm::LANGUAGES)],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'owner_name' => ['required', 'string', 'max:100'],
            'owner_email' => ['required', 'email', 'max:255'],
            'owner_password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $setup->create($input);

        return redirect('/admin/login')->with('status', __('plaashek.setup.done'));
    }
}
