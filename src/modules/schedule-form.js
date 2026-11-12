// 0.90.5: Hilfsfunktionen für das Terminformular (Beginn/Ende, Vorbelegung aus der Arbeitszeit, Änderungsgrund nur bei
// Bedarf, Verfügbarkeit und Tagesleiste). Ohne DOM-Zugriff, damit sie sich in Node prüfen lassen
// (tests/schedule-form-0905-runtime.mjs). Die Regel für den Änderungsgrund spiegelt SchedulingRules::changeReasonRequired().

export const DEFAULT_WORKING_HOURS = { start: '07:00', end: '18:00' }

/** „07:30“ → 450; ungültig → NaN. */
export function toMinutes(time) {
	const match = /^(\d{1,2}):(\d{2})/.exec(String(time || ''))
	if (!match || Number(match[1]) > 23 || Number(match[2]) > 59) return Number.NaN
	return Number(match[1]) * 60 + Number(match[2])
}

/** 450 → „07:30“ (begrenzt auf den Tag). */
export function fromMinutes(minutes) {
	const value = Math.max(0, Math.min(23 * 60 + 59, Math.round(Number(minutes) || 0)))
	return `${String(Math.floor(value / 60)).padStart(2, '0')}:${String(value % 60).padStart(2, '0')}`
}

/** Arbeitszeit aus dem Katalog der Terminplanung; ohne oder mit ungültigem Eintrag 07:00–18:00 Uhr. */
export function workingHours(scheduling) {
	const hours = scheduling?.workingHours || {}
	const start = toMinutes(hours.start)
	const end = toMinutes(hours.end)
	if (Number.isNaN(start) || Number.isNaN(end) || end - start < 60) return { ...DEFAULT_WORKING_HOURS }
	return { start: fromMinutes(start), end: fromMinutes(end) }
}

/** „2026-11-12T10:30:00“ → { date: „2026-11-12“, time: „10:30“ }. */
export function splitDateTime(value) {
	const text = String(value || '')
	return { date: /^\d{4}-\d{2}-\d{2}/.test(text) ? text.slice(0, 10) : '', time: /T\d{2}:\d{2}/.test(text) ? text.slice(11, 16) : '' }
}

/** Ende aus Beginn und Dauer; ein Termin endet spätestens um 23:59 Uhr desselben Tages. */
export function endTime(start, durationMinutes) {
	const begin = toMinutes(start)
	if (Number.isNaN(begin)) return ''
	return fromMinutes(begin + Math.max(15, Number(durationMinutes) || 60))
}

/** Dauer aus Beginn und Ende; null, wenn das Ende nicht mindestens 15 Minuten nach dem Beginn liegt. */
export function durationFromEnd(start, end) {
	const begin = toMinutes(start)
	const finish = toMinutes(end)
	if (Number.isNaN(begin) || Number.isNaN(finish) || finish - begin < 15) return null
	return finish - begin
}

/** Vorbelegte Uhrzeit: Termine beginnen mit dem Arbeitsbeginn, Aufgaben sind zum Arbeitsende fällig. */
export function defaultTime(type, scheduling) {
	const hours = workingHours(scheduling)
	return type === 'task' ? hours.end : hours.start
}

/** 0.90.5: Termine aus einem Ablaufschritt oder einer Aufgabe sind abgestimmt und werden „Bestätigt“ vorbelegt. */
export function defaultScheduleStatus(data) {
	const source = String(data?.link?.source || '').toUpperCase()
	return ['PROCESS', 'FOLLOW_UP'].includes(source) ? 'BESTAETIGT' : 'ENTWURF'
}

export function statusHint(status) {
	switch (String(status || '').toUpperCase()) {
	case 'ENTWURF': return 'Ein Entwurf startet noch keine Folgeaufgaben. Änderungen am Entwurf brauchen keinen Grund.'
	case 'BESTAETIGT': return '„Bestätigt“ startet die Folgeaufgaben des Ablaufs.'
	case 'GEAENDERT': return 'Nach einer Änderung die Folgeaufgaben prüfen und den Termin wieder bestätigen.'
	case 'ABGESAGT': return 'Ein abgesagter Termin belegt keine Personen und Ressourcen mehr.'
	case 'ERLEDIGT': return 'Der Termin hat stattgefunden.'
	default: return ''
	}
}

const DATA_FIELDS = ['location', 'durationMinutes', 'bufferBeforeMinutes', 'bufferAfterMinutes', 'assigneeUids', 'resourceKeys', 'externalParticipants']

/** Geänderte Felder wie SchedulingService::changes() (gleiche Felder, gleicher Vergleich). */
export function scheduleChanges(before, after) {
	const result = []
	for (const key of ['title', 'date', 'status']) {
		if (String(before?.[key] ?? '') !== String(after?.[key] ?? '')) result.push(key)
	}
	const text = (value) => Array.isArray(value) ? value.join(', ') : String(value ?? '')
	for (const key of DATA_FIELDS) {
		if (text(before?.data?.[key]) !== text(after?.data?.[key])) result.push(key)
	}
	return result
}

/** Spiegel von SchedulingRules::changeReasonRequired(). */
export function changeReasonRequired(previousStatus, newStatus, changedFields) {
	if (!changedFields?.length) return false
	const previous = String(previousStatus || '').toUpperCase()
	const next = String(newStatus || '').toUpperCase()
	if (previous === 'ENTWURF') return false
	if (changedFields.length === 1 && changedFields[0] === 'status' && (next === 'ERLEDIGT' || (previous === 'GEAENDERT' && next === 'BESTAETIGT'))) return false
	return true
}

