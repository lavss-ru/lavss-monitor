<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Location;
use App\Models\ProxmoxConnection;
use App\Rules\TcpHost;
use App\Services\MonitorCheckStatisticsService;
use App\Services\ProxmoxApiException;
use App\Services\ProxmoxMonitoringService;
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
        $monitoring = app(ProxmoxMonitoringService::class);
        $statistics = app(MonitorCheckStatisticsService::class);
        $availability = [];
        foreach (['proxmox_connection' => $connections, 'proxmox_node' => $connections->flatMap->nodes, 'proxmox_guest' => $connections->flatMap->guests] as $type => $rows) {
            $availability[$type] = $statistics->forMonitors($type, $rows->pluck('id')->all());
        }
        $connections->each(function ($connection) use ($monitoring, $availability) {
            foreach (['proxmox_connection' => collect([$connection]), 'proxmox_node' => $connection->nodes, 'proxmox_guest' => $connection->guests] as $type => $rows) {
                foreach ($rows as $row) {
                    if ($type === 'proxmox_guest') {
                        $row->setRelation('node', $connection->nodes->firstWhere('id', $row->proxmox_node_id));
                    }
                    $row->setAttribute('monitoring_state', $monitoring->enabled($row) ? $monitoring->state($row, $connection) : 'ignored');
                    $row->setAttribute('availability', $availability[$type][$row->id] ?? null);
                    if ($type === 'proxmox_guest') {
                        $row->unsetRelation('node');
                    }
                }
            }
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
            'monitoring_enabled' => ['sometimes', 'boolean'],
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
        if ($request->has('monitoring_target')) {
            return $this->updateMonitoring($request, $proxmox);
        }
        $data = $this->validated($request, false);
        try {
            DB::transaction(function () use ($proxmox, $data) {
                $current = ProxmoxConnection::whereKey($proxmox->id)->lockForUpdate()->firstOrFail();
                $current->fill($data);
                if ($current->isDirty(['location_id', 'host', 'port', 'scheme', 'api_user', 'api_token_id', 'api_token_secret', 'verify_tls', 'enabled'])) {
                    $current->fill(['status' => 'unknown', 'last_checked_at' => null, 'last_response_ms' => null,
                        'last_error_code' => null, 'version' => null, 'last_synced_at' => null,
                        'failure_started_at' => $current->incident_confirmed_at ? $current->failure_started_at : null]);
                    $current->nodes()->whereNull('incident_confirmed_at')->update(['failure_started_at' => null]);
                    $current->guests()->whereNull('incident_confirmed_at')->update(['failure_started_at' => null]);
                    $current->nodes()->update(['stale' => true, 'status' => 'unknown']);
                    $current->guests()->update(['stale' => true, 'status' => 'unknown']);
                }
                if ($current->isDirty('monitoring_enabled')) {
                    $current->failure_started_at = $current->incident_confirmed_at = $current->incident_notified_at = $current->recovery_pending_at = null;
                    Event::where('type', 'proxmox_connection')->where('source_id', $current->id)
                        ->where('severity', 'warning')->whereNull('resolved_at')->update(['resolved_at' => now()]);
                }
                $current->revision++;
                $current->save();
            });
        } catch (\Throwable) {
            return back()->withErrors(['proxmox' => ProxmoxApiException::messageFor('internal')]);
        }

        return redirect()->route('proxmox.index');
    }

    private function updateMonitoring(Request $request, ProxmoxConnection $proxmox)
    {
        $data = $request->validate([
            'monitoring_target' => ['required', Rule::in(['node', 'guest'])],
            'monitor_id' => ['required', 'integer'], 'monitoring_enabled' => ['required', 'boolean'],
            'expected_status' => ['required_if:monitoring_target,guest', Rule::in(['running', 'stopped', 'ignore'])],
        ]);
        DB::transaction(function () use ($proxmox, $data) {
            $connection = ProxmoxConnection::whereKey($proxmox->id)->lockForUpdate()->firstOrFail();
            $monitor = ($data['monitoring_target'] === 'guest' ? $connection->guests() : $connection->nodes())
                ->whereKey($data['monitor_id'])->lockForUpdate()->firstOrFail();
            $monitor->monitoring_enabled = $data['monitoring_enabled'];
            if ($data['monitoring_target'] === 'guest') {
                $monitor->expected_status = $data['expected_status'];
            }
            if ($monitor->isDirty()) {
                // A new policy starts a new observation interval; editing is never recovery.
                $monitor->failure_started_at = $monitor->incident_confirmed_at = $monitor->incident_notified_at = $monitor->recovery_pending_at = null;
                Event::where('type', 'proxmox_'.$data['monitoring_target'])->where('source_id', $monitor->id)
                    ->where('severity', 'warning')->whereNull('resolved_at')->update(['resolved_at' => now()]);
                $monitor->save();
                $connection->increment('revision');
            }
        });

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
