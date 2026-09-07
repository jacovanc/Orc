import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
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
