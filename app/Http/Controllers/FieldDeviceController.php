<?php

namespace App\Http\Controllers;

use App\Support\Devices;
use App\Support\Farm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The phone's side of pairing (PLAN.md section 5.1): the code from the devices page in, the phone's token out.
 * Open, so an unpaired phone can reach it; limited per address, as the card scan is.
 */
class FieldDeviceController extends Controller
{
    public function __construct(private Devices $devices) {}

    public function pair(Request $request, Farm $farm): JsonResponse
    {
        $code = (string) $request->validate(['code' => ['required', 'string', 'max:20']])['code'];
        $paired = $this->devices->pair($code, (string) $request->userAgent());

        if ($paired === null) {
            return response()->json(['message' => __('plaashek.devices.bad_code')], 422);
        }

        return response()->json([
            'token' => $paired['token'],
            'device' => ['label' => $paired['device']->label],
            'farm' => $farm->name(),
        ]);
    }

    /** Whether this phone is still one of the farm's: 401 once it is disabled or paired again elsewhere. */
    public function show(Request $request): JsonResponse
    {
        $device = $this->devices->fromRequest($request);
        if ($device === null) {
            return response()->json(['message' => __('plaashek.devices.not_paired'), 'unpaired' => true], 401);
        }
        $device->forceFill(['last_seen_at' => now()])->save();

        return response()->json(['device' => ['label' => $device->label]]);
    }
}
