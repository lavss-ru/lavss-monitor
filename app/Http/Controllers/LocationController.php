<?php

namespace App\Http\Controllers;

use App\Models\LocalDevice;
use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class LocationController extends Controller
{
    public function index()
    {
        return Inertia::render('LocalInfrastructure/Index', [
            'locations' => Location::with(['devices' => fn ($q) => $q->orderBy('name')])->orderBy('name')->get(),
            'connectionTypes' => Location::CONNECTION_TYPES,
            'deviceTypes' => LocalDevice::TYPES,
        ]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'connection_type' => ['required', Rule::in(Location::CONNECTION_TYPES)],
            'enabled' => ['required', 'boolean'],
        ]);
    }

    public function store(Request $request)
    {
        Location::create($this->validated($request));
        return redirect()->route('local-infrastructure.index');
    }

    public function update(Request $request, Location $location)
    {
        $data = $this->validated($request);
        DB::transaction(function () use ($location, $data) {
            // Same device-first lock order as checks and device edits.
            $devices = $location->devices()->orderBy('id')->lockForUpdate()->get();
            $location = Location::whereKey($location->id)->lockForUpdate()->firstOrFail();
            if ((bool) $data['enabled'] !== $location->enabled) {
                foreach ($devices as $device) {
                    $device->update(LocalDevice::RESET_STATE);
                    $device->resolveWarnings();
                }
            }
            $location->update($data);
        });
        return redirect()->route('local-infrastructure.index');
    }

    public function destroy(Location $location)
    {
        DB::transaction(function () use ($location) {
            $location = Location::whereKey($location->id)->lockForUpdate()->firstOrFail();
            if ($location->devices()->exists()) {
                throw ValidationException::withMessages(['location' => 'Сначала удалите или перенесите устройства этой площадки.']);
            }
            $location->delete();
        });
        return redirect()->route('local-infrastructure.index');
    }
}
