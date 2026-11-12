/**
 * 0.75.0 – Anwenderhandbuch: Adresse und passende Sprungmarke je Arbeitsbereich.
 * Die Sprungmarken entsprechen den Kapiteldateien docs/handbuch/NN-<kapitel>.md bzw. „<kapitel>-<überschrift>“;
 * tests/handbuch-static.mjs prüft, dass jedes Ziel im erzeugten Handbuch existiert.
 */
export const handbookSlug = (text) => String(text).toLowerCase()
	.replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss')
	.replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '')

export const HANDBOOK_VIEWS = {
	dashboard: 'dashboard', capture: 'schnellerfassung', cases: 'fallakte', orders: 'auftraege-vorsorgen', precare: 'auftraege-vorsorgen', task: 'aufgaben-termine', schedule: 'aufgaben-termine',
	document: 'dokumente', inbox: 'belegeingang', accounting: 'buchhaltung', 'master-data': 'stammdaten-betrieb', administration: 'administration', customizing: 'customizing',
}
export const HANDBOOK_CASE_TABS = {
	overview: 'fallakte-reiter-uebersicht', master: 'stammdaten', order: 'auftrag', 'side-orders': 'nebenauftraege', contact: 'kontakte',
	task: 'aufgaben-termine-aufgaben', schedule: 'aufgaben-termine-termine', deregistration: 'abmeldungen', document: 'dokumente',
	services: 'leistungen', finances: 'finanzen', history: 'datenschutz',
}
export const HANDBOOK_ADMIN_TABS = {
	onboarding: 'administration-ersteinrichtung', cockpit: 'administration-betriebs-cockpit', invoices: 'administration-rechnungswesen', paperless: 'administration-paperless',
	reports: 'administration-kennzahlen-auswertungen', assistant: 'administration-assistenz', backup: 'datensicherung', setup: 'administration-einrichtungspaket', system: 'administration-systempruefung',
}
export const HANDBOOK_CUSTOMIZING_TABS = {
	processes: 'customizing-ablaeufe', 'burial-rules': 'customizing-variantenregeln', surcharges: 'customizing-zuschlagsstaffeln',
	scheduling: 'customizing-terminplanung', documents: 'customizing-dokumentvorlagen', deregistration: 'customizing-abmeldungen', 'document-kinds': 'customizing-dokumentarten', 'order-choices': 'customizing-ankreuzfelder', 'mail-templates': 'customizing-e-mail-vorlagen',
	'order-types': 'customizing-auftragsarten', precare: 'customizing-vorsorge',
}

// 0.92.0: Einstellungen → Stammdaten (Kapitel „Stammdaten des Betriebs“)
export const HANDBOOK_MASTER_DATA_TABS = {
	branches: 'stammdaten-betrieb-niederlassungen-und-laenderprofile', resources: 'stammdaten-betrieb-ressourcen', services: 'stammdaten-betrieb-leistungen-leistungskatalog',
	'value-lists': 'stammdaten-betrieb-wertelisten', labels: 'stammdaten-betrieb-marker',
}

export function handbookUrl() {
	const generate = globalThis.OC?.generateUrl
	return typeof generate === 'function' ? generate('/apps/bestatter/handbuch/index.html') : '/index.php/apps/bestatter/handbuch/index.html'
}

/** Sprungmarke für den aktuellen Arbeitsbereich. */
export function handbookAnchor(state) {
	if (state.view === 'case-detail') return HANDBOOK_CASE_TABS[state.caseTab] || 'fallakte'
	if (state.view === 'master-data') return HANDBOOK_MASTER_DATA_TABS[state.masterDataTab] || 'stammdaten-betrieb'
	if (state.view === 'administration') return HANDBOOK_ADMIN_TABS[state.administrationTab] || 'administration'
	if (state.view === 'customizing') return HANDBOOK_CUSTOMIZING_TABS[state.customizingTab] || 'customizing'
	return HANDBOOK_VIEWS[state.view] || ''
}

export const handbookLink = (state) => `${handbookUrl()}${handbookAnchor(state) ? '#' + handbookAnchor(state) : ''}`

/** Link auf den Kasten „Kurz erklärt“, aus dem eine Antwort der Bedienhilfe stammt. */
export const handbookHelpLink = (heading) => `${handbookUrl()}#h-${handbookSlug(heading)}`

/** 0.89.6: dezenter Textlink am Ende einer Seitenbeschreibung (nur an Seiten mit Regelpflege). */
export const handbookMoreLink = (anchor, esc) => ` <a class="bp-more-link" href="${esc(`${handbookUrl()}#${anchor}`)}" target="_blank" rel="noopener">Mehr im Handbuch</a>`

/** 0.89.6: „Was bedeutet das?“ an Hinweisen – öffnet die Bedienhilfe mit dem Hilfetext (Kennung h-…). */
export const helpExplainButton = (helpId, esc, label = 'Was bedeutet das?') => ` <button type="button" class="bp-link-button" data-help-open="${esc(helpId)}">${esc(label)}</button>`
