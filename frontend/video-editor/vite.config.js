import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  base: '/gallery/assets/video-editor/',
  plugins: [react()],
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    rollupOptions: {
      output: {
        entryFileNames: 'video-editor.js',
        assetFileNames: 'video-editor.[ext]'
      }
    }
  }
});
