export function createRecordsModule(ctx) {
	const { root, urls, state, recordLabels } = ctx
	const api = (...args) => ctx.api(...args)
	const assigneeOptions = (...args) => ctx.assigneeOptions(...args)
	const contactsByCategory = (...args) => ctx.contactsByCategory(...args)
	const currentUser = (...args) => ctx.currentUser(...args)
	const esc = (...args) => ctx.esc(...args)
	const executeWorkflowUrl = (...args) => ctx.executeWorkflowUrl(...args)
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
	const workflowActionsUrl = (...args) => ctx.workflowActionsUrl(...args)
	const schedulingUrl = (...args) => ctx.schedulingUrl(...args)
	const confirmAction = (...args) => ctx.confirmAction(...args)
	const promptAction = (...args) => ctx.promptAction(...args)
	const notifyError = (...args) => ctx.notifyError(...args)
	const notifySuccess = (...args) => ctx.notifySuccess(...args)
	const showFilePreview = (...args) => ctx.showFilePreview?.(...args)

	function filteredTaskRecords(records) {
		const filters = state.taskFilters
		const visible = records.filter((item) => {
			if (!state.showCompletedTasks && filters.status !== 'ERLEDIGT' && item.status === 'ERLEDIGT') return false
			if (filters.caseId !== 'ALL' && String(item.caseId || 0) !== filters.caseId) return false
			if (filters.branch !== 'ALL' && recordBranch(item) !== filters.branch) return false
			if (filters.assignee !== 'ALL' && recordAssigneeUid(item) !== filters.assignee) return false
			return filters.status === 'ALL' || item.status === filters.status
		})
		const selectors = {
			CASE_NUMBER: (item) => recordCaseNumber(item),
			BRANCH: (item) => recordBranch(item),
			ASSIGNEE: (item) => recordAssigneeName(item),
			STATUS: (item) => ({ OFFEN: '1', IN_BEARBEITUNG: '2', ERLEDIGT: '3' }[item.status] || '9'),
			DUE_ASC: (item) => String(item.date || '9999-12-31'),
			DUE_DESC: (item) => String(item.date || ''),
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
			<label><span>Zuständigkeit</span><select data-task-filter="assignee">${filterOptions(members, state.taskFilters.assignee, 'Alle Zuständigen')}</select></label>
			<label><span>Status</span><select data-task-filter="status">${filterOptions([['OFFEN', 'Offen'], ['IN_BEARBEITUNG', 'In Bearbeitung'], ['ERLEDIGT', 'Erledigt']], state.taskFilters.status, 'Alle Status')}</select></label>
			<label><span>Sortierung</span><select id="task-sort"><option value="DUE_ASC" ${state.taskSort === 'DUE_ASC' ? 'selected' : ''}>Fälligkeit aufsteigend</option><option value="DUE_DESC" ${state.taskSort === 'DUE_DESC' ? 'selected' : ''}>Fälligkeit absteigend</option><option value="CASE_NUMBER" ${state.taskSort === 'CASE_NUMBER' ? 'selected' : ''}>Fallnummer</option><option value="BRANCH" ${state.taskSort === 'BRANCH' ? 'selected' : ''}>Niederlassung</option><option value="ASSIGNEE" ${state.taskSort === 'ASSIGNEE' ? 'selected' : ''}>Zuständigkeit</option><option value="STATUS" ${state.taskSort === 'STATUS' ? 'selected' : ''}>Status</option></select></label>
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

	function recordMeta(item, type) {
		const syncState = item.data?.nextcloud?.remoteDeletedAt ? ' · in Nextcloud gelöscht, lokal erhalten' : (item.data?.nextcloud?.stored ? ' · Nextcloud synchronisiert' : (item.data?.nextcloud?.syncError ? ' · Synchronisationsfehler' : ''))
		const assignee = recordAssigneeName(item)
		const scheduleAssignees = type === 'schedule' ? (item.data?.assignees || []).map((entry) => entry.displayName || entry.uid).filter(Boolean).join(', ') : ''
		const branch = recordBranch(item)
		const scheduleKind = type === 'schedule' ? (item.data?.scheduleKind === 'EXTERNAL_APPOINTMENT' ? 'Externer Fixtermin' : 'Interne Tätigkeit') : ''
		const actor = item.updatedBy || item.createdBy || ''
		return `${esc(formatRecordDate(item.date))}${scheduleKind ? ` · ${esc(scheduleKind)}` : ''}${branch ? ` · Niederlassung: ${esc(branch)}` : ''}${type === 'task' && assignee ? ` · Zuständig: ${esc(assignee)}` : ''}${scheduleAssignees ? ` · Zuständig: ${esc(scheduleAssignees)}` : ''}${actor ? ` · Bearbeitet von: ${esc(actor)}` : ''}${syncState}`
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
		const statusLabels = { OFFEN: ['○', 'Offen'], IN_BEARBEITUNG: ['◐', 'In Bearbeitung'], ERLEDIGT: ['✓', 'Erledigt'], BESTAETIGT: ['✓', 'Bestätigt'], ABGESAGT: ['×', 'Abgesagt'], ENTWURF: ['○', 'Entwurf'] }
		const [statusIcon, statusLabel] = statusLabels[status] || ['•', status.replaceAll('_', ' ')]
		const statusClass = status.toLowerCase().replaceAll('_', '-')
		const priority = String(item.data?.priority || 'NORMAL').toUpperCase()
		const priorityLabels = { HOCH: ['↑', 'Hoch'], NORMAL: ['–', 'Normal'], NIEDRIG: ['↓', 'Niedrig'] }
		const [priorityIcon, priorityLabel] = priorityLabels[priority] || ['•', priority]
		return `<span class="bp-status bp-status-${esc(statusClass)}" aria-label="Status: ${esc(statusLabel)}"><span aria-hidden="true">${esc(statusIcon)}</span> Status: ${esc(statusLabel)}</span>${type === 'task' ? `<span class="bp-priority bp-priority-${esc(priority.toLowerCase())}" aria-label="Priorität: ${esc(priorityLabel)}"><span aria-hidden="true">${esc(priorityIcon)}</span> Priorität: ${esc(priorityLabel)}</span>` : ''}`
	}

	function recordRows(type, records) {
		const visible = filteredRecordRecords(type, records)
		if (!visible.length) return '<p class="bp-empty">Keine passenden Einträge vorhanden.</p>'
		if (type === 'document') return `<div class="bp-record-list">${visible.map(documentOutputRow).join('')}</div>`
		if (type === 'activity') return `<div class="bp-history-list">${visible.map((item) => `<article class="bp-history-entry"><span class="bp-history-dot" aria-hidden="true"></span><div><b>${esc(item.title)}</b><small>${esc(item.date || '')} · ${esc(item.userUid || 'system')} · ${esc(item.objectType || '')}</small></div></article>`).join('')}</div>`
		return `<div class="bp-record-list">${visible.map((item) => {
			const caseNumber = recordCaseNumber(item)
			return `<article class="bp-record-row" data-record-row="${item.id}"><label class="bp-record-check">${type === 'task' ? `<input type="checkbox" data-task-complete="${item.id}" ${item.status === 'ERLEDIGT' ? 'checked' : ''}>` : '<span>◷</span>'}</label><button class="bp-record-content" data-record-id="${item.id}" data-record-type="${type}"><b>${caseNumber ? `<span class="bp-case-number">Fall ${esc(caseNumber)}</span>` : ''}${esc(item.title)}</b><small>${recordMeta(item, type)}</small></button><div class="bp-record-badges">${recordBadges(item, type)}</div><button class="bp-secondary edit-record" data-record-id="${item.id}" data-record-type="${type}">Bearbeiten</button></article>`
		}).join('')}</div>`
	}

	function recordsView(type, caseId = 0) {
		let records = (state.records[type] || []).filter((item) => !caseId || item.caseId === caseId)
		if (type === 'document' && !caseId) {
			const knownFileIds = new Set(records.flatMap((item) => [Number(item.data?.fileId || 0), Number(item.data?.pdf?.fileId || 0), Number(item.data?.eInvoice?.fileId || 0)]).filter(Boolean))
			const directFiles = (state.allCaseFiles || []).filter((file) => !knownFileIds.has(Number(file.fileId))).map((file) => ({ id: -Number(file.fileId), caseId: Number(file.caseId), caseNumber: file.caseNumber, title: file.title, date: file.modifiedAt, status: file.status || 'ABLAGE', data: { fileId: file.fileId, documentType: file.documentType, path: file.path, source: file.source, extension: file.extension, mimeType: file.mimeType, readOnly: file.readOnly } }))
			records = [...records, ...directFiles]
		}
		return `<div class="bp-page-head"><div><p class="bp-eyebrow">${recordLabels[type]}</p><h2>${recordLabels[type]}</h2>${type === 'activity' ? '<p class="bp-muted">Automatisch geführtes, unveränderbares Protokoll der Bearbeitungsschritte.</p>' : ''}</div><div class="bp-head-actions">${type === 'task' ? `<label class="bp-task-filter"><input type="checkbox" id="show-completed-tasks" ${state.showCompletedTasks ? 'checked' : ''}> Erledigte Aufgaben anzeigen</label>` : ''}${type === 'schedule' ? `<label class="bp-task-filter"><input type="checkbox" id="show-completed-schedules" ${state.showCompletedSchedules ? 'checked' : ''}> Erledigte Termine anzeigen</label>` : ''}${['document', 'activity'].includes(type) ? '' : `<button class="bp-primary" id="new-record" data-record-type="${type}" data-case-id="${caseId}">Neu anlegen</button>`}${['task', 'schedule'].includes(type) ? `<button class="bp-secondary" id="sync-groupware" data-record-type="${type}">Aus Nextcloud aktualisieren</button>` : ''}</div></div>${type === 'task' ? taskToolbar(caseId) : recordToolbar(type, caseId)}<section class="bp-panel bp-record-panel">${recordRows(type, records)}</section>`
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
		const editable = !immutable && !item.data?.readOnly && ['DOCX','ODT','XLSX','ODS','PPTX','TXT'].includes(format)
		const displayDate = item.date ? new Date(item.date).toLocaleString('de-DE') : 'ohne Datumsangabe'
		const caseNumber = recordCaseNumber(item)
		return `<article class="bp-document-output" data-record-row="${item.id}">${previewUrl ? `<button class="bp-document-thumb preview-nextcloud-file" data-file-id="${previewId}" aria-label="Vorschau ${esc(item.title)}"><span class="bp-document-thumb-fallback" aria-hidden="true"><b>${esc(format)}</b><small>Vorschau öffnen</small></span><img src="${esc(previewUrl)}" alt="" loading="lazy"></button>` : `<div class="bp-document-thumb bp-document-thumb-empty"><b>${esc(format)}</b><small>Keine Vorschau</small></div>`}<div class="bp-record-content"><b>${caseNumber ? `<span class="bp-case-number">Fall ${esc(caseNumber)}</span>` : ''}${esc(item.title)}</b><small>${esc(item.data?.documentType || 'Allgemein')} · ${esc(item.status)} · ${esc(displayDate)}</small><small class="bp-document-location">Ablage: ${esc(item.data?.path || 'Fallakte')}</small>${item.updatedBy || item.createdBy ? `<small>Bearbeitet von: ${esc(item.updatedBy || item.createdBy)}</small>` : ''}${immutable ? '<small>Finale Ausgabe – schreibgeschützt.</small>' : (editable ? '<small>Arbeitsstand – kann in Nextcloud bearbeitet werden.</small>' : '<small>Technische oder schreibgeschützte Datei.</small>')}${item.data?.pdfWarning ? `<small class="bp-error">${esc(item.data.pdfWarning)}</small>` : ''}</div><div class="bp-row-actions">${editable && docxId ? `<button class="bp-secondary open-nextcloud-file" data-file-id="${docxId}">In Nextcloud bearbeiten</button>` : ''}${pdfId ? `<button class="bp-primary open-nextcloud-file" data-file-id="${pdfId}">PDF öffnen</button>` : ''}${!pdfId && docxId ? `<button class="bp-secondary open-nextcloud-file" data-file-id="${docxId}">${editable?'Dokument öffnen':'Datei öffnen'}</button>` : ''}</div></article>`
	}

	function normalizeDateTime(value) {
		if (!value) return ''
		return value.length >= 16 ? value.slice(0, 16) : value
	}

	function caseOptions(selectedCaseId = 0, type = 'task') {
		const generalLabel = type === 'schedule' ? 'allgemeiner Termin' : 'allgemeine Aufgabe'
		return `<option value="0">Keine Fallzuordnung (${generalLabel})</option>${state.cases.slice().sort((a, b) => String(a.caseNumber).localeCompare(String(b.caseNumber), 'de', { numeric: true })).map((entry) => `<option value="${entry.id}" ${Number(entry.id) === Number(selectedCaseId) ? 'selected' : ''}>${esc(entry.caseNumber)} · ${esc(entry.lastName)}, ${esc(entry.firstName)}</option>`).join('')}`
	}

	function showWorkflowDocumentResult(workflowResult) {
		const documents = workflowResult?.result?.documents || (workflowResult?.result?.file ? [workflowResult.result] : [])
		if (!documents.length) return
		const cards = documents.map((entry, index) => {
			const file = entry.file || entry
			const docxId = Number(file.fileId || 0); const pdfId = Number(file.pdf?.fileId || 0); const previewId = pdfId || docxId
			return `<article class="bp-document-output"><div class="bp-record-content"><b>${esc(file.title || `Dokument ${index + 1}`)}</b><small>Ablage: ${esc(file.path || file.pdf?.path || '05 Trauerdruck')}</small>${file.pdfWarning ? `<small class="bp-error">${esc(file.pdfWarning)}</small>` : ''}</div><div class="bp-row-actions">${previewId ? `<button type="button" class="bp-secondary workflow-preview-document" data-file-id="${previewId}" data-title="${esc(file.title || 'Dokument')}">Vorschau</button>` : ''}${docxId ? `<button type="button" class="bp-secondary workflow-open-document" data-file-id="${docxId}">DOCX öffnen</button>` : ''}${pdfId ? `<button type="button" class="bp-primary workflow-print-document" data-file-id="${pdfId}">PDF öffnen / drucken</button>` : ''}</div></article>`
		}).join('')
		root.insertAdjacentHTML('beforeend', `<div class="bp-modal"><section class="bp-wide-modal"><div class="bp-panel-head"><div><p class="bp-eyebrow">Workflow abgeschlossen</p><h2>Erzeugte Dokumente</h2><p class="bp-muted">Die aktuellen Ausgaben wurden im Fallordner „05 Trauerdruck“ abgelegt.</p></div><button type="button" class="bp-secondary" data-close-workflow-result>Schließen</button></div><div class="bp-record-list">${cards}</div><p class="bp-muted">„PDF öffnen / drucken“ öffnet die fertige PDF-Ausgabe; anschließend kann der Browser-Druckdialog verwendet werden.</p></section></div>`)
		const resultModal = [...root.querySelectorAll('.bp-modal')].pop()
		resultModal.querySelector('[data-close-workflow-result]').onclick = () => resultModal.remove()
		resultModal.querySelectorAll('.workflow-preview-document').forEach((button) => button.onclick = () => showFilePreview(button.dataset.fileId, button.dataset.title))
		resultModal.querySelectorAll('.workflow-open-document,.workflow-print-document').forEach((button) => button.onclick = () => window.open(OC.generateUrl(`/f/${button.dataset.fileId}`), '_blank', 'noopener'))
	}

	async function showRecordForm(type, caseId = 0, item = null, preset = {}) {
		const isEvent = type === 'schedule'; const editing = Boolean(item)
		let availableWorkflows = []
		let scheduleHistory = []
		if (type === 'task' && editing) {
			try { availableWorkflows = await api(workflowActionsUrl(item.id)) } catch (_) { availableWorkflows = [] }
		}
		if (type === 'schedule' && editing) {
			try { scheduleHistory = await api(`${schedulingUrl()}/records/${item.id}/history`, { feedback: false }) } catch (_) { scheduleHistory = [] }
		}
		const now = new Date(); const today = `${now.getFullYear()}-${String(now.getMonth()+1).padStart(2,'0')}-${String(now.getDate()).padStart(2,'0')}`
		const defaultDate = ['task','schedule'].includes(type) ? `${today}T00:00` : ''
		const selectedCaseId = Number(item?.caseId || caseId || 0)
		const selectedCase = state.cases.find((entry) => Number(entry.id) === selectedCaseId)
		const linkedCase = item?.caseNumber || selectedCase?.caseNumber || (caseId && state.currentCase?.id === caseId ? state.currentCase.caseNumber : '')
		const baseData = { ...(item?.data || {}), ...(preset.data || {}) }
		if (type === 'schedule' && !baseData.scheduleKind) baseData.scheduleKind = 'INTERNAL_ACTIVITY'
		const assigneeUid = baseData.assigneeUid || item?.assigneeUid || state.team.currentUid || currentUser()
		const scheduleAssignees = new Set((baseData.assigneeUids || [state.team.currentUid || currentUser()]).map(String))
		const assigneeField = type === 'task' ? `<label>Zuständig<select name="assigneeUid" required>${assigneeOptions(assigneeUid)}</select></label>` : (type === 'schedule' ? `<fieldset class="bp-member-picker"><legend>Zuständige Personen und persönliche Kalender</legend>${(state.team.members||[]).map((member)=>`<label class="bp-inline-check"><input type="checkbox" name="assigneeUids" value="${esc(member.uid)}" ${scheduleAssignees.has(member.uid)?'checked':''}> <span>${esc(member.displayName||member.uid)} <small>${esc(member.uid)}</small></span></label>`).join('')}</fieldset>` : '')
		const configuredTypes=(state.scheduling?.types||[]).map((entry)=>({...entry,label:entry.name,title:entry.name,appointmentCategory:entry.name,source:'CONFIGURATION'}));const availableScheduleTypes=[...configuredTypes,...(state.schedulePresets||[]).filter((preset)=>!configuredTypes.some((entry)=>entry.key===preset.key))]
		const schedulePresetOptions = availableScheduleTypes.map((entry) => `<option value="${esc(entry.key)}" ${entry.key === (baseData.scheduleTypeKey||baseData.schedulePresetKey) ? 'selected' : ''}>${esc(entry.label||entry.name)}${entry.source === 'WORKFLOW' ? ` · Workflow ${esc(entry.workflowName || '')}` : ''}</option>`).join('')
		const externalContacts = (state.records.contact || []).slice().sort((a, b) => String(a.title).localeCompare(String(b.title), 'de'))
		const externalContactSuggestions = externalContacts.map((contact) => `<option value="${esc(contact.title)}">${esc(contact.data?.categories || 'Kontakt')}</option>`).join('')
		const externalParticipantsValue = baseData.externalParticipants || [
			...(baseData.externalContacts || []).map((entry) => entry.title),
			baseData.externalParticipantsAdditional || '',
		].filter(Boolean).join('; ')
		const scheduleCategories = [...new Set(['Aufbahrung','1. Trauerfeier','2. Trauerfeier','Beisetzung','Überführung','Abschiednahme','Behördentermin','Beratungsgespräch','Sonstiger externer Termin', ...availableScheduleTypes.map((entry) => entry.appointmentCategory || entry.name).filter(Boolean)])]
		const initialScheduleType=availableScheduleTypes.find((entry)=>entry.key===(baseData.scheduleTypeKey||baseData.schedulePresetKey));const selectedResources=new Set((baseData.resourceKeys||(!editing?initialScheduleType?.defaultResourceKeys:[])||[]).map(String));const resourceFields=(state.scheduling?.resources||[]).map((resource)=>`<label class="bp-inline-check"><input type="checkbox" name="resourceKeys" value="${esc(resource.key)}" ${selectedResources.has(resource.key)?'checked':''}><span>${esc(resource.name)} <small>${esc(resource.resourceType)}</small></span></label>`).join('')
		const scheduleFields = type === 'schedule' ? `<fieldset class="bp-field-section"><legend>Terminart und Disposition</legend><label><span>Terminart / Vorlage</span><select name="schedulePresetKey" id="schedule-preset"><option value="">Freie Eingabe</option>${schedulePresetOptions}</select><small>Übernimmt Dauer, Puffer und vorkonfigurierte Ressourcen; alle Angaben bleiben kontrolliert änderbar.</small></label><p class="bp-record-case-context" id="schedule-requirement-summary"></p><label><span>Fachliche Einordnung</span><select name="scheduleKind" id="schedule-kind"><option value="INTERNAL_ACTIVITY" ${baseData.scheduleKind !== 'EXTERNAL_APPOINTMENT' ? 'selected' : ''}>Interne Tätigkeit / prozessbedingter Termin</option><option value="EXTERNAL_APPOINTMENT" ${baseData.scheduleKind === 'EXTERNAL_APPOINTMENT' ? 'selected' : ''}>Externer Fixtermin</option></select></label><div class="bp-form-grid"><label><span>Dauer (Minuten)</span><input name="durationMinutes" type="number" min="15" step="15" value="${esc(baseData.durationMinutes || 60)}" required></label><label><span>Vorbereitungszeit</span><input name="bufferBeforeMinutes" type="number" min="0" step="5" value="${esc(baseData.bufferBeforeMinutes || 0)}"></label><label><span>Nachbereitungs-/Fahrzeit</span><input name="bufferAfterMinutes" type="number" min="0" step="5" value="${esc(baseData.bufferAfterMinutes || 0)}"></label></div>${resourceFields?`<fieldset class="bp-member-picker"><legend>Fahrzeuge, Räume, Kapellen und Ausstattung</legend>${resourceFields}</fieldset>`:'<p class="bp-muted">Noch keine zusätzlichen Ressourcen konfiguriert.</p>'}<div id="external-appointment-fields"><label><span>Externe Terminart</span><select name="appointmentCategory"><option value="">Bitte auswählen</option>${scheduleCategories.map((value)=>`<option ${value === baseData.appointmentCategory ? 'selected' : ''}>${value}</option>`).join('')}</select></label><label><span>Ort</span><input name="location" value="${esc(baseData.location || '')}" placeholder="Friedhof, Kapelle oder Anschrift"></label><label><span>Externe Personen / Organisationen</span><input name="externalParticipants" list="external-contact-suggestions" value="${esc(externalParticipantsValue)}" placeholder="Namen oder Organisation eingeben"><datalist id="external-contact-suggestions">${externalContactSuggestions}</datalist><small>Passende Namen aus Nextcloud Kontakte werden vorgeschlagen. Mehrere Beteiligte mit Semikolon trennen.</small></label></div>${editing?'<label><span>Änderungsgrund</span><textarea name="changeReason" rows="2" placeholder="Erforderlich bei Änderungen an Zeitpunkt, Status, Ort, Beteiligten oder Ressourcen"></textarea><small>Wesentliche Terminänderungen werden mit Alt-/Neu-Werten protokolliert.</small></label>':''}<label class="bp-inline-check"><input type="checkbox" name="conflictOverride" value="1"> Erkannten Konflikt ausnahmsweise übersteuern (Begründung erforderlich)</label><p class="bp-record-case-context">Die Konfliktprüfung berücksichtigt Mitarbeitende, Ressourcen, Dauer und Puffer. Erst „Bestätigt“ startet konfigurierte Folgeaufgaben.</p></fieldset>` : ''
		const predefinedTasks = [...new Set((state.checklists||[]).flatMap((template)=>(template.items||[]).map((entry)=>entry.title)).filter(Boolean))].sort((a,b)=>a.localeCompare(b,'de'))
		const taskTitleList = type==='task' ? `<datalist id="predefined-task-titles">${predefinedTasks.map((title)=>`<option value="${esc(title)}"></option>`).join('')}</datalist>` : ''
		const detailFields = baseData.kind === 'EMAIL_DRAFT'
			? `<label>Empfänger<input name="recipient" type="email" value="${esc(baseData.recipient || '')}"></label><label>Empfängergruppe<input name="recipientCategory" value="${esc(baseData.recipientCategory || '')}" readonly></label><p class="bp-record-case-context">Entwurfsstatus: Kein automatischer Versand.</p>`
			: (type === 'activity' || baseData.kind === 'CONTACT_ACTIVITY' ? `<label>Kontakt / Organisation<input name="contact" value="${esc(baseData.contact || '')}"></label><label>Kontaktweg<select name="channel">${['Telefon', 'E-Mail', 'Persönlich', 'Post', 'Sonstiges'].map((value) => `<option ${value === baseData.channel ? 'selected' : ''}>${value}</option>`).join('')}</select></label>` : '')
		const fixedCase = state.view === 'case-detail' && selectedCaseId > 0
		const generalRecordLabel = type === 'schedule' ? 'ein allgemeiner Termin' : 'eine allgemeine Aufgabe'
		const caseField = fixedCase
			? `<input type="hidden" name="caseId" value="${selectedCaseId}">`
			: `<label>Fallnummer (optional)<select name="caseId">${caseOptions(selectedCaseId, type)}</select><small>Ohne Fallzuordnung wird ${generalRecordLabel} angelegt.</small></label>`
		const configuredWorkflowActions = type === 'task' && editing && availableWorkflows.length ? `<section class="bp-follow-up bp-configured-workflows"><h3>Vorgegebene Folgeaktionen</h3><p>Diese Aktionen wurden im Customizing für die aktuelle Aufgabe freigegeben. Ungespeicherte Formularänderungen werden dadurch nicht übernommen.</p>${availableWorkflows.map((workflow) => `<article><b>${esc(workflow.name)}</b><small>${esc(workflow.description || '')}</small><div>${workflow.actions.map((action) => {
			const uniqueSchedules = [...new Map((action.scheduleOptions || []).map((entry) => [Number(entry.id), entry])).values()]
			const options = uniqueSchedules.map((entry) => `<option value="${entry.id}" ${Number(entry.id) === Number(action.selectedScheduleId) ? 'selected' : ''}>${esc(`${entry.title} · ${entry.displayDate} · ${entry.displayTime} Uhr${entry.location ? ` · ${entry.location}` : ''}`)}</option>`).join('')
			const selection = uniqueSchedules.length ? `<label class="bp-workflow-schedule"><span>Trauerfeier für die Dokumente</span><select class="workflow-schedule-select" data-workflow-id="${workflow.id}" data-action-key="${esc(action.key)}" ${action.scheduleSelectionRequired ? 'required' : ''}>${action.scheduleSelectionRequired ? '<option value="">Bitte auswählen</option>' : ''}${options}</select></label>` : ''
			const recoveryHint = action.outputMissing ? `<small class="bp-warning">${action.recoveryReason === 'STALE_RUNNING' ? 'Ein früherer Dokumentlauf wurde nicht abgeschlossen.' : 'Die Ausgaben der früheren Ausführung sind nicht vollständig vorhanden.'} Bitte die Dokumente erneut erzeugen.</small>` : ''
			const resultButton = action.executed && ['DOCUMENT', 'DOCUMENT_BUNDLE'].includes(action.type) && action.lastRun?.result
				? `<button type="button" class="bp-secondary show-workflow-output" data-workflow-id="${workflow.id}" data-action-key="${esc(action.key)}">Vorhandene Ausgaben anzeigen</button>`
				: ''
			return `<span class="bp-workflow-action-control">${selection}<button type="button" class="bp-secondary execute-workflow" data-workflow-id="${workflow.id}" data-action-key="${esc(action.key)}" data-action-type="${esc(action.type)}" data-recipient-category="${esc(action.recipientCategory || '')}" ${action.executed && !action.repeatable ? 'disabled' : ''}>${action.executed && !action.repeatable ? '✓ ' : (action.retryable ? '↻ ' : '')}${esc(action.label)}</button>${resultButton}${recoveryHint}</span>`
		}).join('')}</div></article>`).join('')}</section>` : ''
		const workflowActions = type === 'task' && editing ? `<section class="bp-follow-up"><h3>Freie Folgeaktion</h3><p>Für einen einmaligen, nicht vorkonfigurierten Folgeschritt.</p><div><button type="button" class="bp-secondary" data-follow-up="task">Folgeaufgabe anlegen</button><button type="button" class="bp-secondary" data-follow-up="schedule">Folgetermin / Frist anlegen</button></div></section>` : ''
		const recordStatus = item?.status || preset.status || (type === 'schedule' ? 'ENTWURF' : (type === 'activity' ? 'DOKUMENTIERT' : 'OFFEN'))
		const statusValues = type === 'schedule' ? ['ENTWURF', 'BESTAETIGT', 'GEAENDERT', 'ABGESAGT', 'ERLEDIGT'] : [...new Set(['OFFEN', 'IN_BEARBEITUNG', 'ERLEDIGT', 'ENTWURF', 'DOKUMENTIERT', item?.status, preset.status].filter(Boolean))]
		const statusLabels = { ENTWURF: 'Entwurf', BESTAETIGT: 'Bestätigt', GEAENDERT: 'Geändert – Folgeaufgaben prüfen', ABGESAGT: 'Abgesagt', ERLEDIGT: 'Erledigt', OFFEN: 'Offen', IN_BEARBEITUNG: 'In Bearbeitung', DOKUMENTIERT: 'Dokumentiert' }
		const dateValue = normalizeDateTime(item?.date || preset.date || defaultDate)
		const dateFields = type === 'task' ? `<div class="bp-form-grid"><label><span>Fälligkeitsdatum</span><input name="taskDate" type="date" value="${esc(dateValue.slice(0,10))}" required></label><label><span>Fälligkeitszeit</span><input name="taskTime" type="time" value="${esc(dateValue.slice(11,16) || '00:00')}" required></label></div>` : `<label>${isEvent ? 'Beginn' : 'Datum'}<input name="date" type="datetime-local" value="${esc(dateValue)}" ${isEvent ? 'required' : ''}></label>`
		root.insertAdjacentHTML('beforeend', `<div class="bp-modal"><form id="record-form" class="bp-record-form bp-record-form-${type}"><h2>${editing ? 'Bearbeiten' : 'Neu anlegen'} · ${recordLabels[type]}</h2>${fixedCase && linkedCase ? `<p class="bp-record-case-context">Fall ${esc(linkedCase)} · Die Fallnummer und der Rücksprung-Link werden auch in Nextcloud angezeigt.</p>` : ''}${caseField}${detailFields}${scheduleFields}<label>Bezeichnung<input name="title" ${type==='task'?'list="predefined-task-titles"':''} value="${esc(item?.title || preset.title || '')}" required>${type==='task'?'<small>Vorgegebene Aufgabe auswählen oder eigenen Freitext eingeben.</small>':''}</label>${taskTitleList}<label>Beschreibung<textarea name="description" rows="4">${esc(baseData.description || '')}</textarea></label>${dateFields}<label>Priorität<select name="priority">${['HOCH', 'NORMAL', 'NIEDRIG'].map((value) => `<option ${value === (baseData.priority || 'NORMAL') ? 'selected' : ''}>${value}</option>`).join('')}</select></label>${assigneeField}<label>Status<select name="status">${statusValues.map((value) => `<option value="${value}" ${value === recordStatus ? 'selected' : ''}>${statusLabels[value] || value}</option>`).join('')}</select></label>${configuredWorkflowActions}${workflowActions}<p class="bp-save-state" id="record-form-state"></p><div class="bp-modal-actions"><button type="button" class="bp-secondary" id="cancel-record">Abbrechen</button><button class="bp-primary" id="save-record">${editing ? 'Änderungen speichern' : 'In Nextcloud speichern'}</button></div></form></div>`)
		const modals = root.querySelectorAll('.bp-modal'); const modal = modals[modals.length - 1]
		const form = modal.querySelector('#record-form')
		if (scheduleHistory.length) {
			const historyRows = scheduleHistory.map((entry) => `<li><b>${esc(entry.action)}</b> · ${esc(entry.changedBy)} · ${esc(new Date(entry.createdAt).toLocaleString('de-DE'))}${entry.reason ? `<small>Grund: ${esc(entry.reason)}</small>` : ''}${(entry.changes||[]).map((change)=>`<small>${esc(change.label)}: ${esc(String(change.before||'–'))} → ${esc(String(change.after||'–'))}</small>`).join('')}</li>`).join('')
			form.querySelector('.bp-save-state')?.insertAdjacentHTML('beforebegin', `<details class="bp-schedule-history"><summary>Änderungsverlauf (${scheduleHistory.length})</summary><ol>${historyRows}</ol></details>`)
		}
		const toggleExternalFields = () => { const external = form.elements.scheduleKind?.value === 'EXTERNAL_APPOINTMENT'; const section = modal.querySelector('#external-appointment-fields'); if (section) { section.hidden = !external; section.querySelectorAll('select,input').forEach((control) => { control.required = external && ['appointmentCategory','location'].includes(control.name) }) } }
		form.elements.scheduleKind?.addEventListener('change', toggleExternalFields); toggleExternalFields()
		const showRequirements = (selected) => { const summary=modal.querySelector('#schedule-requirement-summary');if(!summary)return;const requirements=[['requiredStaff','Mitarbeitende'],['requiredVehicles','Fahrzeuge'],['requiredRooms','Räume'],['requiredChapels','Kapellen'],['requiredEquipment','Ausstattung']].filter(([key])=>Number(selected?.[key]||0)>0).map(([key,label])=>`${Number(selected[key])} × ${label}`);summary.textContent=requirements.length?`Erforderliche Disposition: ${requirements.join(', ')}`:'Für diese Terminart ist kein Mindestbedarf hinterlegt.' }
		form.elements.schedulePresetKey?.addEventListener('change', () => {
			const selected = availableScheduleTypes.find((entry) => entry.key === form.elements.schedulePresetKey.value)
			showRequirements(selected)
			if (!selected) return
			form.elements.scheduleKind.value = selected.scheduleKind || 'EXTERNAL_APPOINTMENT'
			form.elements.appointmentCategory.value = selected.appointmentCategory || ''
			form.elements.title.value = selected.title || selected.label || ''
			form.elements.description.value = selected.description || ''
			form.elements.priority.value = selected.priority || 'NORMAL'
			form.elements.durationMinutes.value = selected.durationMinutes || 60
			form.elements.bufferBeforeMinutes.value = selected.bufferBeforeMinutes || 0
			form.elements.bufferAfterMinutes.value = selected.bufferAfterMinutes || 0
			if (!editing) form.querySelectorAll('[name="resourceKeys"]').forEach((input)=>{input.checked=(selected.defaultResourceKeys||[]).includes(input.value)})
			if (!editing && form.elements.date && Number(selected.dueOffsetDays || 0) !== 0) {
				const proposed = new Date(); proposed.setDate(proposed.getDate() + Number(selected.dueOffsetDays))
				form.elements.date.value = `${proposed.getFullYear()}-${String(proposed.getMonth()+1).padStart(2,'0')}-${String(proposed.getDate()).padStart(2,'0')}T00:00`
			}
			toggleExternalFields()
		})
		showRequirements(availableScheduleTypes.find((entry)=>entry.key===form.elements.schedulePresetKey?.value))
		const persist = async () => {
			const data = Object.fromEntries(new FormData(form))
			const externalParticipantNames = type === 'schedule' ? String(data.externalParticipants || '').split(';').map((value) => value.trim()).filter(Boolean) : []
			const matchedExternalContacts = externalParticipantNames.map((name) => externalContacts.find((contact) => String(contact.title).localeCompare(name, 'de', { sensitivity: 'base' }) === 0)).filter(Boolean)
			const externalContactIds = [...new Set(matchedExternalContacts.map((contact) => Number(contact.id)))]
			const linkedExternalContacts = matchedExternalContacts.map((contact) => ({ id: Number(contact.id), title: contact.title, email: contact.data?.email || '', phone: contact.data?.phone || '', categories: contact.data?.categories || '' }))
			const matchedNames = new Set(linkedExternalContacts.map((contact) => String(contact.title).toLocaleLowerCase('de')))
			const externalParticipantsAdditional = externalParticipantNames.filter((name) => !matchedNames.has(name.toLocaleLowerCase('de'))).join('; ')
			const externalParticipants = externalParticipantNames.join('; ')
			const payload = JSON.stringify({ ...baseData, description: data.description || '', priority: data.priority || 'NORMAL', ...(type === 'task' ? { assigneeUid: data.assigneeUid || assigneeUid } : {}), ...(type === 'schedule' ? { assigneeUids: [...form.querySelectorAll('[name="assigneeUids"]:checked')].map((input)=>input.value), resourceKeys:[...form.querySelectorAll('[name="resourceKeys"]:checked')].map((input)=>input.value), schedulePresetKey: data.schedulePresetKey || '', scheduleTypeKey:data.schedulePresetKey||'', durationMinutes: Math.max(15, Number(data.durationMinutes || 60)), bufferBeforeMinutes:Math.max(0,Number(data.bufferBeforeMinutes||0)),bufferAfterMinutes:Math.max(0,Number(data.bufferAfterMinutes||0)),changeReason:data.changeReason||'',conflictOverride:Boolean(data.conflictOverride), scheduleKind: data.scheduleKind || 'INTERNAL_ACTIVITY', appointmentCategory: data.scheduleKind === 'EXTERNAL_APPOINTMENT' ? (data.appointmentCategory || '') : '', location: data.scheduleKind === 'EXTERNAL_APPOINTMENT' ? (data.location || '') : '', externalContactIds: data.scheduleKind === 'EXTERNAL_APPOINTMENT' ? externalContactIds : [], externalContacts: data.scheduleKind === 'EXTERNAL_APPOINTMENT' ? linkedExternalContacts : [], externalParticipantsAdditional: data.scheduleKind === 'EXTERNAL_APPOINTMENT' ? externalParticipantsAdditional : '', externalParticipants: data.scheduleKind === 'EXTERNAL_APPOINTMENT' ? externalParticipants : '' } : {}), ...(baseData.kind === 'EMAIL_DRAFT' ? { recipient: data.recipient || '', recipientCategory: data.recipientCategory || baseData.recipientCategory || '' } : {}), ...(type === 'activity' || baseData.kind === 'CONTACT_ACTIVITY' ? { kind: 'CONTACT_ACTIVITY', contact: data.contact || '', channel: data.channel || 'Telefon' } : {}) })
			const url = editing ? recordItemUrl(item.id) : recordUrl(type)
			const recordDate = type === 'task' ? `${data.taskDate}T${data.taskTime || '00:00'}` : (data.date || '')
			return api(url, { method: editing ? 'PUT' : 'POST', body: new URLSearchParams({ title: data.title, date: recordDate, status: data.status, caseId: Number(data.caseId || 0), data: payload }) })
		}
		modal.querySelector('#cancel-record').onclick = () => modal.remove()
		form.onsubmit = async (event) => {
			event.preventDefault()
			const status = modal.querySelector('#record-form-state')
			const submit = modal.querySelector('#save-record')
			submit.disabled = true
			status.textContent = 'Speichert …'; status.dataset.state = 'saving'
			try {
				const saved = await persist()
				modal.remove()
				await loadRecordType(type, state.view === 'case-detail' ? state.currentCase.id : 0)
				if (type === 'schedule') await loadRecordType('task', state.view === 'case-detail' ? state.currentCase.id : 0)
				render()
				if (type === 'schedule' && saved.automation?.errors?.length) notifyError(`Termin gespeichert; ${saved.automation.errors.length} automatische Folgeaktion(en) sind fehlgeschlagen. Bitte Workflow-Protokoll prüfen.`)
				else if (type === 'schedule' && saved.automation?.executed) notifySuccess(`${saved.automation.executed} Folgeaufgabe(n) wurden aus dem bestätigten Termin angelegt.`)
			} catch (error) {
				status.textContent = error.message; status.dataset.state = 'error'; submit.disabled = false
				if(type==='schedule'){const suggestions=[...String(error.message).matchAll(/(\d{2})\.(\d{2})\.(\d{4}) (\d{2}):(\d{2}) Uhr/g)].map((match)=>({label:match[0],value:`${match[3]}-${match[2]}-${match[1]}T${match[4]}:${match[5]}`}));if(suggestions.length){const choices=document.createElement('div');choices.className='bp-alternative-slots';choices.setAttribute('aria-label','Alternative Termine');suggestions.forEach((suggestion)=>{const button=document.createElement('button');button.type='button';button.className='bp-secondary';button.textContent=suggestion.label;button.onclick=()=>{form.elements.date.value=suggestion.value;status.textContent='Alternativtermin übernommen. Bitte erneut speichern.';status.dataset.state='warning';choices.remove();form.elements.date.focus()};choices.append(button)});status.after(choices)}}
			}
		}
		modal.querySelectorAll('[data-follow-up]').forEach((button) => button.addEventListener('click', async () => {
			try {
				const saved = await persist()
				const nextType = button.dataset.followUp
				const parentUid = saved.data?.nextcloud?.uid || ''
				modal.remove()
				showRecordForm(nextType, saved.caseId || caseId, null, { data: {
					description: `Folgeaktion zu: ${saved.title}`,
					priority: saved.data?.priority || 'NORMAL',
					assigneeUid: saved.data?.assigneeUid || state.team.currentUid,
					workflow: { parentRecordId: saved.id, parentUid, source: 'FOLLOW_UP' },
				} })
			} catch (error) { notifyError(error.message) }
		}))
		modal.querySelectorAll('.execute-workflow').forEach((button) => button.addEventListener('click', async (event) => {
			event.preventDefault()
			event.stopPropagation()
			const input = {}
			const scheduleSelect = [...modal.querySelectorAll('.workflow-schedule-select')].find((select) => select.dataset.workflowId === button.dataset.workflowId && select.dataset.actionKey === button.dataset.actionKey)
			if (scheduleSelect) {
				if (!scheduleSelect.value) { scheduleSelect.focus(); notifyWarning('Bitte wählen Sie die Trauerfeier für das Dokumentpaket aus.'); return }
				input.scheduleId = Number(scheduleSelect.value)
			}
			if (button.dataset.actionType === 'EMAIL_DRAFT') {
				const candidates = contactsByCategory(button.dataset.recipientCategory || '').map((entry) => entry.data?.email || entry.title).filter(Boolean)
				const hint = candidates.length ? `\nVerfügbar: ${candidates.join(', ')}` : ''
				const recipient = await promptAction(`Empfänger aus Kontaktgruppe „${button.dataset.recipientCategory || 'Kontakte'}“.${hint}`, candidates[0] || '', { title: 'E-Mail-Entwurf vorbereiten', label: 'Empfänger (optional)', required: false })
				if (recipient === null) return
				input.recipient = recipient
			} else if (button.dataset.actionType === 'CONTACT_ACTIVITY') {
				const contact = await promptAction('Mit wem wurde die Aktivität durchgeführt?', '', { title: 'Kontaktaktivität dokumentieren', label: 'Kontakt / Organisation' })
				if (contact === null) return
				const description = await promptAction('Dokumentieren Sie kurz das Ergebnis.', '', { title: 'Kontaktaktivität dokumentieren', label: 'Gesprächsnotiz / Ergebnis', multiline: true })
				if (description === null) return
				input.contact = contact; input.description = description; input.channel = await promptAction('Über welchen Weg fand der Kontakt statt?', 'Telefon', { title: 'Kontaktaktivität dokumentieren', label: 'Kontaktweg' }) || 'Telefon'
			} else if (button.dataset.actionType === 'CASE_STATUS' && !await confirmAction('Aufgaben- und Fallstatus wie im Workflow festgelegt ändern?', { title: 'Workflow ausführen', confirmLabel: 'Status ändern' })) return
			const status = modal.querySelector('#record-form-state')
			button.disabled = true; status.textContent = 'Folgeaktion wird ausgeführt …'; status.dataset.state = 'saving'
			try {
				const result = await api(executeWorkflowUrl(item.id, Number(button.dataset.workflowId), button.dataset.actionKey), { method: 'POST', feedback: false, loadingLabel: 'Workflow wird ausgeführt …', body: new URLSearchParams({ input: JSON.stringify(input) }) })
				modal.remove()
				await Promise.all([loadRecordType('task', state.view === 'case-detail' ? state.currentCase.id : 0), state.currentCase ? loadRecordType('document', state.currentCase.id) : Promise.resolve(), state.currentCase ? loadRecordType('activity', state.currentCase.id) : Promise.resolve(), state.currentCase ? loadCaseFiles(state.currentCase.id) : Promise.resolve()])
				if (result.result?.case && state.currentCase) state.currentCase = result.result.case
				render()
				notifySuccess(`Folgeaktion „${result.action.label}“ wurde ausgeführt.`)
				showWorkflowDocumentResult(result)
			} catch (error) {
				status.textContent = error.message; status.dataset.state = 'error'; button.disabled = false
			}
		}))
		modal.querySelectorAll('.show-workflow-output').forEach((button) => button.addEventListener('click', () => {
			const workflow = availableWorkflows.find((entry) => String(entry.id) === String(button.dataset.workflowId))
			const action = workflow?.actions?.find((entry) => entry.key === button.dataset.actionKey)
			if (action?.lastRun?.result) showWorkflowDocumentResult({ result: action.lastRun.result })
		}))
	}

	function showEditRecord(type, id) {
		const item = (state.records[type] || []).find((record) => record.id === id)
		if (item) showRecordForm(type, item.caseId || 0, item)
	}

	async function saveRecord(item, type) {
		const saved = await api(recordItemUrl(item.id), { method: 'PUT', body: new URLSearchParams({ title: item.title, date: item.date || '', status: item.status, caseId: item.caseId || 0, data: JSON.stringify(item.data || {}) }) })
		Object.assign(item, saved)
	}

	function showContactForm(category, target) {
		root.insertAdjacentHTML('beforeend', `<div class="bp-modal"><form id="contact-form"><h2>Kontakt anlegen · ${esc(category)}</h2><label>Name / Organisation<input name="title" required></label><label>Straße<input name="street"></label><label>PLZ<input name="postalCode"></label><label>Ort<input name="city"></label><label>Land<input name="country" value="Deutschland"></label><label>Telefon<input name="phone"></label><label>E-Mail<input type="email" name="email"></label><div><button type="button" class="bp-secondary" id="cancel-contact">Abbrechen</button><button class="bp-primary">Kontakt speichern</button></div></form></div>`)
		document.getElementById('cancel-contact').onclick = () => root.querySelector('.bp-modal').remove()
		document.getElementById('contact-form').onsubmit = async (event) => {
			event.preventDefault(); const data = Object.fromEntries(new FormData(event.target)); data.categories = category
			await api(recordUrl('contact'), { method: 'POST', body: new URLSearchParams({ title: data.title, status: 'AKTIV', caseId: state.currentCase?.id || 0, data: JSON.stringify(data) }) })
			await loadRecordType('contact'); if (state.currentCase) { state.currentCase.masterData[target] = data.title; await api(`${urls.cases}/${state.currentCase.id}/master-data`, { method: 'PUT', body: new URLSearchParams({ masterData: JSON.stringify(state.currentCase.masterData) }) }) }
			root.querySelector('.bp-modal').remove(); render()
		}
	}


	return { filteredTaskRecords, filterOptions, taskToolbar, selectableRecordValues, recordToolbar, filteredRecordRecords, formatRecordDate, recordBadges, recordMeta, recordRows, recordsView, documentOutputRow, normalizeDateTime, caseOptions, showWorkflowDocumentResult, showRecordForm, showEditRecord, saveRecord, showContactForm }
}
