<?php

use App\Console\Commands\MonitorVpsCommand;
use App\Models\Event;
use App\Models\LocalDevice;
use App\Models\Location;
use App\Models\Vps;
use App\Models\Website;
use App\Services\LocalDeviceHealthCheckService;
use App\Services\LocalDeviceMonitoringService;
use App\Services\LocationHealthCheckService;
use App\Services\LocationMonitoringService;
use App\Services\NotificationPolicyService as Policy;
use App\Services\VpsHealthCheckService;
use App\Services\VpsMonitoringService;
use App\Services\WebsiteAggregateService;
use App\Services\WebsiteHealthCheckService;
use App\Services\WebsiteMonitoringService;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-13 12:00:00', 'UTC'));
    config(['services.max.bot_token' => 'fake-token', 'services.max.user_id' => '111', 'services.max.api_url' => 'https://max.invalid']);
    $this->health = 'offline';
    $this->delivery = 200;
    $this->body = [];
    Http::preventStrayRequests();
    Http::fake(function () {
        if ($this->delivery instanceof Throwable) {
            throw $this->delivery;
        }

        return Http::response($this->body, $this->delivery);
    });
    foreach ([LocationHealthCheckService::class, VpsHealthCheckService::class, WebsiteHealthCheckService::class, LocalDeviceHealthCheckService::class] as $class) {
        $this->mock($class)->shouldReceive('check')->andReturnUsing(function ($model) {
            $result = ['status' => $this->health, 'response_ms' => 7, 'http_status' => $this->health === 'online' ? 200 : 500];
            $model->update(['status' => $this->health, 'last_checked_at' => now(), 'last_response_ms' => 7]);

            return $result;
        });
    }
});

function policyTarget(string $type): array
{
    $model = match ($type) {
        'location' => Location::create(['name' => 'Location', 'connection_type' => 'local', 'enabled' => true, 'monitoring_enabled' => true, 'probe_host' => '192.0.2.3', 'probe_port' => 22]),
        'vps' => Vps::create(['name' => 'VPS', 'ip_address' => '192.0.2.1', 'check_port' => 22, 'enabled' => true]),
        'website' => Website::create(['name' => 'Site', 'url' => 'https://target.invalid', 'enabled' => true]),
        'local_device' => LocalDevice::create(['name' => 'Device', 'host' => '192.0.2.2', 'check_port' => 22, 'type' => 'linux_server', 'enabled' => true,
            'location_id' => Location::create(['name' => 'Location', 'connection_type' => 'local', 'enabled' => true])->id]),
    };
    $service = app(match ($type) {
        'location' => LocationMonitoringService::class,
        'vps' => VpsMonitoringService::class, 'website' => WebsiteMonitoringService::class, 'local_device' => LocalDeviceMonitoringService::class
    });
    // Deliberately retain the same service object across cycles (schedule:work).
    $cycle = function () use ($model, $service, $type) {
        $result = $service->monitor($model, origin: 'scheduled');
        if ($type === 'website') {
            app(WebsiteAggregateService::class)->evaluate();
        }

        return $result;
    };

    return [$model, $cycle];
}

function policyQuiet(bool $enabled = true): void
{
    DB::table('notification_settings')->where('id', 1)->update(['quiet_hours_enabled' => $enabled,
        'quiet_hours_start' => '12:00', 'quiet_hours_end' => '13:00', 'timezone' => 'UTC']);
}

function policyDelay(string $type, int $delay): void
{
    DB::table('notification_rules')->where('monitor_type', $type)->update(['confirmation_seconds' => $delay]);
}

