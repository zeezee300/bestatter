import fs from 'node:fs'
import path from 'node:path'

const root = path.resolve(import.meta.dirname, '..')
const read = (name) => fs.readFileSync(path.join(root, name), 'utf8')
const expect = (condition, message) => { if (!condition) throw new Error(message) }

const documents = read('lib/Service/DocumentService.php')
for (const marker of ['replaceTextPlaceholders', 'replacePlaceholdersInNode', 'replaceTextRange', 'blankUnknown']) {
	expect(documents.includes(marker), `Laufübergreifende Platzhalterlogik fehlt: ${marker}`)
}
expect(documents.includes("preg_match_all('/\\{\\{[^{}]+\\}\\}/u'"), 'Unbekannte vollständige Platzhalter werden nicht erkannt')

for (const template of ['KONDOLENZLISTE_DECKBLATT.docx', 'KONDOLENZLISTE.docx']) {
	expect(fs.existsSync(path.join(root, 'resources/templates', template)), `Paketvorlage fehlt: ${template}`)
}

const css = read('css/style.css')
expect(css.includes('grid-template-columns: minmax(126px, max-content) minmax(0, 1fr)'), 'Rechnungsnummernbausteine haben keine kollisionsfreie Spaltenbreite')
expect(css.includes('.bp-compliance-note'), 'E-Rechnungs-Prüfstufen sind nicht verständlich gestaltet')

console.log('0.38.1 Dokumentplatzhalter, Kondolenzvorlagen und Rechnungsnummernlayout: statischer Vertrag OK')
