<?php
declare(strict_types=1);

namespace SyncCampus;

/** Typed, validated access to a request body. Validation failures become 422 responses. */
final class Input
{
    public function __construct(private readonly array $data)
    {
    }

    public function has(string $key): bool
    {
        return isset($this->data[$key]) && $this->data[$key] !== '';
    }

    public function text(string $key, string $label, int $min = 0, int $max = 5000): string
    {
        $value = $this->data[$key] ?? '';
        if (!is_scalar($value)) {
            throw new HttpError(422, "$label is not valid");
        }
        $value = trim((string) $value);
        $length = mb_strlen($value);
        if ($length < $min) {
            throw new HttpError(422, $min <= 1 ? "$label is required" : "$label must be at least $min characters");
        }
        if ($length > $max) {
            throw new HttpError(422, "$label must be $max characters or fewer");
        }
        return $value;
    }

    public function int(string $key, string $label, int $default, int $min, int $max): int
    {
        $value = $this->data[$key] ?? $default;
        if ($value === '' || $value === null) {
            $value = $default;
        }
        if (!is_numeric($value) || (int) $value != $value) {
            throw new HttpError(422, "$label must be a whole number");
        }
        $value = (int) $value;
        if ($value < $min || $value > $max) {
            throw new HttpError(422, "$label must be between $min and $max");
        }
        return $value;
    }

    public function optionalInt(string $key): ?int
    {
        $value = $this->data[$key] ?? null;
        return $value === null || $value === '' ? null : (int) $value;
    }

    public function bool(string $key): bool
    {
        $value = $this->data[$key] ?? false;
        return $value === true || $value === 1 || in_array($value, ['1', 'true', 'on', 'yes'], true);
    }

    /** Value must be one of $allowed; $default is used when the field is missing. */
    public function choice(string $key, array $allowed, ?string $default = null, string $message = 'Choose one of the listed options'): ?string
    {
        $value = $this->data[$key] ?? $default;
        if ($value === null || $value === '') {
            return $default;
        }
        if (!in_array($value, $allowed, true)) {
            throw new HttpError(422, $message);
        }
        return $value;
    }

    public function list(string $key): array
    {
        $value = $this->data[$key] ?? [];
        return is_array($value) ? array_values(array_filter($value, 'is_scalar')) : [];
    }

    public function raw(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }
}
