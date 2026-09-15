import fs from 'node:fs'
import assert from 'node:assert/strict'

const read = (file) => fs.readFileSync(new URL('../' + file, import.meta.url), 'utf8')
const article = read('lib/Service/ArticleService.php')
const commercial = read('src/modules/commercial.js')
const migration = read('lib/Migration/Version3100Date20260909000000.php')
const categoryMigration = read('lib/Migration/Version3200Date20260910000000.php')
const commercialState = read('lib/Service/CommercialStateService.php')
const customizing = read('lib/Service/CustomizingService.php')
const initialCustomizing = read('resources/initial-customizing.json')
const ticket = read('docs/TICKET-AUFTRAG-KVA-POSITIONSERFASSUNG.md')
const css = read('css/style.css')

for (const marker of ['position_type', 'FREE_TEXT', 'positionNumber', 'positionType', 'positionNo']) {
	assert(article.includes(marker), 'Positionsmodell fehlt: ' + marker)
}
for (const marker of ['Eine Freitextposition benötigt einen Preis', 'Mehrwertsteuersatz der Freitextposition', 'allowedVatRates']) assert(article.includes(marker), 'Freitext-Plausibilisierung fehlt: ' + marker)
for (const marker of ['emptyServiceLine', 'normalizeServiceLines', 'bp-service-entry-row', 'bp-service-detail-row', 'data-line-field', 'EL', 'FK', 'DP']) {
	assert(commercial.includes(marker), 'Positionsoberfläche fehlt: ' + marker)
}
assert(!commercial.includes('id="add-free-text-service"'), 'Die SAP-artige Leerzeile darf keinen Button „Neue Position“ benötigen.')
assert(commercial.indexOf('<th>Materialnummer</th>') < commercial.indexOf('<th>Bezeichnung</th>'), 'Materialnummer muss vor der Bezeichnung stehen.')
assert(commercial.includes('tabindex="-1" data-show-service-details'), 'Der Detailzugang unterbricht die schnelle Tabulator-Reihenfolge.')
assert(commercialState.includes('effectiveOrderStatus') && commercialState.includes('statusInconsistent') && article.includes("return ($index + 1) * 10"), 'Zentrale Statusprüfung oder fortlaufende 10er-Nummerierung fehlt.')
assert(migration.includes('Version3100Date20260909000000'), 'Positionsmigration fehlt.')
assert(categoryMigration.includes('article_category'), 'Kategorie-Snapshot der Position fehlt.')
assert(categoryMigration.includes("'notnull' => false") && !categoryMigration.includes("'default' => ''"), 'Kategorie-Migration muss für Bestandszeilen nullable und ohne leeren String-Default sein.')
for (const marker of ['POSITION_TYPE', 'QUANTITY_UNIT']) assert(customizing.includes(marker), 'Feste Werteliste fehlt: ' + marker)
for (const marker of ['eigene Leistung', 'Fremdkosten/Fremdleistung', 'echter durchlaufender Posten', 'Stück']) assert(initialCustomizing.includes(marker), 'Bezeichnung der Werteliste fehlt: ' + marker)
assert(ticket.includes('10, 20, 30') && ticket.includes('Freitextpositionen'), 'Realisierungsticket unvollständig.')
assert(css.includes('.bp-article-suggestions') && css.includes('.bp-service-catalog-dialog') && commercial.includes('open-service-catalog'), 'Inline-Auswahl beziehungsweise optionaler Katalogdialog nicht korrekt gestaltet.')
assert(css.includes('width: min(1680px,calc(100vw - 32px))') && css.includes('height: calc(100dvh - 32px)') && css.includes('.bp-service-catalog-dialog .bp-service-browser'), 'Der Leistungskatalog nutzt den Desktop-Bildschirm nicht flexibel aus.')
assert(commercial.includes('rememberCatalogViewport') && commercial.includes('serviceCatalogScrollTop'), 'Die Katalogauswahl erhält ihre Scrollposition nicht.')
for (const marker of ['bp-origin-code', 'KAT', 'FREI', 'bp-coded-select', 'bp-title-input-row', 'data-option-label']) assert(commercial.includes(marker) || css.includes(marker), 'SAP-artige Kurz-/Herkunftsanzeige fehlt: ' + marker)
console.log('0.53.2 Auftrag-/KVA-Positionserfassung: statischer Vertrag OK')
