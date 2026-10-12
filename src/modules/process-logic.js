// Reine, DOM-freie Logik der Ablaufvorlagen (0.66.0): Satzvorschau, Probelauf, Prüfhinweise.
// Bewusst ohne Seiteneffekte, damit sie in tests/process-logic-runtime.mjs direkt geprüft werden kann.

export const PHASES = {
	1: 'Erstkontakt & Überführung',
	2: 'Beratung & Beauftragung',
	3: 'Behörden & Formalitäten',
	4: 'Trauerfeier & Beisetzung',
	5: 'Abmeldungen',
	6: 'Abrechnung',
	7: 'Nachsorge & Abschluss',
}

export const DEFAULT_META = {
	phases: PHASES,
	triggers: { CASE_CREATED: 'Fall angelegt', FUNERAL_TYPE_SET: 'Bestattungsart festgelegt', FIELD_SET: 'Stammdatenfeld ausgefüllt', CASE_STATUS: 'Fallstatus gewechselt', QUOTE_CREATED: 'KVA erstellt', ORDER_COMMISSIONED: 'Auftrag erteilt', ORDER_SIGNED: 'Auftrag unterschrieben', SIDE_ORDER_COMMISSIONED: 'Nebenauftrag beauftragt', SIDE_ORDER_CLOSED: 'Nebenauftrag abgeschlossen', SCHEDULE_CONFIRMED: 'Termin bestätigt', SCHEDULE_DONE: 'Termin stattgefunden', SCHEDULE_CHANGED: 'Termin geändert', SCHEDULE_CANCELLED: 'Termin abgesagt', DOCUMENT_CREATED: 'Dokument erzeugt', DOCUMENT_FINAL: 'Dokument finalisiert', DOCUMENT_SENT: 'Dokument per E-Mail versendet', DOCUMENT_RECEIVED: 'Dokument eingegangen', DEREGISTRATION_SENT: 'Abmeldung versendet', DEREGISTRATION_CONFIRMED: 'Abmeldung bestätigt', STEP_DONE: 'anderer Schritt erledigt', DEPOSIT_AGREED: 'Anzahlung vereinbart', INCOMING_INVOICE: 'Eingangsrechnung erfasst', FINAL_INVOICE_ISSUED: 'Schlussrechnung freigegeben', PAYMENT_RECEIVED: 'Schlussrechnung bezahlt', INVOICE_OVERDUE: 'Rechnung überfällig', DATE_REACHED: 'Datum erreicht', LABEL_SET: 'Marker gesetzt', LABEL_REMOVED: 'Marker entfernt', MANUAL: 'nur von Hand' },
	caseStatuses: { NEU: 'Neu', IN_BEARBEITUNG: 'In Bearbeitung', ABGESCHLOSSEN: 'Abgeschlossen' },
	dateReferences: { DEATH_DATE: 'Sterbedatum', CEREMONY: 'Trauerfeier', BURIAL: 'Beisetzung', CASE_CREATED: 'Fallanlage', CASE_CLOSED: 'Fallabschluss' },
	caseFields: [],
	documentTemplates: [],
	deregistrationTemplates: [],
	references: { TRIGGER: 'Auslösung', CASE_CREATED: 'Fallanlage', DEATH_DATE: 'Sterbedatum', CEREMONY: 'Trauerfeier', BURIAL: 'Beisetzung' },
	roleLabels: { '': 'wie Auslöser / aktuelle Person', FALLVERANTWORTLICH: 'Fallverantwortliche/r', BESTATTER: 'Bestattung', BUERO: 'Büro', FAHRER: 'Fahrer', BUCHHALTUNG: 'Buchhaltung' },
	priorities: { KRITISCH: 'kritisch', HOCH: 'hoch', NORMAL: 'normal', NIEDRIG: 'niedrig' },
	scopes: { ALL: 'alle Fälle', CREMATION: 'Feuerbestattung (inkl. See/Wald)', BURIAL: 'Erdbestattung', NATURE: 'See-, Baum- und Waldbestattung', GRAVE: 'Erd- und Urnengrab mit Grabstelle' },
	completions: { NONE: 'von Hand abhaken', FUNERAL_TYPE_SET: 'Bestattungsart festgelegt', ORDER_COMMISSIONED: 'Auftrag erteilt', DOCUMENT_CREATED: 'Dokument erzeugt', DEREGISTRATIONS_CREATED: 'Abmeldungen angelegt', DEREGISTRATIONS_SENT: 'alle Abmeldungen versendet', SCHEDULE_CONFIRMED: 'Termin bestätigt', SCHEDULE_DONE: 'Termin stattgefunden', FIELD_SET: 'Stammdatenfeld ausgefüllt', CASE_STATUS: 'Fallstatus erreicht', QUOTE_CREATED: 'KVA erstellt', ORDER_SIGNED: 'Auftrag unterschrieben', SIDE_ORDER_CLOSED: 'Nebenauftrag abgeschlossen', DOCUMENT_FINAL: 'Dokument finalisiert', DOCUMENT_SENT: 'Dokument per E-Mail versendet', DOCUMENT_RECEIVED: 'Dokument eingegangen', DEREGISTRATION_SENT: 'Abmeldung versendet', DEREGISTRATION_CONFIRMED: 'Abmeldung bestätigt', DEPOSIT_RECEIVED: 'Anzahlung eingegangen', INCOMING_TRANSFERRED: 'Eingangsrechnung übernommen', FINAL_INVOICE_ISSUED: 'Schlussrechnung freigegeben', PAYMENT_RECEIVED: 'Schlussrechnung bezahlt', LABEL_SET: 'Marker gesetzt', LABEL_REMOVED: 'Marker entfernt' },
	actionTypes: { DOCUMENT: 'Dokument erzeugen', DOCUMENT_BUNDLE: 'Dokumentpaket erzeugen', SCHEDULE: 'Termin anlegen', DEREGISTRATIONS: 'Abmeldungen anlegen', EMAIL_DRAFT: 'E-Mail-Entwurf', TASK: 'Folgeaufgabe', REMINDER: 'Wiedervorlage', CONTACT_ACTIVITY: 'Kontakt dokumentieren', OPEN_VIEW: 'Bereich öffnen', SURCHARGE_CHECK: 'Leistungs- und Zuschlagsprüfung', CASE_STATUS: 'Fallstatus setzen', CASE_CLOSE: 'Fall abschließen', LABEL: 'Marker setzen/entfernen' },
	schedulePresets: [],
	labels: [],
	documentKinds: [],
}

