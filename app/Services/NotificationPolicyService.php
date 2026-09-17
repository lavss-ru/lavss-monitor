<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Immutable operation snapshot. Load once at each entry point, pass through a batch. */
final class NotificationPolicyService
{
    public const DEFAULTS = [
        'notifications_enabled' => true, 'max_enabled' => true, 'max_recipient_id' => null,
        'timezone' => 'Europe/Moscow', 'quiet_hours_enabled' => false,
        'quiet_hours_start' => null, 'quiet_hours_end' => null,
    ];

    public const DELAYS = ['vps' => 0, 'website' => 600, 'local_device' => 120, 'location' => 120, 'proxmox_connection' => 120, 'proxmox_node' => 120, 'proxmox_guest' => 120];

    private function __construct(public readonly array $settings, public readonly array $rules) {}

    public static function load(): self
    {
        return DB::transaction(fn () => self::readSnapshot());
    }

    private static function readSnapshot(): self
    {
        // Serialize with the settings writer to avoid mixed settings/rules versions.
        $row = DB::table('notification_settings')->where('id', 1)->sharedLock()->first();
        $settings = array_replace(self::DEFAULTS, $row ? (array) $row : []);
        foreach (['notifications_enabled', 'max_enabled', 'quiet_hours_enabled'] as $key) {
            $settings[$key] = (bool) $settings[$key];
        }
        $stored = DB::table('notification_rules')->get()->keyBy('monitor_type');
        $rules = [];
        foreach (self::DELAYS as $type => $delay) {
            $rule = $stored->get($type);
            $rules[$type] = ['down_enabled' => (bool) ($rule->down_enabled ?? true),
                'recovery_enabled' => (bool) ($rule->recovery_enabled ?? true),
                'confirmation_seconds' => (int) ($rule->confirmation_seconds ?? $delay)];
        }

        return new self(array_intersect_key($settings, self::DEFAULTS), $rules);
    }

    public function recipient(): ?string
    {
        $recipient = trim((string) ($this->settings['max_recipient_id'] ?? ''));

        return $recipient !== '' ? $recipient : (trim((string) config('services.max.user_id')) ?: null);
    }

    public function maxConfigured(): bool
    {
        return (bool) config('services.max.bot_token') && $this->recipient() !== null;
    }

    public function timezone(): string
    {
        return $this->settings['timezone'];
    }

    public function delay(string $type): int
    {
        return $this->rules[$type]['confirmation_seconds'];
    }

    public function recoveryEnabled(string $type): bool
    {
        return $this->rules[$type]['recovery_enabled'];
    }

    public function quiet(): bool
    {
        if (! $this->settings['quiet_hours_enabled']) {
            return false;
        }
        $start = substr((string) $this->settings['quiet_hours_start'], 0, 5);
        $end = substr((string) $this->settings['quiet_hours_end'], 0, 5);
        if ($start === '' || $end === '' || $start === $end) {
            return false;
        }
        $time = now()->setTimezone($this->timezone())->format('H:i');

        return $start < $end ? $time >= $start && $time < $end : $time >= $start || $time < $end;
    }

    /** Policy never represents a transport error. Suppressed recovery is terminal. */
    public function decision(string $type, bool $recovery): string
    {
        if (! $this->rules[$type][$recovery ? 'recovery_enabled' : 'down_enabled']) {
            return 'suppressed';
        }
        if (! $this->settings['notifications_enabled'] || ! $this->settings['max_enabled'] || $this->quiet()) {
            return 'deferred';
        }

        return 'deliver';
    }
}
