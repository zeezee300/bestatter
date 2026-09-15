import fs from 'node:fs'
import path from 'node:path'

const root = process.argv[2] || '.'
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8')
const assert = (condition, message) => { if (!condition) throw new Error(message) }
const documents = read('src/modules/documents.js')
const records = read('src/modules/records.js')
const main = read('src/main.js')
const commercial = read('src/modules/commercial.js')
const styles = read('css/style.css')
const bundle = read('js/main.js')

for (const marker of ['x=1800&y=2400', 'bp-file-preview-viewport', 'data-preview-zoom-out', 'data-preview-zoom-in', 'data-preview-fit-page', 'data-preview-fit-width', 'Seitenbreite', 'Ganze Seite', 'data-open-preview-file']) {
	assert(documents.includes(marker), `Erweiterte Dokumentvorschau fehlt: ${marker}`)
}
for (const marker of ['bp-file-preview-viewport', 'data-preview-fit-page', 'data-preview-fit-width', 'Seitenbreite', 'Ganze Seite']) {
	assert(bundle.includes(marker), `Browser-Bundle enthält die neue Vorschau nicht: ${marker}`)
}
for (const marker of ['height: calc(100vh - 24px)', 'overflow: auto', '.bp-file-preview-toolbar', '.bp-file-preview-viewport']) {
	assert(styles.includes(marker), `Vorschau-Layout fehlt: ${marker}`)
}
for (const marker of ['x=800&y=1100', 'bp-document-thumb-fallback', 'alt=""']) {
	assert(records.includes(marker), `Robustes Dokument-Thumbnail fehlt: ${marker}`)
}
for (const marker of ['image.naturalWidth === 0', 'preview-unavailable']) {
	assert(main.includes(marker), `Thumbnail-Fehlerbehandlung fehlt: ${marker}`)
}
for (const marker of ['serviceGroupStorageKey', 'data-service-group', 'rememberServiceGroups', 'manuallySet?serviceGroupOpen[stateKey]', 'Boolean(query||state.serviceSelectedOnly)']) {
	assert(commercial.includes(marker), `Manueller Zustand der Leistungsblöcke fehlt: ${marker}`)
}
assert(styles.includes('justify-content: flex-start') && styles.includes('text-align: left'), 'Leistungsblöcke sind nicht eindeutig linksbündig')
assert(/<version>0\.(?:34\.[5-9]|3[5-9]\.\d+|[4-9]\d\.\d+)<\/version>/.test(read('appinfo/info.xml')), 'Releaseversion ab 0.34.5 fehlt')

console.log('0.34.5 readable document preview and zoom controls passed')