const TRIGGER_PHRASES = {
	CASE_CREATED: 'sobald der Fall angelegt ist',
	FUNERAL_TYPE_SET: 'sobald die Bestattungsart feststeht',
	ORDER_COMMISSIONED: 'sobald der Auftrag erteilt ist',
	DEPOSIT_AGREED: 'sobald eine Anzahlung vereinbart ist',
	INCOMING_INVOICE: 'für jede erfasste Eingangsrechnung',
	FINAL_INVOICE_ISSUED: 'sobald die Schlussrechnung freigegeben ist',
	PAYMENT_RECEIVED: 'sobald die Schlussrechnung bezahlt ist',
	QUOTE_CREATED: 'sobald der KVA erstellt ist',
	ORDER_SIGNED: 'sobald der Auftrag unterschrieben ist',
	SIDE_ORDER_COMMISSIONED: 'für jeden beauftragten Nebenauftrag',
	SIDE_ORDER_CLOSED: 'für jeden abgeschlossenen Nebenauftrag',
	MANUAL: 'nur wenn er von Hand hinzugefügt wird',
}

export const SCHEDULE_TRIGGERS = ['SCHEDULE_CONFIRMED', 'SCHEDULE_DONE', 'SCHEDULE_CHANGED', 'SCHEDULE_CANCELLED']
export const DOCUMENT_TRIGGERS = ['DOCUMENT_CREATED', 'DOCUMENT_FINAL', 'DOCUMENT_SENT']
export const DEREGISTRATION_TRIGGERS = ['DEREGISTRATION_SENT', 'DEREGISTRATION_CONFIRMED']
export const LABEL_TRIGGERS = ['LABEL_SET', 'LABEL_REMOVED']
/** 0.80.0 (OP-058): Auslöser bzw. Erledigt-Bedingung mit Dokumentart. */
export const RECEIVED_TRIGGERS = ['DOCUMENT_RECEIVED']

/** Name einer Dokumentart, z. B. „Sterbeurkunde“. */
export function documentKindText(meta = DEFAULT_META, key = '') {
	return (meta.documentKinds || []).find((entry) => entry.key === key)?.name || key || '…'
}

