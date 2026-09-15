import fs from 'node:fs'
import assert from 'node:assert/strict'

const read = (file) => fs.readFileSync(new URL('../' + file, import.meta.url), 'utf8')
const info = read('appinfo/info.xml')
const app = read('lib/AppInfo/Application.php')
const pkg = JSON.parse(read('package.json'))
const article = read('lib/Service/ArticleService.php')
const commercial = read('src/modules/commercial.js')
const migration = read('lib/Migration/Version3300Date20260911000000.php')
const css = read('css/style.css')
const opList = read('docs/OP-LISTE.md')

assert(info.includes('<version>0.59.0</version>') && app.includes("VERSION = '0.59.0'") && pkg.version === '0.59.0', 'Version 0.59.0 ist nicht konsistent.')
assert(article.includes('$activeItems') && article.includes("!== 'STORNIERT'"), 'Stornierte Positionen werden nicht sicher aus den Summen ausgeschlossen.')
assert(article.includes("(int)$item['id'] !== (int)$current['id']") && article.includes('unset($existingItems)'), 'Aktualisierte Positionen werden nicht aus der Löschkandidatenliste entfernt.')
assert(migration.includes("hasColumn('note')") && migration.includes("'notnull' => false"), 'Migration für Positionsbemerkungen fehlt oder ist nicht bestandsdatensicher.')
for (const marker of ['matchRank', 'highlightMatch', 'aria-activedescendant', "event.key === 'Escape'", 'bp-more-matches']) assert(commercial.includes(marker), 'Matchcode-Suche unvollständig: ' + marker)
for (const marker of ['positionArticleMatches', 'document.body.append(box)', "box.dataset.placement = openAbove ? 'above' : 'below'", "document.addEventListener('scroll'"]) assert(commercial.includes(marker), 'Flexible schwebende Trefferliste unvollständig: ' + marker)
assert(css.includes('body > .bp-article-suggestions') && css.includes('position: fixed'), 'Die Trefferliste kann weiterhin vom Tabellen-Scrollcontainer abgeschnitten werden.')
for (const marker of ['Gesamt netto', 'MwSt.-Satz', 'service-position-filter', 'bp-service-detail-row', 'bp-type-totals', 'Bemerkung zur Position']) assert(commercial.includes(marker), 'Phase 1/2 fehlt: ' + marker)
assert(!commercial.includes('data-duplicate-service-line'), 'Die vorläufig deaktivierte Funktion „Duplizieren“ ist noch sichtbar oder bedienbar.')
for (const marker of ['bp-service-row-error', 'bp-service-row-warning', 'bp-line-validation']) assert(commercial.includes(marker) || css.includes(marker), 'Unvollständigkeitsanzeige fehlt: ' + marker)
assert(opList.includes('0.54.0') && opList.includes('Favoriten') && opList.includes('Staffelpreise'), 'Nachgelagerte Phase 3 fehlt in der OP-Liste.')

console.log('0.54.0 Positionsstabilisierung und Ausbaustufen 1 bis 2: statischer Vertrag OK')
