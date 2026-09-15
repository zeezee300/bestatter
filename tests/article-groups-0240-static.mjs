import fs from 'node:fs'
import path from 'node:path'
import { readApiControllers } from './helpers/controller-source.mjs'

const root = path.resolve(process.argv[2] || '.')
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8')
const assert = (condition, message) => { if (!condition) throw new Error(message) }

const migration = read('lib/Migration/Version1800Date20260906000000.php')
const articles = read('lib/Service/ArticleService.php')
const controller = readApiControllers(root)
const routes = read('appinfo/routes.php')
const commercial = read('src/modules/commercial.js')
const administration = read('src/modules/administration.js')
const main = read('src/main.js')
const css = read('css/style.css')
const info = read('appinfo/info.xml')

assert(/<version>0\.(?:2[4-9]|[3-9]\d)\./.test(info), 'App-Version muss mindestens 0.24.x sein.')
for (const marker of ['bestatter_article_groups', 'exclusive_selection', 'addUniqueIndex', 'bestatter_article_group_name']) {
	assert(migration.includes(marker), `Gruppenmigration unvollständig: ${marker}`)
}
assert(migration.includes("'exclusive_selection','boolean',['default'=>false]"), 'Konsolidierte Baseline definiert die exklusive Gruppenregel nicht als Boolean.')
for (const marker of ['saveArticleGroupRule', 'articleGroupRules', 'ensureArticleGroupRules', "'groupRules' =>", 'In der exklusiven Artikelgruppe']) {
	assert(articles.includes(marker), `Serverseitige Gruppenregel unvollständig: ${marker}`)
}
assert(articles.includes('$exclusiveSelection ? 1 : 0') && articles.includes("$values['exclusiveGroup'] ? 1 : 0"), 'Laufzeitpflege bindet Gruppenregel oder Kompatibilitätsspalte nicht MariaDB-sicher als 0/1.')
assert(controller.includes('function saveArticleGroup') && controller.includes('requireBestatterAdmin'), 'Administrativer Gruppenendpunkt ist nicht geschützt.')
assert(routes.includes("'/api/article-groups'"), 'Route für Artikelgruppen fehlt.')
for (const marker of ['servicePageSize', 'servicePage', 'service-page-size', 'data-service-page', 'filtered.slice', 'Seite ${state.servicePage}']) {
	assert((commercial + main).includes(marker), `Paginierte Katalogauswahl unvollständig: ${marker}`)
}
for (const marker of ['Auswahlregeln der Artikelgruppen', 'data-article-group-rule', 'exclusiveSelection', 'Die Prüfung umfasst Einzelartikel und Bestandteile']) {
	assert(administration.includes(marker), `Administrationspflege der Gruppenregeln unvollständig: ${marker}`)
}
for (const marker of ['bp-pagination', 'bp-group-rule-grid', 'bp-group-rule']) assert(css.includes(marker), `Layoutregel fehlt: ${marker}`)

console.log('Artikelgruppen und paginierte Leistungsauswahl ab 0.24: statischer Vertrag OK')
