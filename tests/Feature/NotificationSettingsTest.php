<?php

use App\Models\User;
use App\Services\NotificationPolicyService as Policy;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function settingsPayload(array $overrides = []): array
{
    $policy = Policy::load();

    return array_replace_recursive($policy->settings + ['rules' => $policy->rules], $overrides);
}

test('notification defaults preserve previous delays and MAX configuration fallback', function () {
    config(['services.max.user_id' => 'fallback', 'services.max.bot_token' => 'secret']);
    $policy = Policy::load();
    expect($policy->settings)->toBe(Policy::DEFAULTS)->and($policy->recipient())->toBe('fallback');
    foreach (Policy::DELAYS as $type => $delay) {
        expect($policy->delay($type))->toBe($delay)->and($policy->decision($type, false))->toBe('deliver')
            ->and($policy->decision($type, true))->toBe('deliver');
    }
    expect(Schema::hasColumn('notification_settings', 'max_bot_token'))->toBeFalse();
});

test('notification settings routes require auth', function (string $method) {
    $this->$method('/settings/notifications')->assertRedirect('/login');
})->with(['get', 'put']);

test('settings UI exposes safe values and atomic save updates all rules', function () {
    config(['services.max.bot_token' => 'never-expose-token', 'services.max.user_id' => '111']);
    $this->actingAs(User::factory()->create())->get('/settings/notifications')
        ->assertOk()->assertDontSee('never-expose-token')->assertInertia(fn ($page) => $page
        ->component('Settings/Notifications')->where('effectiveRecipient', '111')->has('rules', 4));
    $payload = settingsPayload(['max_recipient_id' => '222', 'timezone' => 'Asia/Tokyo',
        'quiet_hours_enabled' => true, 'quiet_hours_start' => '22:00', 'quiet_hours_end' => '07:00',
        'rules' => ['vps' => ['confirmation_seconds' => 86400], 'website' => ['down_enabled' => false],
            'local_device' => ['recovery_enabled' => false]]]);
    $this->put('/settings/notifications', $payload + ['max_bot_token' => 'injected'])->assertSessionHasNoErrors()->assertRedirect('/settings/notifications');
    expect(Policy::load()->recipient())->toBe('222')->and(Policy::load()->delay('vps'))->toBe(86400)
        ->and(Policy::load()->rules['website']['down_enabled'])->toBeFalse()
        ->and(Policy::load()->recoveryEnabled('local_device'))->toBeFalse()
        ->and(DB::table('notification_settings')->count())->toBe(1)->and(DB::table('notification_rules')->count())->toBe(4);
    $this->put('/settings/notifications', settingsPayload(['max_recipient_id' => null]))->assertSessionHasNoErrors();
    expect(Policy::load()->recipient())->toBe('111');
});

test('invalid notification settings never partially save', function (string $field, mixed $value) {
    $this->actingAs(User::factory()->create());
    $before = Policy::load();
    $payload = settingsPayload(['max_recipient_id' => 'changed']);
    data_set($payload, $field, $value);
    $this->put('/settings/notifications', $payload)->assertSessionHasErrors($field);
    expect(Policy::load()->settings)->toBe($before->settings)->and(Policy::load()->rules)->toBe($before->rules);
})->with([
    ['timezone', 'Mars/Olympus'], ['timezone', '+03:00'], ['timezone', ''],
    ['quiet_hours_start', '24:00'], ['quiet_hours_end', '8:00'], ['quiet_hours_start', '12:00:00'],
    ['notifications_enabled', 'true'], ['max_enabled', 'yes'], ['quiet_hours_enabled', 'yes'],
    ['rules.vps.down_enabled', 'yes'], ['rules.website.recovery_enabled', 2],
    ['rules.local_device.confirmation_seconds', -1], ['rules.website.confirmation_seconds', 86401],
    ['rules.vps.confirmation_seconds', 1.5], ['max_recipient_id', ['1', '2']], ['max_recipient_id', str_repeat('1', 256)],
    ['rules', []], ['rules.vps', []],
]);

test('enabled quiet hours require distinct start and end', function (mixed $start, mixed $end, string $field) {
    $this->actingAs(User::factory()->create())->put('/settings/notifications', settingsPayload([
        'quiet_hours_enabled' => true, 'quiet_hours_start' => $start, 'quiet_hours_end' => $end,
    ]))->assertSessionHasErrors($field);
})->with([[null, '06:00', 'quiet_hours_start'], ['22:00', null, 'quiet_hours_end'], ['22:00', '22:00', 'quiet_hours_end']]);

test('a database failure rolls back settings and every rule', function () {
    $before = Policy::load();
    DB::unprepared("CREATE TRIGGER fail_rule BEFORE UPDATE ON notification_rules WHEN NEW.monitor_type = 'website' BEGIN SELECT RAISE(ABORT, 'test rollback'); END");
    $this->actingAs(User::factory()->create())->put('/settings/notifications', settingsPayload([
        'max_enabled' => false, 'rules' => ['vps' => ['confirmation_seconds' => 99]],
    ]))->assertStatus(500);
    expect(Policy::load()->settings)->toBe($before->settings)->and(Policy::load()->rules)->toBe($before->rules);
});

test('quiet hours use local timezone and start inclusive end exclusive', function (string $zone, string $start, string $end, string $utc, bool $quiet) {
    DB::table('notification_settings')->where('id', 1)->update(['quiet_hours_enabled' => true,
        'quiet_hours_start' => $start, 'quiet_hours_end' => $end, 'timezone' => $zone]);
    $this->travelTo(Carbon::parse($utc, 'UTC'));
    expect(Policy::load()->quiet())->toBe($quiet);
})->with([
    ['UTC', '09:00', '17:00', '2026-09-13 08:59:59', false],
    ['UTC', '09:00', '17:00', '2026-09-13 09:00:00', true],
    ['UTC', '09:00', '17:00', '2026-09-13 16:59:59', true],
    ['UTC', '09:00', '17:00', '2026-09-13 17:00:00', false],
    ['Europe/Moscow', '22:00', '07:00', '2026-09-13 19:00:00', true],
    ['Europe/Moscow', '22:00', '07:00', '2026-09-14 00:00:00', true],
    ['Europe/Moscow', '22:00', '07:00', '2026-09-14 04:00:00', false],
    ['Asia/Tokyo', '09:00', '10:00', '2026-09-13 00:00:00', true],
    ['America/New_York', '01:00', '02:00', '2026-11-01 05:30:00', true],
    ['America/New_York', '01:00', '02:00', '2026-11-01 06:30:00', true],
]);

test('stage36 migrations can roll back and restore defaults in isolated sqlite', function () {
    $settings = require database_path('migrations/2026_09_13_000000_create_notification_settings.php');
    $vps = require database_path('migrations/2026_09_13_000001_add_vps_incident_state.php');
    $vps->down();
    $settings->down();
    expect(Schema::hasTable('notification_settings'))->toBeFalse()->and(Schema::hasColumn('vps', 'incident_notified_at'))->toBeFalse();
    $settings->up();
    $vps->up();
    expect(Policy::load()->settings)->toBe(Policy::DEFAULTS)->and(Schema::hasColumn('vps', 'incident_notified_at'))->toBeTrue()
        ->and(Schema::hasTable('monitor_checks'))->toBeTrue();
});
