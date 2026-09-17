<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        //
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('monitor:prune-check-history')
            ->dailyAt('03:15')
            ->withoutOverlapping(120);

        $schedule->command('monitor:vps')
            ->everyMinute()
            ->withoutOverlapping(10);

        $schedule->command('monitor:local-devices')
            ->everyMinute()
            ->withoutOverlapping(10);

        $schedule->command('monitor:proxmox')->everyMinute()->withoutOverlapping(10);

        $schedule->command('monitor:websites')
            ->everyFiveMinutes()
            ->withoutOverlapping(10);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['api_token_secret']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
