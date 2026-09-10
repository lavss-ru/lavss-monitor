<?php

namespace App\Http\Controllers;

use App\Models\Website;
use App\Models\WebsiteAggregateState;
use App\Services\WebsiteAggregateService;
use App\Services\WebsiteMonitoringService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class WebsiteController extends Controller
{
    public function __construct(
        private WebsiteMonitoringService $monitoring,
        private WebsiteAggregateService $aggregate,
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

        DB::transaction(function () use ($validated) {
            WebsiteAggregateState::lock();
            Website::create($validated);
        });

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

        DB::transaction(function () use ($validated, $website) {
            WebsiteAggregateState::lock();
            $website = Website::whereKey($website->id)->lockForUpdate()->firstOrFail();
            if ($validated['url'] !== $website->url
                || (array_key_exists('enabled', $validated) && (bool) $validated['enabled'] !== $website->enabled)) {
                $validated = array_merge($validated, [
                    'failure_started_at' => null,
                    'incident_confirmed_at' => null,
                    'incident_notified_at' => null,
                ]);
            }
            if ($validated['url'] !== $website->url) {
                $validated = array_merge($validated, [
                    'status' => 'unknown',
                    'last_checked_at' => null,
                    'last_response_ms' => null,
                    'last_http_status' => null,
                ]);
            }

            $website->update($validated);
        });

        return redirect()->route('websites.index')->with('success', 'Сайт обновлён.');
    }

    /**
     * Delete a Website record.
     */
    public function destroy(Website $website): RedirectResponse
    {
        DB::transaction(function () use ($website) {
            WebsiteAggregateState::lock();
            $website->delete();
        });

        return redirect()->route('websites.index')->with('success', 'Сайт удалён.');
    }

    /**
     * Run a health-check for the given Website (manual trigger).
     * Enabled sites record and notify significant status transitions.
     */
    public function check(Website $website): RedirectResponse
    {
        $result = $this->monitoring->monitor($website);
        if ($result['enabled']) {
            $this->aggregate->evaluate();
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

        $succeeded = true;
        $checkedIds = [];
        foreach ($websites as $website) {
            try {
                $this->monitoring->monitor($website);
                $checkedIds[] = $website->id;
            } catch (\Throwable $e) {
                // One site failure must not prevent the rest from being checked
                report($e);
                $succeeded = false;
            }
        }

        $this->aggregate->evaluate($succeeded, $checkedIds);

        return redirect()->route('websites.index');
    }
}
