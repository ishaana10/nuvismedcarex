<?php
/**
 * Renders a consent form's fields, either as inputs to fill in or as a
 * read-only record of what was answered. One renderer so the form a patient
 * signs and the copy they are given always say the same thing.
 */

if (!function_exists('nxConsentField')) {

    /**
     * @param array $field   one field definition
     * @param mixed $value   the answer, when showing a completed form
     * @param bool  $readOnly
     * @param string $prefix input name prefix
     */
    function nxConsentField(array $field, $value = null, bool $readOnly = false, string $prefix = 'answers'): string
    {
        $type = (string) ($field['type'] ?? 'text');
        $key = (string) ($field['key'] ?? '');
        $label = (string) ($field['label'] ?? '');
        $help = (string) ($field['help'] ?? '');
        $required = !empty($field['required']);
        $name = $prefix . '[' . $key . ']';
        $id = 'cf-' . preg_replace('/[^a-z0-9_-]+/i', '-', $prefix . '-' . $key);
        $e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);

        $helpHtml = $help !== ''
            ? '<p class="text-[11px] text-outline mt-1">' . $e($help) . '</p>'
            : '';
        $req = $required ? ' <span class="text-red-600" title="Required">*</span>' : '';

        switch ($type) {
            case 'heading':
                return '<h4 class="text-[13px] font-semibold text-on-surface pt-3 mt-1 border-t border-outline-variant/30">' . $e($label) . '</h4>';

            case 'paragraph':
                return '<p class="text-[13px] text-on-surface/90 leading-relaxed whitespace-pre-line">' . $e($label) . '</p>';

            case 'acknowledge':
            case 'checkbox':
                $checked = !empty($value) ? 'checked' : '';
                if ($readOnly) {
                    $mark = !empty($value) ? '☑' : '☐';
                    return '<p class="text-[13px] text-on-surface flex items-start gap-2"><span class="text-base leading-none">' . $mark . '</span><span>' . $e($label) . '</span></p>';
                }
                return '<label class="flex items-start gap-2.5 text-[13px] text-on-surface cursor-pointer">'
                    . '<input type="checkbox" id="' . $e($id) . '" name="' . $e($name) . '" value="1" ' . $checked
                    . ($required ? ' required' : '')
                    . ' class="mt-0.5 w-4 h-4 rounded border-outline-variant shrink-0">'
                    . '<span>' . $e($label) . $req . $helpHtml . '</span></label>';

            case 'radio':
                $out = '<fieldset><legend class="form-label">' . $e($label) . $req . '</legend>' . $helpHtml
                    . '<div class="flex flex-wrap gap-2 mt-1">';
                foreach ((array) ($field['options'] ?? []) as $i => $option) {
                    if ($readOnly) {
                        $on = (string) $value === (string) $option;
                        $out .= '<span class="px-2.5 py-1 rounded-full border text-[12px] ' . ($on ? 'bg-blue-50 border-blue-300 text-blue-900 font-semibold' : 'border-outline-variant/40 text-outline') . '">'
                            . ($on ? '● ' : '○ ') . $e($option) . '</span>';
                        continue;
                    }
                    $out .= '<label class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full border border-outline-variant/40 text-[12px] cursor-pointer hover:border-outline-variant">'
                        . '<input type="radio" name="' . $e($name) . '" value="' . $e($option) . '" '
                        . ((string) $value === (string) $option ? 'checked ' : '')
                        . ($required && $i === 0 ? 'required ' : '')
                        . 'class="w-3.5 h-3.5 border-outline-variant">'
                        . '<span>' . $e($option) . '</span></label>';
                }
                return $out . '</div></fieldset>';

            case 'select':
                if ($readOnly) {
                    return '<div><div class="form-label">' . $e($label) . '</div><div class="text-[13px] text-on-surface font-medium">' . ($value !== '' ? $e($value) : '—') . '</div></div>';
                }
                $out = '<div><label class="form-label" for="' . $e($id) . '">' . $e($label) . $req . '</label>'
                    . '<select id="' . $e($id) . '" name="' . $e($name) . '" ' . ($required ? 'required ' : '') . 'class="form-select w-full">'
                    . '<option value="">— choose —</option>';
                foreach ((array) ($field['options'] ?? []) as $option) {
                    $out .= '<option value="' . $e($option) . '"' . ((string) $value === (string) $option ? ' selected' : '') . '>' . $e($option) . '</option>';
                }
                return $out . '</select>' . $helpHtml . '</div>';

            case 'textarea':
                if ($readOnly) {
                    return '<div><div class="form-label">' . $e($label) . '</div><div class="text-[13px] text-on-surface whitespace-pre-line">' . ($value !== '' ? $e($value) : '—') . '</div></div>';
                }
                return '<div><label class="form-label" for="' . $e($id) . '">' . $e($label) . $req . '</label>'
                    . '<textarea id="' . $e($id) . '" name="' . $e($name) . '" rows="3" ' . ($required ? 'required ' : '')
                    . 'class="form-textarea w-full">' . $e($value) . '</textarea>' . $helpHtml . '</div>';

            case 'date':
                if ($readOnly) {
                    return '<div><div class="form-label">' . $e($label) . '</div><div class="text-[13px] text-on-surface font-medium">' . ($value !== '' ? $e(nxDate((string) $value)) : '—') . '</div></div>';
                }
                return '<div><label class="form-label" for="' . $e($id) . '">' . $e($label) . $req . '</label>'
                    . '<input type="date" id="' . $e($id) . '" name="' . $e($name) . '" value="' . $e($value) . '" '
                    . ($required ? 'required ' : '') . 'class="form-input w-full">' . $helpHtml . '</div>';

            case 'initials':
            case 'text':
            default:
                if ($readOnly) {
                    return '<div><div class="form-label">' . $e($label) . '</div><div class="text-[13px] text-on-surface font-medium ' . ($type === 'initials' ? 'font-mono uppercase' : '') . '">' . ($value !== '' ? $e($value) : '—') . '</div></div>';
                }
                return '<div><label class="form-label" for="' . $e($id) . '">' . $e($label) . $req . '</label>'
                    . '<input type="text" id="' . $e($id) . '" name="' . $e($name) . '" value="' . $e($value) . '" '
                    . ($type === 'initials' ? 'maxlength="6" class="form-input w-28 uppercase font-mono" ' : 'maxlength="300" class="form-input w-full" ')
                    . ($required ? 'required' : '') . '>' . $helpHtml . '</div>';
        }
    }

    /** The whole body of a form: intro, fields, declaration. */
    function nxConsentBody(array $form, array $answers = [], bool $readOnly = false, string $prefix = 'answers'): string
    {
        $out = '';
        $intro = trim((string) ($form['intro'] ?? ''));
        if ($intro !== '') {
            $out .= '<div class="text-[13px] text-on-surface/90 leading-relaxed whitespace-pre-line bg-surface-container-low border border-outline-variant/30 rounded-xl p-3.5">'
                . htmlspecialchars($intro) . '</div>';
        }
        foreach ((array) ($form['fields'] ?? []) as $field) {
            $key = (string) ($field['key'] ?? '');
            $out .= '<div>' . nxConsentField($field, $answers[$key] ?? ($field['type'] === 'checkbox' || $field['type'] === 'acknowledge' ? false : ''), $readOnly, $prefix) . '</div>';
        }
        $declaration = trim((string) ($form['declaration'] ?? ''));
        if ($declaration !== '') {
            $out .= '<p class="text-[13px] font-medium text-on-surface border-l-2 border-primary pl-3 whitespace-pre-line">'
                . htmlspecialchars($declaration) . '</p>';
        }
        return $out;
    }
}