/** Klartext eines Markers (mit Wert bei Listen-Markern), z. B. „Kostenträger: Sozialamt“. */
export function labelText(meta = DEFAULT_META, key = '', value = '') {
	const label = (meta.labels || []).find((entry) => entry.key === key)
	if (!label) return key || '…'
	const valueName = value ? (label.values || []).find((entry) => entry.key === value)?.name || value : ''
	return valueName ? `${label.name}: ${valueName}` : label.name
}

function labelFor(list, key) {
	return (list || []).find((entry) => entry.key === key)?.name || (list || []).find((entry) => entry.key === key)?.label || key
}

/** Klartext des Auslösers („sobald …“), auch für die neuen Auslöser ab 0.68.0. */
export function triggerPhrase(trigger = {}, meta = DEFAULT_META, allSteps = []) {
	const type = trigger.type || 'CASE_CREATED'
	if (type === 'STEP_DONE') {
		const previous = allSteps.find((entry) => entry.key === trigger.stepKey)
		return `sobald „${previous?.title || trigger.stepKey || '…'}“ erledigt ist`
	}
	if (SCHEDULE_TRIGGERS.includes(type)) {
		const labels = (trigger.presetKeys || []).map((key) => presetLabel(key, meta))
		const verb = { SCHEDULE_DONE: 'stattgefunden hat', SCHEDULE_CONFIRMED: 'bestätigt ist', SCHEDULE_CHANGED: 'verschoben oder an einen anderen Ort verlegt wird', SCHEDULE_CANCELLED: 'abgesagt wird' }[type]
		const each = type === 'SCHEDULE_CHANGED' ? ' (je Änderung)' : ' (je Termin)'
		const confirmedOnly = type === 'SCHEDULE_CHANGED' || type === 'SCHEDULE_CANCELLED'
		const subject = trigger.once ? (confirmedOnly ? 'der erste bestätigte Termin' : 'der erste Termin') : (confirmedOnly ? 'ein bestätigter Termin' : 'ein Termin')
		return `sobald ${subject} „${labels.join('“ oder „') || '…'}“ ${verb}${trigger.once ? '' : each}`
	}
	if (DOCUMENT_TRIGGERS.includes(type)) {
		const name = trigger.templateKey ? labelFor(meta.documentTemplates, trigger.templateKey) : '…'
		return { DOCUMENT_CREATED: `sobald das Dokument „${name}“ erzeugt ist`, DOCUMENT_FINAL: `sobald das Dokument „${name}“ final ist`, DOCUMENT_SENT: `sobald das Dokument „${name}“ per E-Mail versendet ist` }[type]
	}
	if (DEREGISTRATION_TRIGGERS.includes(type)) {
		const target = trigger.templateKey ? `die Abmeldung „${labelFor(meta.deregistrationTemplates, trigger.templateKey)}“` : 'eine Abmeldung'
		return `sobald ${target} ${type === 'DEREGISTRATION_SENT' ? 'versendet' : 'bestätigt'} ist (je Abmeldung)`
	}
	if (type === 'FIELD_SET') {
		const field = trigger.field ? labelFor(meta.caseFields, trigger.field) : '…'
		return trigger.value ? `sobald im Feld „${field}“ „${trigger.value}“ steht` : `sobald das Feld „${field}“ ausgefüllt ist`
	}
	if (type === 'CASE_STATUS') return `sobald der Fall den Status „${(meta.caseStatuses || DEFAULT_META.caseStatuses)[trigger.status] || trigger.status || '…'}“ hat`
	if (type === 'LABEL_SET') return `sobald der Marker „${labelText(meta, trigger.label, trigger.value)}“ gesetzt wird`
	if (type === 'LABEL_REMOVED') return `sobald der Marker „${labelText(meta, trigger.label)}“ entfernt wird`
	if (type === 'DOCUMENT_RECEIVED') return `sobald ein Dokument „${documentKindText(meta, trigger.documentKind)}“ eingeht`
	if (type === 'INVOICE_OVERDUE') {
		const days = Number(trigger.days || 0)
		return days > 0 ? `sobald eine Rechnung ${days} ${days === 1 ? 'Tag' : 'Tage'} über dem Zahlungsziel ist (je Rechnung)` : 'sobald das Zahlungsziel einer Rechnung erreicht ist (je Rechnung)'
	}
	if (type === 'DATE_REACHED') {
		const days = Number(trigger.days || 0)
		const reference = (meta.dateReferences || DEFAULT_META.dateReferences)[trigger.dateRef] || '…'
		const when = days === 0 ? `am Tag „${reference}“` : `${Math.abs(days)} ${Math.abs(days) === 1 ? 'Tag' : 'Tage'} ${days < 0 ? 'vor' : 'nach'} „${reference}“`
		const lead = Number(trigger.leadDays || 0)
		return `${when}${lead ? ` (angelegt ${lead} ${lead === 1 ? 'Tag' : 'Tage'} vorher)` : ''}`
	}
	return TRIGGER_PHRASES[type] || ''
}

