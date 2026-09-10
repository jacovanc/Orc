import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],
    // Status classes are assembled from enum values in Blade and therefore
    // cannot be discovered statically by Tailwind's content scanner.
    safelist: [
        'status-running',
        'status-paused',
        'status-completed',
        'status-pending',
        'status-setup_pending',
        'status-claimed',
        'status-delivering',
        'status-delivered',
        'status-launched',
        'status-ambiguous',
        'status-cancelled',
        'status-failed',
        'status-expired',
        'status-revoked',
        'status-waiting',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Instrument Sans', ...defaultTheme.fontFamily.sans],
                mono: ['ui-monospace', 'SFMono-Regular', 'Menlo', 'monospace'],
            },
            colors: {
                ink: {
                    950: '#090a0c',
                    900: '#111216',
                    850: '#17181d',
                },
            },
        },
    },

    plugins: [forms],
};