test('all monitor types confirm at custom exact boundaries and preserve history', function (string $type, int $delay) {
    policyDelay($type, $delay);
    [$model, $cycle] = policyTarget($type);
    $cycle();
    if ($delay > 0) {
        $this->travel($delay - 1)->seconds();
        $cycle();
        expect(Event::count())->toBe(0);
        Http::assertNothingSent();
        $this->travel(1)->seconds();
        $cycle();
    }
    expect(Event::count())->toBe(1)->and($model->fresh()->incident_notified_at)->not->toBeNull();
    Http::assertSentCount(1);
    $cycle();
    Http::assertSentCount(1);
    $this->health = 'online';
    $cycle();
    $cycle();
    Http::assertSentCount(2);
    expect(Event::count())->toBe(2)->and(DB::table('monitor_checks')->count())->toBe($delay > 0 ? 6 : 4);
})->with(['vps', 'website', 'local_device', 'location'])->with([0, 1, 37, 86400]);

test('quiet transient incident has events history but no stale red or orphan green', function (string $type) {
    policyDelay($type, 0);
    policyQuiet();
    [$model, $cycle] = policyTarget($type);
    $cycle();
    Http::assertNothingSent();
    expect(Event::count())->toBe(1);
    $this->health = 'online';
    $cycle();
    $this->travel(1)->hours();
    $cycle();
    Http::assertNothingSent();
    expect(Event::count())->toBe(2)->and(DB::table('monitor_checks')->count())->toBe(3);
})->with(['vps', 'website', 'local_device', 'location']);

test('persistent quiet DOWN delivers once after quiet and recovery remains correlated', function (string $type) {
    policyDelay($type, 0);
    policyQuiet();
    [$model, $cycle] = policyTarget($type);
    $cycle();
    Http::assertNothingSent();
    $this->travel(1)->hours();
    $cycle();
    $cycle();
    Http::assertSentCount(1);
    $this->health = 'online';
    $cycle();
    $cycle();
    Http::assertSentCount(2);
    expect(Event::count())->toBe(2);
})->with(['vps', 'website', 'local_device', 'location']);

test('recovery during quiet after delivered DOWN defers then sends once', function (string $type) {
    policyDelay($type, 0);
    [$model, $cycle] = policyTarget($type);
    $cycle();
    policyQuiet();
    $this->health = 'online';
    $cycle();
    $cycle();
    Http::assertSentCount(1);
    expect(Event::count())->toBe(2);
    $this->travel(1)->hours();
    $cycle();
    $cycle();
    Http::assertSentCount(2);
    Http::assertSent(fn ($r) => str_contains($r['text'], '🟢'));
})->with(['vps', 'website', 'local_device', 'location']);

test('disabled policy keeps events and history and picks live update on same service', function (string $type, string $switch) {
    policyDelay($type, 0);
    if ($switch === 'down_enabled') {
        DB::table('notification_rules')->where('monitor_type', $type)->update([$switch => false]);
    } else {
        DB::table('notification_settings')->where('id', 1)->update([$switch => false]);
    }
    [$model, $cycle] = policyTarget($type);
    $cycle();
    $cycle();
    expect(Event::count())->toBe(1)->and(DB::table('monitor_checks')->count())->toBe(2);
    Http::assertNothingSent();
    if ($switch === 'down_enabled') {
        DB::table('notification_rules')->where('monitor_type', $type)->update([$switch => true]);
    } else {
        DB::table('notification_settings')->where('id', 1)->update([$switch => true]);
    }
    $cycle();
    $cycle();
    Http::assertSentCount(1);
    $this->health = 'online';
    $cycle();
    Http::assertSentCount(2);
})->with(['vps', 'website', 'local_device', 'location'])->with(['notifications_enabled', 'max_enabled', 'down_enabled']);

test('suppressed recovery is terminal even if enabled later', function (string $type) {
    policyDelay($type, 0);
    [$model, $cycle] = policyTarget($type);
    $cycle();
    DB::table('notification_rules')->where('monitor_type', $type)->update(['recovery_enabled' => false]);
    $this->health = 'online';
    $cycle();
    DB::table('notification_rules')->where('monitor_type', $type)->update(['recovery_enabled' => true]);
    $cycle();
    Http::assertSentCount(1);
    expect(Event::count())->toBe(2)->and(DB::table('monitor_checks')->count())->toBe(3);
})->with(['vps', 'website', 'local_device', 'location']);

