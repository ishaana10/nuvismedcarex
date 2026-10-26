<?php
/**
 * Single source of truth for app navigation.
 * Used by the sidebar (desktop rail + mobile drawer) and the command palette.
 *
 * Each item: id (matches $activePage), label, icon, url, group,
 * optional 'roles' (only shown to those roles) and 'keywords' for search.
 */
if (!function_exists('nxNavItems')) {
    function nxNavItems(string $role): array {
        $items = [
            ['id' => 'dashboard',      'label' => 'Dashboard',          'icon' => 'space_dashboard',       'url' => 'index.php',          'group' => 'Clinic',     'keywords' => 'home overview today queue'],
            ['id' => 'patients',       'label' => 'Patients',           'icon' => 'groups',                'url' => 'patients.php',       'group' => 'Clinic',     'keywords' => 'records charts directory'],
            ['id' => 'calendar',       'label' => 'Appointments',       'icon' => 'calendar_month',        'url' => 'appointment.php',    'group' => 'Clinic',     'keywords' => 'schedule booking calendar'],
            ['id' => 'clinical-visit', 'label' => 'Clinical encounter', 'icon' => 'stethoscope',           'url' => 'clinical_visit.php', 'group' => 'Clinic',     'keywords' => 'visit soap vitals prescription notes'],
            ['id' => 'billing',        'label' => 'Billing',            'icon' => 'receipt_long',          'url' => 'billing.php',        'group' => 'Operations', 'keywords' => 'invoices payments vms fiscal financials'],
            ['id' => 'inventory',      'label' => 'Inventory',          'icon' => 'inventory_2',           'url' => 'inventory.php',      'group' => 'Operations', 'keywords' => 'stock pharmacy medicine batch expiry'],
            ['id' => 'uploads',        'label' => 'Documents',          'icon' => 'folder_open',           'url' => 'uploads.php',        'group' => 'Operations', 'keywords' => 'upload files lab results scans'],
            ['id' => 'settings',       'label' => 'Settings',           'icon' => 'tune',                  'url' => 'settings.php',       'group' => 'System',     'keywords' => 'preferences clinic profile signature'],
            ['id' => 'admin',          'label' => 'Administration',     'icon' => 'admin_panel_settings',  'url' => 'admin.php',          'group' => 'System',     'keywords' => 'users staff audit log email tenants'],
            ['id' => 'developer',      'label' => 'Developer',          'icon' => 'terminal',              'url' => 'developer.php',      'group' => 'System',     'keywords' => 'git redis migrations debug', 'roles' => ['Developer']],
        ];
        if (function_exists('nxCan') && !nxCan('users') && nxCan('settings')) {
            foreach ($items as &$it) { if ($it['id'] === 'admin') $it['url'] = 'admin.php?tab=clinic'; }
            unset($it);
        }
        return array_values(array_filter($items, function ($i) use ($role) {
            if (!empty($i['roles']) && !in_array($role, $i['roles'], true)) return false;
            // Hide areas this role has no access to in the current clinic
            if ($i['id'] === 'admin' && function_exists('nxCan')) {
                return nxCan('users') || nxCan('settings');
            }
            if (function_exists('nxCan') && defined('NX_PAGE_ACCESS') && isset(NX_PAGE_ACCESS[$i['id']])) {
                return nxCan(NX_PAGE_ACCESS[$i['id']][0], 'view');
            }
            return true;
        }));
    }
}
