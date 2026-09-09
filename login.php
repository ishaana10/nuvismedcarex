<?php
/**
 * Login Page
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/version.php';
require_once __DIR__ . '/includes/security.php';

if (isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true) {
    header("Location: index.php");
    exit;
}

$toast = getToast();
$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html class="light h-full bg-[#f8f9ff]" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Login - NuvisMedcareX</title>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&amp;display=swap" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="h-full font-sans text-slate-800 bg-[#f8f9ff] flex items-center justify-center p-6">

<?php if ($toast): ?>
<div id="toast-notification" class="fixed top-5 right-5 z-50 flex items-center p-4 text-gray-800 bg-white rounded-xl shadow-lg border border-slate-200" role="alert">
    <div class="inline-flex items-center justify-center flex-shrink-0 w-9 h-9 text-<?= $toast['type'] === 'error' ? 'red-600 bg-red-100' : 'emerald-600 bg-emerald-100' ?> rounded-lg">
        <span class="material-symbols-outlined"><?= $toast['type'] === 'error' ? 'error' : 'check_circle' ?></span>
    </div>
    <div class="ms-3 text-sm font-normal pr-4">
        <div class="font-semibold text-gray-900"><?= htmlspecialchars($toast['title']) ?></div>
        <div class="text-xs text-slate-600"><?= htmlspecialchars($toast['message']) ?></div>
    </div>
    <button type="button" onclick="document.getElementById('toast-notification').remove()" class="ms-auto -mx-1.5 -my-1.5 bg-white text-gray-400 hover:text-gray-900 rounded-lg p-1.5 hover:bg-gray-100 inline-flex items-center justify-center h-8 w-8">
        <span class="material-symbols-outlined text-sm">close</span>
    </button>
</div>
<?php endif; ?>

<div class="max-w-4xl w-full bg-white rounded-3xl shadow-xl border border-slate-200 overflow-hidden relative grid grid-cols-1 md:grid-cols-2">
    <!-- Left Column: Login Form & Branding -->
    <div class="flex flex-col justify-between">
        <!-- Brand Header with NuvisMedcareX logo & assets -->
        <div class="bg-white p-6 border-b border-slate-100 text-center flex flex-col items-center justify-center">
            <img src="assets/images/NuvisMedcareX_logo.jpg" alt="NuvisMedcareX" class="h-16 object-contain mb-2 rounded-lg">
            <div class="flex items-center gap-3 mt-1">
                <img src="assets/images/stethoscope_heart.png" alt="Stethoscope" class="h-6 object-contain">
                <span class="text-xs font-semibold text-slate-500 uppercase tracking-widest">NuvisMedcareX EHR Platform <span class="font-mono text-[10px] text-blue-700 font-bold bg-blue-50 px-1.5 py-0.5 rounded border border-blue-200"><?= defined('APP_VERSION') ? APP_VERSION : 'v2.1.0' ?></span></span>
                <img src="assets/images/caduceus_logo.png" alt="Caduceus" class="h-6 object-contain">
            </div>
        </div>

        <!-- Login Form -->
        <div class="p-8">
            <form action="actions/login.php" method="POST" class="space-y-5 text-xs">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">Email Address</label>
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-lg">mail</span>
                        <input type="email" name="email" value="medico@nuvistechnologies.com.fj" required placeholder="medico@nuvistechnologies.com.fj" class="w-full bg-slate-50 pl-10 pr-4 py-3 rounded-xl border border-slate-300 font-medium text-slate-800 focus:outline-none focus:border-blue-600 focus:bg-white transition">
                    </div>
                </div>

                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">Password</label>
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-lg">lock</span>
                        <input type="password" name="password" value="password" required placeholder="••••••••" class="w-full bg-slate-50 pl-10 pr-4 py-3 rounded-xl border border-slate-300 font-medium text-slate-800 focus:outline-none focus:border-blue-600 focus:bg-white transition">
                    </div>
                </div>

                <div class="pt-2">
                    <button type="submit" class="w-full py-3.5 bg-blue-900 text-white font-bold text-xs rounded-xl hover:bg-blue-800 transition shadow-md flex items-center justify-center gap-2">
                        <span class="material-symbols-outlined text-lg">login</span>
                        <span>Sign In to Clinical Portal</span>
                    </button>
                </div>
            </form>

            <div class="mt-6 p-4 rounded-xl bg-slate-50 border border-slate-200 text-[11px] text-slate-600 space-y-1">
                <p class="font-bold text-slate-800">Developer / Administrator Credentials:</p>
                <p>Email: <code class="text-blue-700 font-mono font-bold">medico@nuvistechnologies.com.fj</code></p>
                <p>Password: <code class="text-blue-700 font-mono font-bold">password</code></p>
            </div>
        </div>
    </div>

    <!-- Right Column: Hero Image Attachment -->
    <div class="hidden md:block relative bg-blue-900 overflow-hidden min-h-[450px]">
        <img src="assets/images/NuvisMedcareX_login_sidepanel.jpg" alt="NuvisMedcareX" class="w-full h-full object-cover">
        <div class="absolute inset-0 bg-gradient-to-t from-blue-950/80 via-blue-950/20 to-transparent flex items-end p-8">
            <div class="text-white">
                <h3 class="text-xl font-bold">NuvisMedcareX</h3>
                <p class="text-xs font-semibold text-blue-200 mb-1">by Nuvis Technologies</p>
                <p class="text-xs text-blue-100 leading-relaxed">Comprehensive electronic medical records, clinical decision support & patient care management platform.</p>
            </div>
        </div>
    </div>
</div>

</body>
</html>