/** „2026-11-12T10:30“ → „10:30“. */
const clock = (value) => String(value || '').slice(11, 16)

/** Kurzanzeige hinter einer Person oder Ressource. */
export function availabilityChip(entry, esc) {
	if (!entry) return ''
	const hits = (entry.busy || []).filter((block) => block.overlaps)
	if (hits.length && (entry.conflictRelevant !== false)) {
		const first = hits[0]
		return `<span class="bp-availability is-busy" title="${esc(hits.map((block) => `${clock(block.from)}–${clock(block.to)} ${block.title}`).join(' · '))}">belegt ${esc(clock(first.from))}–${esc(clock(first.to))} · ${esc(first.title)}${hits.length > 1 ? ` (+${hits.length - 1})` : ''}</span>`
	}
	if (hits.length) return `<span class="bp-availability is-unchecked" title="Für diese Ressource ist „Konflikt prüfen“ ausgeschaltet.">gleichzeitig verplant (ohne Prüfung)</span>`
	return '<span class="bp-availability is-free">frei</span>'
}

/** Zusammenfassung unter der Tagesleiste. */
export function availabilitySummary(availability, names) {
	if (!availability) return null
	const busy = [...(availability.people || []), ...(availability.resources || [])].filter((entry) => entry.conflict)
	if (!busy.length) return { state: 'ok', text: 'Alle gewählten Personen und Ressourcen sind in diesem Zeitraum frei.' }
	return { state: 'busy', text: `Belegt: ${busy.map((entry) => names(entry)).join(', ')}. In der Tagesleiste eine freie Stelle anklicken oder die Zeit ändern.` }
}

/**
 * Tagesleiste: eine Zeile je gewählter Person und Ressource mit den belegten Zeiträumen; der geplante Termin ist umrandet,
 * Vor- und Nachbereitung schraffiert. Der Ausschnitt ist die Arbeitszeit, erweitert um Termin und Belegungen des Tages.
 */
export function timelineHtml(availability, { names, esc, hours, window, buffers }) {
	if (!availability) return ''
	const rows = [...(availability.people || []).map((entry) => ({ ...entry, label: names(entry) })), ...(availability.resources || []).map((entry) => ({ ...entry, label: names(entry) }))]
	if (!rows.length) return ''
	const day = availability.date
	const minutesOf = (value) => (String(value).slice(0, 10) < day ? 0 : (String(value).slice(0, 10) > day ? 24 * 60 : toMinutes(clock(value))))
	let from = toMinutes(hours.start)
	let to = toMinutes(hours.end)
	const spans = [...rows.flatMap((row) => (row.busy || []).map((block) => [minutesOf(block.from), minutesOf(block.to)])), ...(window ? [[minutesOf(window.from), minutesOf(window.to)]] : [])]
	for (const [start, end] of spans) { from = Math.min(from, start); to = Math.max(to, end) }
	from = Math.max(0, Math.floor(from / 60) * 60)
	to = Math.min(24 * 60, Math.ceil(to / 60) * 60)
	const span = Math.max(60, to - from)
	const pct = (minutes) => `${(Math.max(0, Math.min(span, minutes - from)) / span * 100).toFixed(2)}%`
	const width = (start, end) => `${(Math.max(0, Math.min(to, end) - Math.max(from, start)) / span * 100).toFixed(2)}%`
	const ticks = []
	for (let minutes = from; minutes <= to; minutes += 60) ticks.push(`<span style="left:${pct(minutes)}">${minutes / 60}</span>`)
	const own = window ? (() => {
		const start = minutesOf(window.from); const end = minutesOf(window.to); const total = Math.max(1, end - start)
		return `<span class="bp-timeline-own" style="left:${pct(start)};width:${width(start, end)}"><span class="bp-timeline-buffer" style="left:0;width:${(buffers.before / total * 100).toFixed(2)}%"></span><span class="bp-timeline-buffer" style="right:0;width:${(buffers.after / total * 100).toFixed(2)}%"></span></span>`
	})() : ''
	const line = (row) => `<div class="bp-timeline-row"><span class="bp-timeline-name" title="${esc(row.label)}">${esc(row.label)}</span><div class="bp-timeline-track" data-timeline-track title="Freie Stelle anklicken, um die Uhrzeit zu übernehmen">${(row.busy || []).map((block) => `<span class="bp-timeline-block${block.overlaps && row.conflictRelevant !== false ? ' is-conflict' : ''}" style="left:${pct(minutesOf(block.from))};width:${width(minutesOf(block.from), minutesOf(block.to))}" title="${esc(`${clock(block.from)}–${clock(block.to)} ${block.title}`)}">${esc(block.title)}</span>`).join('')}${own}</div></div>`
	return `<div class="bp-timeline" data-range-from="${from}" data-range-to="${to}"><div class="bp-timeline-row bp-timeline-scale" aria-hidden="true"><span></span><div class="bp-timeline-ticks">${ticks.join('')}</div></div>${rows.map(line).join('')}</div>`
}

/** Uhrzeit aus einem Klick auf die Tagesleiste (Viertelstundenraster, Termin bleibt innerhalb des Ausschnitts). */
export function startFromTimelineClick(ratio, range, durationMinutes, buffers) {
	const span = Math.max(60, range.to - range.from)
	let start = range.from + Math.round((Math.max(0, Math.min(1, ratio)) * span) / 15) * 15
	start = Math.max(range.from + buffers.before, Math.min(start, range.to - durationMinutes - buffers.after))
	return fromMinutes(start)
}
