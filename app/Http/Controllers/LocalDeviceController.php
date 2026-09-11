<?php

namespace App\Http\Controllers;

use App\Models\LocalDevice;
use App\Rules\TcpHost;
use App\Services\LocalDeviceMonitoringService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LocalDeviceController extends Controller
{
    public function __construct(private LocalDeviceMonitoringService $monitoring) {}

    private function validated(Request $request): array
    {
        return $request->validate([
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(LocalDevice::TYPES)],
            'host' => ['required', 'string', 'max:253', new TcpHost],
            'check_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'enabled' => ['required', 'boolean'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);
    }

    public function store(Request $request)
    {
        LocalDevice::create($this->validated($request));
        return redirect()->route('local-infrastructure.index');
    }

    public function update(Request $request, LocalDevice $localDevice)
    {
        $data = $this->validated($request);
        DB::transaction(function () use ($localDevice, $data) {
            $device = LocalDevice::whereKey($localDevice->id)->lockForUpdate()->firstOrFail();
            if ($data['host'] !== $device->host || (int) $data['check_port'] !== $device->check_port
                || (bool) $data['enabled'] !== $device->enabled || (int) $data['location_id'] !== $device->location_id) {
                $data = array_merge($data, LocalDevice::RESET_STATE);
                $device->resolveWarnings();
            }
            $device->update($data);
        });
        return redirect()->route('local-infrastructure.index');
    }

    public function destroy(LocalDevice $localDevice)
    {
        DB::transaction(function () use ($localDevice) {
            $device = LocalDevice::whereKey($localDevice->id)->lockForUpdate()->firstOrFail();
            $device->resolveWarnings();
            $device->delete();
        });
        return redirect()->route('local-infrastructure.index');
    }

    public function check(LocalDevice $localDevice)
    {
        try {
            $this->monitoring->monitor($localDevice, diagnostic: true);
        } catch (\Throwable $error) {
            report($error);
            return back()->withErrors(['check' => 'Не удалось выполнить проверку устройства.']);
        }
        return redirect()->route('local-infrastructure.index');
    }

    public function checkAll()
    {
        $result = $this->monitoring->checkAll();
        $response = redirect()->route('local-infrastructure.index');
        return $result['errors'] ? $response->withErrors(['check' => "Ошибки проверок: {$result['errors']}. Остальные устройства проверены."]) : $response;
    }
}
