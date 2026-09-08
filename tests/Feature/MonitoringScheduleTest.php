<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;

test('monitoring schedules run every minute in foreground with ten minute overlap locks', function (string $command) {
    Http::preventStrayRequests();
    Http::fake();

    // Initialize Artisan so withSchedule callbacks register the scheduled commands.
    app(Kernel::class)->all();

    // Inspect registration only: schedule:run could spawn processes outside our mocks.
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains($event->command ?? '', $command))
        ->values();

    expect($events)->toHaveCount(1);
    $event = $events->sole();
    expect($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(10)
        ->and($event->runInBackground)->toBeFalse();
    Http::assertNothingSent();
})->with(['monitor:vps', 'monitor:websites']);
