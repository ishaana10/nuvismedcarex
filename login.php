<?php
/**
 * Sign-in page
 */
require_once __DIR__ . '/core/SessionBootstrap.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/version.php';
require_once __DIR__ . '/includes/security.php';

if (isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true) {
    header("Location: index.php");
    exit;
}

$toast = getToast();
$csrfToken = generateCsrfToken();
$expired = isset($_GET['expired']);
?>
<!DOCTYPE html>
<html class="h-full" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Sign in - Nuvis Medcare X</title>
    <?php include __DIR__ . '/includes/theme_head.php'; ?>
</head>
<body class="min-h-full font-sans text-slate-900 bg-background">

<div class="min-h-screen grid lg:grid-cols-[minmax(0,1fr)_minmax(0,1.05fr)]">
    <!-- Form -->
    <main class="flex flex-col px-6 sm:px-12 py-8">
        <div class="flex items-center justify-between">
            <img src="assets/images/NuvisMedcareX_banner.jpg" alt="Nuvis Medcare X" class="h-11 w-auto object-contain mix-blend-multiply dark:mix-blend-normal dark:bg-white dark:rounded-lg dark:px-2">
            <button type="button" class="nx-icon-btn" id="login-theme" aria-label="Switch colour theme">
                <span class="material-symbols-outlined" id="login-theme-icon">dark_mode</span>
            </button>
        </div>

        <div class="flex-1 flex items-center">
            <div class="w-full max-w-[400px] mx-auto py-10">
                <h1 class="text-[28px] leading-tight font-semibold tracking-tight text-slate-900">Sign in to your clinic</h1>
                <p class="mt-2 text-sm text-slate-500">Use the email address your administrator registered for you.</p>

                <?php if ($toast): ?>
                    <div class="mt-6 flex gap-3 rounded-xl border px-4 py-3 text-[13px] <?= $toast['type'] === 'error' ? 'border-red-200 bg-red-50 text-red-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800' ?>" role="<?= $toast['type'] === 'error' ? 'alert' : 'status' ?>">
                        <span class="material-symbols-outlined text-[20px]"><?= $toast['type'] === 'error' ? 'error' : 'check_circle' ?></span>
                        <div>
                            <div class="font-semibold"><?= htmlspecialchars($toast['title']) ?></div>
                            <div class="opacity-90"><?= htmlspecialchars($toast['message']) ?></div>
                        </div>
                    </div>
                <?php elseif ($expired): ?>
                    <div class="mt-6 flex gap-3 rounded-xl border border-amber-200 bg-amber-50 text-amber-800 px-4 py-3 text-[13px]" role="status">
                        <span class="material-symbols-outlined text-[20px]">schedule</span>
                        <div>You were signed out after a period of inactivity. Sign in again to continue.</div>
                    </div>
                <?php endif; ?>

                <form action="actions/login.php" method="POST" class="mt-8 space-y-5" id="login-form" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                    <div>
                        <label class="form-label" for="login-email">Email</label>
                        <input id="login-email" type="email" name="email" required autocomplete="username" inputmode="email" autofocus
                               class="form-input !py-3 !text-sm" placeholder="name@clinic.com">
                        <p class="hidden mt-1.5 text-xs text-red-600" data-error-for="login-email">Enter a valid email address.</p>
                    </div>

                    <div>
                        <div class="flex items-center justify-between">
                            <label class="form-label" for="login-password">Password</label>
                            <a href="forgot_password.php" class="text-xs font-medium text-blue-700 hover:underline mb-1">Forgot password?</a>
                        </div>
                        <div class="relative">
                            <input id="login-password" type="password" name="password" required autocomplete="current-password"
                                   class="form-input !py-3 !pr-12 !text-sm">
                            <button type="button" id="toggle-password" class="absolute right-1.5 top-1/2 -translate-y-1/2 nx-icon-btn !w-9 !h-9" aria-label="Show password" aria-pressed="false">
                                <span class="material-symbols-outlined text-[20px]">visibility</span>
                            </button>
                        </div>
                        <p class="hidden mt-1.5 text-xs text-amber-700 flex items-center gap-1" id="caps-warning">
                            <span class="material-symbols-outlined text-[16px]">keyboard_capslock</span> Caps Lock is on
                        </p>
                        <p class="hidden mt-1.5 text-xs text-red-600" data-error-for="login-password">Enter your password.</p>
                    </div>

                    <label class="flex items-center gap-2 text-[13px] text-slate-600 select-none">
                        <input type="checkbox" id="remember-email" class="rounded border-slate-300 text-blue-700 focus:ring-blue-500/30">
                        Remember my email on this device
                    </label>

                    <button type="submit" class="btn-primary w-full !py-3 !text-sm" id="login-submit">
                        <span class="material-symbols-outlined text-[20px] hidden" id="login-spinner">progress_activity</span>
                        <span id="login-label">Sign in</span>
                    </button>
                </form>
            </div>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500">
            <span>&copy; <?= date('Y') ?> Nuvis Technologies</span>
            <span class="font-mono text-[11px] text-slate-400"><?= htmlspecialchars(AppVersion::get('full')) ?></span>
        </div>
    </main>

    <!-- Brand panel -->
    <aside class="hidden lg:block relative overflow-hidden bg-blue-900">
        <img src="assets/images/NuvisMedcareX_login_sidepanel.jpg" alt="" class="absolute inset-0 w-full h-full object-cover object-top">
        <div class="absolute inset-0" style="background: linear-gradient(180deg, rgb(10 29 63 / 0) 40%, rgb(10 29 63 / .88) 100%);"></div>
        <div class="absolute bottom-0 left-0 right-0 p-12 text-white max-w-xl">
            <p class="text-[26px] leading-snug font-semibold tracking-tight">Patient records, appointments, prescriptions and fiscal billing in one place.</p>
            <p class="mt-3 text-sm text-white/70">Built for clinics in Fiji and the Pacific, with FRCS VMS invoicing built in.</p>
        </div>
    </aside>
