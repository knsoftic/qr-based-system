<?php

namespace App\Payments\Providers;

use App\Payments\Emv\Tlv;
use App\Payments\Exceptions\UnsupportedPayloadException;

/**
 * Shared helpers for describe() / applyEdits() in TLV based adapters.
 */
trait DescribesFields
{
    /**
     * @param  list<array{id: string, value: string}>  $items
     * @param  callable(string): array{0: string, 1: string, 2: string}  $lookup  tag => [name, description, behaviour]
     * @param  callable(string): array  $catalogFor  sub-field catalog of a template tag ([] = not a template)
     */
    protected function rows(array $items, callable $lookup, callable $catalogFor, string $prefix = ''): array
    {
        $rows = [];

        foreach ($items as $item) {
            [$name, $description, $behaviour] = $lookup($item['id']);
            $children = [];

            if ($sub = $catalogFor($item['id'])) {
                try {
                    $children = $this->rows(
                        Tlv::parse($item['value']),
                        fn (string $id) => $sub[$id] ?? ['Sub-field '.$id, 'Scheme specific data.', 'fixed'],
                        fn () => [],
                        $prefix.$item['id'].'.',
                    );
                } catch (UnsupportedPayloadException) {
                    $children = [];
                }
            }

            $rows[] = [
                'tag' => $prefix.$item['id'],
                'name' => $name,
                'description' => $description,
                'value' => $item['value'],
                'present' => true,
                'behaviour' => $behaviour,
                'children' => $children,
            ];
        }

        return $rows;
    }

    /**
     * Optional editable fields that are not in the QR yet, so the admin can add them.
     */
    protected function missingEditableRows(array $rows, callable $lookup): array
    {
        $present = array_column($rows, 'tag');

        foreach ($this->editableFields() as $tag => $field) {
            if (! in_array((string) $tag, $present, true)) {
                [$name, $description, $behaviour] = $lookup((string) $tag);
                $rows[] = [
                    'tag' => (string) $tag, 'name' => $name, 'description' => $description,
                    'value' => null, 'present' => false, 'behaviour' => $behaviour, 'children' => [],
                ];
            }
        }

        usort($rows, fn (array $a, array $b) => strcmp($a['tag'], $b['tag']));

        return $rows;
    }

    /**
     * Put / remove the edited values, after checking each against editableFields().
     *
     * @param  list<array{id: string, value: string}>  $items
     */
    protected function editItems(array $items, array $values, callable $normalise): array
    {
        $editable = $this->editableFields();

        foreach ($values as $tag => $value) {
            $tag = str_pad((string) $tag, 2, '0', STR_PAD_LEFT);
            $field = $editable[$tag] ?? throw new UnsupportedPayloadException("Field {$tag} cannot be edited.");
            $value = $normalise($tag, trim((string) $value));

            if ($value === '') {
                if ($field['required']) {
                    throw new UnsupportedPayloadException("{$field['label']} is required.");
                }
                $items = Tlv::without($items, [$tag]);

                continue;
            }

            if (mb_strlen($value, 'UTF-8') > $field['max']) {
                throw new UnsupportedPayloadException("{$field['label']} can be at most {$field['max']} characters.");
            }

            $items = Tlv::put($items, $tag, $value);
        }

        return $items;
    }
}