test('transport failures retry once per cycle without duplicate DOWN or Recovery', function (string $type, string $failure) {
    policyDelay($type, 0);
    [$model, $cycle] = policyTarget($type);
    if ($failure === 'http') {
        $this->delivery = 503;
    } elseif ($failure === 'application') {
        $this->body = ['success' => false];
    } else {
        $this->delivery = new ConnectionException('fake timeout');
    }
    $cycle();
    $cycle();
    expect(Event::count())->toBe(1)->and($model->fresh()->incident_notified_at)->toBeNull();
    if ($failure !== 'exception') {
        Http::assertSentCount(2);
    }
    $this->delivery = 200;
    $this->body = [];
    $cycle();
    $count = count(Http::recorded());
    $cycle();
    expect(count(Http::recorded()))->toBe($count);
    $this->delivery = 503;
    $this->health = 'online';
    $cycle();
    $this->delivery = 200;
    $cycle();
    $count = count(Http::recorded());
    $cycle();
    expect(count(Http::recorded()))->toBe($count)->and(Event::count())->toBe(2);
})->with(['vps', 'website', 'local_device', 'location'])->with(['http', 'application', 'exception']);

test('failed DOWN followed by recovery never sends orphan green', function (string $type) {
    policyDelay($type, 0);
    [$model, $cycle] = policyTarget($type);
    $this->delivery = 500;
    $cycle();
    $this->delivery = 200;
    $this->health = 'online';
    $cycle();
    $cycle();
    Http::assertSentCount(1);
    expect(Event::count())->toBe(2);
})->with(['vps', 'website', 'local_device', 'location']);

test('live delay change confirms an existing pending incident next cycle', function (string $type) {
    policyDelay($type, 600);
    [$model, $cycle] = policyTarget($type);
    $cycle();
    $this->travel(60)->seconds();
    policyDelay($type, 60);
    $cycle();
    Http::assertSentCount(1);
    expect(Event::count())->toBe(1);
})->with(['vps', 'website', 'local_device', 'location']);

test('MAX recipient override and fallback reach transport without changing auth', function (mixed $recipient, string $expected) {
    DB::table('notification_settings')->where('id', 1)->update(['max_recipient_id' => $recipient]);
    [$model, $cycle] = policyTarget('vps');
    $cycle();
    Http::assertSent(fn ($r) => str_ends_with($r->url(), 'user_id='.$expected) && $r->hasHeader('Authorization', 'fake-token'));
})->with([[null, '111'], ['', '111'], ['222', '222']]);

test('transient incidents during disabled policy never replay after enabling', function (string $type, string $switch) {
    policyDelay($type, 0);
    $table = $switch === 'down_enabled' ? DB::table('notification_rules')->where('monitor_type', $type) : DB::table('notification_settings')->where('id', 1);
    $table->update([$switch => false]);
    [$model, $cycle] = policyTarget($type);
    $cycle();
    $this->health = 'online';
    $cycle();
    $table->update([$switch => true]);
    $cycle();
    Http::assertNothingSent();
    expect(Event::count())->toBe(2)->and(DB::table('monitor_checks')->pluck('status')->all())->toBe(['offline', 'online', 'online']);
})->with(['vps', 'website', 'local_device', 'location'])->with(['notifications_enabled', 'max_enabled', 'down_enabled']);

test('global and MAX toggle defer correlated recovery without losing history', function (string $type, string $switch) {
    policyDelay($type, 0);
    [$model, $cycle] = policyTarget($type);
    $cycle();
    DB::table('notification_settings')->where('id', 1)->update([$switch => false]);
    $this->health = 'online';
    $cycle();
    Http::assertSentCount(1);
    DB::table('notification_settings')->where('id', 1)->update([$switch => true]);
    $cycle();
    $cycle();
    Http::assertSentCount(2);
    expect(Event::count())->toBe(2)->and(DB::table('monitor_checks')->count())->toBe(4);
})->with(['vps', 'website', 'local_device', 'location'])->with(['notifications_enabled', 'max_enabled']);

