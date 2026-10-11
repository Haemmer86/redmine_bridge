import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import path from 'path'
import fs from 'fs'

// Baut nach js/ und css/ direkt im App-Wurzelverzeichnis, mit den
// Dateinamen, die Nextclouds Util::addScript()/addStyle() erwarten:
// {AppId}-{Name}.js — hier "redmine_bridge-main".

// App-Version aus appinfo/info.xml lesen und in den Dateinamen einbauen
// (z. B. "redmine_bridge-main-0.25.16.js") — PageController::index() liest
// dieselbe Datei zur Laufzeit und baut denselben Dateinamen zusammen (siehe
// dort). Grund: Cloudflare hat nach einem Deploy wiederholt noch die alte
// JS-Datei ausgeliefert, obwohl Nextclouds eigene Versions-Query (?v=...)
// sich geändert hatte — offenbar cacht Cloudflare an manchen Standorten
// nach reinem Pfad, ohne die Query-String zu berücksichtigen. Ein Dateiname,
// der sich bei jeder Version ändert, macht das strukturell unmöglich: eine
// alte Version kann dann gar nicht mehr unter der neuen URL ausgeliefert
// werden, unabhängig davon, was irgendein Cache noch vorhält.
const infoXml = fs.readFileSync(path.resolve(__dirname, 'appinfo/info.xml'), 'utf-8')
const APP_VERSION = infoXml.match(/<version>([^<]+)<\/version>/)[1]

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
        entryFileNames: `js/redmine_bridge-main-${APP_VERSION}.js`,
        chunkFileNames: 'js/redmine_bridge-[name].js',
        assetFileNames: (assetInfo) => {
          if (assetInfo.name && assetInfo.name.endsWith('.css')) {
            return `css/redmine_bridge-main-${APP_VERSION}.css`
          }
          return 'js/assets/[name][extname]'
        },
      },
    },
  },
})
