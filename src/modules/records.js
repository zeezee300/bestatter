import { mailButton, printButton } from './icons.js'
import { UNSAVED_CASE_HINT } from './case-unsaved.js'
import { DEFAULT_META } from './process-logic.js'
import { offeredResources, groupByType, droppedKeys, normalizeKeys, requiredResources, resourceChips, resourceDisplayName, resourceTypeIcon, resourceTypeInfo, scheduleResources } from './scheduling-resources.js'
import { availabilityChip, availabilitySummary, changeReasonRequired, defaultScheduleStatus, defaultTime, durationFromEnd, endTime, fromMinutes, scheduleChanges, splitDateTime, startFromTimelineClick, statusHint, timelineHtml, toMinutes, workingHours } from './schedule-form.js'

export function createRecordsModule(ctx) {
	const { root, urls, state, recordLabels, apiBase } = ctx
	const api = (...args) => ctx.api(...args)
	const assigneeOptions = (...args) => ctx.assigneeOptions(...args)
	const contactsByCategory = (...args) => ctx.contactsByCategory(...args)
	const currentUser = (...args) => ctx.currentUser(...args)
	const esc = (...args) => ctx.esc(...args)
	const field = (...args) => ctx.field(...args)
	const loadRecordType = (...args) => ctx.loadRecordType(...args)
	const loadCaseFiles = (...args) => ctx.loadCaseFiles(...args)
	const recordAssigneeName = (...args) => ctx.recordAssigneeName(...args)
	const recordAssigneeUid = (...args) => ctx.recordAssigneeUid(...args)
	const recordBranch = (...args) => ctx.recordBranch(...args)
	const recordCaseNumber = (...args) => ctx.recordCaseNumber(...args)
	const recordItemUrl = (...args) => ctx.recordItemUrl(...args)
	const recordUrl = (...args) => ctx.recordUrl(...args)
	const render = (...args) => ctx.render(...args)
	const title = (...args) => ctx.title(...args)
	const actionsUrl = (...args) => ctx.actionsUrl(...args)
	const schedulingUrl = (...args) => ctx.schedulingUrl(...args)
	const confirmAction = (...args) => ctx.confirmAction(...args)
	const promptAction = (...args) => ctx.promptAction(...args)
	const notifyError = (...args) => ctx.notifyError(...args)
	const notifySuccess = (...args) => ctx.notifySuccess(...args)
	// Zusätzlich beim Umsetzen von Befund #19–#23 (2026-09-20) gefunden: notifyWarning() wurde in dieser Datei bereits
	// verwendet (Zeile ~370, Kondolenzlisten-Terminauswahl; jetzt auch für die Terminverknüpfungshinweise
	// unten), war aber nie aus ctx destrukturiert – ein Aufruf hätte bisher mit einem ReferenceError abgebrochen,
	// statt dem Anwender die vorgesehene Warnung zu zeigen.
	const notifyWarning = (...args) => ctx.notifyWarning(...args)
	const showFilePreview = (...args) => ctx.showFilePreview?.(...args)

	function filteredTaskRecords(records) {
		const filters = state.taskFilters
		const visible = records.filter((item) => {
			if (!state.showCompletedTasks && filters.status !== 'ERLEDIGT' && item.status === 'ERLEDIGT') return false
			if (filters.caseId !== 'ALL' && String(item.caseId || 0) !== filters.caseId) return false
			if (filters.branch !== 'ALL' && recordBranch(item) !== filters.branch) return false
			if (filters.assignee !== 'ALL' && recordAssigneeUid(item) !== filters.assignee && !(item.data?.assigneeUids || []).map(String).includes(filters.assignee)) return false
			const role = filters.role || 'ALL'
			if (role === 'MINE') {
				const me = String(state.team.currentUid || '')
				if (recordAssigneeUid(item) !== me && !(item.data?.assigneeUids || []).map(String).includes(me)) return false
			} else if (role !== 'ALL' && String(item.data?.assigneeRole || '') !== role) return false
			return filters.status === 'ALL' || item.status === filters.status
		})
		const selectors = {
			CASE_NUMBER: (item) => recordCaseNumber(item),
			BRANCH: (item) => recordBranch(item),
			ASSIGNEE: (item) => recordAssigneeName(item),
			STATUS: (item) => ({ OFFEN: '1', IN_BEARBEITUNG: '2', ERLEDIGT: '3' }[item.status] || '9'),
			DUE_ASC: (item) => String(item.date || '9999-12-31'),
			DUE_DESC: (item) => String(item.date || ''),
			PRIORITY: (item) => `${({ KRITISCH: '0', HOCH: '1', NORMAL: '2', NIEDRIG: '3' }[String(item.data?.priority || 'NORMAL').toUpperCase()] || '2')}${String(item.date || '9999-12-31')}`,
		}
		const selector = selectors[state.taskSort] || selectors.DUE_ASC
		return visible.slice().sort((left, right) => {
			const result = selector(left).localeCompare(selector(right), 'de', { numeric: true, sensitivity: 'base' })
			return state.taskSort === 'DUE_DESC' ? -result : result
		})
	}

	function filterOptions(items, selected, allLabel) {
		return `<option value="ALL">${esc(allLabel)}</option>${items.map(([value, label]) => `<option value="${esc(value)}" ${String(value) === String(selected) ? 'selected' : ''}>${esc(label)}</option>`).join('')}`
	}

	function taskToolbar(caseId) {
		const branches = [...new Set(state.cases.map((item) => String(item.branch || item.masterData?.branch || '')).filter(Boolean))].sort().map((value) => [value, value])
		const cases = state.cases.slice().sort((a, b) => String(a.caseNumber).localeCompare(String(b.caseNumber), 'de', { numeric: true })).map((item) => [String(item.id), `${item.caseNumber} · ${item.lastName}, ${item.firstName}`])
		const members = (state.team.members || []).map((member) => [member.uid, member.displayName || member.uid])
		return `<div class="bp-task-toolbar" aria-label="Aufgaben filtern und sortieren">
			${caseId ? '' : `<label><span>Fallnummer</span><select data-task-filter="caseId">${filterOptions(cases, state.taskFilters.caseId, 'Alle Fälle / allgemein')}</select></label><label><span>Niederlassung</span><select data-task-filter="branch">${filterOptions(branches, state.taskFilters.branch, 'Alle Niederlassungen')}</select></label>`}
			<label><span>Rolle</span><select data-task-filter="role">${filterOptions([['MINE', 'Meine Aufgaben (ich oder meine Rolle)'], ...Object.entries(DEFAULT_META.roleLabels).filter(([key]) => key && key !== 'FALLVERANTWORTLICH')], state.taskFilters.role || 'ALL', 'Alle Rollen')}</select></label>
			<label><span>Zuständigkeit</span><select data-task-filter="assignee">${filterOptions(members, state.taskFilters.assignee, 'Alle Zuständigen')}</select></label>
			<label><span>Status</span><select data-task-filter="status">${filterOptions([['OFFEN', 'Offen'], ['IN_BEARBEITUNG', 'In Bearbeitung'], ['ERLEDIGT', 'Erledigt']], state.taskFilters.status, 'Alle Status')}</select></label>
			<label><span>Sortierung</span><select id="task-sort"><option value="DUE_ASC" ${state.taskSort === 'DUE_ASC' ? 'selected' : ''}>Fälligkeit aufsteigend</option><option value="DUE_DESC" ${state.taskSort === 'DUE_DESC' ? 'selected' : ''}>Fälligkeit absteigend</option><option value="PRIORITY" ${state.taskSort === 'PRIORITY' ? 'selected' : ''}>Priorität (kritisch zuerst)</option><option value="CASE_NUMBER" ${state.taskSort === 'CASE_NUMBER' ? 'selected' : ''}>Fallnummer</option><option value="BRANCH" ${state.taskSort === 'BRANCH' ? 'selected' : ''}>Niederlassung</option><option value="ASSIGNEE" ${state.taskSort === 'ASSIGNEE' ? 'selected' : ''}>Zuständigkeit</option><option value="STATUS" ${state.taskSort === 'STATUS' ? 'selected' : ''}>Status</option></select></label>
		</div>`
	}

	function selectableRecordValues(type, field) {
		return [...new Set((state.records[type] || []).map((item) => String(type === 'document' && field === 'documentType' ? (item.data?.documentType || 'Allgemein') : (item[field] || ''))).filter(Boolean))]
			.sort((left, right) => left.localeCompare(right, 'de', { numeric: true, sensitivity: 'base' }))
			.map((value) => [value, value])
	}

	function recordToolbar(type, caseId) {
		if (caseId || !['schedule', 'document'].includes(type)) return ''
		const filters = type === 'schedule' ? state.scheduleFilters : state.documentFilters
		const branches = [...new Set(state.cases.map((item) => String(item.branch || item.masterData?.branch || '')).filter(Boolean))].sort().map((value) => [value, value])
		const cases = state.cases.slice().sort((a, b) => String(a.caseNumber).localeCompare(String(b.caseNumber), 'de', { numeric: true })).map((item) => [String(item.id), `${item.caseNumber} · ${item.lastName}, ${item.firstName}`])
		const statuses = selectableRecordValues(type, 'status')
		const titleFilter = type === 'document' ? `<label><span>Dokumentenart</span><select data-record-filter="title" data-record-filter-type="document">${filterOptions(selectableRecordValues('document', 'documentType'), filters.title, 'Alle Dokumentarten')}</select></label>` : ''
		const kindFilter = type === 'schedule' ? `<label><span>Terminart</span><select data-record-filter="kind" data-record-filter-type="schedule">${filterOptions([['INTERNAL_ACTIVITY', 'Interne Tätigkeit'], ['EXTERNAL_APPOINTMENT', 'Externer Fixtermin']], filters.kind, 'Alle Terminarten')}</select></label>` : ''
		const sortOptions = type === 'schedule'
			? `<option value="DATE_ASC" ${state.scheduleSort === 'DATE_ASC' ? 'selected' : ''}>Termin aufsteigend</option><option value="DATE_DESC" ${state.scheduleSort === 'DATE_DESC' ? 'selected' : ''}>Termin absteigend</option><option value="CASE_NUMBER" ${state.scheduleSort === 'CASE_NUMBER' ? 'selected' : ''}>Fallnummer</option><option value="BRANCH" ${state.scheduleSort === 'BRANCH' ? 'selected' : ''}>Niederlassung</option><option value="STATUS" ${state.scheduleSort === 'STATUS' ? 'selected' : ''}>Status</option>`
			: `<option value="DATE_DESC" ${state.documentSort === 'DATE_DESC' ? 'selected' : ''}>Erstellung absteigend</option><option value="DATE_ASC" ${state.documentSort === 'DATE_ASC' ? 'selected' : ''}>Erstellung aufsteigend</option><option value="CASE_NUMBER" ${state.documentSort === 'CASE_NUMBER' ? 'selected' : ''}>Fallnummer</option><option value="BRANCH" ${state.documentSort === 'BRANCH' ? 'selected' : ''}>Niederlassung</option><option value="TITLE" ${state.documentSort === 'TITLE' ? 'selected' : ''}>Bezeichnung</option><option value="STATUS" ${state.documentSort === 'STATUS' ? 'selected' : ''}>Status</option>`
		return `<div class="bp-task-toolbar" aria-label="${type === 'schedule' ? 'Termine' : 'Dokumente'} filtern und sortieren">
			${type === 'document' ? `<label><span>Dokumente durchsuchen</span><input data-document-query value="${esc(filters.query || '')}" placeholder="Bezeichnung, Art oder Pfad"></label>` : ''}
			<label><span>Fallnummer</span><select data-record-filter="caseId" data-record-filter-type="${type}">${filterOptions(cases, filters.caseId, 'Alle Fälle / allgemein')}</select></label>
			<label><span>Niederlassung</span><select data-record-filter="branch" data-record-filter-type="${type}">${filterOptions(branches, filters.branch, 'Alle Niederlassungen')}</select></label>
			${kindFilter}
			${titleFilter}
			<label><span>Status</span><select data-record-filter="status" data-record-filter-type="${type}">${filterOptions(statuses, filters.status, 'Alle Status')}</select></label>
			<label><span>Sortierung</span><select data-record-sort="${type}">${sortOptions}</select></label>
		</div>`
	}

	function filteredRecordRecords(type, records) {
		if (type === 'task') return filteredTaskRecords(records)
		if (!['schedule', 'document'].includes(type)) return records
		const filters = type === 'schedule' ? state.scheduleFilters : state.documentFilters
		const sort = type === 'schedule' ? state.scheduleSort : state.documentSort
		const visible = records.filter((item) => {
			if (type === 'schedule' && !state.showCompletedSchedules && filters.status !== 'ERLEDIGT' && item.status === 'ERLEDIGT') return false
			if (filters.caseId !== 'ALL' && String(item.caseId || 0) !== filters.caseId) return false
			if (filters.branch !== 'ALL' && recordBranch(item) !== filters.branch) return false
			if (filters.status !== 'ALL' && String(item.status || '') !== filters.status) return false
			if (type === 'schedule' && filters.kind !== 'ALL' && String(item.data?.scheduleKind || 'INTERNAL_ACTIVITY') !== filters.kind) return false
			if (type === 'document' && filters.title !== 'ALL' && String(item.data?.documentType || 'Allgemein') !== filters.title) return false
			if (type === 'document' && filters.query) {
				const haystack = [item.title, item.data?.documentType, item.data?.path, recordCaseNumber(item)].join(' ').toLocaleLowerCase('de')
				if (!haystack.includes(String(filters.query).toLocaleLowerCase('de'))) return false
			}
			return true
		})
		const selectors = {
			DATE_ASC: (item) => String(item.date || '9999-12-31'),
			DATE_DESC: (item) => String(item.date || ''),
			CASE_NUMBER: (item) => recordCaseNumber(item),
			BRANCH: (item) => recordBranch(item),
			TITLE: (item) => String(item.title || ''),
			STATUS: (item) => String(item.status || ''),
		}
		const selector = selectors[sort] || selectors.DATE_ASC
		return visible.slice().sort((left, right) => {
			const result = selector(left).localeCompare(selector(right), 'de', { numeric: true, sensitivity: 'base' })
			return sort === 'DATE_DESC' ? -result : result
		})
	}

	// 0.92.0: Ressourcen eines Termins als Chips mit Symbol; bei Aufgaben die Ressourcen des zugehörigen Termins
	// (Aufgabe aus einem Termin über sourceScheduleId, oder Termin, der aus der Aufgabe angelegt wurde).
	function linkedSchedule(task) {
		const schedules = state.records?.schedule || []
		const sourceId = Number(task?.data?.sourceScheduleId || task?.data?.link?.sourceScheduleId || 0)
		if (sourceId) { const found = schedules.find((entry) => Number(entry.id) === sourceId); if (found) return found }
		return schedules.find((entry) => Number(entry.data?.link?.parentRecordId || 0) === Number(task?.id || -1) && entry.status !== 'ABGESAGT') || null
	}
	function recordResourceChips(item, type = item?.type) {
		const schedule = type === 'schedule' ? item : (type === 'task' ? linkedSchedule(item) : null)
		if (!schedule) return ''
		const chips = resourceChips(scheduleResources(schedule.data || {}, state.scheduling?.resources || []), state.scheduling?.resourceTypes, esc)
		if (!chips) return ''
		return type === 'task' ? `<span class="bp-resource-linked" title="${esc(`Ressourcen des Termins „${schedule.title}“`)}">${chips}</span>` : chips
	}

	function recordMeta(item, type) {
		const syncState = item.data?.nextcloud?.remoteDeletedAt ? ' · in Nextcloud gelöscht, lokal erhalten' : (item.data?.nextcloud?.stored ? ' · Nextcloud synchronisiert' : (item.data?.nextcloud?.syncError ? ' · Synchronisationsfehler' : ''))
		const assignee = recordAssigneeName(item)
		const scheduleAssignees = type === 'schedule' ? (item.data?.assignees || []).map((entry) => entry.displayName || entry.uid).filter(Boolean).join(', ') : ''
		const branch = recordBranch(item)
		const scheduleKind = type === 'schedule' ? (item.data?.scheduleKind === 'EXTERNAL_APPOINTMENT' ? 'Externer Fixtermin' : 'Interne Tätigkeit') : ''
		const actor = item.updatedBy || item.createdBy || ''
		return `${esc(formatRecordDate(item.date))}${scheduleKind ? ` · ${esc(scheduleKind)}` : ''}${branch ? ` · Niederlassung: ${esc(branch)}` : ''}${type === 'task' && assignee ? ` · Zuständig: ${esc(assignee)}` : ''}${type === 'task' && item.data?.assigneeRole && item.data.assigneeRole !== 'FALLVERANTWORTLICH' ? ` · Rolle: ${esc(DEFAULT_META.roleLabels[item.data.assigneeRole] || item.data.assigneeRole)}${(item.data?.assigneeUids || []).length > 1 ? ` (${item.data.assigneeUids.length} Personen)` : ''}` : ''}${type === 'task' && item.data?.processPhase ? ` · Phase ${Number(item.data.processPhase)}` : ''}${scheduleAssignees ? ` · Zuständig: ${esc(scheduleAssignees)}` : ''}${actor ? ` · Bearbeitet von: ${esc(actor)}` : ''}${syncState}`
	}

	function formatRecordDate(value) {
		const text = String(value || '').trim()
		if (!text) return 'ohne Datum'
		const match = text.match(/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2}))?/)
		if (!match) return text.replace('T', ' ')
		const date = `${match[3]}.${match[2]}.${match[1]}`
		return match[4] ? `${date}, ${match[4]}:${match[5]} Uhr` : date
	}

	function recordBadges(item, type) {
		const status = String(item.status || 'OFFEN').toUpperCase()
		const statusLabels = { OFFEN: ['○', 'Offen'], IN_BEARBEITUNG: ['◐', 'In Bearbeitung'], ERLEDIGT: ['✓', 'Erledigt'], BESTAETIGT: ['✓', 'Bestätigt'], ABGESAGT: ['×', 'Abgesagt'], ENTWURF: ['○', 'Entwurf'], GEAENDERT: ['!', 'Geändert – Folgeaufgaben prüfen'], DOKUMENTIERT: ['✓', 'Dokumentiert'] }
		const [statusIcon, statusLabel] = statusLabels[status] || ['•', status.replaceAll('_', ' ')]
		const statusClass = status.toLowerCase().replaceAll('_', '-')
		const priority = String(item.data?.priority || 'NORMAL').toUpperCase()
		const priorityLabels = { KRITISCH: ['‼', 'Kritisch'], HOCH: ['↑', 'Hoch'], NORMAL: ['–', 'Normal'], NIEDRIG: ['↓', 'Niedrig'] }
		const [priorityIcon, priorityLabel] = priorityLabels[priority] || ['•', priority]
		return `<span class="bp-status bp-status-${esc(statusClass)}" aria-label="Status: ${esc(statusLabel)}"><span aria-hidden="true">${esc(statusIcon)}</span> Status: ${esc(statusLabel)}</span>${type === 'task' ? `<span class="bp-priority bp-priority-${esc(priority.toLowerCase())}" aria-label="Priorität: ${esc(priorityLabel)}"><span aria-hidden="true">${esc(priorityIcon)}</span> Priorität: ${esc(priorityLabel)}</span>` : ''}`
	}

	// Kundenwunsch 2026-09-21: Der Fall-Verlauf zeigte bisher pro Eintrag nur den generischen Titel
	// (z. B. "Fall geändert"). AuditService::caseHistory() liefert seit dieser Änderung zusätzlich
	// eine bereits feldweise aufbereitete Änderungsliste (item.changes, [{label, before, after}]),
	// die hier analog zur bestehenden Termin-Änderungshistorie (siehe historyRows in showRecordForm())
	// als Alt-/Neu-Werte je Feld dargestellt wird, statt sie wie bisher ungenutzt zu verwerfen.
	function historyChangeRows(item, showTechnical = false) {
		// 0.89.4: technicalChanges (Abgleich mit Nextcloud, Ablaufkennungen, Dateikennungen) nur bei „Technische Einträge anzeigen“.
		const technical = showTechnical && Array.isArray(item.technicalChanges) ? item.technicalChanges.map((change) => ({ ...change, technical: true })) : []
		const changes = [...(Array.isArray(item.changes) ? item.changes : []), ...technical]
		if (!changes.length) return ''
		const rows = changes.map((change) => change.text
			? `<small>${esc(change.text)}</small>`
			: ['__truncated__', '__context__'].includes(change.key)
				? `<small class="bp-muted">${esc(change.after)}</small>`
				: `<small${change.technical ? ' class="bp-history-technical"' : ''}>${esc(change.label || change.key)}: ${esc(String(change.before ?? '–'))} → ${esc(String(change.after ?? '–'))}</small>`).join('')
		return `<div class="bp-history-changes">${rows}</div>`
	}

	// 0.89.4: Gleichzeitig angelegte Einträge (z. B. die Aufgaben eines Ablaufs) als ein aufklappbarer Eintrag.
	const historyPlural = { TASK: 'Aufgaben', SCHEDULE: 'Termine', DOCUMENT: 'Dokumente', DEREGISTRATION: 'Abmeldungen', CONTACT: 'Kontakte', CASE_CONTACT: 'Kontakte', SIDE_ORDER: 'Nebenaufträge', NOTE: 'Notizen' }
	const HISTORY_GROUP_WINDOW_MS = 120000
	function historyGroups(items) {
		const groups = []
		for (const item of items) {
			const last = groups[groups.length - 1]
			const previous = last?.items[last.items.length - 1]
			if (item.action === 'CREATED' && historyPlural[item.objectType] && previous?.action === 'CREATED' && previous.objectType === item.objectType
				&& previous.userUid === item.userUid && Math.abs(Date.parse(previous.date) - Date.parse(item.date)) <= HISTORY_GROUP_WINDOW_MS) last.items.push(item)
			else groups.push({ items: [item] })
		}
		return groups
	}
	function historyName(item) {
		const context = (item.changes || []).find((change) => change.key === '__context__' && String(change.after || '').startsWith('Titel: '))
		return context ? String(context.after).slice(7) : item.title
	}
	function historyEntry(item, showTechnical) {
		return `<article class="bp-history-entry${item.technical ? ' bp-history-entry-technical' : ''}" data-history-entry="${esc(item.id)}"><span class="bp-history-dot" aria-hidden="true"></span><div><b>${esc(item.title)}</b><small>${esc(formatRecordDate(item.date))} · ${esc(item.userUid || 'system')}</small>${historyChangeRows(item, showTechnical)}</div></article>`
	}
	function historyRows(records) {
		const showTechnical = Boolean(state.showTechnicalHistory)
		const shown = showTechnical ? records : records.filter((item) => !item.technical)
		if (!shown.length) return '<p class="bp-empty">Keine passenden Einträge vorhanden.</p>'
		return `<div class="bp-history-list">${historyGroups(shown).map(({ items }) => {
			if (items.length === 1) return historyEntry(items[0], showTechnical)
			const first = items[0]
			return `<article class="bp-history-entry bp-history-group" data-history-group="${esc(first.objectType)}"><span class="bp-history-dot" aria-hidden="true"></span><div><details><summary><b>${items.length} ${esc(historyPlural[first.objectType])} angelegt</b><small>${esc(formatRecordDate(first.date))} · ${esc(first.userUid || 'system')}</small></summary><div class="bp-history-changes">${items.map((item) => `<small>${esc(historyName(item))}</small>`).join('')}</div></details></div></article>`
		}).join('')}</div>`
	}

	function recordRows(type, records) {
		const visible = filteredRecordRecords(type, records)
		if (!visible.length) return '<p class="bp-empty">Keine passenden Einträge vorhanden.</p>'
		if (type === 'document') return `<div class="bp-record-list">${visible.map(documentOutputRow).join('')}</div>`
		if (type === 'activity') return historyRows(visible)
		return `<div class="bp-record-list">${visible.map((item) => {
			const caseNumber = recordCaseNumber(item)
			return `<article class="bp-record-row" data-record-row="${item.id}"><label class="bp-record-check">${type === 'task' ? `<input type="checkbox" data-task-complete="${item.id}" ${item.status === 'ERLEDIGT' ? 'checked' : ''}>` : '<span>◷</span>'}</label><button class="bp-record-content" data-record-id="${item.id}" data-record-type="${type}"><b>${caseNumber ? `<span class="bp-case-number">Fall ${esc(caseNumber)}</span>` : ''}${esc(item.title)}</b><small>${recordMeta(item, type)}</small>${recordResourceChips(item, type)}</button><div class="bp-record-badges">${recordBadges(item, type)}</div><button class="bp-secondary edit-record" data-record-id="${item.id}" data-record-type="${type}">Bearbeiten</button></article>`
		}).join('')}</div>`
	}

	// 0.79.3: Mit Fallbezug aufgerufen, aber ohne Fallnummer (neuer, noch nicht gespeicherter Fall) → keine Liste.
	// Vorher zeigte der Aufruf mit undefined die Einträge aller Fälle.
	function recordsView(type, ...scope) {
		const caseId = scope.length ? Number(scope[0] || 0) : 0
		if (scope.length && !caseId) return `<section class="bp-panel bp-placeholder"><h3>${esc(recordLabels[type] || type)}</h3><p>${UNSAVED_CASE_HINT}</p></section>`
		let records = (state.records[type] || []).filter((item) => !caseId || item.caseId === caseId)
		let technicalFileCount = 0
		if (type === 'document' && !caseId) {
			const knownFileIds = new Set(records.flatMap((item) => [Number(item.data?.fileId || 0), Number(item.data?.pdf?.fileId || 0), Number(item.data?.eInvoice?.fileId || 0)]).filter(Boolean))
			// 0.89.9: Begleitdateien der Rechnungsausgabe (Abschlussnachweis, Prüfbericht, ältere factur-x.xml) nur auf Wunsch.
			const unknownFiles = (state.allCaseFiles || []).filter((file) => !knownFileIds.has(Number(file.fileId)))
			technicalFileCount = unknownFiles.filter((file) => file.technical).length
			const directFiles = unknownFiles.filter((file) => state.showTechnicalFiles || !file.technical).map((file) => ({ id: -Number(file.fileId), caseId: Number(file.caseId), caseNumber: file.caseNumber, title: file.title, date: file.modifiedAt, status: file.status || 'ABLAGE', data: { fileId: file.fileId, documentType: file.documentType, path: file.path, source: file.source, extension: file.extension, mimeType: file.mimeType, readOnly: file.readOnly, technicalLabel: file.technicalLabel || '', notice: file.notice || '' } }))
			records = [...records, ...directFiles]
		}
		return `<div class="bp-page-head"><div><h2>${recordLabels[type]}</h2>${type === 'activity' ? '<p class="bp-muted">Automatisch geführtes, unveränderbares Protokoll der Bearbeitungsschritte.</p>' : ''}</div><div class="bp-head-actions">${type === 'task' ? `<label class="bp-task-filter"><input type="checkbox" id="show-completed-tasks" ${state.showCompletedTasks ? 'checked' : ''}> Erledigte Aufgaben anzeigen</label>` : ''}${type === 'schedule' ? `<label class="bp-task-filter"><input type="checkbox" id="show-completed-schedules" ${state.showCompletedSchedules ? 'checked' : ''}> Erledigte Termine anzeigen</label>` : ''}${type === 'activity' ? `<label class="bp-task-filter"><input type="checkbox" id="show-technical-history" ${state.showTechnicalHistory ? 'checked' : ''}> Technische Einträge anzeigen (${records.filter((item) => item.technical).length})</label>` : ''}${type === 'document' && !caseId ? technicalFilesToggle(technicalFileCount) : ''}${['document', 'activity'].includes(type) ? '' : `<button class="bp-primary" id="new-record" data-record-type="${type}" data-case-id="${caseId}">Neu anlegen</button>`}${['task', 'schedule'].includes(type) ? `<button class="bp-secondary" id="sync-groupware" data-record-type="${type}">Aus Nextcloud aktualisieren</button>` : ''}</div></div>${type === 'task' ? taskToolbar(caseId) : recordToolbar(type, caseId)}<section class="bp-panel bp-record-panel">${recordRows(type, records)}</section>`
	}

	// 0.89.9: Schalter für technische Begleitdateien (Dokumente-Seite und Dokumente im Fall).
	function technicalFilesToggle(count) {
		return `<label class="bp-task-filter" title="Abschlussnachweise und Prüfberichte der Rechnungsausgabe. Sie bleiben im Abrechnungsordner gespeichert."><input type="checkbox" id="show-technical-files" ${state.showTechnicalFiles ? 'checked' : ''}> Technische Dateien anzeigen (${Number(count) || 0})</label>`
	}

	function documentOutputRow(item) {
		const immutable = ['FINAL', 'UNTERSCHRIEBEN', 'VERSENDET'].includes(String(item.status || '').toUpperCase())
		const pdfId = Number(item.data?.pdf?.fileId || 0); const docxId = Number(item.data?.fileId || 0); const previewId = pdfId || docxId
		// Nextcloud 34 may answer very small PDF previews (240x300) with [] instead
		// of an image. Request a reliable source size and scale it down in CSS.
		const previewUrl = previewId ? OC.generateUrl(`/core/preview?fileId=${previewId}&x=800&y=1100&a=1`) : ''
		const path = String(item.data?.path || item.title || '')
		const extension = String(item.data?.extension || path.match(/\.([A-Za-z0-9]{1,8})$/)?.[1] || '').toUpperCase()
		const format = pdfId ? 'PDF' : (extension || 'DATEI')
		const fileId = pdfId || docxId
		const printablePdfId = pdfId || (format === 'PDF' || String(item.data?.mimeType || '').toLowerCase() === 'application/pdf' ? docxId : 0)
		const mailSource = String(pdfId ? (item.data?.pdf?.fileName || item.data?.pdf?.path || `${item.title}.pdf`) : (item.data?.fileName || path || item.title))
		const mailFileName = mailSource.split(/[\\/]/).pop() || (pdfId ? `${item.title}.pdf` : item.title)
		const mailMimeType = pdfId ? 'application/pdf' : String(item.data?.mimeType || '')
		const editable = !immutable && !item.data?.readOnly && ['DOCX','ODT','XLSX','ODS','PPTX','TXT'].includes(format)
		const displayDate = item.date ? new Date(item.date).toLocaleString('de-DE') : 'ohne Datumsangabe'
		const caseNumber = recordCaseNumber(item)
		const actionData = `data-file-id="${fileId}" data-document-title="${esc(item.title)}" data-case-number="${esc(caseNumber)}" data-file-name="${esc(mailFileName)}" data-mime-type="${esc(mailMimeType)}" data-share-url="${esc(`${apiBase}/cases/${Number(item.caseId)}/files/${fileId}/content`)}"`
		const printData = printablePdfId ? `${actionData.replace(`data-file-id="${fileId}"`, `data-file-id="${printablePdfId}"`)} data-print-url="${esc(`${apiBase}/cases/${Number(item.caseId)}/files/${printablePdfId}/content.pdf`)}"` : ''
		return `<article class="bp-document-output" data-record-row="${item.id}">${previewUrl ? `<button class="bp-document-thumb preview-nextcloud-file" data-file-id="${previewId}" aria-label="Vorschau ${esc(item.title)}"><span class="bp-document-thumb-fallback" aria-hidden="true"><b>${esc(format)}</b><small>Vorschau öffnen</small></span><img src="${esc(previewUrl)}" alt="" loading="lazy"></button>` : `<div class="bp-document-thumb bp-document-thumb-empty"><b>${esc(format)}</b><small>Keine Vorschau</small></div>`}<div class="bp-record-content"><b>${caseNumber ? `<span class="bp-case-number">Fall ${esc(caseNumber)}</span>` : ''}${esc(item.title)}</b><small>${esc(item.data?.documentType || 'Allgemein')} · ${esc(item.status)} · ${esc(displayDate)}</small><small class="bp-document-location">Ablage: ${esc(item.data?.path || 'Fallakte')}</small>${item.updatedBy || item.createdBy ? `<small>Bearbeitet von: ${esc(item.updatedBy || item.createdBy)}</small>` : ''}${immutable ? '<small>Finale Ausgabe – schreibgeschützt.</small>' : (editable ? '<small>Arbeitsstand – kann in Nextcloud bearbeitet werden.</small>' : '<small>Technische oder schreibgeschützte Datei.</small>')}${item.data?.paperSignature ? `<small>${item.data.paperSignature.source === 'GENERATED' ? 'Finale PDF-Revision' : 'Separater Vertrag'} · ${item.status === 'SIGNATURE_REVIEW' ? 'Sichtprüfung ausstehend' : 'handschriftlich unterschrieben'}</small>` : ''}${item.data?.pdfWarning ? `<small class="bp-error">${esc(item.data.pdfWarning)}</small>` : ''}${item.data?.technicalLabel ? `<small class="bp-muted">${esc(item.data.technicalLabel)}</small>` : ''}${item.data?.notice ? `<small class="bp-warning-text">${esc(item.data.notice)}</small>` : ''}${item.data?.eInvoice?.embedded === false && item.data?.eInvoice?.embedError !== undefined && item.data?.pdf?.fileId ? '<small class="bp-warning-text">E-Rechnungsdaten nicht in die PDF eingebettet – die XML liegt separat im Abrechnungsordner und gehört zur Rechnung.</small>' : ''}</div><div class="bp-row-actions">${item.status === 'SIGNATURE_REVIEW' && item.data?.paperSignature && Number(item.id) > 0 ? `<button class="bp-primary review-paper-contract" data-record-id="${Number(item.id)}">Unterschriften prüfen</button>` : ''}${editable && docxId ? `<button class="bp-secondary open-nextcloud-file" data-file-id="${docxId}">In Nextcloud bearbeiten</button>` : ''}${pdfId ? `<button class="bp-primary open-nextcloud-file" data-file-id="${pdfId}">PDF öffnen</button>` : ''}${!pdfId && docxId ? `<button class="bp-secondary open-nextcloud-file" data-file-id="${docxId}">${editable?'Dokument öffnen':'Datei öffnen'}</button>` : ''}${printablePdfId ? printButton('print-nextcloud-pdf', 'Drucken', printData, 'PDF direkt drucken') : ''}${fileId ? mailButton('mail-nextcloud-file', 'Mit Mailprogramm teilen', actionData, 'Datei an ein lokales Mailprogramm übergeben') : ''}</div></article>`
	}

	function normalizeDateTime(value) {
		if (!value) return ''
		return value.length >= 16 ? value.slice(0, 16) : value
	}

	function caseOptions(selectedCaseId = 0, type = 'task') {
		const generalLabel = type === 'schedule' ? 'allgemeiner Termin' : 'allgemeine Aufgabe'
		return `<option value="0">Keine Fallzuordnung (${generalLabel})</option>${state.cases.slice().sort((a, b) => String(a.caseNumber).localeCompare(String(b.caseNumber), 'de', { numeric: true })).map((entry) => `<option value="${entry.id}" ${Number(entry.id) === Number(selectedCaseId) ? 'selected' : ''}>${esc(entry.caseNumber)} · ${esc(entry.lastName)}, ${esc(entry.firstName)}</option>`).join('')}`
	}

	function showActionDocumentResult(actionResult) {
		const documents = actionResult?.result?.documents || (actionResult?.result?.file ? [actionResult.result] : [])
		if (!documents.length) return
		const cards = documents.map((entry, index) => {
			const file = entry.file || entry
			const docxId = Number(file.fileId || 0); const pdfId = Number(file.pdf?.fileId || 0); const previewId = pdfId || docxId
			return `<article class="bp-document-output"><div class="bp-record-content"><b>${esc(file.title || `Dokument ${index + 1}`)}</b><small>Ablage: ${esc(file.path || file.pdf?.path || '05 Trauerdruck')}</small>${file.pdfWarning ? `<small class="bp-error">${esc(file.pdfWarning)}</small>` : ''}</div><div class="bp-row-actions">${previewId ? `<button type="button" class="bp-secondary workflow-preview-document" data-file-id="${previewId}" data-title="${esc(file.title || 'Dokument')}">Vorschau</button>` : ''}${docxId ? `<button type="button" class="bp-secondary workflow-open-document" data-file-id="${docxId}">DOCX öffnen</button>` : ''}${pdfId ? `<button type="button" class="bp-primary workflow-print-document" data-file-id="${pdfId}">PDF öffnen / drucken</button>` : ''}</div></article>`
		}).join('')
		root.insertAdjacentHTML('beforeend', `<div class="bp-modal"><section class="bp-wide-modal"><div class="bp-panel-head"><div><p class="bp-eyebrow">Aktion abgeschlossen</p><h2>Erzeugte Dokumente</h2><p class="bp-muted">Die aktuellen Ausgaben wurden im Fallordner „05 Trauerdruck“ abgelegt.</p></div><button type="button" class="bp-secondary" data-close-workflow-result>Schließen</button></div><div class="bp-record-list">${cards}</div><p class="bp-muted">„PDF öffnen / drucken“ öffnet die fertige PDF-Ausgabe; anschließend kann der Browser-Druckdialog verwendet werden.</p></section></div>`)
		const resultModal = [...root.querySelectorAll('.bp-modal')].pop()
		resultModal.querySelector('[data-close-workflow-result]').onclick = () => resultModal.remove()
		resultModal.querySelectorAll('.workflow-preview-document').forEach((button) => button.onclick = () => showFilePreview(button.dataset.fileId, button.dataset.title))
		resultModal.querySelectorAll('.workflow-open-document,.workflow-print-document').forEach((button) => button.onclick = () => window.open(OC.generateUrl(`/f/${button.dataset.fileId}`), '_blank', 'noopener'))
	}

	async function showRecordForm(type, caseId = 0, item = null, preset = {}) {
		const isEvent = type === 'schedule'; const editing = Boolean(item)
		let processActions = null
		let scheduleHistory = []
		if (type === 'task' && editing) {
			try { const available = await api(actionsUrl(item.id)); processActions = available?.actions?.length ? available : null } catch (_) { processActions = null }
		}
		if (type === 'schedule' && editing) {
			try { scheduleHistory = await api(`${schedulingUrl()}/records/${item.id}/history`, { feedback: false }) } catch (_) { scheduleHistory = [] }
		}
		const now = new Date(); const today = `${now.getFullYear()}-${String(now.getMonth()+1).padStart(2,'0')}-${String(now.getDate()).padStart(2,'0')}`
		// 0.90.5: Statt 00:00 Uhr beginnen neue Termine mit dem Arbeitsbeginn, Aufgaben sind zum Arbeitsende fällig (Customizing → Terminplanung).
		const defaultDate = ['task','schedule'].includes(type) ? `${today}T${defaultTime(type, state.scheduling)}` : ''
		const selectedCaseId = Number(item?.caseId || caseId || 0)
		const selectedCase = state.cases.find((entry) => Number(entry.id) === selectedCaseId)
		const linkedCase = item?.caseNumber || selectedCase?.caseNumber || (caseId && state.currentCase?.id === caseId ? state.currentCase.caseNumber : '')
		const baseData = { ...(item?.data || {}), ...(preset.data || {}) }
		// 0.90.5: Ob die Einordnung vorgegeben war, merken – sonst übernimmt ein neuer Termin sie aus der vorbelegten Terminart (Ablaufknopf „Termin anlegen“).
		const scheduleKindGiven = Boolean(item?.data?.scheduleKind || preset.data?.scheduleKind)
		if (type === 'schedule' && !baseData.scheduleKind) baseData.scheduleKind = 'INTERNAL_ACTIVITY'
		const assigneeUid = baseData.assigneeUid || item?.assigneeUid || state.team.currentUid || currentUser()
		const scheduleAssignees = new Set((baseData.assigneeUids || [state.team.currentUid || currentUser()]).map(String))
		const memberName = (uid) => (state.team.members || []).find((member) => member.uid === uid)?.displayName || uid
		const assigneeField = type === 'task' ? `<label>Zuständig<select name="assigneeUid" required>${assigneeOptions(assigneeUid)}</select></label>` : (type === 'schedule' ? `<fieldset class="bp-member-picker bp-schedule-people" id="schedule-people"><legend>Mitarbeitende</legend>${(state.team.members||[]).map((member)=>`<div class="bp-availability-row"><label class="bp-inline-check"><input type="checkbox" name="assigneeUids" value="${esc(member.uid)}" ${scheduleAssignees.has(member.uid)?'checked':''}> <span>${esc(member.displayName||member.uid)} <small>${esc(member.uid)}</small></span></label><span data-availability-uid="${esc(member.uid)}"></span></div>`).join('')}<small class="bp-muted">Die Termine erscheinen in den persönlichen Kalendern der gewählten Personen.</small></fieldset>` : '')
		const configuredTypes=(state.scheduling?.types||[]).map((entry)=>({...entry,label:entry.name,title:entry.name,appointmentCategory:entry.name,source:'CONFIGURATION'}));const availableScheduleTypes=[...configuredTypes,...(state.schedulePresets||[]).filter((preset)=>!configuredTypes.some((entry)=>entry.key===preset.key))]
		const schedulePresetOptions = availableScheduleTypes.map((entry) => `<option value="${esc(entry.key)}" ${entry.key === (baseData.scheduleTypeKey||baseData.schedulePresetKey) ? 'selected' : ''}>${esc(entry.label||entry.name)}</option>`).join('')
		const externalContacts = (state.records.contact || []).slice().sort((a, b) => String(a.title).localeCompare(String(b.title), 'de'))
		const externalContactSuggestions = externalContacts.map((contact) => `<option value="${esc(contact.title)}">${esc(contact.data?.categories || 'Kontakt')}</option>`).join('')
		const externalParticipantsValue = baseData.externalParticipants || [
			...(baseData.externalContacts || []).map((entry) => entry.title),
			baseData.externalParticipantsAdditional || '',
		].filter(Boolean).join('; ')
		const scheduleCategories = [...new Set(['Aufbahrung','1. Trauerfeier','2. Trauerfeier','Beisetzung','Überführung','Abschiednahme','Behördentermin','Beratungsgespräch','Sonstiger externer Termin', ...availableScheduleTypes.map((entry) => entry.appointmentCategory || entry.name).filter(Boolean)])]
		const initialScheduleType=availableScheduleTypes.find((entry)=>entry.key===(baseData.scheduleTypeKey||baseData.schedulePresetKey));// 0.90.3: Nur die Ressourcen anbieten, die der Terminart im Customizing (Matrix „Ressourcen je Terminart“) zugeordnet sind.
		// 0.92.0: Mit Fallbezug nur Ressourcen der Niederlassung des Falls (oder „Alle Niederlassungen“).
		const caseBranchKey = (caseIdValue) => { const id = Number(caseIdValue || 0); if (!id) return ''; const found = state.cases.find((entry) => Number(entry.id) === id) || (Number(state.currentCase?.id) === id ? state.currentCase : null); return String(found?.branch || found?.masterData?.branch || '') }
		const branchLabel = (key) => (state.scheduling?.branches || []).find((branch) => branch.key === key)?.name || (state.branches || []).find((branch) => branch.key === key)?.name || key
		let currentBranch = caseBranchKey(selectedCaseId)
		const resourceTypes = state.scheduling?.resourceTypes
		const previousResourceKeys=editing?normalizeKeys(item?.data?.resourceKeys||[]):[];const initialSelectedResources=normalizeKeys(baseData.resourceKeys||(!editing?initialScheduleType?.defaultResourceKeys:[])||[])
		const resourcePickerHtml=(scheduleType,selectedKeys)=>{
			const offered=offeredResources(scheduleType,state.scheduling?.resources||[],previousResourceKeys,currentBranch)
			if(!scheduleType)return'<p class="bp-muted" data-resource-empty="no-type">Für Ressourcen bitte eine Terminart wählen.</p>'
			if(!offered.length){const anyAllowed=offeredResources(scheduleType,state.scheduling?.resources||[],previousResourceKeys).length;return anyAllowed&&currentBranch?`<p class="bp-muted" data-resource-empty="none-in-branch">Für diese Terminart sind in der Niederlassung „${esc(branchLabel(currentBranch))}“ keine Ressourcen vorgesehen. Die Niederlassungen einer Ressource pflegen Administratoren unter Stammdaten → Ressourcen.</p>`:'<p class="bp-muted" data-resource-empty="none-assigned">Für diese Terminart sind keine Ressourcen vorgesehen. Die Zuordnung pflegen Administratoren unter Customizing → Terminplanung.</p>'}
			return groupByType(offered,resourceTypes).map((group)=>`<div class="bp-resource-group" data-resource-group="${esc(group.type)}"><small>${resourceTypeIcon(group.type,resourceTypes,esc)} ${esc(group.label)}</small>${group.items.map((resource)=>`<div class="bp-availability-row"><label class="bp-inline-check"><input type="checkbox" name="resourceKeys" value="${esc(resource.key)}" ${selectedKeys.includes(resource.key)?'checked':''}><span>${esc(resource.name)}${resource.identifier?` <small class="bp-resource-identifier">${esc(resource.identifier)}</small>`:''}${resource.active===false?' <small>(nicht mehr aktiv)</small>':''}</span></label><span data-availability-resource="${esc(resource.key)}"></span></div>`).join('')}</div>`).join('')
		}
		const resourceNames=(keys)=>keys.map((key)=>{const resource=(state.scheduling?.resources||[]).find((entry)=>entry.key===key);return`„${resource?resourceDisplayName(resource):key}“`}).join(', ')
		const initialDropped=type==='schedule'?droppedKeys(initialScheduleType,initialSelectedResources,state.scheduling?.resources||[],previousResourceKeys,currentBranch):[]
		const initialResourceHint=initialDropped.length?`${resourceNames(initialDropped)} ${initialDropped.length===1?'ist':'sind'} für diese Terminart nicht vorgesehen und ${initialDropped.length===1?'wird':'werden'} beim Speichern entfernt.`:''
		const predefinedTasks = [...new Set((state.process?.phases || []).flatMap((phase) => (phase.steps || []).map((view) => view.title)).filter(Boolean))].sort((a,b)=>a.localeCompare(b,'de'))
		const taskTitleList = type==='task' ? `<datalist id="predefined-task-titles">${predefinedTasks.map((title)=>`<option value="${esc(title)}"></option>`).join('')}</datalist>` : ''
		const detailFields = baseData.kind === 'EMAIL_DRAFT'
			? `<label>Empfänger<input name="recipient" type="email" value="${esc(baseData.recipient || '')}"></label><label>Empfängergruppe<input name="recipientCategory" value="${esc(baseData.recipientCategory || '')}" readonly></label><p class="bp-record-case-context">Entwurfsstatus: Kein automatischer Versand.</p>`
			: (type === 'activity' || baseData.kind === 'CONTACT_ACTIVITY' ? `<label>Kontakt / Organisation<input name="contact" value="${esc(baseData.contact || '')}"></label><label>Kontaktweg<select name="channel">${['Telefon', 'E-Mail', 'Persönlich', 'Post', 'Sonstiges'].map((value) => `<option ${value === baseData.channel ? 'selected' : ''}>${value}</option>`).join('')}</select></label>` : '')
		const fixedCase = state.view === 'case-detail' && selectedCaseId > 0
		const generalRecordLabel = type === 'schedule' ? 'ein allgemeiner Termin' : 'eine allgemeine Aufgabe'
		const caseField = fixedCase
			? `<input type="hidden" name="caseId" value="${selectedCaseId}">`
			: `<label>Fallnummer (optional)<select name="caseId">${caseOptions(selectedCaseId, type)}</select><small>Ohne Fallzuordnung wird ${generalRecordLabel} angelegt.</small></label>`
		const configuredWorkflowActions = type === 'task' && editing && processActions ? `<section class="bp-follow-up bp-configured-workflows"><h3>Knöpfe aus dem Ablauf</h3><p>Diese Aufgabe ist ein Schritt des Ablaufs${baseData.processPhase ? ` (Phase ${Number(baseData.processPhase)})` : ''}. „Entfällt“ und „Wieder öffnen“ stehen im Fall unter „Aufgaben“ bereit. Ungespeicherte Formularänderungen werden durch die Knöpfe nicht übernommen.</p><article><b>${esc(processActions.title || '')}</b><div>${processActions.actions.map((action) => {
			const recoveryHint = action.outputMissing ? `<small class="bp-warning">${action.recoveryReason === 'STALE_RUNNING' ? 'Ein früherer Dokumentlauf wurde nicht abgeschlossen.' : 'Die Ausgaben der früheren Ausführung sind nicht vollständig vorhanden.'} Bitte die Dokumente erneut erzeugen.</small>` : ''
			const resultButton = action.executed && ['DOCUMENT', 'DOCUMENT_BUNDLE'].includes(action.type) && action.lastRun?.result
				? `<button type="button" class="bp-secondary show-action-output" data-action-key="${esc(action.key)}">Vorhandene Ausgaben anzeigen</button>`
				: ''
			return `<span class="bp-workflow-action-control"><button type="button" class="bp-secondary execute-process-action" data-action-key="${esc(action.key)}" ${action.executed && !action.repeatable ? 'disabled' : ''}>${action.executed && !action.repeatable ? '✓ ' : (action.retryable ? '↻ ' : '')}${esc(action.label)}</button>${resultButton}${recoveryHint}</span>`
		}).join('')}</div></article></section>` : ''
		const workflowActions = type === 'task' && editing ? `<section class="bp-follow-up"><h3>Freie Folgeaktion</h3><p>Für einen einmaligen, nicht vorkonfigurierten Folgeschritt.</p><div><button type="button" class="bp-secondary" data-follow-up="task">Folgeaufgabe anlegen</button><button type="button" class="bp-secondary" data-follow-up="schedule">Folgetermin / Frist anlegen</button></div></section>` : ''
		// 0.90.5: Termine aus einem Ablaufschritt oder einer Aufgabe sind abgestimmt und werden „Bestätigt“ vorbelegt.
		const recordStatus = item?.status || preset.status || (type === 'schedule' ? defaultScheduleStatus(baseData) : (type === 'activity' ? 'DOKUMENTIERT' : 'OFFEN'))
		const statusValues = type === 'schedule' ? ['ENTWURF', 'BESTAETIGT', 'GEAENDERT', 'ABGESAGT', 'ERLEDIGT'] : [...new Set(['OFFEN', 'IN_BEARBEITUNG', 'ERLEDIGT', 'ENTWURF', 'DOKUMENTIERT', item?.status, preset.status].filter(Boolean))]
		const statusLabels = { ENTWURF: 'Entwurf', BESTAETIGT: 'Bestätigt', GEAENDERT: 'Geändert – Folgeaufgaben prüfen', ABGESAGT: 'Abgesagt', ERLEDIGT: 'Erledigt', OFFEN: 'Offen', IN_BEARBEITUNG: 'In Bearbeitung', DOKUMENTIERT: 'Dokumentiert' }
		const statusSelect = `<select name="status">${statusValues.map((value) => `<option value="${value}" ${value === recordStatus ? 'selected' : ''}>${statusLabels[value] || value}</option>`).join('')}</select>`
		const dateValue = normalizeDateTime(item?.date || preset.date || defaultDate)
		const priorityField = `<label>Priorität<select name="priority">${[['KRITISCH', 'Kritisch'], ['HOCH', 'Hoch'], ['NORMAL', 'Normal'], ['NIEDRIG', 'Niedrig']].map(([value, label]) => `<option value="${value}" ${value === (baseData.priority || 'NORMAL') ? 'selected' : ''}>${label}</option>`).join('')}</select></label>`
		const descriptionField = `<label>Beschreibung<textarea name="description" rows="${isEvent ? 3 : 4}">${esc(baseData.description || '')}</textarea></label>`
		let body
		if (isEvent) {
			// 0.90.5: Terminformular im Stil des Nextcloud-Kalenders – Bezeichnung, dann „Wann“ (Datum, Beginn, Ende, Status) hervorgehoben,
			// danach Terminart, Ort, Mitarbeitende und Ressourcen mit Verfügbarkeit; Selteneres unter „Weitere Angaben“.
			const when = splitDateTime(dateValue)
			const startValue = when.time || defaultTime('schedule', state.scheduling)
			const durationValue = Math.max(15, Number(baseData.durationMinutes || initialScheduleType?.durationMinutes || 60))
			const whenBlock = `<fieldset class="bp-schedule-when" id="schedule-when"><legend>Wann</legend><input type="hidden" name="date" value="${esc(when.date ? `${when.date}T${startValue}` : '')}"><input type="hidden" name="durationMinutes" value="${durationValue}"><div class="bp-when-row"><label><span>Datum</span><input name="scheduleDate" type="date" value="${esc(when.date || today)}" required></label><label><span>Beginn</span><input name="scheduleStart" type="time" step="300" value="${esc(startValue)}" required></label><span class="bp-when-dash" aria-hidden="true">–</span><label><span>Ende</span><input name="scheduleEnd" type="time" step="300" value="${esc(endTime(startValue, durationValue))}" required></label><span class="bp-when-duration" id="schedule-duration-label" aria-live="polite"></span></div><div class="bp-when-row bp-when-status"><label><span>Status</span>${statusSelect}</label><small class="bp-when-occupancy" id="schedule-occupancy"></small></div><small class="bp-when-hint" id="schedule-status-hint" aria-live="polite"></small></fieldset>`
			const typeBlock = `<div class="bp-schedule-type"><label><span>Terminart</span><select name="schedulePresetKey" id="schedule-preset"><option value="">Freie Eingabe</option>${schedulePresetOptions}</select><small>Übernimmt Dauer, Vor- und Nachbereitung sowie die vorbelegten Ressourcen.</small></label><p class="bp-record-case-context" id="schedule-requirement-summary"></p></div>`
			const externalBlock = `<div id="external-appointment-fields" class="bp-schedule-external"><label><span>Ort</span><input name="location" value="${esc(baseData.location || '')}" placeholder="Friedhof, Kapelle oder Anschrift"></label><label><span>Externe Personen / Organisationen</span><input name="externalParticipants" list="external-contact-suggestions" value="${esc(externalParticipantsValue)}" placeholder="Namen oder Organisation eingeben"><datalist id="external-contact-suggestions">${externalContactSuggestions}</datalist><small>Passende Namen aus Nextcloud Kontakte werden vorgeschlagen. Mehrere Beteiligte mit Semikolon trennen.</small></label><label id="schedule-category-field"><span>Externe Terminart</span><select name="appointmentCategory"><option value="">Bitte auswählen</option>${scheduleCategories.map((value)=>`<option ${value === baseData.appointmentCategory ? 'selected' : ''}>${value}</option>`).join('')}</select><small>Nur bei „Freie Eingabe“; sonst gilt die Terminart.</small></label></div>`
			const resourceBlock = `<fieldset class="bp-member-picker bp-resource-picker" id="schedule-resource-picker"><legend>Ressourcen</legend><div id="schedule-resource-options">${resourcePickerHtml(initialScheduleType,initialSelectedResources)}</div><p class="bp-record-case-context" id="schedule-resource-hint" aria-live="polite" ${initialResourceHint?'':'hidden'}>${esc(initialResourceHint)}</p></fieldset>`
			const availabilityBlock = `<section class="bp-schedule-availability" aria-label="Verfügbarkeit am Tag des Termins"><div class="bp-schedule-timeline" id="schedule-timeline"></div><p class="bp-availability-summary" id="schedule-availability-summary" aria-live="polite" hidden></p></section>`
			const moreBlock = `<details class="bp-schedule-more" id="schedule-more"><summary>Weitere Angaben</summary><div class="bp-schedule-more-body">${descriptionField}${priorityField}<label><span>Fachliche Einordnung</span><select name="scheduleKind" id="schedule-kind"><option value="INTERNAL_ACTIVITY" ${baseData.scheduleKind !== 'EXTERNAL_APPOINTMENT' ? 'selected' : ''}>Interne Tätigkeit / prozessbedingter Termin</option><option value="EXTERNAL_APPOINTMENT" ${baseData.scheduleKind === 'EXTERNAL_APPOINTMENT' ? 'selected' : ''}>Externer Fixtermin</option></select></label><div class="bp-form-grid"><label><span>Vorbereitungszeit (Minuten)</span><input name="bufferBeforeMinutes" type="number" min="0" step="5" value="${esc(baseData.bufferBeforeMinutes || 0)}"></label><label><span>Nachbereitungs-/Fahrzeit (Minuten)</span><input name="bufferAfterMinutes" type="number" min="0" step="5" value="${esc(baseData.bufferAfterMinutes || 0)}"></label></div><p class="bp-record-case-context">Die Konfliktprüfung berücksichtigt Mitarbeitende, Ressourcen, Dauer sowie Vor- und Nachbereitung.</p></div></details>`
			// 0.90.5: Änderungsgrund und Übersteuerung erscheinen nur, wenn sie gebraucht werden.
			const reasonBlock = `<label id="schedule-change-reason" hidden><span>${editing ? 'Änderungsgrund' : 'Begründung der Übersteuerung'}</span><textarea name="changeReason" rows="2" placeholder="${editing ? 'Warum wird der bestätigte Termin geändert?' : 'Warum wird der Konflikt übersteuert?'}"></textarea><small>${editing ? 'Änderungen an einem bestätigten Termin werden mit Alt-/Neu-Werten protokolliert.' : 'Die Übersteuerung wird im Terminverlauf protokolliert.'}</small></label><label class="bp-inline-check" id="schedule-conflict-override" hidden><input type="checkbox" name="conflictOverride" value="1"> Erkannten Konflikt ausnahmsweise übersteuern (Begründung erforderlich)</label>`
			body = `${caseField}<label class="bp-schedule-title-field"><span>Bezeichnung</span><input name="title" class="bp-schedule-title" value="${esc(item?.title || preset.title || '')}" placeholder="Bezeichnung des Termins" required></label>${whenBlock}${typeBlock}${externalBlock}${assigneeField}${resourceBlock}${availabilityBlock}${moreBlock}${reasonBlock}`
		} else {
			const dateFields = type === 'task' ? `<div class="bp-form-grid"><label><span>Fälligkeitsdatum</span><input name="taskDate" type="date" value="${esc(dateValue.slice(0,10))}" required></label><label><span>Fälligkeitszeit</span><input name="taskTime" type="time" value="${esc(dateValue.slice(11,16) || defaultTime('task', state.scheduling))}" required></label></div>` : `<label>Datum<input name="date" type="datetime-local" value="${esc(dateValue)}"></label>`
			body = `${caseField}${detailFields}<label>Bezeichnung<input name="title" ${type==='task'?'list="predefined-task-titles"':''} value="${esc(item?.title || preset.title || '')}" required>${type==='task'?'<small>Vorgegebene Aufgabe auswählen oder eigenen Freitext eingeben.</small>':''}</label>${taskTitleList}${descriptionField}${dateFields}${priorityField}${assigneeField}<label>Status${statusSelect}</label>${configuredWorkflowActions}${workflowActions}`
		}
		root.insertAdjacentHTML('beforeend', `<div class="bp-modal"><form id="record-form" class="bp-record-form bp-record-form-${type}"><h2>${editing ? 'Bearbeiten' : 'Neu anlegen'} · ${recordLabels[type]}</h2>${fixedCase && linkedCase ? `<p class="bp-record-case-context">Fall ${esc(linkedCase)} · Die Fallnummer und der Rücksprung-Link werden auch in Nextcloud angezeigt.</p>` : ''}${body}<p class="bp-save-state" id="record-form-state"></p><div class="bp-modal-actions"><button type="button" class="bp-secondary" id="cancel-record">Abbrechen</button><button class="bp-primary" id="save-record">${editing ? 'Änderungen speichern' : 'In Nextcloud speichern'}</button></div></form></div>`)
		const modals = root.querySelectorAll('.bp-modal'); const modal = modals[modals.length - 1]
		const form = modal.querySelector('#record-form')
		if (scheduleHistory.length) {
			const historyRows = scheduleHistory.map((entry) => `<li><b>${esc(entry.action)}</b> · ${esc(entry.changedBy)} · ${esc(new Date(entry.createdAt).toLocaleString('de-DE'))}${entry.reason ? `<small>Grund: ${esc(entry.reason)}</small>` : ''}${(entry.changes||[]).map((change)=>`<small>${esc(change.label)}: ${esc(String(change.before||'–'))} → ${esc(String(change.after||'–'))}</small>`).join('')}</li>`).join('')
			form.querySelector('.bp-save-state')?.insertAdjacentHTML('beforebegin', `<details class="bp-schedule-history"><summary>Änderungsverlauf (${scheduleHistory.length})</summary><ol>${historyRows}</ol></details>`)
		}
		const selectedType = () => availableScheduleTypes.find((entry) => entry.key === form.elements.schedulePresetKey?.value)
		const toggleExternalFields = () => { const external = form.elements.scheduleKind?.value === 'EXTERNAL_APPOINTMENT'; const section = modal.querySelector('#external-appointment-fields'); if (section) { section.hidden = !external; const categoryField = modal.querySelector('#schedule-category-field'); const freeEntry = !selectedType(); if (categoryField) categoryField.hidden = !freeEntry; section.querySelectorAll('select,input').forEach((control) => { control.required = external && (control.name === 'location' || (control.name === 'appointmentCategory' && freeEntry)) }) } }
		form.elements.scheduleKind?.addEventListener('change', toggleExternalFields); toggleExternalFields()
		const showRequirements = (selected) => { const summary=modal.querySelector('#schedule-requirement-summary');if(!summary)return;const staff=Number(selected?.requiredStaff||0);const requirements=[...(staff>0?[`${staff} × Mitarbeitende`]:[]),...Object.entries(requiredResources(selected)).map(([key,count])=>`${count} × ${resourceTypeInfo(key,resourceTypes).plural}`)];summary.textContent=requirements.length?`Erforderliche Disposition: ${requirements.join(', ')}`:'Für diese Terminart ist kein Mindestbedarf hinterlegt.' }
		const refreshResources = (scheduleType) => {
			const container = modal.querySelector('#schedule-resource-options'); const hint = modal.querySelector('#schedule-resource-hint'); if (!container) return
			const checked = [...form.querySelectorAll('[name="resourceKeys"]:checked')].map((input) => input.value)
			const offered = offeredResources(scheduleType, state.scheduling?.resources || [], previousResourceKeys, currentBranch).map((resource) => resource.key)
			const next = editing ? checked.filter((key) => offered.includes(key)) : normalizeKeys([...(scheduleType?.defaultResourceKeys || []), ...checked.filter((key) => offered.includes(key))]).filter((key) => offered.includes(key))
			const dropped = checked.filter((key) => !offered.includes(key))
			container.innerHTML = resourcePickerHtml(scheduleType, next)
			if (hint) { hint.textContent = dropped.length ? `${resourceNames(dropped)} ${dropped.length === 1 ? 'passt' : 'passen'} nicht zu ${scheduleType ? `„${scheduleType.label || scheduleType.name}“` : '„Freie Eingabe“'}${currentBranch ? ` bzw. zur Niederlassung „${branchLabel(currentBranch)}“` : ''} und ${dropped.length === 1 ? 'wurde' : 'wurden'} abgewählt.` : ''; hint.hidden = !dropped.length }
		}
		// 0.90.5: Beginn, Ende und Dauer hängen zusammen; das verdeckte Feld „date“ trägt den Beginn wie bisher.
		const buffers = () => ({ before: Math.max(0, Number(form.elements.bufferBeforeMinutes?.value || 0)), after: Math.max(0, Number(form.elements.bufferAfterMinutes?.value || 0)) })
		const syncWhen = () => {
			if (!isEvent) return
			const start = form.elements.scheduleStart.value; const day = form.elements.scheduleDate.value
			const duration = durationFromEnd(start, form.elements.scheduleEnd.value)
			const label = modal.querySelector('#schedule-duration-label')
			form.elements.scheduleEnd.setCustomValidity(duration === null && start && form.elements.scheduleEnd.value ? 'Das Ende muss mindestens 15 Minuten nach dem Beginn liegen.' : '')
			if (duration !== null) form.elements.durationMinutes.value = String(duration)
			if (label) label.textContent = duration === null ? 'Ende prüfen' : (duration >= 60 && duration % 60 === 0 ? `${duration / 60} Std.` : `${duration} Min.`)
			form.elements.date.value = day && start ? `${day}T${start}` : ''
			const occupancy = modal.querySelector('#schedule-occupancy'); const { before, after } = buffers()
			if (occupancy) occupancy.textContent = duration !== null && (before || after) ? `Belegt ${fromMinutes(toMinutes(start) - before)}–${fromMinutes(toMinutes(start) + duration + after)} Uhr inkl. ${before} Min. Vor- und ${after} Min. Nachbereitung` : ''
		}
		const applyDuration = (minutes) => { if (!isEvent) return; form.elements.durationMinutes.value = String(Math.max(15, Number(minutes) || 60)); form.elements.scheduleEnd.value = endTime(form.elements.scheduleStart.value, form.elements.durationMinutes.value); syncWhen() }
		const setStart = (value, day = form.elements.scheduleDate?.value) => { if (!isEvent) return; const duration = Number(form.elements.durationMinutes.value || 60); form.elements.scheduleDate.value = day; form.elements.scheduleStart.value = value; form.elements.scheduleEnd.value = endTime(value, duration); syncWhen() }
		if (isEvent) {
			form.elements.scheduleStart.addEventListener('input', () => { const duration = Number(form.elements.durationMinutes.value || 60); if (!Number.isNaN(toMinutes(form.elements.scheduleStart.value))) { form.elements.scheduleEnd.value = endTime(form.elements.scheduleStart.value, duration) } syncWhen() })
			form.elements.scheduleEnd.addEventListener('input', syncWhen)
			form.elements.scheduleDate.addEventListener('input', syncWhen)
			syncWhen()
		}
		form.elements.schedulePresetKey?.addEventListener('change', () => {
			const selected = selectedType()
			showRequirements(selected)
			refreshResources(selected)
			if (!selected) { toggleExternalFields(); return }
			form.elements.scheduleKind.value = selected.scheduleKind || 'EXTERNAL_APPOINTMENT'
			form.elements.appointmentCategory.value = selected.appointmentCategory || ''
			form.elements.title.value = selected.title || selected.label || ''
			form.elements.description.value = selected.description || ''
			form.elements.priority.value = selected.priority || 'NORMAL'
			form.elements.bufferBeforeMinutes.value = selected.bufferBeforeMinutes || 0
			form.elements.bufferAfterMinutes.value = selected.bufferAfterMinutes || 0
			applyDuration(selected.durationMinutes || 60)
			if (!editing && form.elements.scheduleDate && Number(selected.dueOffsetDays || 0) !== 0) {
				const proposed = new Date(); proposed.setDate(proposed.getDate() + Number(selected.dueOffsetDays))
				setStart(defaultTime('schedule', state.scheduling), `${proposed.getFullYear()}-${String(proposed.getMonth()+1).padStart(2,'0')}-${String(proposed.getDate()).padStart(2,'0')}`)
			}
			toggleExternalFields()
		})
		showRequirements(selectedType())
		// 0.92.0: Wechsel des Falls (Niederlassung) baut die Ressourcenauswahl neu auf.
		if (isEvent && form.elements.caseId?.tagName === 'SELECT') form.elements.caseId.addEventListener('change', () => { currentBranch = caseBranchKey(form.elements.caseId.value); refreshResources(selectedType()) })
		// 0.89.10: Vorbelegte Terminvorlage (z. B. aus einem Ablaufschritt) setzt Einordnung und Terminart gleich mit.
		{ const preset = selectedType(); if (preset && !scheduleKindGiven && form.elements.scheduleKind) { form.elements.scheduleKind.value = preset.scheduleKind || 'EXTERNAL_APPOINTMENT'; if (form.elements.appointmentCategory && !form.elements.appointmentCategory.value) form.elements.appointmentCategory.value = preset.appointmentCategory || ''; toggleExternalFields() } }
		// 0.90.5: Ein neuer Termin mit vorbelegter Terminart übernimmt deren Dauer, Vor- und Nachbereitung.
		if (isEvent && !editing && selectedType() && !baseData.durationMinutes) { const preset = selectedType(); if (preset.bufferBeforeMinutes) form.elements.bufferBeforeMinutes.value = preset.bufferBeforeMinutes; if (preset.bufferAfterMinutes) form.elements.bufferAfterMinutes.value = preset.bufferAfterMinutes; applyDuration(preset.durationMinutes || 60) }
		const currentScheduleValues = () => ({ title: form.elements.title.value, date: form.elements.date?.value || '', status: form.elements.status.value, data: { location: form.elements.scheduleKind?.value === 'EXTERNAL_APPOINTMENT' ? (form.elements.location?.value || '') : '', durationMinutes: Math.max(15, Number(form.elements.durationMinutes?.value || 60)), bufferBeforeMinutes: buffers().before, bufferAfterMinutes: buffers().after, assigneeUids: [...form.querySelectorAll('[name="assigneeUids"]:checked')].map((input) => input.value), resourceKeys: [...form.querySelectorAll('[name="resourceKeys"]:checked')].map((input) => input.value), externalParticipants: form.elements.scheduleKind?.value === 'EXTERNAL_APPOINTMENT' ? String(form.elements.externalParticipants?.value || '').split(';').map((value) => value.trim()).filter(Boolean).join('; ') : '' } })
		const updateScheduleHints = () => {
			if (!isEvent) return
			const hint = modal.querySelector('#schedule-status-hint'); if (hint) hint.textContent = statusHint(form.elements.status.value)
			const override = form.elements.conflictOverride?.checked
			const required = editing && changeReasonRequired(item.status, form.elements.status.value, scheduleChanges({ title: item.title, date: item.date || '', status: item.status, data: item.data || {} }, currentScheduleValues()))
			const reason = modal.querySelector('#schedule-change-reason')
			if (reason) { reason.hidden = !(required || override); form.elements.changeReason.required = Boolean(required || override) }
		}
		// 0.90.5: Verfügbarkeit der gewählten Personen und Ressourcen am Tag des Termins (verzögert, ältere Antworten werden verworfen).
		let availabilityTimer = null; let availabilitySeq = 0
		const availabilityNames = (entry) => entry.uid !== undefined ? memberName(entry.uid) : (entry.name || entry.key)
		const renderAvailability = (result) => {
			modal.querySelectorAll('[data-availability-uid],[data-availability-resource]').forEach((slot) => { slot.innerHTML = '' })
			const timeline = modal.querySelector('#schedule-timeline'); const summary = modal.querySelector('#schedule-availability-summary')
			if (!result) { if (timeline) timeline.innerHTML = ''; if (summary) { summary.hidden = true; summary.textContent = '' } return }
			for (const entry of result.people || []) { const slot = modal.querySelector(`[data-availability-uid="${CSS.escape(entry.uid)}"]`); if (slot) slot.innerHTML = availabilityChip(entry, esc) }
			for (const entry of result.resources || []) { const slot = modal.querySelector(`[data-availability-resource="${CSS.escape(entry.key)}"]`); if (slot) slot.innerHTML = availabilityChip(entry, esc) }
			if (timeline) timeline.innerHTML = timelineHtml(result, { names: availabilityNames, esc, hours: result.workingHours || workingHours(state.scheduling), window: result.window, buffers: buffers() })
			const text = availabilitySummary(result, availabilityNames)
			if (summary) { summary.hidden = !text; summary.textContent = text?.text || ''; summary.dataset.state = text?.state || '' }
		}
		const loadAvailability = () => {
			if (!isEvent) return
			clearTimeout(availabilityTimer)
			availabilityTimer = setTimeout(async () => {
				const uids = [...form.querySelectorAll('[name="assigneeUids"]:checked')].map((input) => input.value)
				const keys = [...form.querySelectorAll('[name="resourceKeys"]:checked')].map((input) => input.value)
				const date = form.elements.date.value
				if (!date || (!uids.length && !keys.length)) { renderAvailability(null); return }
				const seq = ++availabilitySeq
				const params = new URLSearchParams({ date, durationMinutes: String(form.elements.durationMinutes.value || 60), bufferBeforeMinutes: String(buffers().before), bufferAfterMinutes: String(buffers().after), uids: uids.join(','), resources: keys.join(','), exclude: String(editing ? item.id : 0) })
				try {
					const result = await api(`${schedulingUrl()}/availability?${params}`, { feedback: false })
					if (seq === availabilitySeq) renderAvailability(result && Array.isArray(result.people) ? result : null)
				} catch (_) { if (seq === availabilitySeq) renderAvailability(null) }
			}, 250)
		}
		if (isEvent) {
			form.addEventListener('input', () => { updateScheduleHints(); loadAvailability() })
			form.addEventListener('change', () => { updateScheduleHints(); loadAvailability() })
			modal.querySelector('#schedule-timeline')?.addEventListener('click', (event) => {
				const track = event.target.closest?.('[data-timeline-track]'); const timeline = event.target.closest?.('.bp-timeline'); if (!track || !timeline || event.target.closest('.bp-timeline-block')) return
				const box = track.getBoundingClientRect(); if (!box.width) return
				setStart(startFromTimelineClick((event.clientX - box.left) / box.width, { from: Number(timeline.dataset.rangeFrom), to: Number(timeline.dataset.rangeTo) }, Number(form.elements.durationMinutes.value || 60), buffers()))
				updateScheduleHints(); loadAvailability()
			})
			updateScheduleHints(); loadAvailability()
		}
		const persist = async () => {
			const data = Object.fromEntries(new FormData(form))
			const externalParticipantNames = type === 'schedule' ? String(data.externalParticipants || '').split(';').map((value) => value.trim()).filter(Boolean) : []
			const matchedExternalContacts = externalParticipantNames.map((name) => externalContacts.find((contact) => String(contact.title).localeCompare(name, 'de', { sensitivity: 'base' }) === 0)).filter(Boolean)
			const externalContactIds = [...new Set(matchedExternalContacts.map((contact) => Number(contact.id)))]
			const linkedExternalContacts = matchedExternalContacts.map((contact) => ({ id: Number(contact.id), title: contact.title, email: contact.data?.email || '', phone: contact.data?.phone || '', categories: contact.data?.categories || '' }))
			const matchedNames = new Set(linkedExternalContacts.map((contact) => String(contact.title).toLocaleLowerCase('de')))
			const externalParticipantsAdditional = externalParticipantNames.filter((name) => !matchedNames.has(name.toLocaleLowerCase('de'))).join('; ')
			const externalParticipants = externalParticipantNames.join('; ')
			const payload = JSON.stringify({ ...baseData, description: data.description || '', priority: data.priority || 'NORMAL', ...(type === 'task' ? { assigneeUid: data.assigneeUid || assigneeUid } : {}), ...(type === 'schedule' ? { assigneeUids: [...form.querySelectorAll('[name="assigneeUids"]:checked')].map((input)=>input.value), resourceKeys:[...form.querySelectorAll('[name="resourceKeys"]:checked')].map((input)=>input.value), schedulePresetKey: data.schedulePresetKey || '', scheduleTypeKey:data.schedulePresetKey||'', durationMinutes: Math.max(15, Number(data.durationMinutes || 60)), bufferBeforeMinutes:Math.max(0,Number(data.bufferBeforeMinutes||0)),bufferAfterMinutes:Math.max(0,Number(data.bufferAfterMinutes||0)),changeReason:data.changeReason||'',conflictOverride:Boolean(data.conflictOverride), scheduleKind: data.scheduleKind || 'INTERNAL_ACTIVITY', appointmentCategory: data.scheduleKind === 'EXTERNAL_APPOINTMENT' ? (data.schedulePresetKey ? (selectedType()?.appointmentCategory || data.appointmentCategory || '') : (data.appointmentCategory || '')) : '', location: data.scheduleKind === 'EXTERNAL_APPOINTMENT' ? (data.location || '') : '', externalContactIds: data.scheduleKind === 'EXTERNAL_APPOINTMENT' ? externalContactIds : [], externalContacts: data.scheduleKind === 'EXTERNAL_APPOINTMENT' ? linkedExternalContacts : [], externalParticipantsAdditional: data.scheduleKind === 'EXTERNAL_APPOINTMENT' ? externalParticipantsAdditional : '', externalParticipants: data.scheduleKind === 'EXTERNAL_APPOINTMENT' ? externalParticipants : '' } : {}), ...(baseData.kind === 'EMAIL_DRAFT' ? { recipient: data.recipient || '', recipientCategory: data.recipientCategory || baseData.recipientCategory || '' } : {}), ...(type === 'activity' || baseData.kind === 'CONTACT_ACTIVITY' ? { kind: 'CONTACT_ACTIVITY', contact: data.contact || '', channel: data.channel || 'Telefon' } : {}) })
			const url = editing ? recordItemUrl(item.id) : recordUrl(type)
			const recordDate = type === 'task' ? `${data.taskDate}T${data.taskTime || defaultTime('task', state.scheduling)}` : (data.date || '')
			return api(url, { method: editing ? 'PUT' : 'POST', body: new URLSearchParams({ title: data.title, date: recordDate, status: data.status, caseId: Number(data.caseId || 0), data: payload }) })
		}
		modal.querySelector('#cancel-record').onclick = () => { clearTimeout(availabilityTimer); modal.remove() }
		form.onsubmit = async (event) => {
			event.preventDefault()
			const status = modal.querySelector('#record-form-state')
			const submit = modal.querySelector('#save-record')
			submit.disabled = true
			status.textContent = 'Speichert …'; status.dataset.state = 'saving'
			try {
				if (isEvent) syncWhen()
				const saved = await persist()
				clearTimeout(availabilityTimer)
				modal.remove()
				await loadRecordType(type, state.view === 'case-detail' ? state.currentCase.id : 0)
				if (type === 'schedule') await loadRecordType('task', state.view === 'case-detail' ? state.currentCase.id : 0)
				if (['task', 'schedule'].includes(type) && state.view === 'case-detail' && Number(saved.caseId) === Number(state.currentCase?.id)) {
					// 0.90.5: Hat ein bestätigter Trauerfeier-Termin das Fallmerkmal gesetzt, den Fall neu laden (sonst meldet das nächste Speichern einen Konflikt).
					if (saved.process?.ceremonyAdopted && urls?.cases) { try { state.currentCase = await api(`${urls.cases}/${state.currentCase.id}`, { feedback: false }) } catch (_) { /* Anzeige bleibt bis zum nächsten Laden */ } }
					await ctx.loadProcess?.(state.currentCase.id)
					if (type === 'schedule') await loadRecordType('task', state.currentCase.id)
				}
				render()
				if (saved.process?.ceremonyAdopted) notifySuccess('Fallmerkmal „Trauerfeier“ auf „ja“ gesetzt – der Fall hat jetzt einen bestätigten Trauerfeier-Termin.')
				if (saved.process?.created) notifySuccess(`Ablauf: ${saved.process.created} neue Aufgabe(n) angelegt${saved.process.completed ? `, ${saved.process.completed} automatisch erledigt` : ''}.`)
				// Befund #23 (2026-09-20): reactivatedActions wurde von der Schnittstelle bereits geliefert, aber bisher
				// nirgends angezeigt — die Reaktivierung geschah bisher unsichtbar für den Anwender im Tagesgeschäft.
				if (type === 'schedule' && Number(saved.reactivatedActions?.tasks || 0) > 0) notifyWarning(`${saved.reactivatedActions.tasks} verknüpfte Aufgabe(n) wurden wegen der Terminänderung zur erneuten Prüfung markiert.`)
			} catch (error) {
				status.textContent = error.message; status.dataset.state = 'error'; submit.disabled = false
				if(type==='schedule'){
					// 0.90.5: Erst nach einer Konfliktmeldung lässt sich der Konflikt übersteuern.
					if (/^Terminkonflikt/.test(String(error.message))) { const override = modal.querySelector('#schedule-conflict-override'); if (override) override.hidden = false }
					if (/Änderungsgrund/.test(String(error.message))) { const reason = modal.querySelector('#schedule-change-reason'); if (reason) { reason.hidden = false; form.elements.changeReason.required = true; form.elements.changeReason.focus() } }
					const suggestions=[...String(error.message).matchAll(/(\d{2})\.(\d{2})\.(\d{4}) (\d{2}):(\d{2}) Uhr/g)].map((match)=>({label:match[0],day:`${match[3]}-${match[2]}-${match[1]}`,time:`${match[4]}:${match[5]}`}));if(suggestions.length){modal.querySelector('.bp-alternative-slots')?.remove();const choices=document.createElement('div');choices.className='bp-alternative-slots';choices.setAttribute('aria-label','Alternative Termine');suggestions.forEach((suggestion)=>{const button=document.createElement('button');button.type='button';button.className='bp-secondary';button.textContent=suggestion.label;button.onclick=()=>{setStart(suggestion.time, suggestion.day);updateScheduleHints();loadAvailability();status.textContent='Alternativtermin übernommen. Bitte erneut speichern.';status.dataset.state='warning';choices.remove();form.elements.scheduleStart.focus()};choices.append(button)});status.after(choices)}}
			}
		}
		modal.querySelectorAll('[data-follow-up]').forEach((button) => button.addEventListener('click', async () => {
			try {
				const saved = await persist()
				const nextType = button.dataset.followUp
				const parentUid = saved.data?.nextcloud?.uid || ''
				// Befund #23 (2026-09-20): Eine "Freie Folgeaktion" trug bisher keinen Terminbezug, selbst wenn die
				// Ausgangsaufgabe selbst aus einem Termin entstanden war. Dadurch wurde sie von
				// invalidateScheduleDependents() nie erfasst und – anders als eine vorkonfigurierte Folgeaktion –
				// bei einer späteren Terminänderung nicht zur erneuten Prüfung reaktiviert. Jetzt wird ein bereits
				// am Elternteil vorhandener Terminbezug an die neue Folgeaktion weitergereicht.
				const sourceScheduleId = Number(saved.data?.sourceScheduleId || saved.data?.link?.sourceScheduleId || 0)
				const sourceScheduleUid = saved.data?.sourceScheduleUid || saved.data?.link?.sourceScheduleUid || ''
				modal.remove()
				showRecordForm(nextType, saved.caseId || caseId, null, { data: {
					description: `Folgeaktion zu: ${saved.title}`,
					priority: saved.data?.priority || 'NORMAL',
					assigneeUid: saved.data?.assigneeUid || state.team.currentUid,
					link: { parentRecordId: saved.id, parentUid, source: 'FOLLOW_UP', ...(sourceScheduleId ? { sourceScheduleId, sourceScheduleUid } : {}) },
					...(sourceScheduleId ? { sourceScheduleId } : {}),
				} })
			} catch (error) { notifyError(error.message) }
		}))
		modal.querySelectorAll('.execute-process-action').forEach((button) => button.addEventListener('click', async (event) => {
			event.preventDefault()
			event.stopPropagation()
			const processAction = processActions?.actions?.find((entry) => entry.key === button.dataset.actionKey)
			if (!processAction || !ctx.runProcessStepAction) return
			modal.remove()
			await ctx.runProcessStepAction({ recordId: item.id, caseId: item.caseId }, processAction, item)
		}))
		modal.querySelectorAll('.show-action-output').forEach((button) => button.addEventListener('click', () => {
			const action = processActions?.actions?.find((entry) => entry.key === button.dataset.actionKey)
			if (action?.lastRun?.result) showActionDocumentResult({ result: action.lastRun.result })
		}))
	}

	function showEditRecord(type, id) {
		const item = (state.records[type] || []).find((record) => record.id === id)
		if (item) showRecordForm(type, item.caseId || 0, item)
	}

	async function saveRecord(item, type) {
		const saved = await api(recordItemUrl(item.id), { method: 'PUT', body: new URLSearchParams({ title: item.title, date: item.date || '', status: item.status, caseId: item.caseId || 0, data: JSON.stringify(item.data || {}) }) })
		Object.assign(item, saved)
		if (type === 'task' && state.view === 'case-detail' && Number(saved.caseId) === Number(state.currentCase?.id) && ctx.loadProcess) {
			await ctx.loadProcess(state.currentCase.id)
			if (saved.process?.created) await loadRecordType('task', state.currentCase.id)
		}
	}

	function showContactForm(category, target) {
		root.insertAdjacentHTML('beforeend', `<div class="bp-modal"><form id="contact-form"><h2>Kontakt anlegen · ${esc(category)}</h2><label>Name / Organisation<input name="title" required></label><label>Straße<input name="street"></label><label>PLZ<input name="postalCode"></label><label>Ort<input name="city"></label><label>Land<input name="country" value="Deutschland"></label><label>Telefon<input name="phone"></label><label>E-Mail<input type="email" name="email"></label><div><button type="button" class="bp-secondary" id="cancel-contact">Abbrechen</button><button class="bp-primary">Kontakt speichern</button></div></form></div>`)
		document.getElementById('cancel-contact').onclick = () => root.querySelector('.bp-modal').remove()
		document.getElementById('contact-form').onsubmit = async (event) => {
			event.preventDefault(); const data = Object.fromEntries(new FormData(event.target)); data.categories = category
			// 0.86.0: vor dem Anlegen auf Dubletten prüfen (Name, Telefon, E-Mail, Anschrift); nichts wird automatisch zusammengeführt.
			let chosenTitle = data.title; let reuse = false
			const check = await api(`${ctx.apiBase}/contacts/duplicates`, { method: 'POST', feedback: false, body: new URLSearchParams({ title: data.title, data: JSON.stringify(data) }) })
			if ((check.duplicates || []).length) {
				const choice = await ctx.chooseDuplicate(check.duplicates, data.title)
				if (!choice) return
				if (choice.useId) { chosenTitle = choice.duplicate.title; reuse = true }
			}
			if (!reuse) await api(recordUrl('contact'), { method: 'POST', body: new URLSearchParams({ title: data.title, status: 'AKTIV', caseId: state.currentCase?.id || 0, data: JSON.stringify(data) }) })
			await loadRecordType('contact'); if (state.currentCase) { const masterData = { ...state.currentCase.masterData, [target]: chosenTitle }; state.currentCase = await api(`${urls.cases}/${state.currentCase.id}/master-data`, { method: 'PUT', body: new URLSearchParams({ masterData: JSON.stringify(masterData), expectedUpdatedAt: state.currentCase.updatedAt || '' }) }) }
			root.querySelector('.bp-modal').remove(); render()
		}
	}


	return { recordResourceChips, filteredTaskRecords, filterOptions, taskToolbar, selectableRecordValues, recordToolbar, filteredRecordRecords, formatRecordDate, recordBadges, recordMeta, recordRows, recordsView, documentOutputRow, technicalFilesToggle, normalizeDateTime, caseOptions, showActionDocumentResult, showRecordForm, showEditRecord, saveRecord, showContactForm }
}
