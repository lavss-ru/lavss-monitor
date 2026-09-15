<?php

namespace App\Http\Controllers;

use App\Services\NotificationPolicyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class NotificationSettingsController extends Controller
{
    public function edit(): Response
    {
        $policy = NotificationPolicyService::load();
        $settings = $policy->settings;
        foreach (['quiet_hours_start', 'quiet_hours_end'] as $key) {
            $settings[$key] = $settings[$key] === null ? '' : substr($settings[$key], 0, 5);
        }
        $settings['max_recipient_id'] ??= '';

        return Inertia::render('Settings/Notifications', [
            'settings' => $settings, 'rules' => $policy->rules,
            'maxConfigured' => $policy->maxConfigured(),
            'maxStatus' => ! config('services.max.bot_token') ? 'токен MAX не настроен'
                : ($policy->recipient() === null ? 'получатель не указан' : 'MAX настроен'),
            'effectiveRecipient' => $policy->recipient(),
            'timezones' => timezone_identifiers_list(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validation = [
            'notifications_enabled' => ['required', 'boolean'], 'max_enabled' => ['required', 'boolean'],
            'max_recipient_id' => ['nullable', 'string', 'max:255'],
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
            'quiet_hours_enabled' => ['required', 'boolean'],
            'quiet_hours_start' => ['nullable', Rule::requiredIf($request->boolean('quiet_hours_enabled')), 'date_format:H:i'],
            'quiet_hours_end' => ['nullable', Rule::requiredIf($request->boolean('quiet_hours_enabled')), 'date_format:H:i'],
            'rules' => ['required', 'array:vps,website,local_device,location'],
        ];
        if ($request->boolean('quiet_hours_enabled')) {
            $validation['quiet_hours_end'][] = 'different:quiet_hours_start';
        }
        foreach (NotificationPolicyService::DELAYS as $type => $delay) {
            $validation["rules.$type"] = ['required', 'array:down_enabled,recovery_enabled,confirmation_seconds'];
            $validation["rules.$type.down_enabled"] = ['required', 'boolean'];
            $validation["rules.$type.recovery_enabled"] = ['required', 'boolean'];
            $validation["rules.$type.confirmation_seconds"] = ['required', 'integer', 'between:0,86400'];
        }
        $data = $request->validate($validation, [
            'required' => 'Заполните поле.', 'required_if' => 'Укажите время для тихих часов.',
            'boolean' => 'Выберите включено или выключено.', 'in' => 'Выберите часовой пояс IANA из списка.',
            'date_format' => 'Укажите время в формате ЧЧ:ММ.', 'different' => 'Начало и конец тихих часов должны различаться.',
            'integer' => 'Введите целое число секунд.', 'between' => 'Допустимо от 0 до 86400 секунд.',
            'max' => 'Значение слишком длинное (максимум :max символов).', 'string' => 'Введите текст.',
            'array' => 'Некорректный набор правил.',
        ]);
        DB::transaction(function () use ($data) {
            $rules = $data['rules'];
            unset($data['rules']);
            // Upsert also repairs a missing singleton without racing another save.
            DB::table('notification_settings')->upsert([
                $data + ['id' => 1, 'created_at' => now(), 'updated_at' => now()],
            ], ['id'], array_merge(array_keys($data), ['updated_at']));
            foreach ($rules as $type => $rule) {
                DB::table('notification_rules')->upsert([
                    $rule + ['monitor_type' => $type, 'created_at' => now(), 'updated_at' => now()],
                ], ['monitor_type'], ['down_enabled', 'recovery_enabled', 'confirmation_seconds', 'updated_at']);
            }
        });

        return redirect()->route('settings.notifications')->with('success', 'Настройки сохранены.');
    }
}
