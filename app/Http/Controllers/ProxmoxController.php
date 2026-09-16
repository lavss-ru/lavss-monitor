<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Models\ProxmoxConnection;
use App\Rules\TcpHost;
use App\Services\ProxmoxApiException;
use App\Services\ProxmoxSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ProxmoxController extends Controller
{
    public function index(Request $request)
    {
        $connections = ProxmoxConnection::with(['location:id,name,enabled,monitoring_enabled,probe_host,probe_port,status,incident_confirmed_at',
            'nodes' => fn ($q) => $q->orderBy('node_name'), 'guests' => fn ($q) => $q->orderBy('vmid')])->orderBy('name')->get();
        $connections->each(function ($connection) {
            $reason = $connection->unavailableReason();
            $connection->setAttribute('unavailable_reason', $reason);
            if ($reason) {
                $connection->status = 'unknown';
                $connection->last_response_ms = null;
                $connection->last_error_code = $reason;
            }
        });

        return Inertia::render('Proxmox/Index', [
            'connections' => $connections,
            'locations' => Location::orderBy('name')->get(['id', 'name', 'enabled']),
            'result' => $request->session()->get('proxmox_result'),
        ]);
    }

    private function validated(Request $request, bool $creating): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'host' => ['required', 'string', 'max:253', new TcpHost],
            'port' => ['required', 'integer', 'between:1,65535'],
            'scheme' => ['required', Rule::in(['https', 'http'])],
            'verify_tls' => ['required', 'boolean'],
            'api_user' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._-]+@[A-Za-z0-9._-]+$/D'],
            'api_token_id' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._-]+$/D'],
            'api_token_secret' => [$creating ? 'required' : 'nullable', 'string', 'max:4096', 'regex:/^[\x21-\x7E]+$/D'],
            'enabled' => ['required', 'boolean'],
        ], ['api_token_secret.*' => 'Укажите корректный секрет API token.']);
        if (! $creating && ($data['api_token_secret'] ?? '') === '') {
            unset($data['api_token_secret']);
        }

        return $data;
    }

    public function store(Request $request)
    {
        $data = $this->validated($request, true);
        try {
            ProxmoxConnection::create($data);
        } catch (\Throwable) {
            return back()->withErrors(['proxmox' => ProxmoxApiException::messageFor('internal')]);
        }

        return redirect()->route('proxmox.index');
    }

    public function update(Request $request, ProxmoxConnection $proxmox)
    {
        $data = $this->validated($request, false);
        try {
            DB::transaction(function () use ($proxmox, $data) {
                $current = ProxmoxConnection::whereKey($proxmox->id)->lockForUpdate()->firstOrFail();
                $current->fill($data);
                if ($current->isDirty(['location_id', 'host', 'port', 'scheme', 'api_user', 'api_token_id', 'api_token_secret', 'verify_tls', 'enabled'])) {
                    $current->fill(['status' => 'unknown', 'last_checked_at' => null, 'last_response_ms' => null,
                        'last_error_code' => null, 'version' => null]);
                    $current->nodes()->update(['stale' => true, 'status' => 'unknown']);
                    $current->guests()->update(['stale' => true, 'status' => 'unknown']);
                }
                $current->revision++;
                $current->save();
            });
        } catch (\Throwable) {
            return back()->withErrors(['proxmox' => ProxmoxApiException::messageFor('internal')]);
        }

        return redirect()->route('proxmox.index');
    }

    public function destroy(ProxmoxConnection $proxmox)
    {
        $proxmox->delete();

        return redirect()->route('proxmox.index');
    }

    public function test(ProxmoxConnection $proxmox, ProxmoxSyncService $service)
    {
        return $this->action($proxmox, $service, false);
    }

    public function sync(ProxmoxConnection $proxmox, ProxmoxSyncService $service)
    {
        return $this->action($proxmox, $service, true);
    }

    private function action(ProxmoxConnection $connection, ProxmoxSyncService $service, bool $sync)
    {
        $result = $service->run($connection, $sync);

        return redirect()->route('proxmox.index')->with('proxmox_result', [
            'connection_id' => $connection->id, 'success' => $result['code'] === null,
            'message' => $result['code'] ? ProxmoxApiException::messageFor($result['code'])
                : ($sync ? 'Inventory синхронизирован' : 'Подключение успешно проверено'),
        ]);
    }
}
