<?php
/**
 * Move encrypted clinical notes/vitals from the old public default key to your own app_key.
 *
 *   php bin/reencrypt.php                    show where things stand (changes nothing)
 *   php bin/reencrypt.php --dry-run          report what would change (changes nothing)
 *   php bin/reencrypt.php --run              re-encrypt (BACK UP THE DATABASE FIRST)
 *   php bin/reencrypt.php --run --encrypt-plaintext   also encrypt values stored unencrypted
 *
 * Safe to stop and run again. See config/config.example.php for how to set app_key.
 * The same tool is available to Developers in the app (Developer > Encryption).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/autoloader.php';

use ClinicFlow\Services\EncryptionMigrationService;
use ClinicFlow\Utils\Encryption;

$args = array_slice($argv, 1);
$run = in_array('--run', $args, true);
$dry = in_array('--dry-run', $args, true);
$plain = in_array('--encrypt-plaintext', $args, true);

$svc = new EncryptionMigrationService(getDB());

$show = static function (array $s): void {
    echo 'Key configured : ' . ($s['key_configured'] ? 'yes' : 'NO (the public default key is in use)') . "\n";
    echo 'Legacy fallback: ' . ($s['legacy_fallback'] ? 'on (old rows stay readable)' : 'off') . "\n";
    foreach ($s['columns'] as $name => $c) {
        printf("  %-24s current key %-4d old default key %-4d unencrypted %-4d unreadable %-4d\n",
            $name, $c['current_key'], $c['legacy_key'], $c['plaintext'], $c['unreadable']);
    }
    echo "To re-encrypt: {$s['needs_migration']}   Unreadable by any known key: {$s['totals']['unreadable']}\n";
};

$show($svc->status());
if (!$run && !$dry) {
    echo "\nNothing changed. Use --dry-run to preview or --run to re-encrypt.\n";
    exit(0);
}
if (Encryption::isUsingDefaultKey()) {
    fwrite(STDERR, "\nRefusing to continue: no app_key is configured, so re-encrypting would only re-lock the data with the public key.\n");
    exit(2);
}

echo "\n" . ($dry ? "DRY RUN - nothing will be written\n" : "Re-encrypting...\n");
$total = 0;
do {
    $r = $svc->migrate(30, $plain, $dry);
    if (isset($r['error'])) { fwrite(STDERR, $r['error'] . "\n"); exit(2); }
    $total += $r['migrated'];
    echo ($dry ? "  would re-encrypt " : "  re-encrypted ") . "{$r['migrated']}" . ($dry ? "" : " (remaining {$r['remaining']})") . "\n";
    if ($dry) { break; }
} while (!$r['done'] && $r['migrated'] > 0);

echo "\n" . ($dry ? 'Would re-encrypt' : 'Re-encrypted') . " {$total} value(s).\n";
$final = $svc->status();
$show($final);
if (!$dry && $final['needs_migration'] === 0) {
    echo "\nDone. Once you are happy, you may set 'allow_legacy_key' => false in your config so the old public key is never used again.\n";
}
exit($final['totals']['unreadable'] > 0 ? 1 : 0);
