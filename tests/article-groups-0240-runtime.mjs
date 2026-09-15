import { createCommercialModule } from '../src/modules/commercial.js'

const assert = (condition, message) => { if (!condition) throw new Error(message) }
const single = (id, group = 'Allgemein') => ({
	id, itemType: 'SINGLE', articleNumber: `A-${id}`, shortName: `Leistung ${id}`,
	longText: '', category: 'Dienstleistung', articleGroup: group, costType: 'INTERNAL',
	funeralScope: 'ALL', vatRate: 19, unit: 'STK', quantityDecimals: 0,
	salesPriceCents: 1000 + id, active: true, components: [], exclusiveGroup: group === 'Sarg',
})

const articles = Array.from({ length: 45 }, (_, index) => single(index + 1))
articles.push(single(100, 'Sarg'), single(101, 'Sarg'))
articles.push({ ...single(200, 'Pakete'), itemType: 'PACKAGE', shortName: 'Paket mit Sarg', components: [{ articleId: 100, articleNumber: 'A-100', shortName: 'Leistung 100', articleGroup: 'Sarg', quantity: 1, exclusiveGroup: true }] })

const state = {
	articles,
	articleGroupRules: [{ groupName: 'Allgemein', exclusiveSelection: false }, { groupName: 'Sarg', exclusiveSelection: true }, { groupName: 'Pakete', exclusiveSelection: false }],
	currentCase: { id: 1, caseNumber: 'TEST-1', masterData: {} },
	caseServices: { items: [], totals: { netCents: 0, vatCents: 0, grossCents: 0 } },
	commercial: { documents: [] }, serviceDraft: {}, serviceQuery: '', serviceCostType: 'ALL',
	serviceArticleGroup: 'ALL', serviceItemType: 'ALL', serviceSelectedOnly: false,
	serviceCatalogOpen: true, servicePage: 1, servicePageSize: 20,
}
const esc = (value) => String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
const module = createCommercialModule({
	root: { querySelector: () => null, querySelectorAll: () => [] }, state, esc,
	api: async () => ({}), caseServicesUrl: () => '', commercialVersionPanel: () => '',
	field: () => '', render: () => {}, title: () => '',
})

let html = module.serviceSelectionPanel()
assert((html.match(/class="bp-service-card/g) || []).length === 20, 'Seite 1 rendert nicht genau 20 Katalogpositionen.')
assert(html.includes('Seite 1 von 3'), 'Seitennavigation für 48 Positionen ist falsch.')
state.servicePage = 3
html = module.serviceSelectionPanel()
assert((html.match(/class="bp-service-card/g) || []).length === 8, 'Letzte Seite rendert nicht die verbleibenden 8 Positionen.')

state.serviceDraft = { 101: { selected: true, quantity: 1 }, 200: { selected: true, quantity: 1 } }
const conflicts = module.serviceConflictMessages()
assert(conflicts.length === 1, 'Paket und direkter Artikel derselben Exklusivgruppe werden nicht als ein Konflikt erkannt.')
assert(conflicts[0].includes('Sarg') && conflicts[0].includes('Paket mit Sarg') && conflicts[0].includes('Leistung 101'), 'Konflikthinweis nennt Gruppe, Paketquelle und Gegenposition nicht vollständig.')

state.serviceDraft = { 1: { selected: true, quantity: 1 }, 2: { selected: true, quantity: 1 } }
assert(module.serviceConflictMessages().length === 0, 'Nicht exklusive Gruppen dürfen mehrere Positionen enthalten.')
state.servicePage = 1
html = module.serviceSelectionPanel()
assert(!/<details class="bp-service-group"[^>]*\sopen/.test(html), 'Ausgewählte Positionen dürfen einen geschlossenen Leistungsblock nicht automatisch öffnen.')

state.serviceQuery = 'Leistung 42'
state.serviceLinesDraft = [{ id: -1, articleId: 0, articleNumber: '', title: '', quantity: 1, quantityDecimals: 3, unit: 'STK', unitPriceCents: 0, vatRate: 19, positionType: 'EL', origin: 'FREE_TEXT', position: 10 }]
html = module.serviceSelectionPanel()
assert((html.match(/class="bp-service-card/g) || []).length === 1, 'Katalogsuche filtert nicht auf die passende Position.')
assert(html.includes('id="service-catalog-title"'), 'Katalogdialog bleibt für die Suche geöffnet.')
assert(html.includes('data-service-add="42"'), 'Katalogposition bietet keine explizite Hinzufügen-Aktion.')
assert(html.includes('data-article-input') && html.includes('data-article-suggestions'), 'Positionszeile bietet keine Material-/Leistungsnummer-Autovervollständigung.')

console.log('Artikelgruppen 0.24.1: Pagination und Paketkonflikt im Laufzeittest OK')