const SCOPE_PHRASES = { ALL: 'In jedem Fall', CREMATION: 'Bei Feuerbestattung', BURIAL: 'Bei Erdbestattung', NATURE: 'Bei See-, Baum- oder Waldbestattung', GRAVE: 'Bei Bestattung mit Grabstelle' }

export function presetLabel(key, meta = DEFAULT_META) {
	return (meta.schedulePresets || []).find((entry) => entry.key === key)?.label || key
}

/** Satzvorschau eines Schritts in Alltagssprache, z. B. für die Karten im Ablauf-Editor. */
export function stepSentence(step, meta = DEFAULT_META, allSteps = []) {
	const applies = step.applies || {}
	const conditions = [SCOPE_PHRASES[applies.scope || 'ALL'] || SCOPE_PHRASES.ALL]
	if (applies.ceremony === 'YES') conditions.push('mit Trauerfeier')
	if (applies.ceremony === 'NO') conditions.push('ohne Trauerfeier')
	if (applies.pension === 'YES') conditions.push('bei Rentenbezug')
	if (applies.deposit === 'YES') conditions.push('mit vereinbarter Anzahlung')
	if (applies.label) conditions.push(`mit Marker „${labelText(meta, applies.label, applies.labelValue)}“`)
	const trigger = step.trigger || { type: 'CASE_CREATED' }
	const when = triggerPhrase(trigger, meta, allSteps)
	const role = (meta.roleLabels || DEFAULT_META.roleLabels)[step.role || ''] || ''
	const offset = Number(step.dueOffsetDays || 0)
	const scheduleTrigger = ['SCHEDULE_CONFIRMED', 'SCHEDULE_DONE'].includes(trigger.type)
	const dateTrigger = trigger.type === 'DATE_REACHED'
	const referenceKey = step.dueReference || 'TRIGGER'
	const referencePhrases = { TRIGGER: scheduleTrigger ? 'dem Termin' : (dateTrigger ? 'dem Stichtag' : 'Auslösung'), CASE_CREATED: 'der Fallanlage', DEATH_DATE: 'dem Sterbedatum', CEREMONY: 'der Trauerfeier', BURIAL: 'der Beisetzung' }
	const reference = referencePhrases[referenceKey] || 'Auslösung'
	const due = offset === 0
		? (referenceKey !== 'TRIGGER' || scheduleTrigger || dateTrigger ? `am Tag ${{ TRIGGER: dateTrigger ? 'des Stichtags' : 'des Termins', CASE_CREATED: 'der Fallanlage', DEATH_DATE: 'des Todes', CEREMONY: 'der Trauerfeier', BURIAL: 'der Beisetzung' }[referenceKey] || 'der Auslösung'}` : 'sofort')
		: (referenceKey === 'TRIGGER' && !scheduleTrigger && !dateTrigger && offset > 0 ? `${offset} ${offset === 1 ? 'Tag' : 'Tage'} danach` : `${Math.abs(offset)} ${Math.abs(offset) === 1 ? 'Tag' : 'Tage'} ${offset < 0 ? 'vor' : 'nach'} ${reference}`)
	const buttons = (step.actions || []).map((action) => action.label).filter(Boolean)
	if (conditions.length > 1 && (applies.scope || 'ALL') === 'ALL') { conditions.shift(); conditions[0] = conditions[0].charAt(0).toUpperCase() + conditions[0].slice(1) }
	const lead = { 'Mit Trauerfeier': 'Bei Fällen mit Trauerfeier', 'Ohne Trauerfeier': 'Bei Fällen ohne Trauerfeier', 'Mit vereinbarter Anzahlung': 'Bei vereinbarter Anzahlung' }
	conditions[0] = lead[conditions[0]] || (conditions[0].startsWith('Mit Marker') ? `Bei Fällen ${conditions[0].charAt(0).toLowerCase()}${conditions[0].slice(1)}` : conditions[0])
	const parts = [`${conditions.join(', ')}, ${when}: ${step.title || 'Neuer Schritt'}`]
	if (step.role) parts.push(role)
	parts.push(`fällig ${due}`)
	const flags = [step.priority === 'KRITISCH' ? 'kritisch' : (step.priority === 'HOCH' ? 'hohe Priorität' : ''), step.required ? 'Pflicht' : ''].filter(Boolean)
	if (flags.length) parts.push(flags.join(', '))
	if (buttons.length) parts.push(`Knöpfe: ${buttons.join(', ')}`)
	const completion = step.complete?.type && step.complete.type !== 'NONE' ? ` Erledigt sich selbst, wenn: ${completionText(step, meta)}.` : ''
	return `${parts.join(' – ')}.${completion}`
}

