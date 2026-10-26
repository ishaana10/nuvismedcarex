<?php
/**
 * Renders the extra item fields a clinic has defined on
 * Administration → Inventory, for the add and edit item forms.
 */
if (!function_exists('nxRenderInventoryFields')) {
    /**
     * @param array  $fields  definitions from InventorySetup::fields()
     * @param array  $values  current values, keyed by field name
     * @param string $idPrefix unique per form, so labels bind to the right input
     */
    function nxRenderInventoryFields(array $fields, array $values = [], string $idPrefix = 'cf'): string
    {
        if (!$fields) return '';
        $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $cls = 'w-full bg-slate-50 px-3 py-2 rounded-lg text-[13px] border border-slate-200 text-slate-900 focus:ring-2 focus:ring-blue-500/25 focus:border-blue-500 focus:outline-none';

        $out = '<div class="border-t border-slate-200 pt-4 mt-1">'
             . '<h4 class="text-[13px] font-semibold text-slate-800 mb-2.5">Additional details</h4>'
             . '<div class="grid grid-cols-1 md:grid-cols-2 gap-3">';

        foreach ($fields as $f) {
            $name = $f['name'];
            $id = $idPrefix . '-' . $name;
            $val = (string) ($values[$name] ?? '');
            $req = !empty($f['required']) ? ' required' : '';
            $input = 'name="custom_fields[' . $e($name) . ']" id="' . $e($id) . '" data-cf="' . $e($name) . '" class="' . $cls . '"' . $req;

            $out .= '<div' . ($f['type'] === 'textarea' ? ' class="md:col-span-2"' : '') . '>'
                  . '<label for="' . $e($id) . '" class="block text-xs font-medium text-slate-600 mb-1">' . $e($f['label']);
            if (!empty($f['required'])) $out .= ' <span class="text-red-600" aria-hidden="true">*</span>';
            $out .= '</label>';

            switch ($f['type']) {
                case 'select':
                    $out .= '<select ' . $input . '><option value="">Not set</option>';
                    foreach ($f['options'] ?? [] as $opt) {
                        $sel = ($opt === $val) ? ' selected' : '';
                        $out .= '<option value="' . $e($opt) . '"' . $sel . '>' . $e($opt) . '</option>';
                    }
                    $out .= '</select>';
                    break;
                case 'textarea':
                    $out .= '<textarea rows="2" placeholder="' . $e($f['placeholder'] ?? '') . '" ' . $input . '>' . $e($val) . '</textarea>';
                    break;
                case 'number':
                    $out .= '<input type="number" step="any" value="' . $e($val) . '" placeholder="' . $e($f['placeholder'] ?? '') . '" ' . $input . '>';
                    break;
                case 'date':
                    $out .= '<input type="date" value="' . $e($val) . '" ' . $input . '>';
                    break;
                default:
                    $out .= '<input type="text" value="' . $e($val) . '" placeholder="' . $e($f['placeholder'] ?? '') . '" maxlength="500" ' . $input . '>';
            }
            $out .= '</div>';
        }
        return $out . '</div></div>';
    }
}
