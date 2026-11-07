import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.dirname(fileURLToPath(import.meta.url));

export default defineConfig({
  plugins: [react()],
  base: './',
  build: {
    outDir: path.resolve(root, '../assets/dist'),
    emptyOutDir: true,
    manifest: true,
    cssCodeSplit: true,
    target: 'es2022',
    sourcemap: false,
    chunkSizeWarningLimit: 4000,
    rollupOptions: {
      input: {
        app: path.resolve(root, 'src/app.js'),
        register: path.resolve(root, 'src/pages/register.js'),
        keysNew: path.resolve(root, 'src/pages/keys-new.js'),
        keysView: path.resolve(root, 'src/pages/keys-view.js'),
        keysEdit: path.resolve(root, 'src/pages/keys-edit.js'),
        projectView: path.resolve(root, 'src/pages/project-view.js'),
        settings: path.resolve(root, 'src/pages/settings.js'),
      },
      output: {
        entryFileNames: 'js/[name]-[hash].js',
        chunkFileNames: 'js/[name]-[hash].js',
        assetFileNames: 'assets/[name]-[hash][extname]',
      },
    },
  },
});
