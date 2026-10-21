import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

// "Briefing on stage" — the Lumko seminar visual system.
// Navy stage fields for arrival moments, cool paper for reading and doing.
// Colour roles, not decoration:
//   ink       #071130  type, stage fields, primary actions
//   signal    #2C7BE5  rules, active marks, focus, links (700 for small text on paper)
//   verified  #00D97E  ONLY confirmed / approved / checked-in states (never text on paper)
const ink = {
    DEFAULT: '#071130',
    50: '#f3f5f9',
    100: '#e6e9f0',
    200: '#d0d5e1',
    300: '#aab2c6',
    400: '#626d8b', // 5.1:1 on white, 4.7:1 on paper: the lightest ink allowed for text
    500: '#4b5878',
    600: '#3a4870',
    700: '#1f2d58',
    800: '#0f1d44',
    900: '#071130',
    950: '#040a1f',
};

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
        './resources/js/**/*.js',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Poppins', ...defaultTheme.fontFamily.sans],
                display: ['"Archivo Variable"', 'Archivo', 'Poppins', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                ink,
                // `brand` is kept as an alias so pages and the admin area that
                // still say text-brand / bg-brand inherit the new palette.
                brand: ink,
                signal: {
                    DEFAULT: '#2C7BE5',
                    50: '#eef4fd',
                    100: '#d9e7fb',
                    200: '#b3cff6',
                    300: '#7fb0ef',
                    400: '#4f93ea',
                    500: '#2C7BE5',
                    600: '#1f6ad0',
                    700: '#1a5ab3',
                    800: '#174a90',
                },
                verified: {
                    DEFAULT: '#00D97E',
                    50: '#e6fbf2',
                    100: '#c2f5de',
                    500: '#00D97E',
                    600: '#00b368',
                    700: '#007a47',
                },
                paper: '#f5f6f8',
                rule: {
                    DEFAULT: '#dde1e8',
                    strong: '#c3c9d4',
                },
            },
            fontSize: {
                eyebrow: ['0.75rem', { lineHeight: '1rem', letterSpacing: '0.14em' }],
                meta: ['0.8125rem', { lineHeight: '1.25rem' }],
                lede: ['clamp(1.25rem, 1rem + 1vw, 1.75rem)', { lineHeight: '1.4' }],
                'display-sm': ['clamp(1.75rem, 1.4rem + 1.4vw, 2.5rem)', { lineHeight: '1.02', letterSpacing: '-0.01em' }],
                'display-md': ['clamp(2.25rem, 1.6rem + 2.8vw, 4rem)', { lineHeight: '0.96', letterSpacing: '-0.015em' }],
                'display-xl': ['clamp(3rem, 1.6rem + 6.4vw, 7.5rem)', { lineHeight: '0.88', letterSpacing: '-0.02em' }],
            },
            maxWidth: {
                page: '75rem',
            },
            borderRadius: {
                ctl: '2px',
            },
            // Elevation, navy-tinted. `lift` for objects and panels that sit on
            // the page (cards, the remittance slip, the ticket); `float` for
            // overlays (modals, menus).
            boxShadow: {
                lift: '0 1px 2px rgba(7, 17, 48, 0.06), 0 12px 32px -18px rgba(7, 17, 48, 0.28)',
                float: '0 2px 6px rgba(7, 17, 48, 0.08), 0 28px 60px -24px rgba(4, 10, 31, 0.5)',
            },
            transitionTimingFunction: {
                out: 'cubic-bezier(0.22, 1, 0.36, 1)',
            },
        },
    },

    plugins: [forms],
};
