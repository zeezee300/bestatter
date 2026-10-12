/**
 * 0.75.0 – Anwenderhandbuch: Adresse und passende Sprungmarke je Arbeitsbereich.
 * Die Sprungmarken entsprechen den Kapiteldateien docs/handbuch/NN-<kapitel>.md bzw. „<kapitel>-<überschrift>“;
 * tests/handbuch-static.mjs prüft, dass jedes Ziel im erzeugten Handbuch existiert.
 */
export const handbookSlug = (text) => String(text).toLowerCase()
	.replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss')
	.replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '')

export const HANDBOOK_VIEWS = {
	dashboard: 'dashboard', capture: 'schnellerfassung', cases: 'fallakte', task: 'aufgaben-termine', schedule: 'aufgaben-termine',
	document: 'dokumente', inbox: 'belegeingang', administration: 'administration', customizing: 'customizing',
}
export const HANDBOOK_CASE_TABS = {
	overview: 'fallakte-reiter-uebersicht', master: 'stammdaten', order: 'auftrag', 'side-orders': 'nebenauftraege', contact: 'kontakte',
	task: 'aufgaben-termine-aufgaben', schedule: 'aufgaben-termine-termine', deregistration: 'abmeldungen', document: 'dokumente',
	services: 'leistungen', finances: 'finanzen', history: 'datenschutz',
}
export const HANDBOOK_ADMIN_TABS = {
	onboarding: 'administration-ersteinrichtung', cockpit: 'administration-betriebs-cockpit', services: 'administration-leistungen-leistungskatalog',
	branches: 'administration-niederlassungen-und-laenderprofile', invoices: 'administration-rechnungswesen', paperless: 'administration-paperless',
	reports: 'administration-kennzahlen-auswertungen', assistant: 'administration-assistenz', backup: 'datensicherung', setup: 'administration-einrichtungspaket', system: 'administration-systempruefung',
}
export const HANDBOOK_CUSTOMIZING_TABS = {
	processes: 'customizing-ablaeufe', 'value-lists': 'customizing-wertelisten', 'burial-rules': 'customizing-variantenregeln', surcharges: 'customizing-zuschlagsstaffeln',
	scheduling: 'customizing-terminplanung', documents: 'customizing-dokumentvorlagen', deregistration: 'customizing-abmeldungen', labels: 'customizing-marker', 'document-kinds': 'customizing-dokumentarten',
}

export function handbookUrl() {
	const generate = globalThis.OC?.generateUrl
	return typeof generate === 'function' ? generate('/apps/bestatter/handbuch/index.html') : '/index.php/apps/bestatter/handbuch/index.html'
}

/** Sprungmarke für den aktuellen Arbeitsbereich. */
export function handbookAnchor(state) {
	if (state.view === 'case-detail') return HANDBOOK_CASE_TABS[state.caseTab] || 'fallakte'
	if (state.view === 'administration') return HANDBOOK_ADMIN_TABS[state.administrationTab] || 'administration'
	if (state.view === 'customizing') return HANDBOOK_CUSTOMIZING_TABS[state.customizingTab] || 'customizing'
	return HANDBOOK_VIEWS[state.view] || ''
}

export const handbookLink = (state) => `${handbookUrl()}${handbookAnchor(state) ? '#' + handbookAnchor(state) : ''}`

/** Link auf den Kasten „Kurz erklärt“, aus dem eine Antwort der Bedienhilfe stammt. */
export const handbookHelpLink = (heading) => `${handbookUrl()}#h-${handbookSlug(heading)}`
