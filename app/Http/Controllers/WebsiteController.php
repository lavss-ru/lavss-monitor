<?php

namespace App\Http\Controllers;

use App\Models\Website;
use App\Services\WebsiteHealthCheckService;
use App\Services\WebsiteMonitoringService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WebsiteController extends Controller
{
    public function __construct(
        private WebsiteHealthCheckService $healthCheck,
        private WebsiteMonitoringService $monitoring,
    ) {
    }

    /**
     * Display the list of monitored websites.
     */
    public function index(): Response
    {
        $websiteList = Website::orderBy('name')->get()->map(function (Website $site) {
            return [
                'id'               => $site->id,
                'name'             => $site->name,
                'url'              => $site->url,
                'type'             => $site->type,
                'enabled'          => $site->enabled,
                'description'      => $site->description,
                'status'           => $site->status ?? 'unknown',
                'last_checked_at'  => $site->last_checked_at?->toISOString(),
                'last_response_ms' => $site->last_response_ms,
                'last_http_status' => $site->last_http_status,
            ];
        });

        return Inertia::render('Website/Index', [
            'websiteList' => $websiteList->values()->all(),
        ]);
    }

    /**
     * Store a new Website record.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'url'         => ['required', 'string', 'max:255', 'url:http,https'],
            'type'        => ['required', 'in:website,wordpress'],
            'enabled'     => ['boolean'],
            'description' => ['nullable', 'string'],
        ]);

        $validated['status'] = 'unknown';

        Website::create($validated);

        return redirect()->route('websites.index')->with('success', 'Сайт добавлен.');
    }

    /**
     * Update an existing Website record.
     */
    public function update(Request $request, Website $website): RedirectResponse
    {
        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'url'         => ['required', 'string', 'max:255', 'url:http,https'],
            'type'        => ['required', 'in:website,wordpress'],
            'enabled'     => ['boolean'],
            'description' => ['nullable', 'string'],
        ]);

        if ($validated['url'] !== $website->url) {
            $validated = array_merge($validated, [
                'status' => 'unknown',
                'last_checked_at' => null,
                'last_response_ms' => null,
                'last_http_status' => null,
            ]);
        }

        $website->update($validated);

        return redirect()->route('websites.index')->with('success', 'Сайт обновлён.');
    }

    /**
     * Delete a Website record.
     */
    public function destroy(Website $website): RedirectResponse
    {
        $website->delete();

        return redirect()->route('websites.index')->with('success', 'Сайт удалён.');
    }

    /**
     * Run a health-check for the given Website (manual trigger).
     * Enabled sites record and notify significant status transitions.
     */
    public function check(Website $website): RedirectResponse
    {
        if ($website->enabled) {
            $this->monitoring->monitor($website);
        } else {
            // Disabled sites still allow manual diagnostics without notifications.
            $this->healthCheck->check($website);
        }

        return redirect()->route('websites.index');
    }

    /**
     * Run health-check for all enabled websites (manual trigger).
     * Errors in one site do not stop the rest.
     * Enabled sites record and notify significant status transitions.
     */
    public function checkAll(): RedirectResponse
    {
        $websites = Website::where('enabled', true)->orderBy('id')->get();

        foreach ($websites as $website) {
            try {
                $this->monitoring->monitor($website);
            } catch (\Throwable $e) {
                // One site failure must not prevent the rest from being checked
                report($e);
            }
        }

        return redirect()->route('websites.index');
    }
}
