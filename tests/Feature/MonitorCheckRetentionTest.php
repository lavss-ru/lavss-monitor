<?php

use App\Console\Commands\PruneMonitorCheckHistoryCommand;
use App\Models\MonitorCheck;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => $this->travelTo(\Carbon\Carbon::parse('2026-09-11 12:00:00')));

test('retention keeps exact 30 day boundary deletes older rows across several batches and reports count', function () {
    $base = ['monitor_type' => 'vps', 'monitor_id' => 9999, 'origin' => 'manual', 'status' => 'online', 'response_ms' => null, 'http_status' => null];
    foreach ([now()->subDays(30)->addSecond(), now()->subDays(30)] as $at) {
        MonitorCheck::create($base + ['checked_at' => $at]);
    }
    $count = PruneMonitorCheckHistoryCommand::BATCH_SIZE * 2 + 3;
    foreach (array_chunk(array_fill(0, $count, $base + ['checked_at' => now()->subDays(30)->subSecond()]), 100) as $rows) {
        MonitorCheck::insert($rows);
    }
    DB::enableQueryLog(); DB::flushQueryLog();
    $this->artisan('monitor:prune-check-history')->expectsOutput("Deleted {$count} check history rows.")->assertSuccessful();
    $deletes = collect(DB::getQueryLog())->filter(fn ($q) => str_starts_with($q['query'], 'delete'));
    DB::disableQueryLog();
    expect($deletes)->toHaveCount(3)->and(MonitorCheck::count())->toBe(2);
    foreach ($deletes as $query) expect(count($query['bindings']))->toBeLessThanOrEqual(PruneMonitorCheckHistoryCommand::BATCH_SIZE);
    $this->artisan('monitor:prune-check-history')->expectsOutput('Deleted 0 check history rows.')->assertSuccessful();
});

test('pruning is scheduled daily in foreground with overlap protection', function () {
    app(Kernel::class)->all();
    $event = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command ?? '', 'monitor:prune-check-history'))->sole();
    expect($event->expression)->toBe('15 3 * * *')->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(120)->and($event->runInBackground)->toBeFalse();
});
