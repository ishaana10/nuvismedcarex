<?php require_once __DIR__ . '/../config/version.php'; ?>
</main>
</div> <!-- closing flex-1 -->
<footer class="bg-surface-container-lowest border-t border-outline-variant/30 px-6 py-3 text-center text-xs text-outline">
    <div class="flex items-center justify-between">
        <div class="flex items-center gap-2">
            <span>&copy; <?= date('Y') ?> Nuvis Medcare X by Nuvis Technologies. All rights reserved.</span>
            <span class="px-2 py-0.5 rounded-full bg-primary/10 text-primary font-mono text-[10px] font-bold border border-primary/20">
                <?= defined('APP_VERSION') ? APP_VERSION : 'v2.1.0' ?>
            </span>
        </div>
        <span>A2 Hosting PHP 8.1+ Ready</span>
    </div>
</footer>
</body>
</html>
