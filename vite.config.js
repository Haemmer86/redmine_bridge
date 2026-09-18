import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import path from 'path'

// Baut nach js/ und css/ direkt im App-Wurzelverzeichnis, mit den
// Dateinamen, die Nextclouds Util::addScript()/addStyle() erwarten:
// {AppId}-{Name}.js — hier "redmine_bridge-main".
export default defineConfig({
  plugins: [vue()],
  build: {
    outDir: '.',
    emptyOutDir: false,
    rollupOptions: {
      input: path.resolve(__dirname, 'src/main.js'),
      output: {
        // "iife" statt des Standardformats: kapselt unseren gesamten Code
        // in eine eigene, abgeschlossene Funktion. Ohne das landen unsere
        // internen Variablennamen im GLOBALEN Bereich der Seite — bindet
        // Nextcloud mehrere Apps zu einer gemeinsamen Datei zusammen
        // (eigene Optimierung, außerhalb unserer Kontrolle), kann das zu
        // Namenskollisionen mit einer völlig anderen App führen (genau
        // das ist uns mit der Kurzvariable "_" passiert). "iife" macht
        // das strukturell unmöglich, unabhängig davon, was Nextcloud sonst
        // noch dazu bündelt.
        format: 'iife',
        entryFileNames: 'js/redmine_bridge-main.js',
        chunkFileNames: 'js/redmine_bridge-[name].js',
        assetFileNames: (assetInfo) => {
          if (assetInfo.name && assetInfo.name.endsWith('.css')) {
            return 'css/redmine_bridge-main.css'
          }
          return 'js/assets/[name][extname]'
        },
      },
    },
  },
})