test('quiet recovery cannot send green while target is down again', function (string $type) {
    policyDelay($type, 0);
    [$model, $cycle] = policyTarget($type);
    $cycle();
    policyQuiet();
    $this->health = 'online';
    $cycle();
    $this->health = 'offline';
    $cycle();
    $this->travel(1)->hours();
    $cycle();
    Http::assertNotSent(fn ($r) => str_contains($r['text'], '🟢'));
    $this->health = 'online';
    $cycle();
    Http::assertSent(fn ($r) => str_contains($r['text'], '🟢'));
})->with(['vps', 'website', 'local_device', 'location']);

test('default confirmation boundaries remain 0 600 120', function (string $type) {
    [$model, $cycle] = policyTarget($type);
    $cycle();
    $delay = Policy::DELAYS[$type];
    if ($delay) {
        $this->travel($delay - 1)->seconds();
        $cycle();
        Http::assertNothingSent();
        $this->travel(1)->seconds();
        $cycle();
    }
    Http::assertSentCount(1);
    expect(Event::count())->toBe(1);
})->with(['vps', 'website', 'local_device', 'location']);

test('website quiet aggregate publishes only latest membership and preserves grace semantics', function () {
    policyDelay('website', 0);
    policyQuiet();
    [$first, $cycle] = policyTarget('website');
    $cycle();
    $second = Website::create(['name' => 'Second', 'url' => 'https://second.invalid', 'enabled' => true]);
    policyDelay('website', 600);
    app(WebsiteMonitoringService::class)->monitor($second, origin: 'scheduled');
    $this->travel(1)->hours();
    app(WebsiteAggregateService::class)->evaluate();
    Http::assertSentCount(1);
    Http::assertSent(fn ($r) => str_contains($r['text'], $first->url) && str_contains($r['text'], $second->url));
    expect($second->fresh()->incident_confirmed_at)->toBeNull();
    app(WebsiteMonitoringService::class)->monitor($second, origin: 'scheduled');
    app(WebsiteAggregateService::class)->evaluate();
    Http::assertSentCount(1);
    policyQuiet();
    DB::table('notification_settings')->where('id', 1)->update(['quiet_hours_end' => '15:00']);
    $this->health = 'online';
    $cycle();
    app(WebsiteAggregateService::class)->evaluate();
    Http::assertSentCount(1);
    policyQuiet(false);
    app(WebsiteAggregateService::class)->evaluate();
    Http::assertSentCount(2);
    $messages = Http::recorded()->map(fn ($pair) => $pair[0]['text']);
    expect($messages[1])->not->toContain($first->url)->toContain($second->url);
});

test('a reused monitoring command reads saved settings on its next run', function () {
    Vps::create(['name' => 'First', 'ip_address' => '192.0.2.1', 'check_port' => 22, 'enabled' => true]);
    Vps::create(['name' => 'Second', 'ip_address' => '192.0.2.2', 'check_port' => 22, 'enabled' => true]);
    DB::table('notification_settings')->where('id', 1)->update(['notifications_enabled' => false]);
    $this->mock(VpsHealthCheckService::class)->shouldReceive('check')->andReturnUsing(function ($model) {
        // Save while a batch is running: this batch stays on its initial snapshot.
        DB::table('notification_settings')->where('id', 1)->update(['notifications_enabled' => true]);
        $model->update(['status' => 'offline', 'last_checked_at' => now(), 'last_response_ms' => 7]);

        return ['status' => 'offline', 'response_ms' => 7];
    });
    $command = app(MonitorVpsCommand::class);
    $command->setLaravel(app());
    $input = new ArrayInput([]);
    $output = new BufferedOutput;
    expect($command->run($input, $output))->toBe(0);
    Http::assertNothingSent();
    expect($command->run($input, $output))->toBe(0);
    Http::assertSentCount(2);
    expect($command->run($input, $output))->toBe(0);
    Http::assertSentCount(2);
    expect(DB::table('monitor_checks')->count())->toBe(6)->and(Event::count())->toBe(2);
});
