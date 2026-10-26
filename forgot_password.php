<?php
/**
 * Forgot Password & OTP Reset Page
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
$step = $_GET['step'] ?? 'request';
$email = sanitizeInput($_GET['email'] ?? '');
?>
<!DOCTYPE html>
<html class="h-full" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Reset password - Nuvis Medcare X</title>
    <?php include __DIR__ . '/includes/theme_head.php'; ?>
</head>
<body class="min-h-full font-sans text-slate-900 bg-background">
<div class="min-h-screen flex flex-col px-6 sm:px-12 py-8">
    <div><a href="login.php"><img src="assets/images/NuvisMedcareX_banner.jpg" alt="Nuvis Medcare X" class="h-11 w-auto object-contain mix-blend-multiply dark:mix-blend-normal dark:bg-white dark:rounded-lg dark:px-2"></a></div>

    <main class="flex-1 flex items-center">
        <div class="w-full max-w-[420px] mx-auto py-10">
            <!-- Progress: request code, then verify -->
            <ol class="flex items-center gap-2 text-xs font-medium mb-6" aria-label="Steps">
                <li class="flex items-center gap-1.5 <?= $step === 'verify' ? 'text-emerald-700' : 'text-blue-700' ?>">
                    <span class="w-5 h-5 rounded-full flex items-center justify-center text-[11px] text-white <?= $step === 'verify' ? 'bg-emerald-600' : 'bg-blue-700' ?>">1</span> Get a code
                </li>
                <li class="flex-1 h-px bg-slate-200"></li>
                <li class="flex items-center gap-1.5 <?= $step === 'verify' ? 'text-blue-700' : 'text-slate-400' ?>" <?= $step === 'verify' ? 'aria-current="step"' : '' ?>>
                    <span class="w-5 h-5 rounded-full flex items-center justify-center text-[11px] <?= $step === 'verify' ? 'bg-blue-700 text-white' : 'bg-slate-200 text-slate-500' ?>">2</span> Choose a new password
                </li>
            </ol>

            <?php if ($toast): ?>
                <div class="mb-6 flex gap-3 rounded-xl border px-4 py-3 text-[13px] <?= $toast['type'] === 'error' ? 'border-red-200 bg-red-50 text-red-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800' ?>" role="<?= $toast['type'] === 'error' ? 'alert' : 'status' ?>">
                    <span class="material-symbols-outlined text-[20px]"><?= $toast['type'] === 'error' ? 'error' : 'check_circle' ?></span>
                    <div><div class="font-semibold"><?= htmlspecialchars($toast['title']) ?></div><div class="opacity-90"><?= htmlspecialchars($toast['message']) ?></div></div>
                </div>
            <?php endif; ?>

            <?php if ($step === 'verify'): ?>
                <h1 class="text-[26px] leading-tight font-semibold tracking-tight">Enter your code</h1>
                <p class="mt-2 text-sm text-slate-500">We emailed a 6-digit code<?= $email ? ' to <span class="font-medium text-slate-700">' . htmlspecialchars($email) . '</span>' : '' ?>. It expires shortly, so use it now.</p>

                <form action="actions/reset_password_otp.php" method="POST" class="mt-8 space-y-5" id="reset-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <div>
                        <label class="form-label" for="fp-email">Email</label>
                        <input id="fp-email" type="email" name="email" value="<?= htmlspecialchars($email) ?>" required autocomplete="username" class="form-input !py-3 !text-sm">
                    </div>
                    <div>
                        <label class="form-label" for="fp-otp">Verification code</label>
                        <input id="fp-otp" type="text" name="otp" required maxlength="6" minlength="6" pattern="[0-9]{6}" inputmode="numeric" autocomplete="one-time-code" <?= $email ? 'autofocus' : '' ?>
                               class="form-input !py-3 !text-xl font-mono tracking-[0.5em] text-center" placeholder="••••••">
                    </div>
                    <div>
                        <label class="form-label" for="fp-new">New password</label>
                        <input id="fp-new" type="password" name="new_password" required minlength="8" pattern="(?=.*[A-Z])(?=.*\d).{8,}" autocomplete="new-password" class="form-input !py-3 !text-sm" title="At least 8 characters, one capital letter and one number">
                        <p class="mt-1.5 text-xs text-slate-500">At least 8 characters, with a capital letter and a number.</p>
                    </div>
                    <div>
                        <label class="form-label" for="fp-confirm">Confirm new password</label>
                        <input id="fp-confirm" type="password" name="confirm_password" required autocomplete="new-password" class="form-input !py-3 !text-sm">
                        <p class="hidden mt-1.5 text-xs text-red-600" id="fp-mismatch">Passwords don't match yet.</p>
                    </div>
                    <button type="submit" class="btn-primary w-full !py-3 !text-sm">Reset password</button>
                </form>

                <div class="mt-6 flex items-center justify-between text-[13px]">
                    <a href="forgot_password.php?step=request&email=<?= urlencode($email) ?>" class="text-blue-700 font-medium hover:underline">Send a new code</a>
                    <a href="login.php" class="text-slate-500 hover:text-slate-800">Back to sign in</a>
                </div>
            <?php else: ?>
                <h1 class="text-[26px] leading-tight font-semibold tracking-tight">Reset your password</h1>
                <p class="mt-2 text-sm text-slate-500">Enter the email you sign in with and we'll send you a 6-digit code.</p>

                <form action="actions/request_otp.php" method="POST" class="mt-8 space-y-5">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <div>
                        <label class="form-label" for="fp-email">Email</label>
                        <input id="fp-email" type="email" name="email" value="<?= htmlspecialchars($email) ?>" required autofocus autocomplete="username" class="form-input !py-3 !text-sm" placeholder="name@clinic.com">
                    </div>
                    <button type="submit" class="btn-primary w-full !py-3 !text-sm">Email me a code</button>
                </form>

                <div class="mt-6 flex items-center justify-between text-[13px]">
                    <a href="forgot_password.php?step=verify" class="text-blue-700 font-medium hover:underline">I already have a code</a>
                    <a href="login.php" class="text-slate-500 hover:text-slate-800">Back to sign in</a>
                </div>
            <?php endif; ?>
        </div>
    </main>
    <div class="text-xs text-slate-500">&copy; <?= date('Y') ?> Nuvis Technologies</div>
</div>
<script>
(function () {
    var f = document.getElementById('reset-form');
    if (!f) return;
    var a = document.getElementById('fp-new'), b = document.getElementById('fp-confirm'), m = document.getElementById('fp-mismatch');
    var otp = document.getElementById('fp-otp');
    otp.addEventListener('input', function () { otp.value = otp.value.replace(/\D/g, '').slice(0, 6); });
    function check() { var bad = b.value && a.value !== b.value; m.classList.toggle('hidden', !bad); b.setCustomValidity(bad ? "Passwords don't match" : ''); }
    a.addEventListener('input', check); b.addEventListener('input', check);
})();
</script>
</body>
</html>
