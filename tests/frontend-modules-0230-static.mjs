import fs from 'node:fs'
import path from 'node:path'

const root = path.resolve(process.argv[2] || '.')
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8')
const assert = (condition, message) => { if (!condition) throw new Error(message) }

const info = read('appinfo/info.xml')
const packageJson = JSON.parse(read('package.json'))
const vite = read('vite.config.js')
const main = read('src/main.js')
const bundle = read('js/main.js')
const modules = ['cases', 'records', 'commercial', 'documents', 'administration', 'customizing', 'paperless']

assert(/<version>0\.(?:2[4-9]|[3-9]\d)\./.test(info), 'Frontend benötigt mindestens Version 0.24.x.')
assert(packageJson.scripts.build === 'vite build', 'Standard-Buildskript fehlt.')
assert(packageJson.devDependencies.vite, 'Vite ist nicht als Entwicklungsabhängigkeit festgeschrieben.')
assert(vite.includes("formats: ['iife']") && vite.includes("fileName: () => 'main.js'"), 'Nextcloud-kompatibler IIFE-Build fehlt.')
assert(main.split(/\r?\n/).length < 750, 'src/main.js ist weiterhin ein Monolith.')
for (const name of modules) {
	assert(main.includes(`from './modules/${name}.js'`), `Fachmodul ${name} wird nicht importiert.`)
	assert(read(`src/modules/${name}.js`).includes('export function create'), `Fachmodul ${name} exportiert keine Factory.`)
}
assert(main.includes("from './modules/ui.js'"), 'app-weites UI-Infrastrukturmodul wird nicht importiert.')
assert(read('src/modules/ui.js').includes('export function createUi'), 'UI-Infrastruktur exportiert keine Factory.')
assert(bundle.startsWith('(function()') || bundle.startsWith('!function('), 'Produktionsbundle ist kein klassisches IIFE-Skript.')
assert(!/^\s*import\s/m.test(bundle), 'Produktionsbundle enthält unverarbeitete ES-Imports.')
assert(bundle.length > 100000, 'Produktionsbundle ist unerwartet klein oder leer.')

console.log(`Frontend 0.23.0: ${modules.length} Fachmodule, ${main.split(/\r?\n/).length} Zeilen im Einstieg, IIFE-Bundle OK`)
