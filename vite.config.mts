import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// The package's own POS views (Bootstrap markup) as one self-contained IIFE with Vue 3 included: js/dist/quick-pos.js.
// Themes can render their own views on top of js/src/core (the Blackout theme does).
export default defineConfig({
  plugins: [vue()],
  define: { 'process.env.NODE_ENV': JSON.stringify('production') },
  build: {
    outDir: 'js/dist',
    emptyOutDir: true,
    sourcemap: false,
    lib: {
      entry: 'js/src/bootstrap/main.ts',
      name: 'QuickPos',
      formats: ['iife'],
      fileName: () => 'quick-pos.js',
    },
  },
})
