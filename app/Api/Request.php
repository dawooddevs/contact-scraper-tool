<?php

declare(strict_types=1);

namespace App\Api;

/**
 * Typed accessors over the merged query string + JSON body.
 */
final class Request
{
    public function __construct(private array $data, public readonly string $method)
    {
    }

    public static function capture(): self
    {
        $data = $_GET;
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method === 'POST') {
            $type = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
            if (stripos($type, 'application/json') !== false) {
                $raw = file_get_contents('php://input') ?: '';
                $json = $raw === '' ? [] : json_decode($raw, true);
                if (!is_array($json)) {
                    throw new ApiException('Malformed JSON body.', 400);
                }
                $data = array_merge($data, $json);
            } else {
                $data = array_merge($data, $_POST);
            }
        }
        unset($data['r']);
        return new self($data, $method);
    }

    public function all(): array
    {
        return $this->data;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function raw(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function str(string $key, string $default = '', int $max = 5000): string
    {
        $value = $this->data[$key] ?? $default;
        if (is_array($value) || is_object($value)) {
            return $default;
        }
        return mb_substr(trim((string) $value), 0, $max);
    }

    public function int(string $key, int $default = 0, ?int $min = null, ?int $max = null): int
    {
        $value = $this->data[$key] ?? null;
        $int = is_numeric($value) ? (int) $value : $default;
        if ($min !== null) {
            $int = max($min, $int);
        }
        if ($max !== null) {
            $int = min($max, $int);
        }
        return $int;
    }

    public function bool(string $key, bool $default = false): bool
    {
        if (!array_key_exists($key, $this->data)) {
            return $default;
        }
        $value = $this->data[$key];
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    public function arr(string $key): array
    {
        $value = $this->data[$key] ?? [];
        return is_array($value) ? $value : [];
    }

    /** List of positive integer ids from an array or comma separated string. */
    public function ids(string $key): array
    {
        $value = $this->data[$key] ?? [];
        if (is_string($value) || is_int($value)) {
            $value = explode(',', (string) $value);
        }
        if (!is_array($value)) {
            return [];
        }
        $ids = array_filter(array_map('intval', $value), static fn (int $v) => $v > 0);
        return array_values(array_unique($ids));
    }
}
