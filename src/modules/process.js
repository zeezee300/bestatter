import { PHASES, DEFAULT_META, stepSentence, clientWarnings, simulate, phaseProgress, processStateCounts, processCountsText, euroToCents, centsToEuro, presetLabel, DOCUMENT_TRIGGERS, DEREGISTRATION_TRIGGERS, LABEL_TRIGGERS } from './process-logic.js'

/**
 * Ablaufvorlagen in 7 Phasen (0.66.0): Fallcockpit (Phasenleiste, nächste Schritte, Fallmerkmale,
 * Abschlussprüfung), Ablauf im Reiter „Aufgaben“ und der Ablauf-Editor im Customizing.
 */
export function createProcessModule(ctx) {
	const { root, apiBase, state } = ctx
	const api = (...args) => ctx.api(...args)
	const esc = (...args) => ctx.esc(...args)
	const render = (...args) => ctx.render(...args)
	const notifySuccess = (...args) => ctx.notifySuccess(...args)
	const notifyError = (...args) => ctx.notifyError(...args)
	const notifyWarning = (...args) => ctx.notifyWarning(...args)
	const confirmAction = (...args) => ctx.confirmAction(...args)
	const promptAction = (...args) => ctx.promptAction(...args)
	const processUrl = (caseId) => `${apiBase}/cases/${caseId}/process`
	const templatesUrl = () => `${apiBase}/process-templates`
	const runUrl = (runId, action) => `${apiBase}/process/runs/${runId}/${action}`
	const stateIcons = { done: '✓', skipped: '–', open: '○', overdue: '!', waiting: '…', pending: '?', autoskipped: '–', deleted: '×' }
	const stateLabels = { done: 'Erledigt', skipped: 'Entfällt', open: 'Offen', overdue: 'Überfällig', waiting: 'Noch nicht fällig', pending: 'Angabe fehlt', autoskipped: 'Automatisch entfallen', deleted: 'Aufgabe gelöscht' }
	const meta = () => ({ ...DEFAULT_META, ...(state.processAdmin?.meta || {}) })
	const formatDate = (value) => {
		const match = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})/)
		return match ? `${match[3]}.${match[2]}.${match[1].slice(2)}` : ''
	}

	// ------------------------------------------------------------ Laden

	async function loadProcess(caseId = state.currentCase?.id) {
		if (!caseId) return null
		if (Number(state.process?.caseId) !== Number(caseId)) { state.processPhaseOpen = 0; state.processPhaseToggles = {}; state.processPanelOpen = false }
		try {
			state.process = await api(processUrl(caseId), { feedback: false, loadingLabel: 'Ablauf wird abgeglichen …' })
			if (state.process?.reconcile?.errors?.length) notifyWarning(`Einige Ablaufschritte konnten nicht angelegt werden: ${state.process.reconcile.errors.join(' · ')}`)
		} catch (error) {
			state.process = { caseId, error: error.message, phases: [] }
		}
		return state.process
	}

	async function loadProcessAdmin() {
		state.processAdmin = await api(templatesUrl(), { feedback: false })
		const templates = state.processAdmin.templates || []
		if (!templates.some((template) => template.key === state.processAdminKey)) state.processAdminKey = templates[0]?.key || ''
		if (!state.processDraft || state.processDraft.key !== state.processAdminKey || !state.processDraftDirty) resetDraft()
		return state.processAdmin
	}

	function resetDraft() {
		const template = (state.processAdmin?.templates || []).find((entry) => entry.key === state.processAdminKey)
		state.processDraft = template ? JSON.parse(JSON.stringify(template)) : null
		state.processDraftDirty = false
	}

	async function reloadCaseData() {
		const caseId = state.currentCase?.id
		if (!caseId) return
		await Promise.all([
			loadProcess(caseId),
			ctx.loadRecordType('task', caseId),
			ctx.loadRecordType('document', caseId),
			api(`${ctx.urls.cases}/${caseId}`, { feedback: false }).then((item) => { state.currentCase = item }),
		])
	}

	// ------------------------------------------------------------ Fall: Darstellung

	function phaseBar(process, interactive = true) {
		const progress = phaseProgress(process.phases || [])
		return `<ol class="bp-phase-bar" aria-label="Fortschritt nach Phasen">${progress.map((phase) => {
			const title = `${phase.label}: ${phase.done} von ${phase.total} erledigt${phase.overdue ? `, ${phase.overdue} überfällig` : ''}`
			const classes = ['bp-phase', phase.complete ? 'is-complete' : '', phase.overdue ? 'is-overdue' : '', !phase.total ? 'is-empty' : ''].join(' ')
			const inner = `<span class="bp-phase-number" aria-hidden="true">${phase.complete ? '✓' : phase.phase}</span><span class="bp-phase-label">${esc(phase.label)}</span><span class="bp-phase-count">${phase.done}/${phase.total}${phase.overdue ? ` · ${phase.overdue} !` : ''}</span><span class="bp-phase-meter" aria-hidden="true"><span style="width:${phase.percent}%"></span></span>`
			return `<li class="${classes}">${interactive ? `<button type="button" data-process-phase="${phase.phase}" title="${esc(title)}" aria-label="${esc(title)}">${inner}</button>` : `<span title="${esc(title)}">${inner}</span>`}</li>`
		}).join('')}</ol>`
	}

	function stepMeta(view) {
		const parts = []
		if (view.due) parts.push(`fällig ${formatDate(view.due)}`)
		if (view.roleLabel && view.role) parts.push(view.roleLabel)
		if (view.assigneeName) parts.push(view.assigneeName)
		if (view.required) parts.push('Pflicht')
		if (view.priority === 'KRITISCH' || view.priority === 'HOCH') parts.push(view.priority === 'KRITISCH' ? 'kritisch' : 'hoch')
		return parts
	}

	function stepRow(view, options = {}) {
		const open = ['open', 'overdue'].includes(view.state)
		const done = ['done', 'skipped'].includes(view.state)
		const notes = []
		if (view.reason) notes.push(`<small class="bp-step-reason">${esc(view.reason)}</small>`)
		if (view.shortNotice && open) notes.push('<small class="bp-warning">Kurzfristig: Die reguläre Frist lag bereits in der Vergangenheit.</small>')
		// 0.88.1: kurze Marke statt eines Warnabsatzes je Schritt; die ausführliche Meldung steht einmal zentral (Fallanlage, Startseite).
		if (view.roleUnresolved && open) notes.push(`<small class="bp-role-fallback" title="Für die Rolle ist keine Nextcloud-Gruppe mit Mitgliedern eingerichtet – die Aufgabe liegt bei der Fallverantwortung.">Rolle ${esc(view.roleLabel || 'unbekannt')} unbesetzt → Fallverantwortung</small>`)
		if (view.autoCompleted) notes.push(`<small class="bp-step-reason">Automatisch erledigt: ${esc(view.autoCompletedReason)}</small>`)
		if (view.state === 'skipped' && view.skipReason) notes.push(`<small class="bp-step-reason">Entfällt: ${esc(view.skipReason)}</small>`)
		if (open && view.completion && view.completion !== 'von Hand abhaken') notes.push(`<small class="bp-step-reason">Hakt sich selbst ab, wenn: ${esc(view.completion)}</small>`)
		const actionButtons = open ? (view.actions || []).map((action) => {
			const serverSide = !['OPEN_VIEW', 'SURCHARGE_CHECK', 'CASE_CLOSE'].includes(action.type) && !(action.type === 'SCHEDULE' && action.openForm)
			const blocked = serverSide && !view.accessible
			const executed = action.executed
			return `<button type="button" class="bp-secondary bp-step-action" data-process-action="${esc(action.key)}" data-run-id="${view.runId}" ${blocked || executed ? 'disabled' : ''} title="${esc(blocked ? 'Nur für die zuständige Person bzw. Rolle ausführbar.' : (executed ? 'Bereits ausgeführt' : action.label))}">${executed ? '✓ ' : ''}${esc(action.label)}</button>`
		}).join('') : ''
		const controls = view.runId ? [
			open ? `<button type="button" class="bp-primary bp-step-complete" data-process-complete="${view.runId}">Erledigt</button>` : '',
			open ? `<button type="button" class="bp-secondary" data-process-skip="${view.runId}" data-required="${view.required ? '1' : '0'}" title="Trifft in diesem Fall nicht zu">Entfällt</button>` : '',
			done && !options.compact ? `<button type="button" class="bp-secondary" data-process-reopen="${view.runId}">Wieder öffnen</button>` : '',
			view.recordId && !options.compact ? `<button type="button" class="bp-secondary" data-process-open-task="${view.recordId}">Aufgabe öffnen</button>` : '',
		].join('') : ''
		const label = stateLabels[view.state] || view.state
		return `<article class="bp-step bp-step-${esc(view.state)}${view.priority === 'KRITISCH' && open ? ' is-critical' : ''}"><span class="bp-step-icon" role="img" aria-label="${esc(label)}" title="${esc(label)}">${stateIcons[view.state] || '•'}</span><div class="bp-step-body"><b>${esc(view.title)}</b><small class="bp-step-meta">${esc(stepMeta(view).join(' · '))}${options.showTemplate && view.templateName ? ` · ${esc(view.templateName)}` : ''}</small>${notes.join('')}</div><div class="bp-step-actions">${actionButtons}${controls}</div></article>`
	}

	function attributesForm(process) {
		const attributes = process.attributes || {}
		const tri = (name, value, labels) => `<select name="${name}">${[['', labels[0]], ['1', labels[1]], ['0', labels[2]]].map(([key, text]) => `<option value="${key}" ${String(value ?? '') === key ? 'selected' : ''}>${esc(text)}</option>`).join('')}</select>`
		return `<form class="bp-process-attributes" id="process-attributes"><h4>Fallmerkmale für den Ablauf</h4><p class="bp-muted">Steuern, welche Schritte anfallen. Änderungen wirken sofort.</p>
			<div class="bp-attribute-row"><span>Bestattungsart</span><span class="bp-attribute-value">${esc(attributes.funeralType || attributes.funeralScopeLabel || 'noch offen')} <button type="button" class="bp-link-button" data-process-open-view="master">ändern</button></span></div>
			<label><span>Trauerfeier</span>${tri('ceremony', attributes.ceremony, ['noch offen', 'ja', 'nein'])}</label>
			<label><span>Bezieht der/die Verstorbene eine Rente?</span>${tri('pension', attributes.pension, ['unbekannt', 'ja', 'nein'])}</label>
			<label><span>Vereinbarte Anzahlung (€, je Fall)</span><input name="depositAmount" inputmode="decimal" placeholder="keine" value="${attributes.depositCents ? esc(centsToEuro(attributes.depositCents)) : ''}"></label>
			<label><span>Anzahlung eingegangen am</span><input name="depositReceivedAt" type="date" value="${esc(attributes.depositReceivedAt || '')}" ${attributes.depositCents ? '' : 'disabled'}></label>
			<div class="bp-row-actions"><button class="bp-secondary" id="save-process-attributes">Merkmale speichern</button><span class="bp-save-state" aria-live="polite"></span></div></form>`
	}

	/**
	 * Reiter „Termine“: kompakte Phasenleiste und die offenen Ablaufschritte, die einen Termin vereinbaren
	 * oder sich über einen Termin abhaken. Die übrigen Fallreiter zeigen die Phasen bewusst nicht.
	 */
	function processScheduleStrip() {
		const process = state.process
		if (!process || process.error || Number(process.caseId) !== Number(state.currentCase?.id)) return ''
		const steps = (process.phases || []).flatMap((phase) => phase.steps || []).filter((view) => view.scheduleRelated && ['open', 'overdue', 'pending'].includes(view.state))
		const list = steps.length ? `<div class="bp-step-list">${steps.map((view) => stepRow(view, { compact: true })).join('')}</div>` : '<p class="bp-empty">Kein Terminschritt offen.</p>'
		// 0.88.1: steht unter der Terminliste und lässt sich einklappen.
		const open = state.processScheduleOpen !== false
		return `<details class="bp-panel bp-process-schedule bp-process-collapsible" id="process-schedule-details" ${open ? 'open' : ''}><summary><span class="bp-eyebrow">Ablauf</span><b id="process-schedule-title">Terminschritte</b><small>${steps.length} offen</small></summary><div class="bp-panel-head"><p class="bp-muted">Offene Schritte rund um Termine: Termin vereinbaren, auf einen bestätigten Termin vorbereiten oder einen stattgefundenen Termin nachbereiten.</p><button type="button" class="bp-secondary" data-process-phase-open-tab="task">Alle Schritte</button></div><div class="bp-process-header-bar">${phaseBar(process)}</div>${list}</details>`
	}

	function processOverviewCard() {
		const process = state.process
		if (!process || Number(process.caseId) !== Number(state.currentCase?.id)) return '<section class="bp-panel bp-process-card"><p class="bp-muted">Ablauf wird geladen …</p></section>'
		if (process.error) return `<section class="bp-panel bp-process-card"><p class="bp-error">Der Ablauf konnte nicht geladen werden: ${esc(process.error)}</p></section>`
		const totals = (process.phases || []).reduce((sum, phase) => ({ total: sum.total + phase.total, done: sum.done + phase.done, overdue: sum.overdue + phase.overdue }), { total: 0, done: 0, overdue: 0 })
		const counts = processStateCounts(process.phases || [])
		const next = (process.nextSteps || []).map((view) => stepRow(view, { compact: true })).join('') || '<p class="bp-empty">Aktuell ist kein Schritt offen.</p>'
		const closure = process.closure || {}
		const closeButton = closure.alreadyClosed ? '<span class="bp-status ready">Fall abgeschlossen</span>' : `<button type="button" class="${closure.closable ? 'bp-primary' : 'bp-secondary'}" id="open-closure" title="Abschlussprüfung anzeigen">Fall abschließen …</button>`
		return `<section class="bp-panel bp-process-card" aria-labelledby="process-card-title"><div class="bp-panel-head"><div><p class="bp-eyebrow">Ablauf</p><h3 id="process-card-title">${esc(processCountsText(counts))}${totals.overdue ? ` · <span class="bp-warning-text">${totals.overdue} überfällig</span>` : ''}</h3><p class="bp-muted bp-process-total">Insgesamt ${totals.done} von ${totals.total} Schritten erledigt. „Später“ sind Schritte, deren Auslöser noch nicht eingetreten ist – zum Beispiel erst nach dem Auftrag.</p></div><div class="bp-head-actions"><button type="button" class="bp-secondary" data-case-tab="task" data-process-reset-phases>Alle Schritte</button>${closeButton}</div></div>${phaseBar(process)}<div class="bp-process-grid"><div class="bp-next-steps"><h4>Nächste Schritte</h4>${next}</div>${attributesForm(process)}</div></section>`
	}

	function processTaskPanel() {
		const process = state.process
		if (!process || Number(process.caseId) !== Number(state.currentCase?.id)) return '<section class="bp-panel"><p class="bp-muted">Ablauf wird geladen …</p></section>'
		if (process.error) return `<section class="bp-panel"><p class="bp-error">Der Ablauf konnte nicht geladen werden: ${esc(process.error)}</p></section>`
		const hideDone = Boolean(state.processHideDone)
		const openPhase = Number(state.processPhaseOpen || 0)
		const emptyPhases = (process.phases || []).filter((phase) => !phase.total && !(phase.steps || []).length)
		const phases = (process.phases || []).filter((phase) => !emptyPhases.includes(phase)).map((phase) => {
			const steps = phase.steps.filter((view) => !hideDone || !['done', 'skipped', 'autoskipped', 'deleted'].includes(view.state))
			const toggles = state.processPhaseToggles || {}
			const isOpen = toggles[phase.phase] ?? (openPhase ? openPhase === phase.phase : (phase.open > 0 || phase.overdue > 0))
			return `<details class="bp-process-phase-detail" data-process-phase-details="${phase.phase}" ${isOpen ? 'open' : ''}><summary><span class="bp-phase-number">${phase.total && phase.done === phase.total ? '✓' : phase.phase}</span><b>${esc(phase.label)}</b><small>${phase.done}/${phase.total} erledigt${phase.overdue ? ` · ${phase.overdue} überfällig` : ''}</small></summary><div class="bp-step-list">${steps.map((view) => stepRow(view, { showTemplate: true })).join('') || '<p class="bp-empty">Keine Schritte in dieser Ansicht.</p>'}</div></details>`
		}).join('')
		const manual = process.manualSteps || []
		const newSteps = manual.filter((step) => step.isNew)
		const otherSteps = manual.filter((step) => !step.isNew)
		const option = (step) => `<option value="${step.stepId}">${esc(`${step.phase} · ${step.title}${step.templateName ? ` (${step.templateName})` : ''}`)}</option>`
		const addStep = manual.length ? `<label class="bp-inline-field"><span>Schritt hinzufügen</span><select id="process-add-step"><option value="">– auswählen –</option>${newSteps.length ? `<optgroup label="Neu in der Vorlage (nach Start dieses Falls)">${newSteps.map(option).join('')}</optgroup>` : ''}${otherSteps.length ? `<optgroup label="Vorziehen bzw. nur von Hand">${otherSteps.map(option).join('')}</optgroup>` : ''}</select></label><button type="button" class="bp-secondary" id="process-add-step-button">Hinzufügen</button>` : ''
		const newHint = newSteps.length ? `<aside class="bp-compliance-note"><b>Bestandsschutz:</b> ${newSteps.length} Schritt(e) sind erst nach Start dieses Falls in eine Ablaufvorlage gekommen und werden hier nicht automatisch angelegt. Bei Bedarf über „Schritt hinzufügen“ übernehmen.</aside>` : ''
		// 0.88.1: Die Aufgabenliste steht oben; der Ablauf nach Phasen folgt darunter und ist eingeklappt, bis eine Phase gewählt
		// oder der Bereich geöffnet wird.
		const counts = processStateCounts(process.phases || [])
		const panelOpen = Boolean(state.processPanelOpen || openPhase)
		return `<details class="bp-panel bp-process-panel bp-process-collapsible" id="process-panel-details" ${panelOpen ? 'open' : ''}><summary><span class="bp-eyebrow">Ablauf</span><b id="process-panel-title">Schritte nach Phasen</b><small>${esc(processCountsText(counts))}${counts.overdue ? ` · ${counts.overdue} überfällig` : ''}</small></summary><div class="bp-panel-head"><p class="bp-muted">Schritte entstehen automatisch, sobald ihr Auslöser eintritt (z. B. Auftrag erteilt, Termin bestätigt). „Entfällt“ markiert einen Schritt als nicht zutreffend.</p><div class="bp-head-actions"><label class="bp-inline-check"><input type="checkbox" id="process-hide-done" ${hideDone ? 'checked' : ''}> Erledigte ausblenden</label>${addStep}</div></div>${newHint}${phaseBar(process)}${phases}${emptyPhases.length ? `<p class="bp-muted bp-process-empty-phases">Ohne Schritte in diesem Fall: ${emptyPhases.map((phase) => esc(`${phase.phase} ${phase.label}`)).join(' · ')}</p>` : ''}</details>`
	}

	// ------------------------------------------------------------ Fall: Aktionen

	function findStep(runId) {
		for (const phase of state.process?.phases || []) for (const view of phase.steps) if (Number(view.runId) === Number(runId)) return view
		return null
	}

	async function ensureCaseOpen(caseId) {
		if (!caseId || (state.view === 'case-detail' && Number(state.currentCase?.id) === Number(caseId))) return
		state.currentCase = await api(`${ctx.urls.cases}/${caseId}`, { feedback: false })
		state.currentCaseId = Number(caseId)
		state.view = 'case-detail'
		await loadProcess(caseId)
	}

	function openCaseTab(tab) {
		const button = root.querySelector(`[data-case-tab="${tab}"]`)
		if (button) { button.click(); return }
		state.caseTab = tab
		render()
	}

	async function afterProcessChange(message = '') {
		if (state.view === 'case-detail' && state.currentCase) await reloadCaseData()
		else await ctx.loadRecordType('task', 0)
		render()
		if (message) notifySuccess(message)
	}

	function dialog(title, bodyHtml, submitLabel = 'Übernehmen', options = {}) {
		return new Promise((resolve) => {
			root.insertAdjacentHTML('beforeend', `<div class="bp-modal" role="dialog" aria-modal="true" aria-labelledby="process-dialog-title"><form class="bp-wide-modal bp-process-dialog ${options.narrow ? 'bp-process-dialog-narrow' : ''}"><div class="bp-panel-head"><div><p class="bp-eyebrow">${esc(options.eyebrow || 'Ablauf')}</p><h2 id="process-dialog-title">${esc(title)}</h2></div><button type="button" class="bp-secondary" data-close-dialog>Schließen</button></div><div class="bp-process-dialog-body">${bodyHtml}</div><p class="bp-save-state" aria-live="polite"></p><div class="bp-modal-actions">${options.hideCancel ? '' : `<button type="button" class="bp-secondary" data-close-dialog>${submitLabel ? 'Abbrechen' : 'Schließen'}</button>`}${submitLabel ? `<button class="bp-primary">${esc(submitLabel)}</button>` : ''}</div></form></div>`)
			const modal = [...root.querySelectorAll('.bp-modal')].pop()
			const form = modal.querySelector('form')
			const close = (value) => { modal.remove(); resolve(value) }
			modal.querySelectorAll('[data-close-dialog]').forEach((button) => { button.onclick = () => close(null) })
			modal.addEventListener('keydown', (event) => { if (event.key === 'Escape') close(null) })
			form.onsubmit = (event) => { event.preventDefault(); close(options.collect ? options.collect(form) : new FormData(form)) }
			if (options.onOpen) options.onOpen(form, close)
			form.querySelector('input, select, textarea, button.bp-primary')?.focus()
		})
	}

	async function runStepAction(view, action, recordItem = null) {
		const caseId = Number(view?.caseId || recordItem?.caseId || state.currentCase?.id || 0)
		const recordId = Number(view?.recordId || recordItem?.id || 0)
		if (action.type === 'OPEN_VIEW') { await ensureCaseOpen(caseId); if (state.view !== 'case-detail') return; render(); openCaseTab(action.view || 'overview'); return }
		if (action.type === 'SURCHARGE_CHECK') { await showSuggestions(caseId); return }
		if (action.type === 'CASE_CLOSE') { await ensureCaseOpen(caseId); render(); await showClosure(caseId); return }
		if (action.type === 'SCHEDULE' && (action.openForm || action.schedulePresetKey)) {
			const label = action.schedulePresetKey ? presetLabel(action.schedulePresetKey, meta()) : ''
			await ctx.showRecordForm('schedule', caseId, null, { title: label && label !== action.schedulePresetKey ? label : '', data: { ...(action.schedulePresetKey ? { schedulePresetKey: action.schedulePresetKey } : {}), link: { source: 'PROCESS', parentRecordId: recordId } } })
			return
		}
		const input = {}
		if (action.type === 'DEREGISTRATIONS') {
			if (!state.deregistrationTemplates?.length) state.deregistrationTemplates = await api(ctx.deregistrationTemplateUrl(), { feedback: false })
			const existing = new Set((state.records.deregistration || []).filter((item) => Number(item.caseId) === caseId).map((item) => item.data?.templateKey))
			const pension = state.process?.attributes?.pension === '1'
			const preselected = new Set([...(action.templateKeys || []), ...(pension && (action.templateKeys || []).includes('RENTENSERVICE') ? ['RENTENSERVICE'] : [])])
			const options = (state.deregistrationTemplates || []).filter((template) => template.active).map((template) => `<label class="bp-inline-check"><input type="checkbox" name="templateKeys" value="${esc(template.key)}" ${preselected.has(template.key) && !existing.has(template.key) ? 'checked' : ''} ${existing.has(template.key) ? 'disabled' : ''}> ${esc(template.name)}${existing.has(template.key) ? ' <small>(bereits angelegt)</small>' : ''}</label>`).join('')
			const result = await dialog('Abmeldungen anlegen', `<p class="bp-muted">Für jede ausgewählte Stelle wird ein Abmeldeentwurf angelegt. Empfänger, Übertragungsweg und Versand werden anschließend im Reiter „Abmeldungen“ ergänzt.</p><div class="bp-check-list">${options}</div>`, 'Entwürfe anlegen', { collect: (form) => [...form.querySelectorAll('input[name="templateKeys"]:checked')].map((input) => input.value) })
			if (!result) return
			if (!result.length) { notifyWarning('Bitte mindestens eine Abmeldung auswählen.'); return }
			input.templateKeys = result
		}
		if (action.type === 'DOCUMENT_BUNDLE') {
			const available = await api(ctx.actionsUrl(recordId), { feedback: false })
			const decorated = (available.actions || []).find((entry) => entry.key === action.key)
			const schedules = decorated?.scheduleOptions || []
			if (schedules.length > 1 && !decorated.selectedScheduleId) {
				const choice = await dialog('Trauerfeier auswählen', `<label><span>Für welche Trauerfeier sollen die Dokumente erzeugt werden?</span><select name="scheduleId" required><option value="">Bitte auswählen</option>${schedules.map((entry) => `<option value="${entry.id}">${esc(`${entry.title} · ${entry.displayDate} · ${entry.displayTime} Uhr`)}</option>`).join('')}</select></label>`, 'Dokumente erzeugen', { narrow: true })
				if (!choice) return
				input.scheduleId = Number(choice.get('scheduleId'))
			} else if (decorated?.selectedScheduleId) input.scheduleId = Number(decorated.selectedScheduleId)
		}
		if (action.type === 'EMAIL_DRAFT') {
			const recipient = await promptAction('Empfänger des E-Mail-Entwurfs (optional). Der Text wird aus der Vorlage mit den Falldaten gefüllt.', '', { title: action.label, label: 'Empfänger (E-Mail)', required: false })
			if (recipient === null) return
			input.recipient = recipient
		}
		if (action.type === 'CONTACT_ACTIVITY') {
			const contact = await promptAction('Mit wem wurde gesprochen?', '', { title: action.label, label: 'Kontakt / Organisation' })
			if (contact === null) return
			const description = await promptAction('Ergebnis kurz dokumentieren.', '', { title: action.label, label: 'Notiz', multiline: true })
			if (description === null) return
			input.contact = contact; input.description = description
		}
		// 0.77.0: Marker mit Pflichtnotiz – die Notiz wird vor dem Setzen abgefragt.
		if (action.type === 'LABEL' && action.labelMode !== 'REMOVE') {
			const label = (meta().labels || []).find((entry) => entry.key === action.labelKey)
			if (label?.noteMode === 'REQUIRED') {
				const note = await promptAction(`Zum Marker „${label.name}“ ist eine Notiz erforderlich.`, '', { title: action.label, label: label.noteHint || 'Notiz', multiline: true })
				if (note === null) return
				input.note = note
			}
		}
		if (action.type === 'CASE_STATUS' && !await confirmAction(`Aktion „${action.label}“ ausführen?`, { title: 'Ablauf', confirmLabel: 'Ausführen' })) return
		try {
			const result = await api(ctx.executeActionUrl(recordId, action.key), { method: 'POST', feedback: false, loadingLabel: `${action.label} …`, body: new URLSearchParams({ input: JSON.stringify(input) }) })
			await afterProcessChange(`„${action.label}“ wurde ausgeführt.`)
			if (action.type === 'DEREGISTRATIONS') {
				const created = result.result?.created?.length || 0
				const failed = result.result?.failed || []
				if (failed.length) notifyWarning(`Nicht angelegt: ${failed.map((entry) => entry.message).join(' · ')}`)
				if (created) notifySuccess(`${created} Abmeldeentwurf/-entwürfe angelegt. Bitte im Reiter „Abmeldungen“ Empfänger ergänzen und versenden.`)
			}
			if (['DOCUMENT', 'DOCUMENT_BUNDLE'].includes(action.type)) ctx.showActionDocumentResult?.(result)
		} catch (error) {
			notifyError(error.message)
		}
	}

	async function showSuggestions(caseId) {
		let result
		try { result = await api(`${processUrl(caseId)}/suggestions`, { feedback: false, loadingLabel: 'Leistungen werden geprüft …' }) } catch (error) { notifyError(error.message); return }
		const rows = (result.items || []).map((item) => `<tr class="${item.present ? 'is-done' : ''}"><td>${item.present ? '✓' : '○'}</td><td><b>${esc(item.label)}</b><br><small>${esc(item.reason)}</small></td><td>${item.articleNumber ? `${esc(item.articleNumber)} · ${esc(item.articleName)}` : (item.missingArticle ? '<span class="bp-warning">Zuschlagsstaffel ohne Katalogleistung – bitte im Customizing hinterlegen</span>' : '')}</td><td>${item.present ? 'bereits erfasst' : (item.articleId ? `<button type="button" class="bp-secondary" data-suggestion-article="${item.articleId}" data-article-number="${esc(item.articleNumber)}">In Leistungen öffnen</button>` : (item.kind === 'INCOMING' ? '<button type="button" class="bp-secondary" data-suggestion-view="finances">Eingangsrechnungen öffnen</button>' : ''))}</td></tr>`).join('')
		const body = `<p class="bp-muted">${result.openCount ? `${result.openCount} Hinweis(e) sind noch offen.` : 'Keine offenen Hinweise – alles erfasst.'} ${result.orderFrozen ? 'Da der Auftrag bereits erteilt ist, werden Ergänzungen als begründeter Vertragsnachtrag erfasst.' : ''}</p>${rows ? `<div class="bp-table-scroll"><table class="bp-process-suggestions"><thead><tr><th></th><th>Hinweis</th><th>Katalogleistung</th><th></th></tr></thead><tbody>${rows}</tbody></table></div>` : '<p class="bp-empty">Keine Zuschläge oder fehlenden Leistungen erkannt.</p>'}`
		await dialog('Leistungs- und Zuschlagsprüfung', body, '', {
			eyebrow: 'Umsatzsicherung', hideCancel: false,
			onOpen: (form, close) => {
				form.querySelectorAll('[data-suggestion-article]').forEach((button) => button.addEventListener('click', async () => {
					close(null)
					state.caseTab = 'services'
					await ctx.loadCommercial?.()
					state.serviceCatalogOpen = true
					state.serviceQuery = button.dataset.articleNumber || ''
					render()
				}))
				form.querySelectorAll('[data-suggestion-view]').forEach((button) => button.addEventListener('click', () => { close(null); openCaseTab(button.dataset.suggestionView) }))
			},
		})
	}

	async function showClosure(caseId) {
		let check
		try { check = await api(`${processUrl(caseId)}/closure`, { feedback: false }) } catch (error) { notifyError(error.message); return }
		if (check.alreadyClosed) { notifySuccess('Der Fall ist bereits abgeschlossen.'); return }
		const blockers = (check.blockers || []).map((item) => `<li>${esc(item)}</li>`).join('')
		const warnings = (check.warnings || []).map((item) => `<li>${esc(item)}</li>`).join('')
		const body = `${blockers ? `<aside class="bp-compliance-note"><b>Noch offen</b><ul>${blockers}</ul></aside>` : '<p><b>Alle Voraussetzungen sind erfüllt.</b></p>'}${warnings ? `<aside class="bp-muted"><b>Hinweise</b><ul>${warnings}</ul></aside>` : ''}${check.closable ? '<label><span>Abschlussnotiz (optional)</span><input name="note" placeholder="z. B. Ablauf vollständig abgeschlossen"></label>' : ''}`
		const result = await dialog('Fall abschließen', body, check.closable ? 'Fall abschließen' : '', { eyebrow: 'Abschlussprüfung' })
		if (!result || !check.closable) return
		try {
			state.process = await api(`${processUrl(caseId)}/close`, { method: 'POST', feedback: false, body: new URLSearchParams({ note: result.get('note') || '' }) })
			await afterProcessChange('Der Fall wurde abgeschlossen.')
		} catch (error) { notifyError(error.message) }
	}

	async function showSkip(runId, required) {
		const view = findStep(runId)
		const reason = await promptAction(required ? 'Pflichtschritt überspringen: Bitte kurz begründen (wird im Fall-Verlauf protokolliert).' : 'Optional: Warum entfällt dieser Schritt?', '', { title: `„${view?.title || 'Schritt'}“ entfällt`, label: 'Begründung', required })
		if (reason === null) return
		try {
			state.process = await api(runUrl(runId, 'skip'), { method: 'POST', feedback: false, body: new URLSearchParams({ reason }) })
			await afterProcessChange('Schritt als „entfällt“ markiert.')
		} catch (error) { notifyError(error.message) }
	}

	function bindProcess() {
		root.querySelectorAll('[data-process-phase]').forEach((button) => button.addEventListener('click', () => {
			state.processPhaseOpen = Number(button.dataset.processPhase)
			state.processPhaseToggles = { [state.processPhaseOpen]: true }
			state.processPhaseScroll = true
			if (state.caseTab !== 'task') openCaseTab('task'); else render()
		}))
		// Nur echte Klicks merken (das toggle-Ereignis feuert auch beim Rendern geöffneter Phasen).
		root.querySelectorAll('[data-process-phase-details] > summary').forEach((summary) => summary.addEventListener('click', () => {
			const details = summary.parentElement
			state.processPhaseToggles = { ...(state.processPhaseToggles || {}), [Number(details.dataset.processPhaseDetails)]: !details.open }
		}))
		root.querySelectorAll('[data-process-phase-open-tab]').forEach((button) => button.addEventListener('click', () => { state.processPhaseOpen = 0; state.processPhaseToggles = {}; state.processPanelOpen = true; openCaseTab(button.dataset.processPhaseOpenTab) }))
		root.querySelectorAll('[data-process-reset-phases]').forEach((button) => button.addEventListener('click', () => { state.processPhaseOpen = 0; state.processPhaseToggles = {}; state.processPanelOpen = true }, { capture: true }))
		// 0.88.1: Auf- und Zuklappen von Ablauf (Aufgaben) und Terminschritten merken.
		root.querySelector('#process-panel-details > summary')?.addEventListener('click', (event) => { const details = event.currentTarget.parentElement; state.processPanelOpen = !details.open; if (details.open) state.processPhaseOpen = 0 })
		root.querySelector('#process-schedule-details > summary')?.addEventListener('click', (event) => { state.processScheduleOpen = !event.currentTarget.parentElement.open })
		if (state.caseTab === 'task' && state.processPhaseScroll && Number(state.processPhaseOpen || 0)) requestAnimationFrame(() => root.querySelector(`[data-process-phase-details="${Number(state.processPhaseOpen)}"]`)?.scrollIntoView({ block: 'start' }))
		state.processPhaseScroll = false
		root.querySelector('#process-hide-done')?.addEventListener('change', (event) => { state.processHideDone = event.target.checked; globalThis.localStorage?.setItem('bestatter:process-hide-done', event.target.checked ? '1' : '0'); render() })
		root.querySelectorAll('[data-process-complete]').forEach((button) => button.addEventListener('click', async () => {
			button.disabled = true
			try { state.process = await api(runUrl(button.dataset.processComplete, 'complete'), { method: 'POST', feedback: false }); await afterProcessChange('Schritt erledigt.') } catch (error) { button.disabled = false; notifyError(error.message) }
		}))
		root.querySelectorAll('[data-process-skip]').forEach((button) => button.addEventListener('click', () => showSkip(button.dataset.processSkip, button.dataset.required === '1')))
		root.querySelectorAll('[data-process-reopen]').forEach((button) => button.addEventListener('click', async () => {
			try { state.process = await api(runUrl(button.dataset.processReopen, 'reopen'), { method: 'POST', feedback: false }); await afterProcessChange('Schritt wieder geöffnet.') } catch (error) { notifyError(error.message) }
		}))
		root.querySelectorAll('[data-process-open-task]').forEach((button) => button.addEventListener('click', async () => {
			const id = Number(button.dataset.processOpenTask)
			let item = (state.records.task || []).find((record) => Number(record.id) === id)
			if (!item) { await ctx.loadRecordType('task', state.currentCase.id); item = (state.records.task || []).find((record) => Number(record.id) === id) }
			if (!item) { notifyWarning('Die Aufgabe ist nur für die zuständige Person bzw. Rolle sichtbar.'); return }
			ctx.showRecordForm('task', item.caseId || state.currentCase.id, item)
		}))
		root.querySelectorAll('[data-process-action]').forEach((button) => button.addEventListener('click', async () => {
			const view = findStep(button.dataset.runId)
			const action = view?.actions?.find((entry) => entry.key === button.dataset.processAction)
			if (view && action) await runStepAction(view, action)
		}))
		root.querySelectorAll('[data-process-open-view]').forEach((button) => button.addEventListener('click', () => openCaseTab(button.dataset.processOpenView)))
		root.querySelector('#open-closure')?.addEventListener('click', () => showClosure(state.currentCase.id))
		root.querySelector('#process-add-step-button')?.addEventListener('click', async () => {
			const stepId = Number(root.querySelector('#process-add-step')?.value || 0)
			if (!stepId) { notifyWarning('Bitte einen Schritt auswählen.'); return }
			try { state.process = await api(`${processUrl(state.currentCase.id)}/steps/${stepId}`, { method: 'POST', feedback: false }); await afterProcessChange('Schritt hinzugefügt.') } catch (error) { notifyError(error.message) }
		})
		const attributesForm = root.querySelector('#process-attributes')
		if (attributesForm) {
			const amount = attributesForm.elements.depositAmount
			amount?.addEventListener('input', () => { attributesForm.elements.depositReceivedAt.disabled = !(euroToCents(amount.value) > 0) })
			attributesForm.onsubmit = async (event) => {
				event.preventDefault()
				const status = attributesForm.querySelector('.bp-save-state')
				const cents = euroToCents(amount.value)
				if (cents === null) { status.textContent = 'Bitte den Betrag als Zahl angeben, z. B. 1.500,00.'; status.dataset.state = 'error'; return }
				status.textContent = 'Wird gespeichert …'; status.dataset.state = 'saving'
				try {
					state.process = await api(`${processUrl(state.currentCase.id)}/attributes`, { method: 'PUT', feedback: false, body: new URLSearchParams({ attributes: JSON.stringify({ ceremony: attributesForm.elements.ceremony.value, pension: attributesForm.elements.pension.value, depositAmount: amount.value, depositReceivedAt: cents > 0 ? attributesForm.elements.depositReceivedAt.value : '' }), expectedUpdatedAt: state.currentCase.updatedAt || '' }) })
					await afterProcessChange('Fallmerkmale gespeichert – der Ablauf wurde angepasst.')
				} catch (error) { status.textContent = error.message; status.dataset.state = 'error' }
			}
		}
	}

	// ------------------------------------------------------------ Customizing: Ablauf-Editor

	function selectOptions(map, selected) {
		return Object.entries(map).map(([key, label]) => `<option value="${esc(key)}" ${String(selected ?? '') === key ? 'selected' : ''}>${esc(label)}</option>`).join('')
	}

	function processAdminView() {
		const admin = state.processAdmin
		if (!admin) return '<section class="bp-panel"><p class="bp-muted">Abläufe werden geladen …</p></section>'
		const m = meta()
		if (!(admin.templates || []).some((template) => template.key === state.processAdminKey)) state.processAdminKey = admin.templates?.[0]?.key || ''
		if (!state.processDraft || state.processDraft.key !== state.processAdminKey) resetDraft()
		const draft = state.processDraft
		const nav = (admin.templates || []).map((template) => `<button type="button" class="${template.key === state.processAdminKey ? 'active' : ''}" data-process-template="${esc(template.key)}"><span>${esc(template.name)}</span><small>${template.active ? 'aktiv' : 'deaktiviert'} · ${template.steps.filter((step) => !step.archived).length} Schritte${template.origin === 'BUNDLED' ? ' · mitgeliefert' : ''}</small></button>`).join('')
		let editor = '<p class="bp-empty">Keine Ablaufvorlage ausgewählt.</p>'
		if (draft) {
			const steps = draft.steps.filter((step) => !step.archived)
			const warnings = [...new Set([...(state.processDraftDirty ? [] : draft.warnings || []), ...clientWarnings(steps)])]
			const roleInfo = (m.roles || []).map((role) => `<li><b>${esc(role.label)}</b>: ${role.group ? `Gruppe „${esc(role.group)}“ – ${role.groupExists ? (role.members.length ? esc(role.members.join(', ')) : 'noch keine Mitglieder') : `<span class="bp-warning-text">${esc(role.groupHint || 'Gruppe existiert noch nicht')}</span>`}` : 'nicht belegt'}</li>`).join('')
			const phases = Object.entries(m.phases || PHASES).map(([number, label]) => {
				const phaseSteps = steps.map((step, index) => ({ step, index })).filter(({ step }) => Number(step.phase) === Number(number))
				return `<section class="bp-process-phase-column" data-phase-drop="${number}"><h4><span class="bp-phase-number">${number}</span> ${esc(label)} <small>${phaseSteps.length}</small></h4>${phaseSteps.map(({ step, index }) => stepCard(step, index, steps, m)).join('') || '<p class="bp-empty bp-drop-hint">Schritte hierher ziehen oder hinzufügen.</p>'}<button type="button" class="bp-secondary bp-add-step" data-add-step="${number}">+ Schritt</button></section>`
			}).join('')
			editor = `<form id="process-template-form" class="bp-process-editor"><div class="bp-panel-head"><div><p class="bp-eyebrow">${draft.origin === 'BUNDLED' ? 'Mitgelieferte Ablaufvorlage' : 'Eigene Ablaufvorlage'}</p><h3>${esc(draft.name)}</h3></div><label class="bp-inline-check bp-toggle-active"><input type="checkbox" name="active" ${draft.active ? 'checked' : ''}> Aktiv – gilt für alle ab jetzt begonnenen Fälle</label></div>
				<div class="bp-form-grid bp-process-header"><label><span>Name</span><input name="name" value="${esc(draft.name)}" required></label><label class="bp-span-2"><span>Beschreibung</span><textarea name="description" rows="2">${esc(draft.description || '')}</textarea></label></div>
				<div class="bp-process-toolbar"><button class="bp-primary" id="save-process-template" ${state.processDraftDirty ? '' : 'disabled'}>Speichern</button><button type="button" class="bp-secondary" id="discard-process-template" ${state.processDraftDirty ? '' : 'disabled'}>Änderungen verwerfen</button><button type="button" class="bp-secondary" id="simulate-process">Probelauf</button><button type="button" class="bp-secondary" id="export-process">CSV exportieren</button><label class="bp-secondary bp-file-button">CSV importieren<input type="file" id="import-process" accept=".csv,text/csv" hidden></label><button type="button" class="bp-secondary" id="export-process-json" title="Vollständige Vorlage mit allen Knöpfen – für Einrichtungspakete und die Übertragung in eine andere Installation">JSON exportieren</button><label class="bp-secondary bp-file-button" title="Ersetzt die Schrittliste dieser Vorlage verlustfrei, einschließlich Knöpfen">JSON importieren<input type="file" id="import-process-json" accept=".json,application/json" hidden></label><button type="button" class="bp-secondary" id="copy-process">Als Kopie anlegen</button>${draft.origin === 'BUNDLED' ? '<button type="button" class="bp-secondary" id="restore-process">Auslieferungsstand wiederherstellen</button>' : '<button type="button" class="bp-danger" id="delete-process">Löschen</button>'}${state.processDraftDirty ? '<span class="bp-status">Ungespeicherte Änderungen</span>' : ''}</div>
				${warnings.length ? `<aside class="bp-compliance-note"><b>Prüfhinweise</b><ul>${warnings.map((warning) => `<li>${esc(warning)}</li>`).join('')}</ul></aside>` : ''}
				${(m.unusedPresets || []).length ? `<p class="bp-muted bp-process-info">Hinweis: Auf die Terminart(en) ${esc(m.unusedPresets.join(', '))} reagiert noch kein aktiver Schritt.</p>` : ''}
				<details class="bp-process-roles"><summary>Rollen und zuständige Nextcloud-Gruppen</summary><ul>${roleInfo}</ul><p class="bp-muted">Die Gruppen werden unter Administration → Systemprüfung → Installationsparameter zugeordnet. Mitglieder einer Rolle sehen die Aufgaben ihrer Rolle.</p></details>
				<div class="bp-process-phase-board">${phases}</div></form>`
		}
		return `<section class="bp-panel bp-custom-table bp-process-admin"><div class="bp-panel-head"><div><h3>Abläufe</h3><p class="bp-muted">Ein Ablauf beschreibt je Schritt in Klartext: wann er entsteht, für welche Fälle er gilt, wer zuständig ist, bis wann er fällig ist und welche Knöpfe er anbietet. Schritte lassen sich per Ziehen zwischen den Phasen verschieben. <b>Bestandsschutz:</b> Neue oder neu aktivierte Schritte entstehen nur in Fällen, deren Ablauf danach beginnt; laufende Fälle können sie gezielt über „Schritt hinzufügen“ übernehmen.</p></div><div class="bp-head-actions"><button class="bp-primary" id="new-process-template">+ Ablaufvorlage</button></div></div><div class="bp-value-list-workspace bp-process-workspace"><nav class="bp-value-list-nav" aria-label="Ablaufvorlagen">${nav}</nav><div class="bp-value-list-editor">${editor}</div></div></section>`
	}

	function stepCard(step, index, steps, m) {
		const badges = [
			step.required ? '<span class="bp-badge">Pflicht</span>' : '',
			step.priority === 'KRITISCH' ? '<span class="bp-badge bp-badge-critical">kritisch</span>' : (step.priority === 'HOCH' ? '<span class="bp-badge bp-badge-high">hoch</span>' : ''),
			step.active === false ? '<span class="bp-badge bp-badge-muted">deaktiviert</span>' : '',
			step.role ? `<span class="bp-badge bp-badge-role">${esc(m.roleLabels?.[step.role] || step.role)}</span>` : '',
		].join('')
		return `<article class="bp-step-card ${step.active === false ? 'is-inactive' : ''}" draggable="true" data-step-index="${index}" tabindex="0" aria-label="${esc(step.title)}"><p class="bp-step-card-title"><b>${esc(step.title)}</b>${badges}</p><p class="bp-step-sentence">${esc(stepSentence(step, m, steps))}</p><div class="bp-row-actions"><button type="button" class="bp-secondary" data-edit-step="${index}">Bearbeiten</button><button type="button" class="bp-secondary" data-move-step="${index}" data-direction="-1" aria-label="Nach oben">↑</button><button type="button" class="bp-secondary" data-move-step="${index}" data-direction="1" aria-label="Nach unten">↓</button><button type="button" class="bp-secondary" data-copy-step="${index}">Duplizieren</button><button type="button" class="bp-danger" data-remove-step="${index}">Entfernen</button></div></article>`
	}

	// 0.76.0: Auswahl von Marker und Wert (Listen-Marker) im Schritt- und Knopf-Editor.
	/** 0.80.0: Dokumentarten für „Dokument eingegangen“ (Auslöser und Erledigt-Bedingung). */
	function documentKindSelectOptions(m, selected, emptyLabel = '– bitte wählen –') {
		return `<option value="">${esc(emptyLabel)}</option>${(m.documentKinds || []).map((kind) => `<option value="${esc(kind.key)}" ${kind.key === selected ? 'selected' : ''}>${esc(kind.name)}${kind.active ? '' : ' (inaktiv)'}</option>`).join('')}`
	}
	function labelSelectOptions(m, selected, emptyLabel = '– bitte wählen –') {
		return `<option value="">${esc(emptyLabel)}</option>${(m.labels || []).map((label) => `<option value="${esc(label.key)}" ${label.key === selected ? 'selected' : ''}>${esc(label.name)}${label.active ? '' : ' (inaktiv)'}${label.kind === 'LIST' ? ' …' : ''}</option>`).join('')}`
	}
	function labelValueOptions(m, labelKey, selected, emptyLabel = 'beliebiger Wert') {
		const label = (m.labels || []).find((entry) => entry.key === labelKey)
		if (!label || label.kind !== 'LIST') return ''
		return `<option value="">${esc(emptyLabel)}</option>${(label.values || []).map((value) => `<option value="${esc(value.key)}" ${value.key === selected ? 'selected' : ''}>${esc(value.name)}</option>`).join('')}`
	}
	function syncLabelValueSelect(m, labelSelect, valueSelect) {
		if (!labelSelect || !valueSelect) return
		if (valueSelect.dataset.labelFor === labelSelect.value) return
		valueSelect.dataset.labelFor = labelSelect.value
		const options = labelValueOptions(m, labelSelect.value, valueSelect.value)
		valueSelect.innerHTML = options
		valueSelect.disabled = !options
	}

	function actionEditor(action, index, m) {
		const docOptions = (m.documentTemplates || []).map((entry) => `<option value="${esc(entry.key)}" ${entry.key === action.templateKey ? 'selected' : ''}>${esc(entry.name)}</option>`).join('')
		const docChecks = (m.documentTemplates || []).map((entry) => `<label class="bp-inline-check"><input type="checkbox" data-action-templates value="${esc(entry.key)}" ${(action.templateKeys || []).includes(entry.key) ? 'checked' : ''}> ${esc(entry.name)}</label>`).join('')
		const deregChecks = (m.deregistrationTemplates || []).map((entry) => `<label class="bp-inline-check"><input type="checkbox" data-action-deregs value="${esc(entry.key)}" ${(action.templateKeys || []).includes(entry.key) ? 'checked' : ''}> ${esc(entry.name)}</label>`).join('')
		const presets = (m.schedulePresets || []).map((entry) => `<option value="${esc(entry.key)}" ${entry.key === action.schedulePresetKey ? 'selected' : ''}>${esc(entry.label)}</option>`).join('')
		const type = action.type || 'DOCUMENT'
		const show = (types) => (types.includes(type) ? '' : 'hidden')
		return `<fieldset class="bp-action-editor" data-action-index="${index}"><legend>Knopf ${index + 1}</legend><div class="bp-form-grid">
			<label><span>Art</span><select data-action-type>${selectOptions(m.actionTypes || DEFAULT_META.actionTypes, type)}</select></label>
			<label><span>Beschriftung des Knopfs</span><input data-action-label value="${esc(action.label || '')}" placeholder="z. B. Sterbefallanzeige erzeugen"></label>
			<label ${show(['DOCUMENT'])} data-when="DOCUMENT"><span>Dokumentvorlage</span><select data-action-template><option value="">– bitte wählen –</option>${docOptions}</select></label>
			<label ${show(['SCHEDULE'])} data-when="SCHEDULE"><span>Terminart</span><select data-action-preset><option value="">Terminart im Formular wählen</option>${presets}</select></label>
			<label ${show(['OPEN_VIEW'])} data-when="OPEN_VIEW"><span>Bereich im Fall</span><select data-action-view>${selectOptions(m.views || {}, action.view || 'overview')}</select></label>
			<label ${show(['TASK', 'REMINDER', 'EMAIL_DRAFT', 'CONTACT_ACTIVITY'])} data-when="TASK REMINDER EMAIL_DRAFT CONTACT_ACTIVITY" class="bp-span-2"><span>Titel / Betreff</span><input data-action-title value="${esc(action.title || '')}" placeholder="Platzhalter z. B. {{case.number}}"></label>
			<label ${show(['TASK', 'REMINDER'])} data-when="TASK REMINDER"><span>Fällig in Tagen</span><input type="number" data-action-offset value="${Number(action.dueOffsetDays || 0)}"></label>
			<label ${show(['LABEL'])} data-when="LABEL"><span>Marker</span><select data-action-label-key>${labelSelectOptions(m, action.labelKey || '')}</select></label>
			<label ${show(['LABEL'])} data-when="LABEL"><span>Aktion</span><select data-action-label-mode>${selectOptions({ SET: 'setzen', REMOVE: 'entfernen' }, action.labelMode || 'SET')}</select></label>
			<label ${show(['LABEL'])} data-when="LABEL" data-label-value-wrap><span>Wert (Listen-Marker)</span><select data-action-label-value>${labelValueOptions(m, action.labelKey || '', action.labelValue || '', '– bitte wählen –')}</select></label>
			<label ${show(['CASE_STATUS'])} data-when="CASE_STATUS"><span>Fallstatus danach</span><select data-action-case-status>${['IN_BEARBEITUNG', 'ABGESCHLOSSEN', 'STORNIERT'].map((value) => `<option ${value === action.caseStatusAfter ? 'selected' : ''}>${value}</option>`).join('')}</select></label>
			<label ${show(['TASK', 'REMINDER', 'EMAIL_DRAFT', 'CONTACT_ACTIVITY'])} data-when="TASK REMINDER EMAIL_DRAFT CONTACT_ACTIVITY" class="bp-span-3"><span>Text / Beschreibung</span><textarea data-action-description rows="4" placeholder="Platzhalter: {{case.number}}, {{case.first_name}}, {{case.last_name}}, {{deposit.amount}}, {{invoice.number}}, {{invoice.amount}}, {{invoice.due_date}}, {{branch.iban}}">${esc(action.description || '')}</textarea></label>
			</div>
			<div ${show(['DOCUMENT_BUNDLE'])} data-when="DOCUMENT_BUNDLE" class="bp-check-list"><span>Vorlagen des Pakets</span>${docChecks}</div>
			<div ${show(['DEREGISTRATIONS'])} data-when="DEREGISTRATIONS" class="bp-check-list"><span>Vorausgewählte Abmeldungen (im Fall änderbar)</span>${deregChecks}</div>
			<div class="bp-row-actions"><label class="bp-inline-check" ${show(['DOCUMENT', 'DOCUMENT_BUNDLE', 'EMAIL_DRAFT', 'TASK', 'REMINDER', 'CONTACT_ACTIVITY', 'DEREGISTRATIONS'])} data-when="DOCUMENT DOCUMENT_BUNDLE EMAIL_DRAFT TASK REMINDER CONTACT_ACTIVITY DEREGISTRATIONS"><input type="checkbox" data-action-repeatable ${action.repeatable ? 'checked' : ''}> mehrfach ausführbar</label><label class="bp-inline-field" data-when="DOCUMENT DOCUMENT_BUNDLE EMAIL_DRAFT TASK REMINDER CONTACT_ACTIVITY DEREGISTRATIONS CASE_STATUS" ${show(['DOCUMENT', 'DOCUMENT_BUNDLE', 'EMAIL_DRAFT', 'TASK', 'REMINDER', 'CONTACT_ACTIVITY', 'DEREGISTRATIONS', 'CASE_STATUS'])}><span>Schritt danach</span><select data-action-progress>${selectOptions({ '': 'unverändert', IN_BEARBEITUNG: 'in Bearbeitung', ERLEDIGT: 'erledigt (ein Klick hakt ab)' }, action.sourceStatusAfter || '')}</select></label><button type="button" class="bp-danger" data-remove-action="${index}">Knopf entfernen</button></div>
			<input type="hidden" data-action-key value="${esc(action.key || '')}"></fieldset>`
	}

	function collectActions(form) {
		return [...form.querySelectorAll('[data-action-index]')].map((fieldset) => {
			const type = fieldset.querySelector('[data-action-type]').value
			const action = { key: fieldset.querySelector('[data-action-key]').value, type, label: fieldset.querySelector('[data-action-label]').value.trim(), repeatable: fieldset.querySelector('[data-action-repeatable]')?.checked || false }
			if (type === 'DOCUMENT') action.templateKey = fieldset.querySelector('[data-action-template]').value
			if (type === 'DOCUMENT_BUNDLE') action.templateKeys = [...fieldset.querySelectorAll('[data-action-templates]:checked')].map((input) => input.value)
			if (type === 'DEREGISTRATIONS') action.templateKeys = [...fieldset.querySelectorAll('[data-action-deregs]:checked')].map((input) => input.value)
			const progress = fieldset.querySelector('[data-action-progress]')?.value || ''
			if (progress && !['OPEN_VIEW', 'SURCHARGE_CHECK', 'CASE_CLOSE', 'SCHEDULE'].includes(type)) action.sourceStatusAfter = progress
			if (type === 'SCHEDULE') { action.schedulePresetKey = fieldset.querySelector('[data-action-preset]').value; action.openForm = true }
			if (type === 'OPEN_VIEW') action.view = fieldset.querySelector('[data-action-view]').value
			if (['TASK', 'REMINDER', 'EMAIL_DRAFT', 'CONTACT_ACTIVITY'].includes(type)) { action.title = fieldset.querySelector('[data-action-title]').value.trim(); action.description = fieldset.querySelector('[data-action-description]').value }
			if (['TASK', 'REMINDER'].includes(type)) action.dueOffsetDays = Number(fieldset.querySelector('[data-action-offset]').value || 0)
			if (type === 'CASE_STATUS') action.caseStatusAfter = fieldset.querySelector('[data-action-case-status]').value
			if (type === 'LABEL') { action.labelKey = fieldset.querySelector('[data-action-label-key]').value; action.labelMode = fieldset.querySelector('[data-action-label-mode]').value; action.labelValue = action.labelMode === 'SET' ? fieldset.querySelector('[data-action-label-value]').value : '' }
			if (!action.label) action.label = (meta().actionTypes || {})[type] || type
			return action
		})
	}

	async function editStep(index, phase = 1) {
		const draft = state.processDraft
		const steps = draft.steps.filter((step) => !step.archived)
		const isNew = index === null
		const step = isNew ? { key: '', phase, title: '', description: '', trigger: { type: 'CASE_CREATED', presetKeys: [], stepKey: '', once: false }, dueOffsetDays: 0, dueReference: 'TRIGGER', role: '', priority: 'NORMAL', required: false, applies: { scope: 'ALL', ceremony: 'ANY', pension: 'ANY', deposit: 'ANY' }, complete: { type: 'NONE', templateKey: '', presetKey: '' }, actions: [], active: true } : JSON.parse(JSON.stringify(steps[index]))
		step.trigger = { templateKey: '', field: '', value: '', status: '', dateRef: '', days: 0, leadDays: 0, label: '', documentKind: '', ...step.trigger }
		step.complete = { field: '', value: '', status: '', label: '', documentKind: '', ...step.complete }
		step.applies = { label: '', labelValue: '', ...step.applies }
		const m = meta()
		const presets = m.schedulePresets || []
		const presetChecks = presets.map((entry) => `<label class="bp-inline-check"><input type="checkbox" name="presetKeys" value="${esc(entry.key)}" ${(step.trigger.presetKeys || []).includes(entry.key) ? 'checked' : ''}> ${esc(entry.label)}</label>`).join('')
		const otherSteps = steps.filter((entry) => entry.key !== step.key || isNew).map((entry) => `<option value="${esc(entry.key)}" ${entry.key === step.trigger.stepKey ? 'selected' : ''}>${esc(`${entry.phase} · ${entry.title}`)}</option>`).join('')
		const roleLabels = Object.fromEntries(Object.entries(m.roleLabels || DEFAULT_META.roleLabels).map(([key, label]) => { const role = (m.roles || []).find((entry) => entry.key === key); return [key, role?.group ? `${label} (Gruppe „${role.group}“)` : label] }))
		const docOptions = (m.documentTemplates || []).map((entry) => `<option value="${esc(entry.key)}" ${entry.key === step.complete.templateKey ? 'selected' : ''}>${esc(entry.name)}</option>`).join('')
		const presetOptions = presets.map((entry) => `<option value="${esc(entry.key)}" ${entry.key === step.complete.presetKey ? 'selected' : ''}>${esc(entry.label)}</option>`).join('')
		const keyOptions = (list, selected, labelKey = 'name') => (list || []).map((entry) => `<option value="${esc(entry.key)}" ${entry.key === selected ? 'selected' : ''}>${esc(entry[labelKey] || entry.key)}</option>`).join('')
		const triggerDocument = DOCUMENT_TRIGGERS.includes(step.trigger.type) ? step.trigger.templateKey : ''
		const triggerDereg = DEREGISTRATION_TRIGGERS.includes(step.trigger.type) ? step.trigger.templateKey : ''
		const completeDereg = DEREGISTRATION_TRIGGERS.includes(step.complete.type) ? step.complete.templateKey : ''
		const fieldValues = (field) => { const listKey = ctx.listMap?.[field]; const list = (state.customizing || []).find((entry) => entry.key === listKey); return (list?.items || []).map((item) => `<option value="${esc(item.value)}">${esc(item.label)}</option>`).join('') }
		const fieldSelect = (name, selected) => `<select name="${name}"><option value="">– bitte wählen –</option>${keyOptions(m.caseFields, selected, 'label')}</select>`
		const dateRefs = m.dateReferences || DEFAULT_META.dateReferences
		const statuses = m.caseStatuses || DEFAULT_META.caseStatuses
		const body = `<p class="bp-step-sentence" id="step-sentence-preview"></p>
			<fieldset><legend>Was ist zu tun?</legend><div class="bp-form-grid"><label class="bp-span-2"><span>Bezeichnung</span><input name="title" value="${esc(step.title)}" required maxlength="200"></label><label><span>Phase</span><select name="phase">${selectOptions(m.phases || PHASES, String(step.phase))}</select></label><label class="bp-span-3"><span>Beschreibung (erscheint in der Aufgabe)</span><textarea name="description" rows="2">${esc(step.description || '')}</textarea></label></div></fieldset>
			<fieldset><legend>Für welche Fälle?</legend><div class="bp-form-grid"><label><span>Gilt für</span><select name="scope">${selectOptions(m.scopes || DEFAULT_META.scopes, step.applies.scope)}</select></label><label><span>Trauerfeier</span><select name="ceremony">${selectOptions({ ANY: 'egal', YES: 'nur mit Trauerfeier', NO: 'nur ohne Trauerfeier' }, step.applies.ceremony)}</select></label><label><span>Rente</span><select name="pension">${selectOptions({ ANY: 'egal', YES: 'nur bei Rentenbezug' }, step.applies.pension)}</select></label><label><span>Anzahlung</span><select name="deposit">${selectOptions({ ANY: 'egal', YES: 'nur mit vereinbarter Anzahlung' }, step.applies.deposit)}</select></label><label><span>Marker</span><select name="appliesLabel">${labelSelectOptions(m, step.applies.label, 'egal')}</select></label><label data-label-value-wrap><span>mit Wert</span><select name="appliesLabelValue">${labelValueOptions(m, step.applies.label, step.applies.labelValue)}</select></label></div></fieldset>
			<fieldset><legend>Wann entsteht der Schritt?</legend><div class="bp-form-grid"><label><span>Startet, wenn</span><select name="triggerType">${selectOptions(m.triggers || DEFAULT_META.triggers, step.trigger.type)}</select></label><label data-trigger-when="STEP_DONE"><span>Vorheriger Schritt</span><select name="stepKey"><option value="">– bitte wählen –</option>${otherSteps}</select></label><label class="bp-inline-check" data-trigger-when="SCHEDULE_CONFIRMED SCHEDULE_DONE SCHEDULE_CHANGED SCHEDULE_CANCELLED"><input type="checkbox" name="once" ${step.trigger.once ? 'checked' : ''}> nur einmal je Fall (erster passender Termin)</label>
				<label data-trigger-when="DOCUMENT_CREATED DOCUMENT_FINAL DOCUMENT_SENT"><span>Dokument</span><select name="triggerDocument"><option value="">– bitte wählen –</option>${keyOptions(m.documentTemplates, triggerDocument)}</select></label>
				<label data-trigger-when="DEREGISTRATION_SENT DEREGISTRATION_CONFIRMED"><span>Abmeldung (Stelle)</span><select name="triggerDereg"><option value="">jede Abmeldung</option>${keyOptions(m.deregistrationTemplates, triggerDereg)}</select></label>
				<label data-trigger-when="FIELD_SET"><span>Stammdatenfeld</span>${fieldSelect('triggerField', step.trigger.field)}</label>
				<label data-trigger-when="FIELD_SET"><span>mit Wert (leer = beliebig)</span><input name="triggerValue" list="trigger-field-values" value="${esc(step.trigger.value)}" maxlength="200"><datalist id="trigger-field-values">${fieldValues(step.trigger.field)}</datalist></label>
				<label data-trigger-when="DOCUMENT_RECEIVED"><span>Dokumentart</span><select name="triggerDocumentKind">${documentKindSelectOptions(m, step.trigger.documentKind)}</select></label>
				<label data-trigger-when="LABEL_SET LABEL_REMOVED"><span>Marker</span><select name="triggerLabel">${labelSelectOptions(m, step.trigger.label)}</select></label>
				<label data-trigger-when="LABEL_SET" data-label-value-wrap><span>mit Wert</span><select name="triggerLabelValue">${labelValueOptions(m, step.trigger.label, LABEL_TRIGGERS.includes(step.trigger.type) ? step.trigger.value : '')}</select></label>
				<label data-trigger-when="CASE_STATUS"><span>Fallstatus</span><select name="triggerStatus"><option value="">– bitte wählen –</option>${selectOptions(statuses, step.trigger.status)}</select></label>
				<label data-trigger-when="DATE_REACHED"><span>Bezugsdatum</span><select name="dateRef"><option value="">– bitte wählen –</option>${selectOptions(dateRefs, step.trigger.dateRef)}</select></label>
				<label data-trigger-when="DATE_REACHED"><span>Tage danach (− = vorher)</span><input type="number" name="dateDays" min="-365" max="3650" value="${step.trigger.type === 'DATE_REACHED' ? Number(step.trigger.days || 0) : 0}"></label>
				<label data-trigger-when="DATE_REACHED"><span>Vorlauf (Tage)</span><input type="number" name="leadDays" min="0" max="365" value="${Number(step.trigger.leadDays || 0)}"></label>
				<label data-trigger-when="INVOICE_OVERDUE"><span>Tage nach dem Zahlungsziel</span><input type="number" name="overdueDays" min="0" max="365" value="${step.trigger.type === 'INVOICE_OVERDUE' ? Number(step.trigger.days || 0) : 1}"></label>
				</div><div class="bp-check-list" data-trigger-when="SCHEDULE_CONFIRMED SCHEDULE_DONE SCHEDULE_CHANGED SCHEDULE_CANCELLED"><span>Terminart(en)</span>${presetChecks}</div><p class="bp-muted" data-trigger-when="DATE_REACHED INVOICE_OVERDUE">Zeitgesteuert: Die App prüft diese Auslöser alle 6 Stunden im Hintergrund (Nextcloud-Cron) und zusätzlich beim Öffnen des Falls. „Datum erreicht“ entsteht auch nach dem Fallabschluss.</p><p class="bp-muted" data-trigger-when="SCHEDULE_CHANGED SCHEDULE_CANCELLED">Reagiert nur auf Termine, die bereits bestätigt waren; „geändert“ heißt: Zeitpunkt oder Ort wurde geändert.</p></fieldset>
			<fieldset><legend>Wer und bis wann?</legend><div class="bp-form-grid"><label><span>Zuständig (Rolle)</span><select name="role">${selectOptions(roleLabels, step.role)}</select></label><label><span>Frist (Tage, negativ = vorher)</span><input type="number" name="dueOffsetDays" min="-60" max="730" value="${Number(step.dueOffsetDays || 0)}"></label><label><span>bezogen auf</span><select name="dueReference">${selectOptions(m.references || DEFAULT_META.references, step.dueReference)}</select></label><label><span>Priorität</span><select name="priority">${selectOptions(m.priorities || DEFAULT_META.priorities, step.priority)}</select></label><label class="bp-inline-check"><input type="checkbox" name="required" ${step.required ? 'checked' : ''}> Pflichtschritt (Fallabschluss erst danach)</label><label class="bp-inline-check"><input type="checkbox" name="active" ${step.active !== false ? 'checked' : ''}> aktiv</label></div></fieldset>
			<fieldset><legend>Erledigt sich selbst, wenn …</legend><div class="bp-form-grid"><label><span>Bedingung</span><select name="completeType">${selectOptions(m.completions || DEFAULT_META.completions, step.complete.type)}</select></label><label data-complete-when="DOCUMENT_CREATED DOCUMENT_FINAL DOCUMENT_SENT"><span>Dokument</span><select name="completeTemplate"><option value="">wie Auslöser / bitte wählen</option>${docOptions}</select></label><label data-complete-when="DEREGISTRATION_SENT DEREGISTRATION_CONFIRMED"><span>Abmeldung (Stelle)</span><select name="completeDereg"><option value="">jede bzw. die auslösende</option>${keyOptions(m.deregistrationTemplates, completeDereg)}</select></label><label data-complete-when="FIELD_SET"><span>Stammdatenfeld</span>${fieldSelect('completeField', step.complete.field)}</label><label data-complete-when="FIELD_SET"><span>mit Wert (leer = beliebig)</span><input name="completeValue" list="complete-field-values" value="${esc(step.complete.value)}" maxlength="200"><datalist id="complete-field-values">${fieldValues(step.complete.field)}</datalist></label><label data-complete-when="DOCUMENT_RECEIVED"><span>Dokumentart</span><select name="completeDocumentKind">${documentKindSelectOptions(m, step.complete.documentKind, 'wie Auslöser / bitte wählen')}</select></label><label data-complete-when="LABEL_SET LABEL_REMOVED"><span>Marker</span><select name="completeLabel">${labelSelectOptions(m, step.complete.label, 'wie Auslöser / bitte wählen')}</select></label><label data-complete-when="LABEL_SET" data-label-value-wrap><span>mit Wert</span><select name="completeLabelValue">${labelValueOptions(m, step.complete.label || step.trigger.label, step.complete.type === 'LABEL_SET' ? step.complete.value : '')}</select></label><label data-complete-when="CASE_STATUS"><span>Fallstatus</span><select name="completeStatus"><option value="">– bitte wählen –</option>${selectOptions(statuses, step.complete.status)}</select></label><label data-complete-when="SCHEDULE_DONE SCHEDULE_CONFIRMED"><span>Terminart</span><select name="completePreset"><option value="">wie Auslöser</option>${presetOptions}</select></label></div><p class="bp-muted">Ist die Bedingung beim Entstehen schon erfüllt, entfällt der Schritt automatisch.</p></fieldset>
			<fieldset><legend>Knöpfe im Schritt</legend><div id="step-actions">${(step.actions || []).map((action, actionIndex) => actionEditor(action, actionIndex, m)).join('')}</div><button type="button" class="bp-secondary" id="add-step-action">+ Knopf</button></fieldset>
			<details><summary>Experte</summary><label><span>Technischer Schlüssel (leer = aus der Bezeichnung)</span><input name="key" value="${esc(step.key)}" pattern="[A-Za-z0-9_-]*" ${isNew ? '' : 'readonly'}></label><p class="bp-muted">Bestehende Schlüssel bleiben unverändert, damit laufende Fälle ihre Aufgaben behalten.</p></details>`
		const collect = (form) => {
			const presetKeys = [...form.querySelectorAll('input[name="presetKeys"]:checked')].map((input) => input.value)
			return {
				...step, key: form.elements.key.value.trim().toUpperCase(), title: form.elements.title.value.trim(), phase: Number(form.elements.phase.value), description: form.elements.description.value.trim(),
				applies: { scope: form.elements.scope.value, ceremony: form.elements.ceremony.value, pension: form.elements.pension.value, deposit: form.elements.deposit.value, label: form.elements.appliesLabel.value, labelValue: form.elements.appliesLabel.value && !form.elements.appliesLabelValue.disabled ? form.elements.appliesLabelValue.value : '' },
				trigger: (() => {
					const type = form.elements.triggerType.value
					return {
						type, presetKeys, stepKey: form.elements.stepKey.value, once: form.elements.once.checked,
						templateKey: DOCUMENT_TRIGGERS.includes(type) ? form.elements.triggerDocument.value : (DEREGISTRATION_TRIGGERS.includes(type) ? form.elements.triggerDereg.value : ''),
						field: type === 'FIELD_SET' ? form.elements.triggerField.value : '', value: type === 'FIELD_SET' ? form.elements.triggerValue.value.trim() : (type === 'LABEL_SET' && !form.elements.triggerLabelValue.disabled ? form.elements.triggerLabelValue.value : ''),
						label: LABEL_TRIGGERS.includes(type) ? form.elements.triggerLabel.value : '',
						documentKind: type === 'DOCUMENT_RECEIVED' ? form.elements.triggerDocumentKind.value : '',
						status: type === 'CASE_STATUS' ? form.elements.triggerStatus.value : '', dateRef: type === 'DATE_REACHED' ? form.elements.dateRef.value : '',
						days: type === 'DATE_REACHED' ? Number(form.elements.dateDays.value || 0) : (type === 'INVOICE_OVERDUE' ? Number(form.elements.overdueDays.value || 0) : 0),
						leadDays: type === 'DATE_REACHED' ? Number(form.elements.leadDays.value || 0) : 0,
					}
				})(),
				role: form.elements.role.value, dueOffsetDays: Number(form.elements.dueOffsetDays.value || 0), dueReference: form.elements.dueReference.value, priority: form.elements.priority.value,
				required: form.elements.required.checked, active: form.elements.active.checked,
				complete: (() => {
					const type = form.elements.completeType.value
					return {
						type, templateKey: DEREGISTRATION_TRIGGERS.includes(type) ? form.elements.completeDereg.value : form.elements.completeTemplate.value, presetKey: form.elements.completePreset.value,
						field: type === 'FIELD_SET' ? form.elements.completeField.value : '', value: type === 'FIELD_SET' ? form.elements.completeValue.value.trim() : (type === 'LABEL_SET' && !form.elements.completeLabelValue.disabled ? form.elements.completeLabelValue.value : ''), status: type === 'CASE_STATUS' ? form.elements.completeStatus.value : '',
						label: LABEL_TRIGGERS.includes(type) ? form.elements.completeLabel.value : '',
						documentKind: type === 'DOCUMENT_RECEIVED' ? form.elements.completeDocumentKind.value : '',
					}
				})(),
				actions: collectActions(form),
			}
		}
		const result = await dialog(isNew ? 'Neuer Schritt' : 'Schritt bearbeiten', body, 'Übernehmen', {
			eyebrow: draft.name, collect,
			onOpen: (form) => {
				const refresh = () => {
					syncLabelValueSelect(m, form.elements.appliesLabel, form.elements.appliesLabelValue)
					syncLabelValueSelect(m, form.elements.triggerLabel, form.elements.triggerLabelValue)
					if (form.elements.completeLabelValue) { const source = form.elements.completeLabel.value ? form.elements.completeLabel : form.elements.triggerLabel; if (form.elements.completeLabelValue.dataset.labelFor !== source.value) { form.elements.completeLabelValue.dataset.labelFor = source.value; const options = labelValueOptions(m, source.value, form.elements.completeLabelValue.value); form.elements.completeLabelValue.innerHTML = options; form.elements.completeLabelValue.disabled = !options } }
					form.querySelectorAll('[data-action-index]').forEach((fieldset) => syncLabelValueSelect(m, fieldset.querySelector('[data-action-label-key]'), fieldset.querySelector('[data-action-label-value]')))
					const current = collect(form)
					form.querySelectorAll('[data-trigger-when]').forEach((element) => { element.hidden = !element.dataset.triggerWhen.split(' ').includes(current.trigger.type) })
					form.querySelectorAll('[data-complete-when]').forEach((element) => { element.hidden = !element.dataset.completeWhen.split(' ').includes(current.complete.type) })
					form.querySelectorAll('[data-action-index]').forEach((fieldset) => {
						const type = fieldset.querySelector('[data-action-type]').value
						fieldset.querySelectorAll('[data-when]').forEach((element) => { element.hidden = !element.dataset.when.split(' ').includes(type) })
					})
					// 0.76.0: Wertauswahl nur bei Listen-Markern zeigen (sonst ausgeblendet und deaktiviert).
					form.querySelectorAll('[data-label-value-wrap]').forEach((element) => {
						const select = element.querySelector('select')
						const usable = select && !select.disabled && select.options.length > 0
						if (!usable) element.hidden = true
						else if (!element.dataset.triggerWhen && !element.dataset.completeWhen && !element.dataset.when) element.hidden = false
					})
					const appliesValue = form.elements.appliesLabelValue
					if (appliesValue && (!form.elements.appliesLabel.value || appliesValue.disabled)) appliesValue.closest('label').hidden = true
					// Knopf „Marker entfernen“ braucht keinen Wert.
					form.querySelectorAll('[data-action-index]').forEach((fieldset) => { if (fieldset.querySelector('[data-action-label-mode]')?.value === 'REMOVE') { const wrap = fieldset.querySelector('[data-label-value-wrap]'); if (wrap) wrap.hidden = true } })
					form.querySelector('#step-sentence-preview').textContent = stepSentence(current, m, steps)
					for (const [select, list] of [['triggerField', 'trigger-field-values'], ['completeField', 'complete-field-values']]) {
						const element = form.elements[select]
						if (element && element.dataset.listFor !== element.value) { element.dataset.listFor = element.value; form.querySelector(`#${list}`).innerHTML = fieldValues(element.value) }
					}
				}
				form.addEventListener('input', refresh)
				form.addEventListener('change', refresh)
				form.querySelector('#add-step-action').addEventListener('click', () => {
					const container = form.querySelector('#step-actions')
					container.insertAdjacentHTML('beforeend', actionEditor({ type: 'DOCUMENT', label: '' }, container.querySelectorAll('[data-action-index]').length, m))
					refresh()
				})
				form.addEventListener('click', (event) => {
					const remove = event.target.closest('[data-remove-action]')
					if (!remove) return
					remove.closest('[data-action-index]').remove()
					form.querySelectorAll('[data-action-index]').forEach((fieldset, position) => { fieldset.dataset.actionIndex = String(position); fieldset.querySelector('legend').textContent = `Knopf ${position + 1}` })
					refresh()
				})
				refresh()
			},
		})
		if (!result) return
		if (!result.title) { notifyWarning('Bitte eine Bezeichnung angeben.'); return }
		const all = draft.steps
		if (isNew) all.push(result)
		else all[all.indexOf(steps[index])] = result
		state.processDraftDirty = true
		render()
	}

	function moveStep(fromIndex, targetPhase, targetIndex = null) {
		const draft = state.processDraft
		const visible = draft.steps.filter((step) => !step.archived)
		const moving = visible[fromIndex]
		if (!moving) return
		const others = draft.steps.filter((step) => step !== moving)
		moving.phase = Number(targetPhase)
		let insertAt = others.length
		if (targetIndex !== null && visible[targetIndex] && visible[targetIndex] !== moving) insertAt = others.indexOf(visible[targetIndex])
		else {
			const lastInPhase = others.map((step, position) => ({ step, position })).filter(({ step }) => Number(step.phase) === Number(targetPhase)).pop()
			if (lastInPhase) insertAt = lastInPhase.position + 1
		}
		others.splice(insertAt, 0, moving)
		draft.steps = others
		state.processDraftDirty = true
		render()
	}

	async function showSimulation() {
		const draft = state.processDraft
		const m = meta()
		const body = `<div class="bp-form-grid"><label><span>Bestattungsart</span><select name="scope"><option value="BURIAL">Erdbestattung</option><option value="CREMATION">Feuerbestattung mit Urnengrab</option><option value="SEA">Seebestattung</option></select></label><label class="bp-inline-check"><input type="checkbox" name="ceremony" checked> mit Trauerfeier</label><label class="bp-inline-check"><input type="checkbox" name="pension" checked> Rentenbezug</label><label class="bp-inline-check"><input type="checkbox" name="deposit"> Anzahlung vereinbart</label><label class="bp-inline-check"><input type="checkbox" name="incoming" checked> Eingangsrechnung Friedhof</label><label class="bp-inline-check"><input type="checkbox" name="others" checked> weitere aktive Vorlagen einbeziehen</label>${(m.labels || []).filter((label) => label.active).length ? `<div class="bp-check-list bp-span-3"><span>Marker im Beispielfall</span>${(m.labels || []).filter((label) => label.active).flatMap((label) => label.kind === 'LIST' ? (label.values || []).map((value) => [`${label.key}=${value.key}`, `${label.name}: ${value.name}`]) : [[label.key, label.name]]).map(([key, name]) => `<label class="bp-inline-check"><input type="checkbox" name="simLabels" value="${esc(key)}"> ${esc(name)}</label>`).join('')}</div>` : ''}<label><span>Trauerfeier am Tag</span><input type="number" name="ceremonyDay" value="7" min="1"></label><label><span>Beisetzung am Tag</span><input type="number" name="burialDay" value="7" min="1"></label><label><span>Zahlungseingang am Tag</span><input type="number" name="paymentDay" value="24" min="1"></label></div><div id="simulation-result"></div>`
		await dialog(`Probelauf: ${draft.name}`, body, '', {
			eyebrow: 'Beispielfall', onOpen: (form) => {
				const run = () => {
					const others = form.elements.others.checked ? (state.processAdmin.templates || []).filter((template) => template.active && template.key !== draft.key).flatMap((template) => template.steps) : []
					const scope = form.elements.scope.value
					const burialDay = Number(form.elements.burialDay.value || 7)
					const result = simulate([...draft.steps.filter((step) => !step.archived), ...others.filter((step) => !step.archived)], { scope: scope === 'SEA' ? 'CREMATION' : scope, nature: scope === 'SEA', urnGrave: scope === 'CREMATION', ceremony: form.elements.ceremony.checked, pension: form.elements.pension.checked, deposit: form.elements.deposit.checked, incomingInvoice: form.elements.incoming.checked, labels: [...form.querySelectorAll('input[name="simLabels"]:checked')].map((input) => input.value), ceremonyDay: Number(form.elements.ceremonyDay.value || 7), burialDay, invoiceDay: burialDay + 3, paymentDay: Number(form.elements.paymentDay.value || 24) })
					form.querySelector('#simulation-result').innerHTML = `<p class="bp-muted">Tag 0 = Fallanlage. Es entstehen ${result.entries.length} Aufgaben.</p><div class="bp-table-scroll"><table class="bp-process-simulation"><thead><tr><th>entsteht</th><th>fällig</th><th>Phase</th><th>Schritt</th><th>Rolle</th><th>Verlauf</th></tr></thead><tbody>${result.entries.map((entry) => `<tr><td>Tag ${entry.day}</td><td>Tag ${entry.due}${entry.shortNotice ? ' ⚠' : ''}</td><td>${entry.phase}</td><td>${esc(entry.title)}${entry.required ? ' <small>(Pflicht)</small>' : ''}</td><td>${esc(m.roleLabels?.[entry.role] || '')}</td><td>${esc(entry.state)}</td></tr>`).join('')}</tbody></table></div>${result.notCreated.length ? `<details><summary>${result.notCreated.length} Schritt(e) entstehen in diesem Beispiel nicht</summary><ul>${result.notCreated.map((entry) => `<li>${esc(entry.title)} – ${esc(entry.reason)}</li>`).join('')}</ul></details>` : ''}`
				}
				form.addEventListener('change', run)
				form.addEventListener('input', run)
				run()
			},
		})
	}

	function bindProcessAdmin() {
		root.querySelectorAll('[data-process-template]').forEach((button) => button.addEventListener('click', async () => {
			if (state.processDraftDirty && !await confirmAction('Ungespeicherte Änderungen verwerfen?', { title: 'Ablaufvorlage wechseln', confirmLabel: 'Verwerfen' })) return
			state.processAdminKey = button.dataset.processTemplate
			globalThis.localStorage?.setItem('bestatter:process-admin-key', state.processAdminKey)
			resetDraft(); render()
		}))
		root.querySelector('#new-process-template')?.addEventListener('click', async () => {
			const name = await promptAction('Name der neuen Ablaufvorlage', '', { title: 'Neue Ablaufvorlage', label: 'Name' })
			if (!name) return
			try { const created = await api(templatesUrl(), { method: 'POST', body: new URLSearchParams({ name }) }); state.processAdminKey = created.key; state.processDraftDirty = false; await loadProcessAdmin(); render() } catch (error) { notifyError(error.message) }
		})
		const form = root.querySelector('#process-template-form')
		if (!form) return
		const markDirty = () => { state.processDraft.name = form.elements.name.value; state.processDraft.description = form.elements.description.value; state.processDraft.active = form.elements.active.checked; if (!state.processDraftDirty) { state.processDraftDirty = true; render() } }
		form.elements.name.addEventListener('change', markDirty)
		form.elements.description.addEventListener('change', markDirty)
		form.elements.active.addEventListener('change', markDirty)
		form.onsubmit = async (event) => {
			event.preventDefault()
			const draft = state.processDraft
			try {
				await api(`${templatesUrl()}/${encodeURIComponent(draft.key)}`, { method: 'PUT', body: new URLSearchParams({ template: JSON.stringify({ name: form.elements.name.value, description: form.elements.description.value, active: form.elements.active.checked, steps: draft.steps.filter((step) => !step.archived) }) }) })
				state.processDraftDirty = false
				await loadProcessAdmin(); resetDraft(); render()
			} catch (error) { notifyError(error.message) }
		}
		root.querySelector('#discard-process-template')?.addEventListener('click', () => { resetDraft(); render() })
		root.querySelector('#simulate-process')?.addEventListener('click', () => showSimulation())
		root.querySelector('#export-process')?.addEventListener('click', async () => {
			try { await ctx.download(`${templatesUrl()}/${encodeURIComponent(state.processDraft.key)}/export`, `Ablauf-${state.processDraft.key}.csv`) } catch (error) { notifyError(error.message) }
		})
		root.querySelector('#import-process')?.addEventListener('change', async (event) => {
			const file = event.target.files?.[0]
			if (!file) return
			if (!await confirmAction(`„${file.name}“ importieren? Die Schrittliste von „${state.processDraft.name}“ wird durch den Inhalt der Datei ersetzt. Knöpfe bereits vorhandener Schritte bleiben erhalten.`, { title: 'CSV importieren', confirmLabel: 'Importieren' })) { event.target.value = ''; return }
			try {
				const content = await file.text()
				await api(`${templatesUrl()}/${encodeURIComponent(state.processDraft.key)}/import`, { method: 'POST', body: new URLSearchParams({ content }) })
				state.processDraftDirty = false; await loadProcessAdmin(); resetDraft(); render()
			} catch (error) { notifyError(error.message) } finally { event.target.value = '' }
		})
		root.querySelector('#export-process-json')?.addEventListener('click', async () => {
			try { await ctx.download(`${templatesUrl()}/${encodeURIComponent(state.processDraft.key)}/export-json`, `Ablauf-${state.processDraft.key}.json`) } catch (error) { notifyError(error.message) }
		})
		root.querySelector('#import-process-json')?.addEventListener('change', async (event) => {
			const file = event.target.files?.[0]
			if (!file) return
			if (!await confirmAction(`„${file.name}“ importieren? Die Schrittliste von „${state.processDraft.name}“ wird vollständig durch den Inhalt der Datei ersetzt, einschließlich aller Knöpfe. Laufende Fälle behalten ihre Aufgaben.`, { title: 'JSON importieren', confirmLabel: 'Importieren' })) { event.target.value = ''; return }
			try {
				const result = await api(`${templatesUrl()}/${encodeURIComponent(state.processDraft.key)}/import-json`, { method: 'POST', body: new URLSearchParams({ content: await file.text(), mode: 'REPLACE' }), feedback: 'Ablaufvorlage importiert.' })
				if (result.warnings?.length) ctx.notifyWarning(`${result.warnings.length} Verweis(e) fehlen in dieser Installation: ${result.warnings.slice(0, 3).join(' ')}${result.warnings.length > 3 ? ' …' : ''}`)
				state.processDraftDirty = false; await loadProcessAdmin(); resetDraft(); render()
			} catch (error) { notifyError(error.message) } finally { event.target.value = '' }
		})
		root.querySelector('#copy-process')?.addEventListener('click', async () => {
			const name = await promptAction('Name der Kopie', `${state.processDraft.name} (Kopie)`, { title: 'Als Kopie anlegen', label: 'Name' })
			if (!name) return
			try { const created = await api(templatesUrl(), { method: 'POST', body: new URLSearchParams({ name, copyFrom: state.processDraft.key }) }); state.processAdminKey = created.key; state.processDraftDirty = false; await loadProcessAdmin(); resetDraft(); render() } catch (error) { notifyError(error.message) }
		})
		root.querySelector('#restore-process')?.addEventListener('click', async () => {
			if (!await confirmAction('Auslieferungsstand wiederherstellen? Eigene Änderungen an dieser Vorlage gehen verloren; laufende Fälle behalten ihre Aufgaben.', { title: 'Auslieferungsstand', confirmLabel: 'Wiederherstellen', danger: true })) return
			try { await api(`${templatesUrl()}/${encodeURIComponent(state.processDraft.key)}/restore`, { method: 'POST' }); state.processDraftDirty = false; await loadProcessAdmin(); resetDraft(); render() } catch (error) { notifyError(error.message) }
		})
		root.querySelector('#delete-process')?.addEventListener('click', async () => {
			if (!await confirmAction(`Ablaufvorlage „${state.processDraft.name}“ löschen?`, { title: 'Ablaufvorlage löschen', confirmLabel: 'Löschen', danger: true })) return
			try { await api(`${templatesUrl()}/${encodeURIComponent(state.processDraft.key)}`, { method: 'DELETE' }); state.processAdminKey = ''; state.processDraftDirty = false; await loadProcessAdmin(); render() } catch (error) { notifyError(error.message) }
		})
		root.querySelectorAll('[data-add-step]').forEach((button) => button.addEventListener('click', () => editStep(null, Number(button.dataset.addStep))))
		root.querySelectorAll('[data-edit-step]').forEach((button) => button.addEventListener('click', () => editStep(Number(button.dataset.editStep))))
		root.querySelectorAll('[data-copy-step]').forEach((button) => button.addEventListener('click', () => {
			const visible = state.processDraft.steps.filter((step) => !step.archived)
			const source = visible[Number(button.dataset.copyStep)]
			const copy = { ...JSON.parse(JSON.stringify(source)), key: '', title: `${source.title} (Kopie)`, id: undefined }
			state.processDraft.steps.splice(state.processDraft.steps.indexOf(source) + 1, 0, copy)
			state.processDraftDirty = true; render()
		}))
		root.querySelectorAll('[data-remove-step]').forEach((button) => button.addEventListener('click', async () => {
			const visible = state.processDraft.steps.filter((step) => !step.archived)
			const step = visible[Number(button.dataset.removeStep)]
			if (!await confirmAction(`Schritt „${step.title}“ entfernen? Bereits entstandene Aufgaben in Fällen bleiben erhalten.`, { title: 'Schritt entfernen', confirmLabel: 'Entfernen', danger: true })) return
			state.processDraft.steps = state.processDraft.steps.filter((entry) => entry !== step)
			state.processDraftDirty = true; render()
		}))
		root.querySelectorAll('[data-move-step]').forEach((button) => button.addEventListener('click', () => {
			const visible = state.processDraft.steps.filter((step) => !step.archived)
			const index = Number(button.dataset.moveStep)
			const step = visible[index]
			const samePhase = visible.map((entry, position) => ({ entry, position })).filter(({ entry }) => Number(entry.phase) === Number(step.phase))
			const own = samePhase.findIndex(({ position }) => position === index)
			const target = samePhase[own + Number(button.dataset.direction)]
			if (!target) return
			const all = state.processDraft.steps
			const a = all.indexOf(step); const b = all.indexOf(target.entry)
			;[all[a], all[b]] = [all[b], all[a]]
			state.processDraftDirty = true; render()
		}))
		let dragIndex = null
		root.querySelectorAll('.bp-step-card').forEach((card) => {
			card.addEventListener('dragstart', (event) => { dragIndex = Number(card.dataset.stepIndex); card.classList.add('is-dragging'); event.dataTransfer?.setData('text/plain', String(dragIndex)) })
			card.addEventListener('dragend', () => card.classList.remove('is-dragging'))
			card.addEventListener('keydown', (event) => { if (event.key === 'Enter') editStep(Number(card.dataset.stepIndex)) })
		})
		root.querySelectorAll('[data-phase-drop]').forEach((column) => {
			column.addEventListener('dragover', (event) => { event.preventDefault(); column.classList.add('is-drop-target') })
			column.addEventListener('dragleave', () => column.classList.remove('is-drop-target'))
			column.addEventListener('drop', (event) => {
				event.preventDefault(); column.classList.remove('is-drop-target')
				if (dragIndex === null) return
				const targetCard = event.target.closest('.bp-step-card')
				moveStep(dragIndex, Number(column.dataset.phaseDrop), targetCard ? Number(targetCard.dataset.stepIndex) : null)
				dragIndex = null
			})
		})
	}

	return { loadProcess, loadProcessAdmin, processScheduleStrip, processOverviewCard, processTaskPanel, processAdminView, bindProcess, bindProcessAdmin, runStepAction, showSuggestions, showClosure }
}
