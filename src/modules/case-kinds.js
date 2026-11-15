/**
 * 0.89.7 (OP-074/OP-075) – Vorgangsarten der Fallakte: Sterbefall, Auftrag ohne Sterbefall, Vorsorge.
 *
 * Jede Art hat einen eigenen Navigationseintrag mit eigener Liste (gleiche Listenkomponente, Filter „kind“),
 * eigenen Nummernkreis (Sterbefall 2026-0001, Auftrag A-2026-0001, Vorsorge V-2026-0001) und eigenen Oberordner der Ablage.
 */
export const CASE_KINDS = ['STERBEFALL', 'AUFTRAG', 'VORSORGE']

export const CASE_KIND_LABELS = { STERBEFALL: 'Sterbefall', AUFTRAG: 'Auftrag', VORSORGE: 'Vorsorge' }
export const CASE_KIND_PLURALS = { STERBEFALL: 'Sterbefälle', AUFTRAG: 'Aufträge', VORSORGE: 'Vorsorgen' }

/** Navigationseintrag (data-view) je Vorgangsart. */
export const CASE_KIND_VIEWS = { STERBEFALL: 'cases', AUFTRAG: 'orders', VORSORGE: 'precare' }
export const CASE_LIST_VIEWS = Object.values(CASE_KIND_VIEWS)

/** Reiter der Fallakte, die es nur im Sterbefall gibt (Nebenaufträge zu demselben Verstorbenen, Abmeldungen). */
export const DEATH_ONLY_CASE_TABS = ['side-orders', 'deregistration']

/**
 * Mitgelieferte Auftragsarten (Konzept E3). Bis zur Pflege unter Customizing → Auftragsarten (Schritt B) feste Liste;
 * requiresDeceased: Angaben zur verstorbenen Person gehören dazu (Überführung, Urnenbeisetzung).
 */
export const DEFAULT_ORDER_TYPES = [
	{ key: 'VERKAUF', label: 'Verkauf', requiresDeceased: false },
	{ key: 'UEBERFUEHRUNG', label: 'Überführung', requiresDeceased: true },
	{ key: 'URNENBEISETZUNG', label: 'Urnenbeisetzung/Seebestattung', requiresDeceased: true },
	{ key: 'TRAUERFEIER', label: 'Trauerfeier/Abschiednahme', requiresDeceased: false },
]

export function normalizeCaseKind(value) {
	const kind = String(value || '').trim().toUpperCase()
	return CASE_KINDS.includes(kind) ? kind : 'STERBEFALL'
}

export function caseKindOf(item) {
	return normalizeCaseKind(item?.caseKind)
}

export function caseKindForView(view) {
	return Object.keys(CASE_KIND_VIEWS).find((kind) => CASE_KIND_VIEWS[kind] === view) || null
}

export function viewForCaseKind(kind) {
	return CASE_KIND_VIEWS[normalizeCaseKind(kind)]
}

export function orderTypeLabel(key, types = DEFAULT_ORDER_TYPES) {
	const found = (types || []).find((type) => type.key === key)
	return found ? found.label : (key || '')
}

/** Anzeigename der Hauptperson: Auftrag ohne Vornamen (z. B. Firma) nur mit dem Namen, sonst „Nachname, Vorname“. */
export function caseDisplayName(item) {
	const last = String(item?.lastName || '').trim()
	const first = String(item?.firstName || '').trim()
	return first ? `${last}, ${first}` : last
}

/** Vorbelegung der Auftrags-/KVA-Nummer: Sterbefall „A-2026-0001“, Auftrag und Vorsorge mit ihrer eigenen Nummer (kein „A-A-“). */
export function orderDocumentNumber(caseNumber, quote = false) {
	const number = String(caseNumber || '').trim()
	if (quote) return `KVA-${number}`
	return /^[AV]-/.test(number) ? number : `A-${number}`
}

/** Kopfzeile der Fallakte: „Fallakte 2026-0001“, „Auftrag A-2026-0001“, „Vorsorge V-2026-0001“. */
export function caseEyebrow(item) {
	const kind = caseKindOf(item)
	return `${kind === 'STERBEFALL' ? 'Fallakte' : CASE_KIND_LABELS[kind]} ${item?.caseNumber || ''}`.trim()
}

/**
 * 0.89.7 (Schritte B/C): Stammdaten je Vorgangsart. Der Sterbefall behält seine Bereiche (main.js); Aufträge zeigen die
 * verstorbene Person nur, wenn die Auftragsart sie verlangt (Überführung, Urnenbeisetzung), sonst den Kunden. Vorsorgen
 * führen Person, Anschrift und Wünsche – unter denselben Feldschlüsseln wie im Sterbefall, damit die Übernahme ohne
 * Umschlüsselung gelingt.
 */