function completionText(step, meta) {
	const complete = step.complete || {}
	const label = (meta.completions || DEFAULT_META.completions)[complete.type] || complete.type
	if (complete.type === 'FIELD_SET') return `${label} („${labelFor(meta.caseFields, complete.field)}“${complete.value ? ` = „${complete.value}“` : ''})`
	if (complete.type === 'CASE_STATUS') return `${label} („${(meta.caseStatuses || DEFAULT_META.caseStatuses)[complete.status] || complete.status}“)`
	if (LABEL_TRIGGERS.includes(complete.type)) return `${label} („${labelText(meta, complete.label || step.trigger?.label, complete.type === 'LABEL_SET' ? complete.value : '')}“)`
	if (['DOCUMENT_CREATED', 'DOCUMENT_FINAL', 'DOCUMENT_SENT'].includes(complete.type)) {
		const key = complete.templateKey || (DOCUMENT_TRIGGERS.includes(step.trigger?.type) ? step.trigger.templateKey : '')
		return key ? `${label} („${labelFor(meta.documentTemplates, key)}“)` : label
	}
	if (complete.type === 'DOCUMENT_RECEIVED') return `${label} („${documentKindText(meta, complete.documentKind || (step.trigger?.type === 'DOCUMENT_RECEIVED' ? step.trigger.documentKind : ''))}“)`
	if (DEREGISTRATION_TRIGGERS.includes(complete.type) && complete.templateKey) return `${label} („${labelFor(meta.deregistrationTemplates, complete.templateKey)}“)`
	return label
}

