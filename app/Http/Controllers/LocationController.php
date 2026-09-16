<?php

namespace App\Http\Controllers;

use App\Models\LocalDevice;
use App\Models\Location;
use App\Rules\TcpHost;
use App\Services\LocationMonitoringService;
use App\Services\MonitorCheckStatisticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class LocationController extends Controller
{
    public function index()
    {
        $locations = Location::with(['devices' => fn ($q) => $q->orderBy('name')])->orderBy('name')->get();
        foreach ($locations as $location) {
            foreach ($location->devices as $device) {
                $device->setRelation('location', $location->withoutRelations());
                $device->applyLocationAvailability();
                $device->unsetRelation('location');
            }
        }

        return Inertia::render('LocalInfrastructure/Index', [
            'locations' => $locations,
            // Inertia excludes this closure before evaluation on locations-only polling.
            'localDeviceStats' => fn () => app(MonitorCheckStatisticsService::class)
                ->forMonitors('local_device', $locations->flatMap->devices->pluck('id')->all()),
            'locationStats' => fn () => app(MonitorCheckStatisticsService::class)
                ->forMonitors('location', $locations->pluck('id')->all()),
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
            'monitoring_enabled' => ['sometimes', 'boolean'],
            'probe_type' => ['sometimes', Rule::in(['tcp'])],
            'probe_host' => ['nullable', Rule::requiredIf($request->boolean('monitoring_enabled')), 'string', 'max:253', new TcpHost],
            'probe_port' => ['nullable', Rule::requiredIf($request->boolean('monitoring_enabled')), 'required_with:probe_host', 'integer', 'between:1,65535'],
            'wireguard_interface' => ['nullable', 'string', 'regex:/^(?!-)[A-Za-z0-9_.-]{1,15}$/D'],
            'wireguard_peer_public_key' => ['bail', 'nullable', 'string', 'regex:/^[A-Za-z0-9+\/]{43}=$/D', function ($attribute, $value, $fail) {
                $decoded = base64_decode($value, true);
                if ($decoded === false || strlen($decoded) !== 32 || base64_encode($decoded) !== $value) {
                    $fail('Некорректный публичный ключ WireGuard.');
                }
            }],
        ]);
    }

    public function store(Request $request)
    {
        Location::create($this->validated($request));

        return redirect()->route('local-infrastructure.index');
    }

    public function update(Request $request, Location $location)
    {
        $request->mergeIfMissing($location->only(['monitoring_enabled', 'probe_type', 'probe_host', 'probe_port', 'wireguard_interface', 'wireguard_peer_public_key']));
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
            $location->fill($data);
            if ($location->isDirty(['enabled', 'monitoring_enabled', 'probe_type', 'probe_host', 'probe_port',
                'wireguard_interface', 'wireguard_peer_public_key', 'connection_type'])) {
                $location->fill(Location::RESET_STATE);
                $location->resolveWarnings();
            }
            $location->save();
        });

        return redirect()->route('local-infrastructure.index');
    }

    public function check(Location $location, LocationMonitoringService $monitoring)
    {
        try {
            $monitoring->monitor($location, origin: 'manual');
        } catch (\Throwable $error) {
            report($error);

            return back()->withErrors(['check' => 'Не удалось выполнить проверку площадки.']);
        }

        return redirect()->route('local-infrastructure.index');
    }

    public function destroy(Location $location)
    {
        DB::transaction(function () use ($location) {
            $location = Location::whereKey($location->id)->lockForUpdate()->firstOrFail();
            if ($location->devices()->exists() || $location->proxmoxConnections()->exists()) {
                throw ValidationException::withMessages(['location' => 'Сначала удалите или перенесите устройства и подключения Proxmox этой площадки.']);
            }
            $location->resolveWarnings();
            $location->delete();
        });

        return redirect()->route('local-infrastructure.index');
    }
}
