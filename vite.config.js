import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import path from 'path'

// Baut nach js/ und css/ direkt im App-Wurzelverzeichnis, mit den
// Dateinamen, die Nextclouds Util::addScript()/addStyle() erwarten:
// {AppId}-{Name}.js — hier "redmine_bruecke-main".
export default defineConfig({
  plugins: [vue()],
  build: {
    outDir: '.',
    emptyOutDir: false,
    rollupOptions: {
      input: path.resolve(__dirname, 'src/main.js'),
      output: {
        entryFileNames: 'js/redmine_bruecke-main.js',
        chunkFileNames: 'js/redmine_bruecke-[name].js',
        assetFileNames: (assetInfo) => {
          if (assetInfo.name && assetInfo.name.endsWith('.css')) {
            return 'css/redmine_bruecke-main.css'
          }
          return 'js/assets/[name][extname]'
        },
      },
    },
  },
})
