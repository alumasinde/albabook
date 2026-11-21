/**
 * Tailwind scans templates for class names, so classes must appear in full in
 * source files. Never build class names from database values (plan.md §8.8);
 * tenant colours arrive as CSS custom properties instead.
 *
 * Colours reference CSS custom properties defined in resources/css/app.css as
 * RGB channels, so opacity modifiers (bg-danger/10) work.
 *
 * @type {import('tailwindcss').Config}
 */
const token = (name) => `rgb(var(--color-${name}) / <alpha-value>)`;

module.exports = {
  content: ['./resources/views/**/*.twig', './resources/js/**/*.js'],
  theme: {
    extend: {
      colors: {
        navy: token('navy'),
        primary: { DEFAULT: token('primary'), strong: token('primary-strong'), soft: token('primary-soft') },
        success: { DEFAULT: token('success'), strong: token('success-strong'), soft: token('success-soft') },
        warning: { DEFAULT: token('warning'), strong: token('warning-strong'), soft: token('warning-soft') },
        danger: { DEFAULT: token('danger'), strong: token('danger-strong'), soft: token('danger-soft') },
        surface: token('surface'),
        ink: token('ink'),
        muted: token('muted'),
        line: token('line'),
      },
      fontFamily: {
        sans: ['"Inter Variable"', 'Inter', 'system-ui', '-apple-system', 'Segoe UI', 'Roboto', 'sans-serif'],
        mono: ['ui-monospace', 'SFMono-Regular', 'Menlo', 'Consolas', 'monospace'],
      },
      borderRadius: {
        lg: '0.625rem',
        xl: '0.875rem',
      },
      boxShadow: {
        card: '0 1px 2px rgb(23 26 44 / 0.04), 0 1px 3px rgb(23 26 44 / 0.06)',
      },
    },
  },
  plugins: [],
};
