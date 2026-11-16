/**
 * Tailwind build config (dev-time only: `npm run build:css`).
 * The compiled file is committed as assets/css/tailwind.css, so the server
 * (A2 Hosting) needs no Node and browsers no longer download and run
 * Tailwind's in-browser compiler on every page.
 *
 * Every colour family resolves to a CSS variable defined in custom.css, so
 * light/dark themes and the clinic accent colour switch without a rebuild.
 */
const shades = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950];
const scale = (family) => {
  const s = {};
  shades.forEach((n) => { s[n] = `rgb(var(--c-${family}-${n}) / <alpha-value>)`; });
  s.DEFAULT = `rgb(var(--c-${family}-700) / <alpha-value>)`;
  return s;
};
const v = (name) => `rgb(var(--${name}) / <alpha-value>)`;

module.exports = {
  darkMode: 'class',
  content: ['./*.php', './includes/**/*.php', './src/**/*.php', './actions/**/*.php', './assets/js/*.js'],
  // Classes stored in the database (doctor colours etc.) are invisible to the scanner
  safelist: [{
    pattern: /^(bg|text|border|ring|from|to)-(slate|blue|primary|emerald|green|amber|yellow|red|rose|sky)-(50|100|200|300|400|500|600|700|800|900|950)$/,
  }],
  theme: {
    extend: {
      colors: {
        slate: scale('slate'), gray: scale('slate'),
        blue: scale('blue'), primary: scale('blue'),
        emerald: scale('emerald'), green: scale('emerald'),
        amber: scale('amber'), yellow: scale('amber'),
        red: scale('red'), rose: scale('rose'), sky: scale('sky'),
        'primary-container': v('c-blue-800'),
        background: v('nx-bg'),
        brand: {
          blue: v('c-blue-700'), accent: v('c-blue-600'), bg: v('nx-bg'),
          card: v('nx-card'), border: v('c-slate-200'), sidebar: v('nx-card'), navy: v('c-blue-900'),
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
        outline: v('c-slate-500'),
        'outline-variant': v('c-slate-300'),
      },
      fontFamily: {
        sans: ['"IBM Plex Sans"', 'Inter', 'system-ui', '-apple-system', 'Segoe UI', 'Roboto', 'sans-serif'],
        mono: ['"IBM Plex Mono"', 'ui-monospace', 'SFMono-Regular', 'monospace'],
      },
      boxShadow: {
        '2xs': '0 1px 1px 0 rgb(15 43 92 / 0.04)',
        xs: '0 1px 2px 0 rgb(15 43 92 / 0.06)',
        pop: '0 12px 32px -8px rgb(10 29 63 / 0.28), 0 2px 6px rgb(10 29 63 / 0.08)',
      },
      backdropBlur: { xs: '2px' },
      borderRadius: { '4xl': '2rem' },
    },
  },
  plugins: [require('@tailwindcss/forms'), require('@tailwindcss/container-queries')],
};
