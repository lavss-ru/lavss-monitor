<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class TcpHost implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::valid($value)) {
            $fail('Укажите IPv4, IPv6 без скобок или hostname без протокола и порта.');
        }
    }

    public static function valid(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }
        // ASCII DNS names (including single-label LAN names and trailing dot).
        // Reject malformed numeric IPs, transport schemes, paths and scoped IPv6.
        return strlen($host) <= 253
            && ! preg_match('/^[0-9.]+$/D', $host)
            && (bool) preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*\.?$/iD', $host);
    }
}