/** Clientseitige Sofortprüfung beim Bearbeiten (die verbindliche Prüfung erfolgt beim Speichern auf dem Server). */
export function clientWarnings(steps) {
	const warnings = []
	const active = steps.filter((step) => step.active !== false)
	const titles = new Map()
	for (const step of active) {
		const key = String(step.title || '').trim().toLowerCase()
		if (!key) warnings.push('Ein Schritt hat noch keine Bezeichnung.')
		else if (titles.has(key)) warnings.push(`Der Schritt „${step.title}“ kommt doppelt vor.`)
		titles.set(key, true)
		const trigger = step.trigger || {}
		if (SCHEDULE_TRIGGERS.includes(trigger.type) && !(trigger.presetKeys || []).length) warnings.push(`Beim Schritt „${step.title}“ fehlt die Terminart.`)
		if (DOCUMENT_TRIGGERS.includes(trigger.type) && !trigger.templateKey) warnings.push(`Beim Schritt „${step.title}“ fehlt die Dokumentvorlage des Auslösers.`)
		if (trigger.type === 'FIELD_SET' && !trigger.field) warnings.push(`Beim Schritt „${step.title}“ fehlt das Stammdatenfeld des Auslösers.`)
		if (trigger.type === 'CASE_STATUS' && !trigger.status) warnings.push(`Beim Schritt „${step.title}“ fehlt der Fallstatus des Auslösers.`)
		if (trigger.type === 'DATE_REACHED' && !trigger.dateRef) warnings.push(`Beim Schritt „${step.title}“ fehlt das Bezugsdatum für „Datum erreicht“.`)
		if (LABEL_TRIGGERS.includes(trigger.type) && !trigger.label) warnings.push(`Beim Schritt „${step.title}“ fehlt der Marker des Auslösers.`)
		if (trigger.type === 'DOCUMENT_RECEIVED' && !trigger.documentKind) warnings.push(`Beim Schritt „${step.title}“ fehlt die Dokumentart des Auslösers.`)
		if (step.complete?.type === 'DOCUMENT_RECEIVED' && !(step.complete.documentKind || (trigger.type === 'DOCUMENT_RECEIVED' && trigger.documentKind))) warnings.push(`Beim Schritt „${step.title}“ fehlt die Dokumentart der Erledigt-Bedingung.`)
		if (LABEL_TRIGGERS.includes(step.complete?.type) && !(step.complete.label || (LABEL_TRIGGERS.includes(trigger.type) && trigger.label))) warnings.push(`Beim Schritt „${step.title}“ fehlt der Marker der Erledigt-Bedingung.`)
		if (step.required && ['DATE_REACHED', 'SCHEDULE_CHANGED', 'SCHEDULE_CANCELLED', 'INVOICE_OVERDUE'].includes(trigger.type)) warnings.push(`Der Pflichtschritt „${step.title}“ entsteht nur in besonderen Fällen bzw. spät – als Pflichtschritt kann er den Fallabschluss verzögern.`)
		if (trigger.type === 'STEP_DONE') {
			const target = steps.find((entry) => entry.key === trigger.stepKey)
			if (!target) warnings.push(`Der Schritt „${step.title}“ wartet auf einen Schritt, den es nicht gibt.`)
			else if (target.active === false) warnings.push(`Der Schritt „${step.title}“ wartet auf den deaktivierten Schritt „${target.title}“.`)
		}
		for (const action of step.actions || []) {
			if (action.type === 'DOCUMENT' && !action.templateKey) warnings.push(`Knopf „${action.label || 'ohne Namen'}“ bei „${step.title}“: Dokumentvorlage fehlt.`)
			if (action.type === 'DOCUMENT_BUNDLE' && !(action.templateKeys || []).length) warnings.push(`Knopf „${action.label || 'ohne Namen'}“ bei „${step.title}“: keine Dokumentvorlagen im Paket.`)
			if (action.type === 'DEREGISTRATIONS' && !(action.templateKeys || []).length) warnings.push(`Knopf „${action.label || 'ohne Namen'}“ bei „${step.title}“: keine Abmeldung vorausgewählt.`)
			if (action.type === 'LABEL' && !action.labelKey) warnings.push(`Knopf „${action.label || 'ohne Namen'}“ bei „${step.title}“: Marker fehlt.`)
		}
		if (!step.role) warnings.push(`Der Schritt „${step.title}“ hat keine feste Zuständigkeit – die Aufgabe geht an die Person, die den Auslöser ausführt.`)
		if (step.required && trigger.type === 'MANUAL') warnings.push(`Der Pflichtschritt „${step.title}“ entsteht nur von Hand.`)
	}
	return [...new Set(warnings)]
}

function appliesTo(applies = {}, scenario) {
	const scope = applies.scope || 'ALL'
	if (scope === 'CREMATION' && scenario.scope !== 'CREMATION') return false
	if (scope === 'BURIAL' && scenario.scope !== 'BURIAL') return false
	if (scope === 'NATURE' && !scenario.nature) return false
	if (scope === 'GRAVE' && !(scenario.scope === 'BURIAL' || scenario.urnGrave)) return false
	if (applies.ceremony === 'YES' && !scenario.ceremony) return false
	if (applies.ceremony === 'NO' && scenario.ceremony) return false
	if (applies.pension === 'YES' && !scenario.pension) return false
	if (applies.deposit === 'YES' && !scenario.deposit) return false
	if (applies.label && !(scenario.labels || []).some((entry) => entry === applies.label || entry === `${applies.label}=${applies.labelValue || ''}` || (!applies.labelValue && entry.startsWith(`${applies.label}=`)))) return false
	return true
}

/**
 * Probelauf: Welche Aufgaben entstehen bei einem Beispielfall wann? Tage relativ zur Fallanlage.
 * scenario: { scope: 'CREMATION'|'BURIAL', nature, urnGrave, ceremony, pension, deposit,
 *             orderDay, ceremonyDay, burialDay, invoiceDay, paymentDay, incomingInvoice }
 */
