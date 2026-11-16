/**
 * Second Tailwind build, for the pages that do not use the app shell: the
 * installer, printable documents and the public prescription verification page.
 * They use Tailwind's standard colours (the app's themed palette needs
 * custom.css, which these pages do not load). Output: assets/css/tailwind-public.css
 */
module.exports = {
  content: [
    './install.php', './print_invoice.php', './print_receipt.php', './print_prescription.php',
    './print_lab_order.php', './print_medical_certificate.php', './verify_prescription.php',
  ],
  theme: {
    extend: {
      fontFamily: {
        sans: ['"IBM Plex Sans"', 'system-ui', '-apple-system', 'Segoe UI', 'Roboto', 'sans-serif'],
        mono: ['"IBM Plex Mono"', 'ui-monospace', 'SFMono-Regular', 'monospace'],
      },
    },
  },
  plugins: [],
};
