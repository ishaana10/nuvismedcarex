<?php
/**
 * Live notifications (Server-Sent Events).
 *
 * Pushes new patient registrations and appointment bookings for the active
 * clinic. The stream closes itself after ~25 seconds so hosts don't kill a
 * long-running PHP process; the browser reconnects automatically and sends
 * back the id of the last event it saw (Last-Event-ID), so nothing that
 * happens during the gap is missed and nothing is delivered twice.
 */

require_once __DIR__ . '/../../core/SessionBootstrap.php';
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, no-transform');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // nginx: don't buffer the stream

while (ob_get_level() > 0) {
    ob_end_flush();
}
ob_implicit_flush(true);

if (empty($_SESSION['authenticated'])) {
    echo "data: " . json_encode(['error' => 'Unauthorized', 'status' => 'unauthenticated']) . "\n\n";
    flush();
    exit;
}

$pdo = getDB();
$tenantId = \ClinicFlow\Shared\TenantContext::getTenantId();

// Let other requests for this session proceed while we hold the connection open
session_write_close();

/** Is this clinic using live notifications? Its own choice, else the installation default. */
function nxSseEnabled(PDO $pdo, string $tenantId): bool
{
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM tenant_settings WHERE tenant_id = :tid AND setting_key = 'sse_notifications_enabled' LIMIT 1");
        $stmt->execute(['tid' => $tenantId]);
        $val = $stmt->fetchColumn();
        if ($val !== false && $val !== null && $val !== '') {
            return $val === '1' || $val === 'true';
        }
        $stmt = $pdo->query("SELECT setting_value FROM clinic_settings WHERE setting_key = 'sse_notifications_enabled' LIMIT 1");
        $val = $stmt ? $stmt->fetchColumn() : false;
        if ($val !== false && $val !== null && $val !== '') {
            return $val === '1' || $val === 'true';
        }
    } catch (\Throwable $e) {
        error_log('[sse] enabled check: ' . $e->getMessage());
    }
    return true;
}

$send = static function (string $event, array $payload, ?string $id = null): void {
    if ($id !== null) {
        echo 'id: ' . $id . "\n";
    }
    echo 'data: ' . json_encode($payload) . "\n\n";
    flush();
};

echo "retry: 3000\n\n"; // reconnect quickly if the connection drops

if (!nxSseEnabled($pdo, $tenantId)) {
    $send('message', ['status' => 'disabled', 'enabled' => false, 'tenant_id' => $tenantId]);
    exit;
}

/**
 * Where to resume from. The event id is "<cursor timestamp>|<tenant>"; the
 * tenant is included so a stream resumed after switching clinics starts fresh
 * rather than replaying another clinic's events.
 */
$cursor = null;
$lastEventId = (string) ($_SERVER['HTTP_LAST_EVENT_ID'] ?? $_GET['lastEventId'] ?? '');
if ($lastEventId !== '' && str_contains($lastEventId, '|')) {
    [$ts, $forTenant] = explode('|', $lastEventId, 2);
    if ($forTenant === $tenantId && strtotime($ts) !== false) {
        $cursor = $ts;
    }
}
$isResume = $cursor !== null;
if ($cursor === null) {
    // A fresh connection only reports what happens from now on
    $cursor = date('Y-m-d H:i:s');
}

$send('message', [
    'status'    => $isResume ? 'resumed' : 'connected',
    'enabled'   => true,
    'tenant_id' => $tenantId,
    'timestamp' => date('Y-m-d H:i:s'),
]);

$startTime = time();
$maxDuration = 25;
$lastEnabledCheck = time();

while ((time() - $startTime) < $maxDuration) {
    if (connection_aborted()) {
        break;
    }

    // Re-check every 10s rather than every loop, to keep the query load down
    if (time() - $lastEnabledCheck >= 10) {
        $lastEnabledCheck = time();
        if (!nxSseEnabled($pdo, $tenantId)) {
            $send('message', ['status' => 'disabled', 'enabled' => false, 'tenant_id' => $tenantId]);
            break;
        }
    }

    try {
        // Both queries read from the same cursor, and the cursor only moves
        // once both have been collected — otherwise advancing it for patients
        // would skip appointments booked a moment earlier.
        $events = [];

        $stmt = $pdo->prepare(
            "SELECT id, mrn, first_name, last_name, created_at
               FROM patients
              WHERE tenant_id = :tid AND created_at > :cursor
              ORDER BY created_at ASC LIMIT 25"
        );
        $stmt->execute(['tid' => $tenantId, 'cursor' => $cursor]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $fullName = trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
            $events[] = [
                'at'      => (string) $p['created_at'],
                'payload' => [
                    'type'         => 'patient_registered',
                    'title'        => 'New patient registered',
                    'message'      => trim($fullName . ' (' . ($p['mrn'] ?? '') . ')') . ' has been added.',
                    'patient_id'   => $p['id'],
                    'patient_name' => $fullName,
                    'mrn'          => $p['mrn'] ?? '',
                    'timestamp'    => $p['created_at'],
                    'tenant_id'    => $tenantId,
                    'enabled'      => true,
                ],
            ];
        }

        $stmt = $pdo->prepare(
            "SELECT id, patient_id, patient_name, doctor_name, appointment_date, time, created_at
               FROM appointments
              WHERE tenant_id = :tid AND created_at > :cursor
              ORDER BY created_at ASC LIMIT 25"
        );
        $stmt->execute(['tid' => $tenantId, 'cursor' => $cursor]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $when = trim((string) ($a['appointment_date'] ?? '') . ' ' . (string) ($a['time'] ?? ''));
            $events[] = [
                'at'      => (string) $a['created_at'],
                'payload' => [
                    'type'         => 'appointment_booked',
                    'title'        => 'Appointment booked',
                    'message'      => ($a['patient_name'] ?? 'A patient') . ' with ' . ($a['doctor_name'] ?? 'a clinician') . ($when !== '' ? ', ' . $when : '') . '.',
                    'patient_id'   => $a['patient_id'],
                    'patient_name' => $a['patient_name'] ?? '',
                    'timestamp'    => $a['created_at'],
                    'tenant_id'    => $tenantId,
                    'enabled'      => true,
                ],
            ];
        }

        usort($events, fn($x, $y) => strcmp($x['at'], $y['at']));
        foreach ($events as $e) {
            $send('message', $e['payload'], $e['at'] . '|' . $tenantId);
            if ($e['at'] > $cursor) {
                $cursor = $e['at'];
            }
        }
    } catch (\Throwable $e) {
        error_log('[sse] query: ' . $e->getMessage());
    }

    echo ": ping\n\n"; // keeps proxies from closing an idle connection
    flush();
    sleep(2);
}

// Tell the browser where to pick up, even if nothing was sent this round
echo 'id: ' . $cursor . '|' . $tenantId . "\n";
echo "data: " . json_encode(['status' => 'idle', 'enabled' => true]) . "\n\n";
flush();
