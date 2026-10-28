<?php
declare(strict_types=1);

namespace ClinicFlow\Services\Cloud;

/** Picks the provider the installation is set to use. */
final class Cloud
{
    /** Provider object for the active (or given) provider, or null for local-only storage. */
    public static function provider(?string $id = null, ?array $cfg = null): ?CloudProvider
    {
        $cfg = $cfg ?? CloudConfig::get();
        $id = $id ?? $cfg['provider'];
        switch ($id) {
            case 'gdrive':   return new GoogleDriveProvider($cfg);
            case 'onedrive': return new OneDriveProvider($cfg);
            case 'dropbox':  return new DropboxProvider($cfg);
            case 'webdav':   return new WebDavProvider($cfg);
            default:         return null;
        }
    }

    public static function labels(): array
    {
        return ['local' => 'This server only', 'gdrive' => 'Google Drive', 'onedrive' => 'OneDrive', 'dropbox' => 'Dropbox', 'webdav' => 'WebDAV (Nextcloud, ownCloud…)'];
    }
}
