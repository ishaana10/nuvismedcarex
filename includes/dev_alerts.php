<?php
/**
 * Renders active developer broadcast alerts for the current user as dismissible
 * banners. Included once at the top of the main content area (see header.php).
 * Fails silent if the feature tables are not present yet.
 */
if (!function_exists('nxRenderDevAlerts')) {
    function nxRenderDevAlerts(): void
    {
        try {
            $userId   = $_SESSION['user_id'] ?? '';
            $tenantId = \ClinicFlow\Shared\TenantContext::getTenantId();
            $role     = $_SESSION['user_role'] ?? $_SESSION['role'] ?? 'Doctor';

            $svc = \ClinicFlow\Shared\Container::getInstance()
                ->get(\ClinicFlow\Services\DeveloperAlertService::class);
            $alerts = $svc->getActiveAlertsForUser($userId ?: null, $tenantId, $role);
        } catch (\Throwable $e) {
            return;
        }

        if (empty($alerts)) {
            return;
        }

        $styles = [
            'info'     => ['wrap' => 'bg-blue-50 border-blue-200 text-blue-900',      'icon' => 'campaign',       'chip' => 'text-blue-700'],
            'success'  => ['wrap' => 'bg-emerald-50 border-emerald-200 text-emerald-900', 'icon' => 'check_circle', 'chip' => 'text-emerald-700'],
            'warning'  => ['wrap' => 'bg-amber-50 border-amber-200 text-amber-900',    'icon' => 'warning',        'chip' => 'text-amber-700'],
            'critical' => ['wrap' => 'bg-rose-50 border-rose-200 text-rose-900',       'icon' => 'e911_emergency', 'chip' => 'text-rose-700'],
        ];
        $csrf = htmlspecialchars(getCsrfToken());
        ?>
        <div class="space-y-2 mb-5" id="nx-dev-alerts">
            <?php foreach ($alerts as $a):
                $s = $styles[$a['severity']] ?? $styles['info'];
            ?>
                <div class="nx-dev-alert flex items-start gap-3 rounded-2xl border px-4 py-3 shadow-xs <?= $s['wrap'] ?>" data-alert-id="<?= htmlspecialchars($a['id']) ?>">
                    <span class="material-symbols-outlined text-[20px] mt-0.5 <?= $s['chip'] ?>"><?= $s['icon'] ?></span>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="font-bold text-sm"><?= htmlspecialchars($a['title']) ?></span>
                            <span class="text-[10px] uppercase tracking-wider font-bold px-2 py-0.5 rounded-full bg-white/60 <?= $s['chip'] ?>">From the developers</span>
                        </div>
                        <?php if (!empty($a['body'])): ?>
                            <p class="text-[13px] leading-relaxed mt-0.5 opacity-90"><?= nl2br(htmlspecialchars($a['body'])) ?></p>
                        <?php endif; ?>
                        <?php if (!empty($a['link_url'])): ?>
                            <a href="<?= htmlspecialchars($a['link_url']) ?>" class="inline-flex items-center gap-1 text-[13px] font-semibold underline mt-1 <?= $s['chip'] ?>">
                                <span class="material-symbols-outlined text-[16px]">open_in_new</span>Learn more
                            </a>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="shrink-0 nx-icon-btn -mr-1 -mt-1 opacity-70 hover:opacity-100"
                            title="Dismiss" onclick="nxDismissDevAlert('<?= htmlspecialchars($a['id']) ?>', this)">
                        <span class="material-symbols-outlined text-[18px]">close</span>
                    </button>
                </div>
            <?php endforeach; ?>
        </div>
        <script>
        function nxDismissDevAlert(id, btn) {
            var banner = btn.closest('.nx-dev-alert');
            if (banner) banner.style.display = 'none';
            try {
                var body = new URLSearchParams();
                body.set('action', 'dismiss_alert');
                body.set('alert_id', id);
                body.set('ajax', '1');
                body.set('csrf_token', '<?= $csrf ?>');
                fetch('actions/developer_alert_actions.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() }).catch(function(){});
            } catch (e) {}
        }
        </script>
        <?php
    }
}

nxRenderDevAlerts();
