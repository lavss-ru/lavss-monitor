<?php

namespace App\Services;

// Never attach the transport exception: it can contain credentials and response bodies.
final class ProxmoxApiException extends \RuntimeException
{
    public function __construct(public readonly string $safeCode)
    {
        parent::__construct(self::messageFor($safeCode));
    }

    public static function messageFor(string $code): string
    {
        return match ($code) {
            'auth' => 'Ошибка авторизации API',
            'network' => 'Ошибка подключения',
            'http' => 'Ошибка HTTP API',
            'payload' => 'Некорректный ответ API',
            'internal' => 'Внутренняя ошибка',
            'disabled' => 'Подключение отключено',
            'location_unavailable' => 'Площадка недоступна',
            'superseded' => 'Настройки или состояние изменились; повторите проверку',
            default => 'Не удалось выполнить операцию Proxmox',
        };
    }
}