export function simulate(steps, scenario = {}) {
	const s = { scope: 'BURIAL', nature: false, urnGrave: false, ceremony: true, pension: true, deposit: false, orderDay: 2, ceremonyDay: 7, burialDay: 7, invoiceDay: 10, paymentDay: 24, incomingInvoice: true, ...scenario }
	const schedules = [
		{ key: 'UEBERFUEHRUNG', confirm: 0, event: 0 },
		{ key: 'BERATUNG', confirm: 0, event: 1 },
		...(s.ceremony ? [{ key: 'TRAUERFEIER_1', confirm: 2, event: s.ceremonyDay }] : []),
		{ key: 'BEISETZUNG', confirm: 2, event: s.burialDay },
	]
	// Marker im Beispielfall gelten ab Tag 0 als gesetzt (Probelauf mit ausgewählten Markern).
	const labelSet = (trigger) => (s.labels || []).some((entry) => entry === trigger.label || entry === `${trigger.label}=${trigger.value || ''}` || (!trigger.value && entry.startsWith(`${trigger.label}=`)))
	const events = { CASE_CREATED: 0, FUNERAL_TYPE_SET: 1, QUOTE_CREATED: Math.max(1, s.orderDay - 1), ORDER_SIGNED: s.orderDay, ORDER_COMMISSIONED: s.orderDay, DEPOSIT_AGREED: s.deposit ? s.orderDay : null, INCOMING_INVOICE: s.incomingInvoice ? s.burialDay + 5 : null, FINAL_INVOICE_ISSUED: s.invoiceDay, PAYMENT_RECEIVED: s.paymentDay }
	const completions = { NONE: null, FUNERAL_TYPE_SET: 1, QUOTE_CREATED: Math.max(1, s.orderDay - 1), ORDER_SIGNED: s.orderDay, ORDER_COMMISSIONED: s.orderDay, DEPOSIT_RECEIVED: s.deposit ? s.orderDay + 4 : null, FINAL_INVOICE_ISSUED: s.invoiceDay, PAYMENT_RECEIVED: s.paymentDay, INCOMING_TRANSFERRED: s.incomingInvoice ? s.burialDay + 7 : null }
	const reference = (step, anchor) => {
		if (step.dueReference === 'CASE_CREATED') return 0
		if (step.dueReference === 'DEATH_DATE') return -1
		if (step.dueReference === 'CEREMONY') return s.ceremony ? s.ceremonyDay : anchor
		if (step.dueReference === 'BURIAL') return s.burialDay
		return anchor
	}
	const done = new Map()
	const entries = []
	const notCreated = []
	const active = steps.filter((step) => step.active !== false)
	const pending = [...active]
	for (let guard = 0; guard < 10 && pending.length; guard++) {
		for (const step of [...pending]) {
			if (!appliesTo(step.applies, s)) { notCreated.push({ title: step.title, phase: step.phase, reason: step.applies?.label && !(s.labels || []).length ? 'nur mit Marker – im Beispielfall ist keiner gesetzt' : 'gilt nicht für diesen Beispielfall' }); pending.splice(pending.indexOf(step), 1); continue }
			const trigger = step.trigger || { type: 'CASE_CREATED' }
			let anchors = []
			if (trigger.type in events) anchors = events[trigger.type] === null || events[trigger.type] === undefined ? [] : [events[trigger.type]]
			else if (trigger.type === 'STEP_DONE') { if (!done.has(trigger.stepKey)) continue; anchors = [done.get(trigger.stepKey)] }
			else if (['SCHEDULE_CONFIRMED', 'SCHEDULE_DONE'].includes(trigger.type)) {
				const matching = schedules.filter((entry) => (trigger.presetKeys || []).includes(entry.key))
				const chosen = trigger.once ? matching.slice(0, 1) : matching
				anchors = chosen.map((entry) => (trigger.type === 'SCHEDULE_DONE' ? { trigger: entry.event, anchor: entry.event } : { trigger: entry.confirm, anchor: entry.event }))
			} else if (trigger.type === 'DATE_REACHED') {
				const base = { DEATH_DATE: -1, CEREMONY: s.ceremony ? s.ceremonyDay : null, BURIAL: s.burialDay, CASE_CREATED: 0, CASE_CLOSED: s.paymentDay + 1 }[trigger.dateRef]
				if (base !== null && base !== undefined) { const target = base + Number(trigger.days || 0); anchors = [{ trigger: Math.max(0, target - Number(trigger.leadDays || 0)), anchor: target }] }
			} else if (trigger.type === 'INVOICE_OVERDUE') {
				const target = s.invoiceDay + 14 + Number(trigger.days || 0)
				if (s.paymentDay > target) anchors = [{ trigger: target, anchor: target }]
				else { notCreated.push({ title: step.title, phase: step.phase, reason: 'Rechnung ist im Beispielfall rechtzeitig bezahlt' }); pending.splice(pending.indexOf(step), 1); continue }
			} else if (trigger.type === 'LABEL_SET') {
				if (labelSet(trigger)) anchors = [0]
				else { notCreated.push({ title: step.title, phase: step.phase, reason: `entsteht, sobald der Marker „${trigger.label}“ gesetzt wird` }); pending.splice(pending.indexOf(step), 1); continue }
			} else if (trigger.type === 'MANUAL') { notCreated.push({ title: step.title, phase: step.phase, reason: 'entsteht nur von Hand' }); pending.splice(pending.indexOf(step), 1); continue }
			else { notCreated.push({ title: step.title, phase: step.phase, reason: `Ereignis „${DEFAULT_META.triggers[trigger.type] || trigger.type}“ wird im Probelauf nicht nachgestellt` }); pending.splice(pending.indexOf(step), 1); continue }
			pending.splice(pending.indexOf(step), 1)
			if (!anchors.length) { notCreated.push({ title: step.title, phase: step.phase, reason: 'Auslöser tritt in diesem Beispielfall nicht ein' }); continue }
			for (const raw of anchors) {
				const triggerDay = typeof raw === 'object' ? raw.trigger : raw
				const anchor = typeof raw === 'object' ? raw.anchor : raw
				const completeDay = step.complete?.type && step.complete.type !== 'NONE' ? completions[step.complete.type] : null
				let due = reference(step, anchor) + Number(step.dueOffsetDays || 0)
				const shortNotice = due < triggerDay
				if (due < triggerDay) due = triggerDay
				if (completeDay !== null && completeDay !== undefined && completeDay <= triggerDay) { entries.push({ day: triggerDay, due, title: step.title, phase: step.phase, role: step.role || '', state: 'entfällt (schon erledigt)' }); done.set(step.key, triggerDay); continue }
				entries.push({ day: triggerDay, due, title: step.title, phase: step.phase, role: step.role || '', required: Boolean(step.required), shortNotice, state: completeDay !== null && completeDay !== undefined ? `erledigt sich an Tag ${completeDay}` : 'offen' })
				done.set(step.key, completeDay ?? due)
			}
		}
	}
	for (const step of pending) notCreated.push({ title: step.title, phase: step.phase, reason: 'wartet auf einen Schritt, der nicht entsteht' })
	entries.sort((left, right) => left.due - right.due || left.phase - right.phase)
	return { entries, notCreated }
}

/** Kennzahlen für die Phasenleiste. */
export function phaseProgress(phases = []) {
	return phases.map((phase) => {
		const total = Number(phase.total || 0)
		const done = Number(phase.done || 0)
		return { phase: phase.phase, label: phase.label, total, done, overdue: Number(phase.overdue || 0), percent: total ? Math.round((done / total) * 100) : 0, complete: total > 0 && done === total }
	})
}

/** Euro-Eingabe („1.500,00“) in Cent. Leere Eingabe = 0; ungültig = null. */
export function euroToCents(value) {
	let raw = String(value ?? '').replace(/[€\s]/g, '')
	// „1.500,50“ / „1500,50“ → Komma ist Dezimaltrenner; „1500.50“ → Punkt ist Dezimaltrenner; „1.500“ → Tausenderpunkt
	if (raw.includes(',')) raw = raw.replace(/\./g, '').replace(',', '.')
	else if (!/^\d+\.\d{1,2}$/.test(raw)) raw = raw.replace(/\./g, '')
	if (raw === '') return 0
	if (!/^\d+(\.\d{1,2})?$/.test(raw)) return null
	return Math.round(Number(raw) * 100)
}

export function centsToEuro(cents) {
	return (Number(cents || 0) / 100).toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
