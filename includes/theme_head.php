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

// The clinic's brand colour (Administration > Appearance). Auth pages have no clinic yet.
$nxAccent = '';
$nxAccentCss = '';
if ($nxUserPrefs && function_exists('nxSetting')) {
    $nxAccent = (string) nxSetting('ui_accent', '');
    $nxAccentCss = $nxAccent !== '' ? \ClinicFlow\Services\AppearanceSettings::css($nxAccent) : '';
}
?>
    <meta name="color-scheme" content="light dark"/>
    <meta name="theme-color" content="<?= $nxAccentCss !== '' ? htmlspecialchars(\ClinicFlow\Services\AppearanceSettings::readable($nxAccent)) : '#0F2B5C' ?>"/>
    <link rel="icon" type="image/png" href="assets/images/nuvis_mark.png"/>

    <!-- Apply the chosen theme before first paint (no light flash in dark mode) -->
    <script nonce="<?= nxCspNonce() ?>">
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

    <!-- Fonts are served from assets/fonts (see assets/css/fonts.css); preload the ones every page needs -->
    <link rel="preload" href="assets/fonts/ibm-plex-sans-latin-400-normal.woff2" as="font" type="font/woff2" crossorigin/>
    <link rel="preload" href="assets/fonts/ibm-plex-sans-latin-600-normal.woff2" as="font" type="font/woff2" crossorigin/>
    <link rel="preload" href="assets/fonts/material-symbols-outlined.woff2" as="font" type="font/woff2" crossorigin/>

    <link rel="stylesheet" href="assets/css/fonts.css?v=<?= $nxAssetVersion ?>"/>
    <link rel="stylesheet" href="assets/css/custom.css?v=<?= $nxAssetVersion ?>"/>

    <!-- Utility classes: pre-built from tailwind.config.js (npm run build:css), no in-browser compiler -->
    <link rel="stylesheet" href="assets/css/tailwind.css?v=<?= $nxAssetVersion ?>"/>
<?php if ($nxAccentCss !== ''): ?>
    <style nonce="<?= nxCspNonce() ?>" id="nx-clinic-accent"><?= $nxAccentCss /* built from a validated #RRGGBB, never user text */ ?></style>
<?php endif; ?>
