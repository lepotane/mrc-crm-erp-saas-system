import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
        vue(),
    ],
    build: {
        rollupOptions: {
            output: {
                manualChunks(id) {
                    if (id.includes('node_modules')) {
                        if (id.includes('vue') || id.includes('pinia') || id.includes('vuedraggable') || id.includes('sortablejs')) {
                            return 'vendor-vue';
                        }
                        if (id.includes('lucide-vue-next')) {
                            return 'vendor-icons';
                        }
                        if (id.includes('zxing-wasm')) {
                            return 'vendor-zxing';
                        }
                        if (id.includes('axios')) {
                            return 'vendor-core';
                        }
                    }
                },
            },
        },
        chunkSizeWarningLimit: 600,
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
