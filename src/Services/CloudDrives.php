<?php
declare(strict_types=1);

namespace ClinicFlow\Services;

/**
 * One description of the cloud drives documents can be stored in, shared by the
 * Developer screen, the save action and the connection tests, so a field only
 * has to be added in one place.
 *
 * Values live in clinic_settings through FileUploadService, keyed per clinic.
 */
final class CloudDrives
{
    /**
     * key => [label, icon, tone, blurb, docs, fields]
     * Each field: key => [label, type(text|secret|textarea), placeholder, hint, required]
     */
    public const DRIVES = [
        'onedrive' => [
            'label' => 'Microsoft OneDrive',
            'icon'  => 'cloud_queue',
            'tone'  => 'sky',
            'blurb' => 'Uploads go to OneDrive through the Microsoft Graph API, using an app registered in Azure.',
            'docs'  => 'https://learn.microsoft.com/graph/auth-register-app-v2',
            'fields' => [
                'cloud_onedrive_client_id' => [
                    'label' => 'Application (client) ID', 'type' => 'text',
                    'placeholder' => '00000000-0000-0000-0000-000000000000',
                    'hint' => 'From the app registration overview in Azure.', 'required' => true,
                ],
                'cloud_onedrive_client_secret' => [
                    'label' => 'Client secret', 'type' => 'secret',
                    'placeholder' => '', 'hint' => 'The secret value, not its ID.', 'required' => true,
                ],
                'cloud_onedrive_tenant_id' => [
                    'label' => 'Directory (tenant) ID', 'type' => 'text',
                    'placeholder' => 'common', 'hint' => 'Use "common" for personal accounts.', 'required' => false,
                ],
            ],
        ],
        'googledrive' => [
            'label' => 'Google Drive',
            'icon'  => 'add_to_drive',
            'tone'  => 'amber',
            'blurb' => 'Staff pick files with the Google Picker, and the server can upload on its own with a refresh token or a service account.',
            'docs'  => 'https://developers.google.com/drive/api/quickstart/js',
            'fields' => [
                'cloud_googledrive_client_id' => [
                    'label' => 'OAuth client ID', 'type' => 'text',
                    'placeholder' => '1234567890-abc.apps.googleusercontent.com',
                    'hint' => 'Needed for the file picker staff see.', 'required' => true,
                ],
                'cloud_googledrive_api_key' => [
                    'label' => 'API key', 'type' => 'secret',
                    'placeholder' => '', 'hint' => 'Used by the picker and by the connection test.', 'required' => true,
                ],
                'cloud_googledrive_client_secret' => [
                    'label' => 'OAuth client secret', 'type' => 'secret',
                    'placeholder' => '', 'hint' => 'Only needed for server-side uploads.', 'required' => false,
                ],
                'cloud_googledrive_refresh_token' => [
                    'label' => 'Refresh token', 'type' => 'secret',
                    'placeholder' => '', 'hint' => 'Lets the server upload without anyone signing in.', 'required' => false,
                ],
                'cloud_googledrive_folder_id' => [
                    'label' => 'Target folder ID', 'type' => 'text',
                    'placeholder' => '1A2b3C...', 'hint' => 'Leave empty to upload to the drive root.', 'required' => false,
                ],
                'cloud_googledrive_service_account_json' => [
                    'label' => 'Service account JSON', 'type' => 'textarea',
                    'placeholder' => '{"type": "service_account", ...}',
                    'hint' => 'An alternative to the refresh token for unattended uploads.', 'required' => false,
                ],
            ],
        ],
    ];

    /** Field keys belonging to one drive. */
    public static function keys(string $drive): array
    {
        return array_keys(self::DRIVES[$drive]['fields'] ?? []);
    }

    /** Every field key across every drive. */
    public static function allKeys(): array
    {
        $keys = [];
        foreach (array_keys(self::DRIVES) as $drive) {
            $keys = array_merge($keys, self::keys($drive));
        }
        return $keys;
    }

    public static function isSecret(string $key): bool
    {
        foreach (self::DRIVES as $drive) {
            if (isset($drive['fields'][$key])) {
                return in_array($drive['fields'][$key]['type'], ['secret', 'textarea'], true);
            }
        }
        return false;
    }

    /**
     * How a drive is doing: configured when every required field has a value.
     *
     * @return array{configured:bool, missing:string[], filled:int, total:int}
     */
    public static function status(string $drive, array $settings): array
    {
        $fields = self::DRIVES[$drive]['fields'] ?? [];
        $missing = [];
        $filled = 0;
        foreach ($fields as $key => $meta) {
            $has = trim((string) ($settings[$key] ?? '')) !== '';
            if ($has) {
                $filled++;
            } elseif (!empty($meta['required'])) {
                $missing[] = $meta['label'];
            }
        }
        return [
            'configured' => $missing === [],
            'missing'    => $missing,
            'filled'     => $filled,
            'total'      => count($fields),
        ];
    }

    /** Show enough of a stored secret to recognise it, never enough to use it. */
    public static function mask(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $len = strlen($value);
        return $len <= 4 ? str_repeat('•', $len) : str_repeat('•', 8) . substr($value, -4);
    }
}