</div>

<script>
(function () {
    var html = document.documentElement;
    var themeBtn = document.getElementById('login-theme'), themeIcon = document.getElementById('login-theme-icon');
    function syncTheme() { themeIcon.textContent = html.classList.contains('dark') ? 'light_mode' : 'dark_mode'; }
    themeBtn.addEventListener('click', function () {
        var dark = !html.classList.contains('dark');
        html.classList.toggle('dark', dark);
        try { localStorage.setItem('nx-theme', dark ? 'dark' : 'light'); } catch (e) {}
        syncTheme();
    });
    syncTheme();

    var form = document.getElementById('login-form');
    var email = document.getElementById('login-email'), pw = document.getElementById('login-password');
    var remember = document.getElementById('remember-email');
    try {
        var saved = localStorage.getItem('nx-login-email');
        if (saved) { email.value = saved; remember.checked = true; pw.focus(); }
    } catch (e) {}

    var toggle = document.getElementById('toggle-password');
    toggle.addEventListener('click', function () {
        var show = pw.type === 'password';
        pw.type = show ? 'text' : 'password';
        toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
        toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        toggle.firstElementChild.textContent = show ? 'visibility_off' : 'visibility';
        pw.focus();
    });

    var caps = document.getElementById('caps-warning');
    function capsCheck(e) { if (e.getModifierState) caps.classList.toggle('hidden', !e.getModifierState('CapsLock')); }
    pw.addEventListener('keyup', capsCheck);
    pw.addEventListener('keydown', capsCheck);

    function showError(input, bad) {
        var msg = document.querySelector('[data-error-for="' + input.id + '"]');
        if (msg) msg.classList.toggle('hidden', !bad);
        input.setAttribute('aria-invalid', bad ? 'true' : 'false');
        input.classList.toggle('!border-red-400', bad);
    }
    [email, pw].forEach(function (i) { i.addEventListener('input', function () { showError(i, false); }); });

    form.addEventListener('submit', function (e) {
        var badEmail = !email.value.trim() || !email.checkValidity();
        var badPw = !pw.value;
        showError(email, badEmail); showError(pw, badPw);
        if (badEmail || badPw) { e.preventDefault(); (badEmail ? email : pw).focus(); return; }
        try {
            if (remember.checked) localStorage.setItem('nx-login-email', email.value.trim());
            else localStorage.removeItem('nx-login-email');
        } catch (err) {}
        var btn = document.getElementById('login-submit');
        btn.disabled = true;
        document.getElementById('login-spinner').classList.remove('hidden');
        document.getElementById('login-spinner').style.animation = 'nx-spin 1s linear infinite';
        document.getElementById('login-label').textContent = 'Signing in…';
    });
    window.addEventListener('pageshow', function () {
        var btn = document.getElementById('login-submit');
        btn.disabled = false;
        document.getElementById('login-spinner').classList.add('hidden');
        document.getElementById('login-label').textContent = 'Sign in';
    });
})();
</script>
</body>
</html>
