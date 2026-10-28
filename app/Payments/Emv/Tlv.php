<?php

namespace App\Payments\Emv;

use App\Payments\Exceptions\UnsupportedPayloadException;

/**
 * EMVCo data objects: 2-digit ID, 2-digit length, value. Lengths count
 * characters, so multibyte merchant names (tag 64) are handled with mb_*.
 */
final class Tlv
{
    /**
     * @return list<array{id: string, value: string}>
     */
    public static function parse(string $data): array
    {
        $items = [];
        $position = 0;
        $total = mb_strlen($data, 'UTF-8');

        while ($position < $total) {
            if ($position + 4 > $total) {
                throw new UnsupportedPayloadException("Truncated data object at position {$position}.");
            }

            $id = mb_substr($data, $position, 2, 'UTF-8');
            $length = mb_substr($data, $position + 2, 2, 'UTF-8');

            if (! ctype_digit($id) || ! ctype_digit($length)) {
                throw new UnsupportedPayloadException("Invalid data object header at position {$position}.");
            }

            $value = mb_substr($data, $position + 4, (int) $length, 'UTF-8');

            if (mb_strlen($value, 'UTF-8') !== (int) $length) {
                throw new UnsupportedPayloadException("Data object {$id} is shorter than its declared length.");
            }

            $items[] = ['id' => $id, 'value' => $value];
            $position += 4 + (int) $length;
        }

        return $items;
    }

    /**
     * @param  list<array{id: string, value: string}>  $items
     */
    public static function encode(array $items): string
    {
        return implode('', array_map(fn (array $item) => self::object($item['id'], $item['value']), $items));
    }

    public static function object(string $id, string $value): string
    {
        $length = mb_strlen($value, 'UTF-8');

        if ($length > 99) {
            throw new UnsupportedPayloadException("Data object {$id} exceeds 99 characters.");
        }

        return $id.str_pad((string) $length, 2, '0', STR_PAD_LEFT).$value;
    }

    /**
     * @param  list<array{id: string, value: string}>  $items
     */
    public static function find(array $items, string $id): ?string
    {
        foreach ($items as $item) {
            if ($item['id'] === $id) {
                return $item['value'];
            }
        }

        return null;
    }

    /**
     * Set (or insert, keeping ascending ID order) a data object.
     *
     * @param  list<array{id: string, value: string}>  $items
     * @return list<array{id: string, value: string}>
     */
    public static function put(array $items, string $id, string $value): array
    {
        foreach ($items as $index => $item) {
            if ($item['id'] === $id) {
                $items[$index]['value'] = $value;

                return $items;
            }
        }

        $position = count($items);
        foreach ($items as $index => $item) {
            if (strcmp($item['id'], $id) > 0) {
                $position = $index;
                break;
            }
        }

        array_splice($items, $position, 0, [['id' => $id, 'value' => $value]]);

        return $items;
    }

    /**
     * @param  list<array{id: string, value: string}>  $items
     * @param  list<string>  $ids
     * @return list<array{id: string, value: string}>
     */
    public static function without(array $items, array $ids): array
    {
        return array_values(array_filter($items, fn (array $item) => ! in_array($item['id'], $ids, true)));
    }
}
