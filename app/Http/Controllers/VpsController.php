<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Vps;
use App\Services\MonitorCheckStatisticsService;
use App\Services\NotificationPolicyService;
use App\Services\VpsMonitoringService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class VpsController extends Controller
{
    public function __construct(private VpsMonitoringService $monitoring) {}

    /**
     * Display the list of VPS servers.
     */
    public function index(): Response
    {
        $vpsList = Vps::orderBy('name')->get()->map(function (Vps $vps) {
            return [
                'id' => $vps->id,
                'name' => $vps->name,
                'hostname' => $vps->hostname,
                'ip_address' => $vps->ip_address,
                'description' => $vps->description,
                'enabled' => $vps->enabled,
                'status' => $vps->status ?? 'unknown',
                'check_port' => $vps->check_port ?? 22,
                'last_checked_at' => $vps->last_checked_at?->toISOString(),
                'last_response_ms' => $vps->last_response_ms,
            ];
        });

        return Inertia::render('Vps/Index', [
            'vpsList' => $vpsList->values()->all(),
            'vpsStats' => fn () => app(MonitorCheckStatisticsService::class)
                ->forMonitors('vps', $vpsList->pluck('id')->all()),
        ]);
    }

    /**
     * Store a new VPS record.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'hostname' => ['nullable', 'string', 'max:255'],
            'ip_address' => ['required', 'ip'],
            'description' => ['nullable', 'string'],
            'check_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'enabled' => ['boolean'],
        ]);

        $validated['status'] = 'unknown';

        Vps::create($validated);

        return redirect()->route('vps.index')->with('success', 'VPS добавлен.');
    }

    /**
     * Run a health-check for the given VPS (manual trigger).
     * Uses VpsMonitoringService so events are created on status transitions.
     */
    public function check(Vps $vps): RedirectResponse
    {
        $this->monitoring->monitor($vps, origin: 'manual');

        return redirect()->route('vps.index');
    }

    /**
     * Run health-check for all enabled VPS servers (manual trigger).
     * Uses VpsMonitoringService so events are created on status transitions.
     */
    public function checkAll(): RedirectResponse
    {
        $policy = NotificationPolicyService::load();
        $vpsList = Vps::where('enabled', true)->get();

        foreach ($vpsList as $vps) {
            $this->monitoring->monitor($vps, origin: 'manual_batch', policy: $policy);
        }

        return redirect()->route('vps.index');
    }

    /**
     * Update an existing VPS record.
     */
    public function update(Request $request, Vps $vps): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'hostname' => ['nullable', 'string', 'max:255'],
            'ip_address' => ['required', 'ip'],
            'description' => ['nullable', 'string'],
            'check_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'enabled' => ['boolean'],
        ]);

        DB::transaction(function () use ($vps, $validated) {
            $vps = Vps::whereKey($vps->id)->lockForUpdate()->firstOrFail();
            if ($validated['ip_address'] !== $vps->ip_address || (int) $validated['check_port'] !== $vps->check_port
                || (array_key_exists('enabled', $validated) && (bool) $validated['enabled'] !== $vps->enabled)) {
                $validated = array_merge($validated, ['status' => 'unknown', 'last_checked_at' => null,
                    'last_response_ms' => null, 'failure_started_at' => null, 'incident_confirmed_at' => null,
                    'incident_notified_at' => null, 'recovery_pending_at' => null]);
                Event::where('type', 'vps')->where('source_id', $vps->id)->where('severity', 'warning')
                    ->whereNull('resolved_at')->update(['resolved_at' => now()]);
            }
            $vps->update($validated);
        });

        return redirect()->route('vps.index')->with('success', 'VPS обновлён.');
    }

    /**
     * Delete a VPS record.
     */
    public function destroy(Vps $vps): RedirectResponse
    {
        $vps->delete();

        return redirect()->route('vps.index')->with('success', 'VPS удалён.');
    }
}