const ADMINISTRATION_SECTIONS = [['Zuständigkeit', ['responsible_employee', 'branch']], ['Fallführung', ['status', 'close_reason', 'closed_at']]]
export const ORDER_DECEASED_SECTIONS = {
	'Verstorbene Person': [
		['Persönliche Angaben', ['salutation', 'title', 'first_name', 'last_name', 'birth_name']],
		['Geburt und Tod', ['date_of_birth', 'date_of_death', 'place_of_death']],
		['Letzter Wohnsitz', ['last_residence', 'last_residence_postal_code', 'last_residence_city', 'last_residence_country']],
	],
	Durchführung: [
		['Überführung', ['pickup_time', 'transfer_from', 'transfer_to', 'transfer_status']],
		['Beisetzung', ['funeral_type', 'cemetery_contact', 'grave_number', 'grave_type', 'grave_location', 'urn_handover_type', 'urn_handover_date', 'funeral_notes']],
	],
	Administration: ADMINISTRATION_SECTIONS,
}
export const ORDER_CUSTOMER_SECTIONS = {
	Kunde: [
		['Kunde', ['salutation', 'title', 'first_name', 'last_name']],
		['Durchführung', ['with_funeral_ceremony', 'cemetery_contact', 'funeral_notes']],
	],
	Administration: ADMINISTRATION_SECTIONS,
}
export const PRECARE_SECTIONS = {
	Person: [
		['Persönliche Angaben', ['salutation', 'title', 'first_name', 'additional_first_names', 'last_name', 'birth_name', 'profession']],
		['Geburt', ['date_of_birth', 'birth_place', 'birth_registry_office']],
		['Anschrift', ['last_residence', 'last_residence_postal_code', 'last_residence_city', 'last_residence_country']],
		['Familie und Zugehörigkeit', ['gender', 'civil_status', 'religion', 'religion_disclosure', 'nationality']],
		['Ehepartner/in', ['spouse_first_name', 'spouse_last_name', 'spouse_date_of_birth', 'spouse_birth_place', 'spouse_residence', 'spouse_date_of_death', 'spouse_death_place']], ['Ehe / Lebenspartnerschaft', ['marriage_date', 'marriage_place', 'partnership_date', 'divorce_date']],
		['Rentenversicherung', ['pension_insurance_number', 'pension_institution']],
	],
	Wünsche: [
		['Bestattung', ['funeral_type', 'with_funeral_ceremony', 'cemetery_contact', 'coffin_urn_size']],
		['Grab', ['grave_type', 'grave_location', 'grave_inscription']],
		['Abschied und Trauerfeier', ['precare_ceremony_place', 'precare_speaker', 'precare_music', 'precare_flowers', 'precare_clothing']],
		['Weitere Wünsche', ['precare_obituary', 'precare_notify', 'precare_documents_location', 'precare_other_wishes', 'funeral_notes']],
	],
	Administration: ADMINISTRATION_SECTIONS,
}
/** Zusätzliche Feldbezeichnungen der Vorsorge (Präfix precare_). */
export const PRECARE_LABELS = {
	precare_ceremony_place: 'Ort der Trauerfeier / Abschiednahme', precare_speaker: 'Redner/in, Pfarrer/in', precare_music: 'Musik',
	precare_flowers: 'Blumenschmuck', precare_clothing: 'Kleidung', precare_obituary: 'Traueranzeige (Zeitung, Text)',
	precare_notify: 'Wer soll benachrichtigt werden?', precare_documents_location: 'Ablageort wichtiger Unterlagen', precare_other_wishes: 'Weitere Wünsche',
}

/** Stammdatenbereiche der geöffneten Akte (null = Bereiche des Sterbefalls). */
export function masterSectionsFor(item, orderTypes = DEFAULT_ORDER_TYPES) {
	const kind = caseKindOf(item)
	if (kind === 'VORSORGE') return PRECARE_SECTIONS
	if (kind === 'AUFTRAG') return (orderTypes || []).find((type) => type.key === item?.orderType)?.requiresDeceased ? ORDER_DECEASED_SECTIONS : ORDER_CUSTOMER_SECTIONS
	return null
}

/** 0.89.7: Fehlt für die Stammdaten der Name? Aufträge brauchen nur den Nachnamen bzw. Firmennamen. */
export function masterNamesMissing(kind, data) {
	return !data.last_name || (!data.first_name && kind !== 'AUFTRAG')
}

/**
 * 0.89.7: Hinweis zum fehlenden Namen. Beim Tippen und Feldwechsel neutral (state „pending“), erst beim Verlassen des Formulars
 * als Fehler – vorher meldete schon der Wechsel vom Vor- ins Nachnamenfeld „Vor- und Nachname sind erforderlich.“
 */
export function masterNameNotice(kind, isNew, final) {
	const order = kind === 'AUFTRAG'
	if (final) return { text: order ? 'Der Name (bzw. Firmenname) ist erforderlich.' : 'Vor- und Nachname sind erforderlich.', state: 'error' }
	const what = order ? 'der Name (bzw. Firmenname) eingetragen ist' : 'Vor- und Nachname eingetragen sind'
	const subject = ({ AUFTRAG: 'Der Auftrag wird', VORSORGE: 'Die Vorsorge wird' })[kind] || 'Der Fall wird'
	return { text: isNew ? `${subject} angelegt, sobald ${what}.` : `Gespeichert wird, sobald ${what}.`, state: 'pending' }
}
