import {
    defineConfig,
    lazyPlugins
} from 'vite-plus';
import laravel from 'laravel-vite-plugin';
import tailwindcss from "@tailwindcss/vite";


export default defineConfig({
    plugins: lazyPlugins(() => [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/passkeys.js',
            ],
            refresh: [`resources/views/**/*`],
        }),
        tailwindcss(),
    ]),
    server: {
        cors: true,
        // Advertise localhost (not [::1]) so the browser reaches Vite through the Dev Container's port forward
        hmr: {
            host: 'localhost',
        },
    },
    build: {
        rollupOptions: {
            output: {
                manualChunks: (id) => {
                    // Create a separate chunk for CodeMirror
                    if (id.includes('codemirror') ||
                        id.includes('@codemirror/') ||
                        id.includes('@fsegurai/codemirror-theme-github-light')) {
                        return 'codemirror';
                    }
                }
            }
        }
    },
});
