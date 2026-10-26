<?php
declare(strict_types=1);

namespace ClinicFlow\Services;

/**
 * How a clinic sets up its inventory: the categories and units it stocks,
 * reorder and expiry warning levels, and any extra fields it wants to record
 * against an item (manufacturer, storage temperature, supplier…).
 *
 * The editor on Administration → Inventory writes these; the add/edit item
 * forms and the stock list read them back.
 */
final class InventorySetup
{
    public const DEFAULT_CATEGORIES = ['Pharmaceuticals', 'Surgical supplies', 'Medical equipment', 'Diagnostics', 'Consumables'];
    public const DEFAULT_UNITS = ['Box', 'Bottle', 'Pack', 'Piece', 'Vial', 'Ampoule', 'Tablet', 'Strip', 'Tube', 'Litre'];

    /** Field types a clinic can add, and how each is rendered. */
    public const FIELD_TYPES = [
        'text'   => 'Text',
        'number' => 'Number',
        'date'   => 'Date',
        'select' => 'Choice list',
        'textarea' => 'Long text',
    ];

    public const MAX_FIELDS = 12;

    /** "A, B,, A " → "A, B" (trimmed, de-duplicated, order kept). */
    public static function cleanCategoryList(string $raw): string
    {
        $parts = preg_split('/\s*,\s*/', trim($raw)) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = trim(preg_replace('/\s+/', ' ', $p));
            if ($p === '' || mb_strlen($p) > 60) continue;
            foreach ($out as $seen) {
                if (mb_strtolower($seen) === mb_strtolower($p)) continue 2;
            }
            $out[] = $p;
            if (count($out) >= 60) break;
        }
        return implode(', ', $out);
    }

    /** @return string[] */
    public static function parseList(?string $raw, array $fallback): array
    {
        $list = array_values(array_filter(array_map('trim', preg_split('/\s*,\s*/', (string) $raw) ?: [])));
        return $list ?: $fallback;
    }

    /**
     * Validate the custom-field definition coming from the builder.
     * Anything malformed is dropped rather than stored, so the item forms
     * can trust what they read.
     */
    public static function cleanFieldJson(string $json): string
    {
        $in = json_decode($json, true);
        if (!is_array($in)) {
            return '[]';
        }
        $out = [];
        $used = [];
        foreach ($in as $row) {
            if (!is_array($row)) continue;
            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') continue;
            $label = mb_substr($label, 0, 60);

            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') $name = $label;
            $name = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $name) ?? '');
            $name = trim($name, '_');
            if ($name === '' || is_numeric($name[0])) $name = 'field_' . $name;
            $name = mb_substr($name, 0, 40);
            $base = $name;
            $n = 2;
            while (in_array($name, $used, true)) {
                $name = $base . '_' . $n++;
            }
            $used[] = $name;

            $type = (string) ($row['type'] ?? 'text');
            if (!isset(self::FIELD_TYPES[$type])) $type = 'text';

            $field = [
                'name'  => $name,
                'label' => $label,
                'type'  => $type,
                'placeholder' => mb_substr(trim((string) ($row['placeholder'] ?? '')), 0, 80),
                'required' => !empty($row['required']),
            ];
            if ($type === 'select') {
                $opts = [];
                $raw = $row['options'] ?? [];
                foreach (is_array($raw) ? $raw : preg_split('/\s*,\s*/', (string) $raw) as $o) {
                    $o = trim((string) $o);
                    if ($o !== '' && !in_array($o, $opts, true)) $opts[] = mb_substr($o, 0, 60);
                }
                if (!$opts) continue; // a choice list with nothing to choose is not usable
                $field['options'] = array_slice($opts, 0, 40);
            }
            $out[] = $field;
            if (count($out) >= self::MAX_FIELDS) break;
        }
        return json_encode(array_values($out));
    }

    /** @return array<int, array> the clinic's custom fields, safe to render */
    public static function fields(?string $json): array
    {
        $decoded = json_decode(self::cleanFieldJson((string) ($json ?: '[]')), true);
        return is_array($decoded) ? $decoded : [];
    }

    /** Validate one item's custom-field values against the definition. */
    public static function valuesFromRequest(array $fields, array $input): array
    {
        $out = [];
        foreach ($fields as $f) {
            $key = $f['name'];
            $v = trim((string) ($input[$key] ?? ''));
            if ($v === '') continue;
            if ($f['type'] === 'number') {
                if (!is_numeric($v)) continue;
                $v = (string) (0 + $v);
            } elseif ($f['type'] === 'select' && !in_array($v, $f['options'] ?? [], true)) {
                continue;
            } elseif ($f['type'] === 'date' && !strtotime($v)) {
                continue;
            }
            $out[$key] = mb_substr($v, 0, 500);
        }
        return $out;
    }
}
