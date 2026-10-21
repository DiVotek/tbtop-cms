<?php

declare(strict_types=1);

namespace Tbtop\Cms\Form\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use JsonException;

/**
 * A submission payload that keeps the form's field order. MySQL's JSON
 * column normalizes object keys (shorter keys first), so the stored
 * payload alone loses the order the fields were submitted in, and with it
 * LeadIdentity's "first field" fallback and the detail page's field order.
 * The key order is stored next to it as a JSON list (lists keep their
 * order on every driver) and reapplied on read. A row written before the
 * order column existed reads in the driver's stored order, as it did.
 *
 * @implements CastsAttributes<array<string, mixed>, array<string, mixed>>
 */
final class OrderedPayload implements CastsAttributes
{
    public const ORDER_COLUMN = 'payload_order';

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        $payload = self::decode($value);
        $order = self::decode($attributes[self::ORDER_COLUMN] ?? null);

        $ordered = [];
        foreach ($order as $field) {
            if ((is_string($field) || is_int($field)) && array_key_exists($field, $payload)) {
                $ordered[$field] = $payload[$field];
            }
        }

        return $ordered + $payload;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string>
     *
     * @throws JsonException
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        // The column is NOT NULL and every writer stores a validated array:
        // anything else is a bug to surface, not a value to coerce.
        if (! is_array($value)) {
            throw new InvalidArgumentException("[{$key}] must be an array, got " . get_debug_type($value) . '.');
        }

        $payload = $value;

        return [
            $key => json_encode($payload, JSON_THROW_ON_ERROR),
            self::ORDER_COLUMN => json_encode(array_keys($payload), JSON_THROW_ON_ERROR),
        ];
    }

    /** @return array<array-key, mixed> */
    private static function decode(mixed $value): array
    {
        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
