<?php
/**
 * Shared <head> assets: fonts, theme bootstrap, Tailwind config and the
 * unified stylesheet. Included by the app shell (header.php) and by the
 * standalone auth pages (login.php, forgot_password.php).
 *
 * Every colour family used across the app (slate, blue/primary, emerald,
 * amber, red, rose, sky and the surface-* aliases) resolves to a CSS
 * variable, so light/dark themes are switched centrally in custom.css
 * without touching individual pages.
 */
require_once __DIR__ . '/../config/version.php';
$nxAssetVersion = urlencode((string) AppVersion::get('commit'));

// The signed-in person's saved look, so it applies on any device they use.
// Auth pages have no user yet and fall back to the device setting.
$nxUserPrefs = function_exists('nxPrefs') && !empty($_SESSION['authenticated']) ? nxPrefs() : null;
?>
    <meta name="color-scheme" content="light dark"/>
    <meta name="theme-color" content="#0F2B5C"/>
    <link rel="icon" type="image/png" href="assets/images/nuvis_mark.png"/>

    <!-- Apply the chosen theme before first paint (no light flash in dark mode) -->
    <script>
      (function () {
        var saved = <?= $nxUserPrefs ? json_encode([
            'theme' => $nxUserPrefs['theme'],
            'density' => $nxUserPrefs['density'],
            'sidebar' => !empty($nxUserPrefs['sidebar_collapsed']),
            'hints' => !empty($nxUserPrefs['show_help_hints']),
        ]) : 'null' ?>;
        var root = document.documentElement;
        try {
          var theme = saved ? saved.theme : (localStorage.getItem('nx-theme') || 'system');
          if (theme === 'system' || !theme) {
            theme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
          }
          root.classList.toggle('dark', theme === 'dark');

          var collapsed = saved ? saved.sidebar : localStorage.getItem('nx-sidebar') === 'collapsed';
          // A choice made with [ during this visit wins over the saved default
          var session = sessionStorage.getItem('nx-sidebar');
          if (session !== null) collapsed = session === 'collapsed';
          root.classList.toggle('nx-sidebar-collapsed', !!collapsed);

          if (saved && saved.density === 'compact') root.classList.add('nx-compact');
          if (saved && saved.hints === false) root.classList.add('nx-no-hints');
        } catch (e) {}
      })();
    </script>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com"/>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:ital,wght@0,400;0,500;0,600;0,700;1,400&amp;family=IBM+Plex+Mono:wght@400;500;600&amp;display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,300..600,0..1,0&amp;display=block" rel="stylesheet"/>

    <link rel="stylesheet" href="assets/css/custom.css?v=<?= $nxAssetVersion ?>"/>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <script id="tailwind-config">
      (function () {
        var shades = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950];
        function scale(family) {
          var s = {};
          shades.forEach(function (n) { s[n] = 'rgb(var(--c-' + family + '-' + n + ') / <alpha-value>)'; });
          s.DEFAULT = 'rgb(var(--c-' + family + '-700) / <alpha-value>)';
          return s;
        }
        var v = function (name) { return 'rgb(var(--' + name + ') / <alpha-value>)'; };
        tailwind.config = {
          darkMode: 'class',
          theme: {
            extend: {
              colors: {
                slate: scale('slate'),
                gray: scale('slate'),
                blue: scale('blue'),
                primary: scale('blue'),
                emerald: scale('emerald'),
                green: scale('emerald'),
                amber: scale('amber'),
                yellow: scale('amber'),
                red: scale('red'),
                rose: scale('rose'),
                sky: scale('sky'),
                'primary-container': v('c-blue-800'),
                background: v('nx-bg'),
                brand: {
                  blue: v('c-blue-700'), accent: v('c-blue-600'), bg: v('nx-bg'),
                  card: v('nx-card'), border: v('c-slate-200'), sidebar: v('nx-card'), navy: v('c-blue-900')
                },
                'primary-hover': v('c-blue-800'),
                'primary-fixed': v('c-blue-100'),
                'on-primary-fixed': v('c-blue-900'),
                'on-primary': 'rgb(255 255 255 / <alpha-value>)',
                surface: v('nx-card'),
                'surface-variant': v('c-slate-100'),
                'surface-container-highest': v('c-slate-300'),
                'surface-container-lowest': v('nx-card'),
                'surface-container-low': v('c-slate-50'),
                'surface-container': v('c-slate-100'),
                'surface-container-high': v('c-slate-200'),
                'on-surface': v('c-slate-900'),
                'on-surface-variant': v('c-slate-600'),
                'outline': v('c-slate-500'),
                'outline-variant': v('c-slate-300')
              },
              fontFamily: {
                sans: ['"IBM Plex Sans"', 'Inter', 'system-ui', '-apple-system', 'Segoe UI', 'Roboto', 'sans-serif'],
                mono: ['"IBM Plex Mono"', 'ui-monospace', 'SFMono-Regular', 'monospace']
              },
              boxShadow: {
                '2xs': '0 1px 1px 0 rgb(15 43 92 / 0.04)',
                'xs': '0 1px 2px 0 rgb(15 43 92 / 0.06)',
                'pop': '0 12px 32px -8px rgb(10 29 63 / 0.28), 0 2px 6px rgb(10 29 63 / 0.08)'
              },
              backdropBlur: { xs: '2px' },
              borderRadius: { '4xl': '2rem' }
            }
          }
        };
      })();
    </script>
