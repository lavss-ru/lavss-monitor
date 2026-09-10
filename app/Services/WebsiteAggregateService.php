<?php

namespace App\Services;

use App\Models\Website;
use App\Models\WebsiteAggregateState;
use Illuminate\Support\Facades\DB;

class WebsiteAggregateService
{
    public function __construct(private MaxNotifier $notifier) {}

    /** One attempt at most. No transaction retry: HTTP is not transactional. */
    public function evaluate(bool $batchSucceeded = true, ?array $checkedIds = null): void
    {
        DB::transaction(function () use ($batchSucceeded, $checkedIds) {
            $state = WebsiteAggregateState::lock();
            $sites = Website::where('enabled', true)->orderBy('id')->get();
            if ($sites->isEmpty()) {
                // Monitoring was removed, not measured as recovered.
                $state->update(['published_at' => null, 'snapshot' => null, 'fingerprint' => null]);
                return;
            }
            $offline = $sites->where('status', 'offline');
            if ($offline->isEmpty()) {
                if ($state->published_at !== null && $batchSucceeded
                    && $sites->every(fn ($site) => $site->status === 'online' && $site->last_checked_at !== null
                        && ($checkedIds === null || in_array($site->id, $checkedIds, true)))
                    && $this->notifier->sendWebsiteAggregateRecovery()) {
                    $state->update(['published_at' => null, 'snapshot' => null, 'fingerprint' => null]);
                }
                return;
            }

            // Identity includes URL so reconfigured targets cannot reuse old dedup state.
            // Durations, response codes and confirmation are deliberately excluded.
            $snapshot = $offline->map(fn ($site) => ['id' => $site->id, 'url' => $site->url])->values()->all();
            $fingerprint = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
            if ($state->published_at === null && ! $offline->contains(fn ($site) => $site->incident_confirmed_at !== null)) {
                return;
            }
            if ($state->fingerprint === $fingerprint) {
                // A grace member may now be confirmed, but was not notified as confirmed.
                return;
            }
            if (! $this->notifier->sendWebsiteAggregate($offline, $state->published_at !== null)) {
                return;
            }
            $state->update(['published_at' => now(), 'snapshot' => $snapshot, 'fingerprint' => $fingerprint]);
            foreach ($offline as $site) {
                if ($site->incident_confirmed_at !== null && $site->incident_notified_at === null) {
                    $site->update(['incident_notified_at' => now()]);
                }
            }
        });
    }
}
