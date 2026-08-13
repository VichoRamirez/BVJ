import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            // Archivo es la familia del sistema Modernist. Se sirve desde Bunny en
            // lugar de un @import a Google Fonts: evita la cascada bloqueante y el
            // tercero (AUDITORIA-UI.md H13).
            fonts: [
                bunny('Archivo', {
                    weights: [400, 600, 800],
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
