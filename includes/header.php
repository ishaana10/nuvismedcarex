<?php
/**
 * Common Header Layout Component with Mobile Responsiveness
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/security.php';

$pdo = getDB();
$toast = getToast();
$activePage = $activePage ?? 'dashboard';

// Authentication Check
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    if (basename($_SERVER['PHP_SELF']) !== 'login.php' && basename($_SERVER['PHP_SELF']) !== 'install.php') {
        header("Location: login.php");
        exit;
    }
}

// Get doctors for current doctor selector
$doctors = $pdo->query("SELECT * FROM doctors ORDER BY name ASC")->fetchAll();
$currentDoctorId = $_SESSION['current_doctor_id'] ?? ($doctors[0]['id'] ?? 'doc-1');
$currentDoctor = array_filter($doctors, fn($d) => $d['id'] === $currentDoctorId);
$currentDoctor = reset($currentDoctor) ?: ($doctors[0] ?? ['id' => 'doc-1', 'name' => 'Dr. Sarah Jenkins', 'specialty' => 'Internal Medicine']);
?>
<!DOCTYPE html>
<html class="light h-full bg-[#f8f9ff]" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title><?= htmlspecialchars($pageTitle ?? 'Nuvis Medicoz') ?></title>

    <!-- Material Symbols Outlined -->
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com"/>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&amp;family=JetBrains+Mono:wght@400;500&amp;display=swap" rel="stylesheet"/>

    <!-- Custom Unified Clinic Stylesheet -->
    <link rel="stylesheet" href="assets/css/custom.css"/>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <script id="tailwind-config">
      tailwind.config = {
        darkMode: "class",
        theme: {
          extend: {
            colors: {
              primary: {
                DEFAULT: '#1d4ed8', // Vibrant modern blue matching Dashboard.png
                50: '#eff6ff',
                100: '#dbeafe',
                500: '#3b82f6',
                600: '#2563eb',
                700: '#1d4ed8',
                800: '#1e40af',
                900: '#1e3a8a',
              },
              brand: {
                blue: '#1d4ed8',
                accent: '#2563eb',
                bg: '#f8fafc',
                card: '#ffffff',
                border: '#e2e8f0',
                sidebar: '#f8fafc',
              },
              "surface-container-lowest": "#ffffff",
              "surface-container-low": "#f8fafc",
              "surface-container": "#f1f5f9",
              "surface-container-high": "#e2e8f0",
              "on-surface": "#0f172a",
              "on-surface-variant": "#475569",
              "outline": "#64748b",
              "outline-variant": "#cbd5e1"
            },
            fontFamily: {
              sans: ["Inter", "-apple-system", "BlinkMacSystemFont", "Segoe UI", "Roboto", "sans-serif"],
              mono: ["JetBrains Mono", "monospace"]
            }
          }
        }
      };
    </script>
    <style>
      .material-symbols-outlined {
        font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
      }
      .material-symbols-outlined.fill {
        font-variation-settings: 'FILL' 1;
      }
    </style>
</head>
<body class="h-full font-sans text-on-surface bg-background flex flex-col min-h-screen">

<!-- Toast Notification -->
<?php if ($toast): ?>
<div id="toast-notification" class="fixed top-5 right-5 z-50 flex items-center p-4 mb-4 text-gray-800 bg-white rounded-xl shadow-lg border border-slate-200 max-w-sm" role="alert">
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
<script>
  setTimeout(() => {
    const el = document.getElementById('toast-notification');
    if (el) el.remove();
  }, 4000);
</script>
<?php endif; ?>

<!-- Top Navigation Bar -->
<header class="bg-white border-b border-slate-200/80 sticky top-0 z-30 shadow-xs">
    <div class="flex items-center justify-between px-3 sm:px-6 py-2.5 sm:py-3">
        <!-- Logo & Mobile Toggle -->
        <div class="flex items-center gap-3 shrink-0">
            <button type="button" onclick="toggleMobileSidebar()" aria-label="Toggle Navigation Menu" class="md:hidden p-2 text-slate-600 hover:bg-slate-100 rounded-xl transition flex items-center justify-center border border-slate-200">
                <span class="material-symbols-outlined text-2xl font-bold">menu</span>
            </button>
            <a href="index.php" class="flex items-center gap-2">
                <img src="assets/images/nuvis_medicoz_logo.png" alt="Nuvis Medicoz Logo" class="w-8 h-8 rounded-full object-cover border border-amber-500/50 shadow-xs">
                <span class="font-bold text-xl text-blue-900 tracking-tight">Nuvis Medicoz</span>
            </a>
        </div>

        <!-- Global Search Bar (Dashboard.png style) -->
        <div class="relative w-80 lg:w-[480px] hidden md:block">
            <form action="patients.php" method="GET" class="relative">
                <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 text-lg">search</span>
                <input type="text" name="q" placeholder="Search patients, doctors..." class="w-full bg-slate-100/80 pl-11 pr-4 py-2 rounded-full text-xs font-medium border border-transparent focus:border-blue-500 focus:bg-white focus:outline-none transition-all placeholder:text-slate-400">
            </form>
        </div>

        <!-- Right Utilities & Profile -->
        <div class="flex items-center gap-2 sm:gap-3 shrink-0">
            <!-- Notifications Icon -->
            <button type="button" class="relative p-2 text-slate-500 hover:text-slate-800 hover:bg-slate-100 rounded-full transition flex items-center">
                <span class="material-symbols-outlined text-xl">notifications</span>
                <span class="absolute top-1.5 right-1.5 w-2 h-2 rounded-full bg-red-500 border border-white"></span>
            </button>

            <!-- Help Icon -->
            <button type="button" class="p-2 text-slate-500 hover:text-slate-800 hover:bg-slate-100 rounded-full transition flex items-center">
                <span class="material-symbols-outlined text-xl">help</span>
            </button>

            <!-- Doctor Selector & Profile -->
            <div class="flex items-center gap-2 pl-2 border-l border-slate-200">
                <form action="actions/set_doctor.php" method="POST" class="flex items-center gap-2">
                    <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                    <select name="doctor_id" onchange="this.form.submit()" class="bg-transparent border-none text-xs font-bold text-slate-800 focus:outline-none cursor-pointer max-w-[130px] sm:max-w-none truncate">
                        <?php foreach ($doctors as $doc): ?>
                            <option value="<?= htmlspecialchars($doc['id']) ?>" <?= $doc['id'] === $currentDoctor['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($doc['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <img src="<?= htmlspecialchars($currentDoctor['avatar'] ?? 'https://images.unsplash.com/photo-1559839734-2b71ea197ec2?auto=format&fit=crop&q=80&w=200') ?>" class="w-8 h-8 rounded-full object-cover border border-slate-200 shadow-xs" alt="Doctor Avatar">
            </div>

            <!-- Logout Button (Header Right) -->
            <a href="actions/logout.php" class="p-2 text-slate-500 hover:text-rose-600 hover:bg-rose-50 rounded-full transition flex items-center" title="Logout">
                <span class="material-symbols-outlined text-xl">logout</span>
            </a>
        </div>
    </div>
</header>

<!-- Change Password Global Modal -->
<div id="modal-change-password-global" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center hidden p-4">
    <div class="bg-surface-container-lowest rounded-3xl border border-outline-variant/30 shadow-2xl max-w-md w-full overflow-hidden">
        <div class="p-5 bg-slate-900 text-white flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-xl">key</span>
                <h3 class="font-bold text-sm">Change Account Password</h3>
            </div>
            <button type="button" onclick="closeChangePasswordModal()" class="text-slate-400 hover:text-white">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <form action="actions/change_password.php" method="POST" class="p-6 space-y-4 text-xs">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <input type="hidden" name="redirect_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '../index.php') ?>">

            <div>
                <label class="block font-bold text-slate-700 mb-1">Current Password *</label>
                <input type="password" name="current_password" required placeholder="••••••••" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-medium text-on-surface focus:outline-none focus:border-primary">
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">New Password *</label>
                <input type="password" name="new_password" required minlength="6" placeholder="At least 6 characters" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-medium text-on-surface focus:outline-none focus:border-primary">
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">Confirm New Password *</label>
                <input type="password" name="confirm_password" required minlength="6" placeholder="Repeat new password" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-medium text-on-surface focus:outline-none focus:border-primary">
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-outline-variant/20">
                <button type="button" onclick="closeChangePasswordModal()" class="px-4 py-2 bg-surface-container hover:bg-surface-container-high rounded-xl font-semibold text-on-surface">Cancel</button>
                <button type="submit" class="px-5 py-2 bg-primary text-white font-bold rounded-xl hover:bg-primary/90 shadow-xs flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-base">lock_reset</span>
                    <span>Update Password</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openChangePasswordModal() {
    document.getElementById('modal-change-password-global').classList.remove('hidden');
}
function closeChangePasswordModal() {
    document.getElementById('modal-change-password-global').classList.add('hidden');
}
</script>

<div class="flex flex-1 overflow-hidden relative">
<?php include __DIR__ . '/sidebar.php'; ?>
<main class="flex-1 overflow-y-auto p-3 sm:p-6 w-full">
