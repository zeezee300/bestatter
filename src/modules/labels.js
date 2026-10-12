/**
 * 0.76.0 – Marker am Fall (Variante C: Symbole in der Fallliste, volle Chips im Fallkopf).
 *
 * - Fallkopf: Chips der gesetzten Marker und automatischen Hinweise, Knopf „+ Marker“ (Taste E).
 * - Auswahlfenster: Suche, Klick bzw. Enter schaltet sofort (ohne Speichern-Knopf), Listen-Marker mit Werten.
 * - Fallliste: Spalte „Hinweise“ (bis zu 4 Symbole), Menü „⋯“ bzw. Rechtsklick → „Marker …“,
 *   Klick auf ein Symbol filtert die Liste, Filterleiste mit Marker-Auswahl.
 * - Customizing → Marker: Pflege mit fester Farbpalette und Symbolauswahl (48 Tabler-Symbole).
 * - 0.77.0: Notiz je gesetztem Marker (Sprechblase am Chip, Punkt am Listensymbol, Notizfenster mit „Speichern“;
 *   Pflichtnotiz fragt das Notizfenster schon beim Setzen ab).
 */
export function createLabelsModule(ctx) {
	const { root, state, apiBase } = ctx
	const api = (...args) => ctx.api(...args)
	const esc = (...args) => ctx.esc(...args)
	const render = (...args) => ctx.render(...args)
	const notifyError = (...args) => ctx.notifyError(...args)
	const notifySuccess = (...args) => ctx.notifySuccess(...args)
	const notifyWarning = (...args) => ctx.notifyWarning(...args)
	const confirmAction = (...args) => ctx.confirmAction(...args)

	const labelsUrl = () => `${apiBase}/labels`
	const caseLabelUrl = (caseId, key) => `${apiBase}/cases/${caseId}/labels/${encodeURIComponent(key)}`
	const NOTE_MAX = 500
	const noteIcon = '<svg class="bp-label-note-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M8 9h8" /><path d="M8 13h6" /><path d="M18 4a3 3 0 0 1 3 3v8a3 3 0 0 1 -3 3h-5l-5 3v-3h-2a3 3 0 0 1 -3 -3v-8a3 3 0 0 1 3 -3h12z" /></svg>'
	const noteAllowed = (label) => (label?.noteMode || definition(label?.key)?.noteMode || 'OPTIONAL') !== 'NONE'
	const catalog = () => state.labels || { labels: [], colors: {}, kinds: {}, hints: [], icons: { groups: [], icons: [] }, maxActive: 12, canEdit: false }
	const iconMap = () => {
		const icons = catalog().icons?.icons || []
		if (iconMap.cache?.source !== icons) iconMap.cache = { source: icons, map: new Map(icons.map((icon) => [icon.key, icon])) }
		return iconMap.cache.map
	}
	const activeLabels = () => (catalog().labels || []).filter((label) => label.active)
	const definition = (key) => (catalog().labels || []).find((label) => label.key === key) || null
	const colorClass = (color) => `bp-label-c-${String(color || 'SLATE').toLowerCase()}`

	async function loadLabels() {
		try { state.labels = await api(labelsUrl(), { feedback: false }) } catch (error) { state.labels = state.labels || null; throw error }
		return state.labels
	}

	function iconSvg(key) {
		const icon = iconMap().get(key) || iconMap().get('tag')
		return `<svg class="bp-label-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false">${icon?.svg || ''}</svg>`
	}

	function setInfo(label) {
		if (!label.setAt) return ''
		const when = new Date(label.setAt)
		const date = Number.isNaN(when.getTime()) ? label.setAt : when.toLocaleString('de-DE', { dateStyle: 'short', timeStyle: 'short' })
		return `gesetzt ${label.setByName ? `von ${label.setByName} ` : ''}am ${date}`
	}

	function noteInfo(label) {
		if (!label.noteAt) return ''
		const when = new Date(label.noteAt)
		const date = Number.isNaN(when.getTime()) ? label.noteAt : when.toLocaleString('de-DE', { dateStyle: 'short', timeStyle: 'short' })
		return `Notiz ${label.noteByName ? `von ${label.noteByName} ` : ''}am ${date}`
	}

	/** Sprechblase am Chip: zeigt die Notiz als Tooltip, Klick öffnet das Notizfenster. */
	function noteButton(label, editable) {
		if (!noteAllowed(label) && !label.note) return ''
		const note = String(label.note || '')
		const missing = Boolean(label.noteMissing)
		const mode = note ? 'has-note' : (missing ? 'is-missing' : 'is-empty')
		const title = note ? `Notiz: ${note}` : (missing ? 'Notiz fehlt (erforderlich)' : 'Notiz hinzufügen')
		if (!editable) return note ? `<span class="bp-label-chip-note ${mode}" title="${esc(title)}" role="img" aria-label="${esc(title)}">${noteIcon}</span>` : ''
		return `<button type="button" class="bp-label-chip-note ${mode}" data-label-note="${esc(label.key)}" title="${esc(title)}" aria-label="${esc(`${note ? 'Notiz zu' : 'Notiz hinzufügen zu'} „${label.text || label.name}“${note ? `: ${note}` : ''}`)}" aria-haspopup="dialog">${noteIcon}${missing ? '<span aria-hidden="true">!</span>' : ''}</button>`
	}

	/** Voller Chip (Fallkopf). */
	function labelChip(label, options = {}) {
		const info = [label.description, setInfo(label), label.note ? `Notiz: ${label.note}` : '', label.inactive ? 'Marker ist deaktiviert' : ''].filter(Boolean).join(' · ')
		const remove = options.removable ? `<button type="button" class="bp-label-chip-remove" data-label-remove="${esc(label.key)}" aria-label="Marker „${esc(label.text || label.name)}“ entfernen" title="Entfernen">×</button>` : ''
		const body = `${iconSvg(label.icon)}<span>${esc(label.text || label.name)}</span>`
		const inner = label.kind === 'LIST' && options.removable
			? `<button type="button" class="bp-label-chip-main" data-label-open="${esc(label.key)}" title="${esc(`${info}${info ? ' · ' : ''}Klick: Wert ändern`)}">${body}</button>`
			: `<span class="bp-label-chip-main" title="${esc(info)}">${body}</span>`
		const note = options.withNote ? noteButton(label, options.removable) : ''
		return `<span class="bp-label-chip ${colorClass(label.color)} ${label.inactive ? 'is-inactive' : ''} ${label.note ? 'has-note' : ''}" data-label-chip="${esc(label.key)}">${inner}${note}${remove}</span>`
	}

	function hintChip(hint) {
		return `<span class="bp-label-chip bp-label-hint ${colorClass(hint.color)}" title="${esc(`Automatischer Hinweis: ${hint.description}`)}"><span class="bp-label-chip-main">${iconSvg(hint.icon)}<span>${esc(hint.title || hint.name)}</span></span></span>`
	}

	/** Marker-Leiste im Fallkopf (auch bei einem neuen, noch nicht gespeicherten Fall). */
	function caseLabelsHead(item = state.currentCase) {
		if (!item) return ''
		const labels = item.labels || []
		const hints = item.hints || []
		const canAdd = activeLabels().length > 0
		const add = canAdd ? `<button type="button" class="bp-label-add" id="label-picker-open" data-label-picker aria-haspopup="dialog" title="Marker setzen oder entfernen (Taste E)"><span aria-hidden="true">+</span> Marker</button>` : (catalog().canEdit ? '<span class="bp-muted bp-label-empty">Noch keine Marker aktiv – Customizing → Marker</span>' : '')
		if (!labels.length && !hints.length && !add) return ''
		return `<div class="bp-case-labels" aria-label="Marker und Hinweise">${labels.map((label) => labelChip(label, { removable: !label.inactive, withNote: true })).join('')}${hints.map(hintChip).join('')}${add}</div>`
	}

	/** Symbolspalte der Fallliste: Marker (filterbar) und Hinweise, höchstens 4 Symbole + „+n“. */
	function caseListSymbols(item) {
		const entries = [...(item.labels || []).map((label) => ({ ...label, hint: false })), ...(item.hints || []).map((hint) => ({ ...hint, hint: true }))]
		if (!entries.length) return '<span class="bp-muted" aria-label="keine Marker">–</span>'
		const shown = entries.slice(0, 4)
		const rest = entries.slice(4)
		const symbols = shown.map((entry) => {
			if (entry.hint) return `<span class="bp-label-dot is-hint ${colorClass(entry.color)}" title="${esc(`${entry.title || entry.name} (automatischer Hinweis)`)}" role="img" aria-label="${esc(entry.title || entry.name)}">${iconSvg(entry.icon)}</span>`
			const label = entry.text || entry.name
			const info = [label, entry.note ? `Notiz: ${entry.note}` : '', setInfo(entry), 'Klick: Liste nach diesem Marker filtern'].filter(Boolean).join(' – ')
			return `<button type="button" class="bp-label-dot ${colorClass(entry.color)} ${entry.note ? 'has-note' : ''}" data-label-filter-add="${esc(entry.key)}" title="${esc(info)}" aria-label="${esc(`${label}${entry.note ? ' (mit Notiz)' : ''} – Liste danach filtern`)}">${iconSvg(entry.icon)}</button>`
		}).join('')
		const more = rest.length ? `<span class="bp-label-more" title="${esc(rest.map((entry) => entry.text || entry.title || entry.name).join(', '))}">+${rest.length}</span>` : ''
		return `<span class="bp-label-dots">${symbols}${more}</span>`
	}

	/** Filterleiste der Fallliste. */
	function caseListFilterBar() {
		const filters = state.caseSearch?.labels || []
		if (!(catalog().labels || []).length) return ''
		const chips = filters.map((filter) => {
			const [key, value] = filter.split('=')
			const label = definition(key)
			const valueName = value ? (label?.values || []).find((entry) => entry.key === value)?.name || value : ''
			const text = label ? (valueName ? `${label.name}: ${valueName}` : label.name) : key
			return `<span class="bp-label-chip ${colorClass(label?.color)} bp-label-filter-chip"><span class="bp-label-chip-main">${iconSvg(label?.icon || 'tag')}<span>${esc(text)}</span></span><button type="button" class="bp-label-chip-remove" data-label-filter-remove="${esc(filter)}" aria-label="Filter „${esc(text)}“ entfernen">×</button></span>`
		}).join('')
		return `<div class="bp-label-filter-bar" aria-label="Markerfilter"><button type="button" class="bp-secondary bp-label-filter-open" id="label-filter-open" aria-haspopup="dialog">Marker filtern${filters.length ? ` (${filters.length})` : ''}</button>${chips}${filters.length > 1 ? '<span class="bp-muted">alle müssen zutreffen</span>' : ''}${filters.length ? '<button type="button" class="bp-link-button" data-label-filter-clear>Filter zurücksetzen</button>' : ''}</div>`
	}

	function caseRowMenuButton(item) {
		return `<button type="button" class="bp-row-menu-button" data-case-menu="${Number(item.id)}" aria-haspopup="menu" aria-label="${esc(`Aktionen für ${item.caseNumber}`)}" title="Aktionen">⋯</button>`
	}

	// ------------------------------------------------------------------ Popover (Auswahlfenster, Menü, Filter)

	let popover = null
	function closePopover(restoreFocus = true) {
		if (!popover) return
		const { element, returnFocus, onClose } = popover
		popover = null
		element.remove()
		document.removeEventListener('mousedown', outsideClose, true)
		document.removeEventListener('keydown', escapeClose, true)
		if (onClose) onClose()
		if (restoreFocus && returnFocus && document.contains(returnFocus)) returnFocus.focus()
	}
	// Escape schließt das offene Fenster auch dann, wenn der Fokus inzwischen außerhalb liegt.
	function escapeClose(event) {
		if (popover && event.key === 'Escape' && !popover.element.contains(event.target)) { event.preventDefault(); closePopover() }
	}
	function outsideClose(event) {
		if (popover && !popover.element.contains(event.target)) closePopover(false)
	}
	function openPopover(html, anchor, options = {}) {
		closePopover(false)
		const element = document.createElement('div')
		element.className = `bp-label-popover ${options.className || ''}`
		element.setAttribute('role', options.role || 'dialog')
		if (options.label) element.setAttribute('aria-label', options.label)
		element.innerHTML = html
		root.append(element)
		const rect = options.point ? { left: options.point.x, right: options.point.x, bottom: options.point.y, top: options.point.y } : anchor.getBoundingClientRect()
		const width = Math.min(element.offsetWidth || 340, window.innerWidth - 16)
		const left = Math.max(8, Math.min(rect.left, window.innerWidth - width - 8))
		let top = rect.bottom + 6
		const height = element.offsetHeight || 300
		if (top + height > window.innerHeight - 8 && rect.top - height - 6 > 8) top = rect.top - height - 6
		element.style.left = `${Math.round(left)}px`
		element.style.top = `${Math.round(Math.max(8, top))}px`
		popover = { element, returnFocus: options.returnFocus || anchor, onClose: options.onClose }
		element.addEventListener('keydown', (event) => { if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); closePopover() } })
		setTimeout(() => { document.addEventListener('mousedown', outsideClose, true); document.addEventListener('keydown', escapeClose, true) }, 0)
		return element
	}

	/**
	 * Auswahlfenster: Marker eines Falls setzen/entfernen. caseId = 0 → neuer, noch nicht gespeicherter Fall
	 * (Auswahl wird bei der Anlage mitgeschickt).
	 */
	function openLabelPicker(anchor, caseId, options = {}) {
		const target = () => (Number(state.currentCase?.id || 0) === Number(caseId) || (!caseId && state.newCase) ? state.currentCase : (state.caseSearch?.items || []).find((entry) => Number(entry.id) === Number(caseId)))
		let query = ''
		let expanded = options.expandKey || ''
		let busy = false
		const current = () => new Map(((target()?.labels) || []).map((label) => [label.key, label]))
		const content = () => {
			const set = current()
			const needle = query.trim().toLowerCase()
			const labels = activeLabels().filter((label) => !needle || `${label.name} ${label.description} ${(label.values || []).map((value) => value.name).join(' ')}`.toLowerCase().includes(needle))
			const rows = labels.map((label) => {
				const assigned = set.get(label.key)
				const listLabel = label.kind === 'LIST'
				const values = listLabel && (expanded === label.key || (needle && (label.values || []).some((value) => value.name.toLowerCase().includes(needle))))
					? `<div class="bp-label-values" role="group" aria-label="${esc(label.name)}">${(label.values || []).filter((value) => value.active).map((value) => `<button type="button" class="bp-label-value ${assigned?.value === value.key ? 'is-selected ' + colorClass(label.color) : ''}" data-picker-value="${esc(label.key)}" data-value="${esc(value.key)}" aria-pressed="${assigned?.value === value.key}">${assigned?.value === value.key ? '✓ ' : ''}${esc(value.name)}</button>`).join('')}${assigned ? `<button type="button" class="bp-label-value bp-label-value-remove" data-picker-toggle="${esc(label.key)}">entfernen</button>` : ''}</div>`
					: ''
				const noteLink = assigned && label.noteMode !== 'NONE' ? `<button type="button" class="bp-label-option-note ${assigned.note ? 'has-note' : ''}" data-picker-note="${esc(label.key)}" title="${esc(assigned.note ? `Notiz: ${assigned.note}` : 'Notiz hinzufügen')}" aria-label="${esc(`${assigned.note ? 'Notiz bearbeiten' : 'Notiz hinzufügen'}: ${label.name}`)}">${noteIcon}</button>` : ''
				const requiredMark = label.noteMode === 'REQUIRED' ? '<small class="bp-label-option-required">mit Notiz</small>' : ''
				return `<div class="bp-label-option-wrap"><div class="bp-label-option-line"><button type="button" class="bp-label-option ${assigned ? 'is-set' : ''}" data-picker-toggle="${esc(label.key)}" ${listLabel ? `aria-expanded="${expanded === label.key}"` : `role="menuitemcheckbox" aria-checked="${Boolean(assigned)}"`} title="${esc(label.description || '')}"><span class="bp-label-dot ${colorClass(label.color)}" aria-hidden="true">${iconSvg(label.icon)}</span><span class="bp-label-option-text">${esc(label.name)}${listLabel && assigned ? `<small>${esc(assigned.valueName)}</small>` : ''}${assigned?.note ? `<small class="bp-label-option-notetext">${esc(assigned.note)}</small>` : (!assigned ? requiredMark : '')}</span><span class="bp-label-option-state" aria-hidden="true">${listLabel ? (expanded === label.key ? '▾' : '▸') : (assigned ? '✓' : '')}</span></button>${noteLink}</div>${values}</div>`
			}).join('')
			return `<div class="bp-label-picker-head"><input type="search" class="bp-label-search" placeholder="Marker suchen …" aria-label="Marker suchen" value="${esc(query)}"><kbd title="Tastenkürzel im Fall">E</kbd></div><div class="bp-label-options" role="menu">${rows || '<p class="bp-muted">Kein passender Marker.</p>'}</div><p class="bp-label-picker-foot bp-muted" aria-live="polite">${caseId ? 'Änderungen werden sofort gespeichert.' : 'Wird mit dem neuen Fall gespeichert.'}</p>`
		}
		const element = openPopover(content(), anchor, { className: 'bp-label-picker', label: 'Marker auswählen', onClose: () => {
			render()
			// Nach dem Neuzeichnen den Fokus an den Auslöser zurückgeben (Fallkopf bzw. Zeilenmenü der Fallliste).
			requestAnimationFrame(() => { if (!popover) (document.getElementById('label-picker-open') || root.querySelector(`[data-case-menu="${Number(caseId)}"]`))?.focus() })
		} })
		const refresh = (focusKey = '') => {
			const active = document.activeElement
			const focusSearch = active?.classList?.contains('bp-label-search')
			const caret = focusSearch ? active.selectionStart : 0
			element.innerHTML = content()
			if (focusSearch) { const search = element.querySelector('.bp-label-search'); search.focus(); search.setSelectionRange(caret, caret) }
			else if (focusKey) element.querySelector(`[data-picker-toggle="${CSS.escape(focusKey)}"]`)?.focus()
		}
		const apply = async (key, value, remove) => {
			if (busy) return
			const label = definition(key)
			if (!label) return
			// Pflichtnotiz: Erst die Notiz erfassen, dann wird der Marker mit Notiz gesetzt.
			if (!remove && label.noteMode === 'REQUIRED' && !current().get(key)?.note) {
				openNoteEditor(anchor, caseId, key, { value: value || '', setting: true })
				return
			}
			if (!caseId) {
				const item = target()
				const labels = (item.labels || []).filter((entry) => entry.key !== key)
				if (!remove) {
					const valueName = (label.values || []).find((entry) => entry.key === value)?.name || ''
					const previous = (item.labels || []).find((entry) => entry.key === key)
					labels.push({ key, name: label.name, kind: label.kind, color: label.color, icon: label.icon, description: label.description, value: value || '', valueName, text: label.kind === 'LIST' ? `${label.name}: ${valueName}` : label.name, sortOrder: label.sortOrder, noteMode: label.noteMode, note: previous?.note || '' })
				}
				item.labels = labels.sort((left, right) => (left.sortOrder || 0) - (right.sortOrder || 0))
				refresh(key)
				return
			}
			busy = true
			element.setAttribute('aria-busy', 'true')
			try {
				const result = remove
					? await api(caseLabelUrl(caseId, key), { method: 'DELETE', feedback: false, loadingLabel: 'Marker wird entfernt …' })
					: await api(caseLabelUrl(caseId, key), { method: 'PUT', feedback: false, loadingLabel: 'Marker wird gesetzt …', body: new URLSearchParams({ value: value || '' }) })
				applyCaseLabels(caseId, result)
				const foot = element.querySelector('.bp-label-picker-foot')
				if (result.process?.created) notifySuccess(`${result.process.created} Ablaufschritt(e) entstanden.`)
				refresh(key)
				const status = element.querySelector('.bp-label-picker-foot')
				if (status && foot) {
					status.textContent = remove ? `„${label.name}“ entfernt.` : `„${label.name}“ gesetzt.`
					if (!remove && label.noteMode !== 'NONE') status.insertAdjacentHTML('beforeend', ` <button type="button" class="bp-link-button" data-picker-note="${esc(key)}">Notiz hinzufügen</button>`)
				}
			} catch (error) {
				notifyError(error.message)
			} finally {
				busy = false
				element.removeAttribute('aria-busy')
			}
		}
		element.addEventListener('input', (event) => { if (event.target.classList.contains('bp-label-search')) { query = event.target.value; refresh() } })
		element.addEventListener('click', (event) => {
			const noteLink = event.target.closest('[data-picker-note]')
			if (noteLink) { openNoteEditor(anchor, caseId, noteLink.dataset.pickerNote); return }
			const valueButton = event.target.closest('[data-picker-value]')
			if (valueButton) { apply(valueButton.dataset.pickerValue, valueButton.dataset.value, false); return }
			const toggle = event.target.closest('[data-picker-toggle]')
			if (!toggle) return
			const key = toggle.dataset.pickerToggle
			const label = definition(key)
			const assigned = current().get(key)
			if (label?.kind === 'LIST' && !toggle.classList.contains('bp-label-value-remove')) { expanded = expanded === key ? '' : key; refresh(key); return }
			apply(key, '', Boolean(assigned))
		})
		element.addEventListener('keydown', (event) => {
			const options = [...element.querySelectorAll('.bp-label-search, [data-picker-toggle], [data-picker-note], [data-picker-value]')]
			const index = options.indexOf(document.activeElement)
			if (event.key === 'ArrowDown') { event.preventDefault(); options[Math.min(options.length - 1, index + 1)]?.focus() }
			if (event.key === 'ArrowUp') { event.preventDefault(); options[Math.max(0, index - 1)]?.focus() }
			if (event.key === 'Enter' && document.activeElement?.classList.contains('bp-label-search')) {
				event.preventDefault()
				const first = element.querySelector('[data-picker-toggle]')
				if (first) first.click()
			}
		})
		const search = element.querySelector('.bp-label-search')
		if (options.expandKey) element.querySelector(`[data-picker-toggle="${CSS.escape(options.expandKey)}"]`)?.focus()
		else search.focus()
	}

	/** Ergebnis eines Marker-Aufrufs in den Fall (Fallkopf) und die Trefferliste übernehmen. */
	function applyCaseLabels(caseId, result) {
		const update = (item) => { if (item && Number(item.id) === Number(caseId)) { item.labels = result.labels || []; item.hints = result.hints || [] } }
		update(state.currentCase)
		;(state.caseSearch?.items || []).forEach(update)
		;(state.cases || []).forEach(update)
	}

	/**
	 * Notizfenster zu einem gesetzten Marker (bzw. vor dem Setzen eines Markers mit Pflichtnotiz).
	 * Gespeichert wird ausdrücklich mit „Speichern“ (Strg+Enter); caseId = 0 → neuer Fall, nur lokal.
	 */
	function openNoteEditor(anchor, caseId, key, options = {}) {
		const label = definition(key)
		if (!label) return
		const item = Number(state.currentCase?.id || 0) === Number(caseId) || (!caseId && state.newCase) ? state.currentCase : (state.caseSearch?.items || []).find((entry) => Number(entry.id) === Number(caseId))
		const assigned = (item?.labels || []).find((entry) => entry.key === key) || null
		const required = label.noteMode === 'REQUIRED'
		const valueName = options.value ? (label.values || []).find((entry) => entry.key === options.value)?.name || '' : (assigned?.valueName || '')
		const title = label.kind === 'LIST' && valueName ? `${label.name}: ${valueName}` : label.name
		const note = assigned?.note || ''
		const html = `<form class="bp-label-note-form" novalidate><p class="bp-label-picker-title"><span class="bp-label-dot ${colorClass(label.color)}" aria-hidden="true">${iconSvg(label.icon)}</span> Notiz zu „${esc(title)}“${required ? ' <small>(erforderlich)</small>' : ''}</p><label class="hidden-visually" for="label-note-text">Notiz</label><textarea id="label-note-text" name="note" rows="4" maxlength="${NOTE_MAX}" placeholder="${esc(label.noteHint || 'Kurze interne Notiz …')}" ${required ? 'required' : ''}>${esc(note)}</textarea><p class="bp-label-note-meta"><span class="bp-muted">Intern – steht im Fall-Verlauf und in Aufgaben, nie in Dokumenten. Sachlich, keine Diagnosen.</span><span class="bp-label-note-count" aria-live="polite">${note.length}/${NOTE_MAX}</span></p>${assigned?.noteAt ? `<p class="bp-muted bp-label-note-info">${esc(noteInfo(assigned))}</p>` : ''}<div class="bp-label-picker-actions"><button type="submit" class="bp-primary">${options.setting ? 'Marker setzen' : 'Speichern'}</button><button type="button" class="bp-secondary" data-note-cancel>Abbrechen</button>${note && !required ? '<button type="button" class="bp-link-button bp-danger-link" data-note-delete>Notiz löschen</button>' : ''}</div><p class="bp-save-state" aria-live="polite"></p></form>`
		// Position vorab merken: Schließt das Auswahlfenster, zeichnet die Ansicht neu und der Auslöser wird ersetzt.
		const rect = anchor.getBoundingClientRect()
		const element = openPopover(html, anchor, { className: 'bp-label-picker bp-label-note-editor', label: `Notiz zu ${title}`, point: { x: rect.left, y: rect.bottom }, onClose: () => {
			render()
			requestAnimationFrame(() => { if (!popover) (root.querySelector(`[data-label-note="${CSS.escape(key)}"]`) || document.getElementById('label-picker-open') || root.querySelector(`[data-case-menu="${Number(caseId)}"]`))?.focus() })
		} })
		const form = element.querySelector('form')
		const textarea = form.elements.note
		const status = form.querySelector('.bp-save-state')
		textarea.addEventListener('input', () => { form.querySelector('.bp-label-note-count').textContent = `${textarea.value.length}/${NOTE_MAX}` })
		textarea.addEventListener('keydown', (event) => { if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) { event.preventDefault(); form.requestSubmit() } })
		form.querySelector('[data-note-cancel]').addEventListener('click', () => closePopover())
		const save = async (text) => {
			const value = text.trim()
			if (required && !value) { status.textContent = 'Zu diesem Marker ist eine Notiz erforderlich.'; textarea.focus(); return }
			if (!caseId) {
				const target = state.currentCase
				const others = (target.labels || []).filter((entry) => entry.key !== key)
				const base = assigned || { key, name: label.name, kind: label.kind, color: label.color, icon: label.icon, description: label.description, value: options.value || '', valueName, text: title, sortOrder: label.sortOrder, noteMode: label.noteMode }
				target.labels = [...others, { ...base, ...(options.setting ? { value: options.value || '', valueName, text: title } : {}), note: value }].sort((left, right) => (left.sortOrder || 0) - (right.sortOrder || 0))
				closePopover()
				return
			}
			form.setAttribute('aria-busy', 'true')
			try {
				const result = options.setting || !assigned
					? await api(caseLabelUrl(caseId, key), { method: 'PUT', feedback: false, loadingLabel: 'Marker wird gesetzt …', body: new URLSearchParams({ value: options.value || assigned?.value || '', note: value }) })
					: await api(`${caseLabelUrl(caseId, key)}/note`, { method: 'PUT', feedback: false, loadingLabel: 'Notiz wird gespeichert …', body: new URLSearchParams({ note: value }) })
				applyCaseLabels(caseId, result)
				if (result.process?.created) notifySuccess(`${result.process.created} Ablaufschritt(e) entstanden.`)
				else notifySuccess(options.setting ? `„${title}“ gesetzt.` : (value ? 'Notiz gespeichert.' : 'Notiz gelöscht.'))
				if (ctx.loadProcess && Number(state.currentCase?.id || 0) === Number(caseId)) ctx.loadProcess(caseId).catch(() => {})
				closePopover()
			} catch (error) {
				status.textContent = error.message
			} finally {
				form.removeAttribute('aria-busy')
			}
		}
		form.addEventListener('submit', (event) => { event.preventDefault(); save(textarea.value) })
		form.querySelector('[data-note-delete]')?.addEventListener('click', () => save(''))
		textarea.focus()
		textarea.setSelectionRange(textarea.value.length, textarea.value.length)
	}

	function openCaseMenu(anchor, caseId, point = null) {
		const item = (state.caseSearch?.items || []).find((entry) => Number(entry.id) === Number(caseId))
		const element = openPopover(`<button type="button" role="menuitem" data-menu-labels>Marker …</button><button type="button" role="menuitem" data-menu-open>Fall öffnen</button>`, anchor, { className: 'bp-label-menu', role: 'menu', label: `Aktionen für ${item?.caseNumber || 'Fall'}`, point })
		element.querySelector('[data-menu-labels]').addEventListener('click', () => { closePopover(false); if (!activeLabels().length) { notifyWarning('Es sind noch keine Marker aktiv (Customizing → Marker).'); return } openLabelPicker(anchor, caseId) })
		element.querySelector('[data-menu-open]').addEventListener('click', () => { closePopover(false); root.querySelector(`tr[data-case-id="${Number(caseId)}"]`)?.click() })
		element.querySelector('button')?.focus()
		element.addEventListener('keydown', (event) => {
			const buttons = [...element.querySelectorAll('button')]
			const index = buttons.indexOf(document.activeElement)
			if (event.key === 'ArrowDown') { event.preventDefault(); buttons[(index + 1) % buttons.length].focus() }
			if (event.key === 'ArrowUp') { event.preventDefault(); buttons[(index - 1 + buttons.length) % buttons.length].focus() }
		})
	}

	async function reloadCaseSearch() {
		try { if (await ctx.loadCaseSearch(true)) render() } catch (error) { notifyError(error.message) }
	}

	function openFilterPicker(anchor) {
		const filters = new Set(state.caseSearch?.labels || [])
		const labels = (catalog().labels || []).filter((label) => label.active || filters.has(label.key) || [...filters].some((entry) => entry.startsWith(`${label.key}=`)))
		const html = `<p class="bp-label-picker-title">Nur Fälle mit …</p><div class="bp-label-options">${labels.map((label) => {
			const values = label.kind === 'LIST' ? `<div class="bp-label-filter-values">${(label.values || []).map((value) => `<label class="bp-inline-check"><input type="checkbox" data-filter-key="${esc(`${label.key}=${value.key}`)}" ${filters.has(`${label.key}=${value.key}`) ? 'checked' : ''}> ${esc(value.name)}</label>`).join('')}</div>` : ''
			return `<div class="bp-label-filter-option"><label class="bp-inline-check"><input type="checkbox" data-filter-key="${esc(label.key)}" ${filters.has(label.key) ? 'checked' : ''}><span class="bp-label-dot ${colorClass(label.color)}" aria-hidden="true">${iconSvg(label.icon)}</span> ${esc(label.name)}${label.kind === 'LIST' ? ' <small>(beliebiger Wert)</small>' : ''}</label>${values}</div>`
		}).join('') || '<p class="bp-muted">Keine Marker vorhanden.</p>'}</div><div class="bp-label-picker-actions"><button type="button" class="bp-primary" data-filter-apply>Anwenden</button><button type="button" class="bp-secondary" data-filter-cancel>Abbrechen</button></div><p class="bp-muted bp-label-picker-foot">Mehrere Marker: alle müssen zutreffen. Automatische Hinweise sind nicht filterbar.</p>`
		const element = openPopover(html, anchor, { className: 'bp-label-picker bp-label-filter-picker', label: 'Markerfilter' })
		element.querySelector('[data-filter-cancel]').addEventListener('click', () => closePopover())
		element.querySelector('[data-filter-apply]').addEventListener('click', async () => {
			state.caseSearch.labels = [...element.querySelectorAll('[data-filter-key]:checked')].map((input) => input.dataset.filterKey)
			closePopover(false)
			await reloadCaseSearch()
		})
		element.querySelector('input')?.focus()
	}

	// ------------------------------------------------------------------ Binden

	function bindLabels() {
		root.querySelectorAll('[data-label-picker]').forEach((button) => button.addEventListener('click', (event) => { event.stopPropagation(); openLabelPicker(button, Number(state.newCase ? 0 : state.currentCase?.id || 0)) }))
		root.querySelectorAll('[data-label-open]').forEach((button) => button.addEventListener('click', (event) => { event.stopPropagation(); openLabelPicker(button, Number(state.newCase ? 0 : state.currentCase?.id || 0), { expandKey: button.dataset.labelOpen }) }))
		root.querySelectorAll('[data-label-note]').forEach((button) => button.addEventListener('click', (event) => { event.stopPropagation(); openNoteEditor(button, Number(state.newCase ? 0 : state.currentCase?.id || 0), button.dataset.labelNote) }))
		root.querySelectorAll('[data-label-remove]').forEach((button) => button.addEventListener('click', async (event) => {
			event.stopPropagation()
			const key = button.dataset.labelRemove
			if (state.newCase) { state.currentCase.labels = (state.currentCase.labels || []).filter((label) => label.key !== key); render(); return }
			try {
				const assigned = (state.currentCase.labels || []).find((label) => label.key === key)
				if (assigned?.note && !await confirmAction({ title: 'Marker entfernen', message: `Mit dem Marker „${assigned.text || assigned.name}“ wird auch seine Notiz entfernt (sie bleibt im Fall-Verlauf nachvollziehbar).`, confirmLabel: 'Entfernen' })) return
				const result = await api(caseLabelUrl(state.currentCase.id, key), { method: 'DELETE', feedback: false, loadingLabel: 'Marker wird entfernt …' })
				applyCaseLabels(state.currentCase.id, result)
				if (ctx.loadProcess && result.process?.created) await ctx.loadProcess(state.currentCase.id)
				render()
				document.getElementById('label-picker-open')?.focus()
			} catch (error) { notifyError(error.message) }
		}))
		root.querySelectorAll('[data-label-filter-add]').forEach((button) => button.addEventListener('click', async (event) => {
			event.stopPropagation()
			const key = button.dataset.labelFilterAdd
			const filters = new Set(state.caseSearch.labels || [])
			filters.add(key)
			state.caseSearch.labels = [...filters]
			await reloadCaseSearch()
		}))
		root.querySelectorAll('[data-label-filter-remove]').forEach((button) => button.addEventListener('click', async () => {
			state.caseSearch.labels = (state.caseSearch.labels || []).filter((entry) => entry !== button.dataset.labelFilterRemove)
			await reloadCaseSearch()
		}))
		root.querySelector('[data-label-filter-clear]')?.addEventListener('click', async () => { state.caseSearch.labels = []; await reloadCaseSearch() })
		root.querySelector('#label-filter-open')?.addEventListener('click', (event) => openFilterPicker(event.currentTarget))
		root.querySelectorAll('[data-case-menu]').forEach((button) => button.addEventListener('click', (event) => { event.stopPropagation(); openCaseMenu(button, Number(button.dataset.caseMenu)) }))
		root.querySelectorAll('tr[data-case-id]').forEach((row) => row.addEventListener('contextmenu', (event) => {
			// Rechtsklick ist nur eine Abkürzung auf dasselbe Menü wie „⋯“ (Tastatur/Touch: Knopf „⋯“).
			event.preventDefault()
			const button = row.querySelector('[data-case-menu]')
			if (button) openCaseMenu(button, Number(row.dataset.caseId), { x: event.clientX, y: event.clientY })
		}))
		bindLabelsAdmin()
	}

	// Tastenkürzel „E“ im geöffneten Fall (nicht in Eingabefeldern, nicht bei offenem Dialog).
	document.addEventListener('keydown', (event) => {
		if (event.key !== 'e' && event.key !== 'E') return
		if (event.ctrlKey || event.metaKey || event.altKey || event.repeat) return
		if (state.view !== 'case-detail' || popover) return
		const target = event.target
		if (target && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))) return
		if (root.querySelector('.bp-modal, .bp-ui-dialog-overlay') || document.querySelector('.bp-ui-dialog-overlay')) return
		const button = document.getElementById('label-picker-open')
		if (!button) return
		event.preventDefault()
		button.click()
	})

	// ------------------------------------------------------------------ Customizing → Marker

	function labelsAdminView() {
		const data = catalog()
		const labels = data.labels || []
		const activeCount = labels.filter((label) => label.active).length
		const inactiveBundled = labels.filter((label) => label.origin === 'BUNDLED' && !label.active).length
		const rows = labels.map((label, index) => `<tr data-label-row="${esc(label.key)}" class="${label.active ? '' : 'is-inactive'}"><td><span class="bp-label-dot ${colorClass(label.color)}" aria-hidden="true">${iconSvg(label.icon)}</span></td><td><b>${esc(label.name)}</b>${label.description ? `<small>${esc(label.description)}</small>` : ''}</td><td>${esc(data.kinds?.[label.kind] || label.kind)}${label.kind === 'LIST' ? `<small>${esc((label.values || []).filter((value) => value.active).map((value) => value.name).join(', '))}</small>` : ''}${label.noteMode !== 'OPTIONAL' ? `<small>${esc(data.noteModes?.[label.noteMode] || label.noteMode)}</small>` : ''}</td><td>${labelChip({ ...label, text: label.name })}</td><td><label class="bp-inline-check"><input type="checkbox" data-label-active="${esc(label.key)}" ${label.active ? 'checked' : ''}> aktiv</label>${label.keepOnAnonymize ? '<small>bleibt bei Anonymisierung</small>' : ''}</td><td>${Number(label.usage || 0)}</td><td><div class="bp-row-actions"><button type="button" class="bp-secondary" data-label-edit="${esc(label.key)}">Bearbeiten</button><button type="button" class="bp-secondary" data-label-move="${esc(label.key)}" data-direction="-1" aria-label="${esc(label.name)} nach oben" ${index === 0 ? 'disabled' : ''}>↑</button><button type="button" class="bp-secondary" data-label-move="${esc(label.key)}" data-direction="1" aria-label="${esc(label.name)} nach unten" ${index === labels.length - 1 ? 'disabled' : ''}>↓</button>${label.origin !== 'BUNDLED' && !Number(label.usage || 0) ? `<button type="button" class="bp-danger" data-label-delete="${esc(label.key)}">Löschen</button>` : ''}</div></td></tr>`).join('')
		const hints = (data.hints || []).map((hint) => `<li>${hintChip({ ...hint, title: hint.name })} <span class="bp-muted">${esc(hint.description)}</span></li>`).join('')
		const warning = activeCount > Number(data.maxActive || 12) ? `<aside class="bp-compliance-note">Es sind ${activeCount} Marker aktiv. Empfohlen sind höchstens ${Number(data.maxActive || 12)}, damit die Symbole in der Fallliste unterscheidbar bleiben.</aside>` : ''
		return `<section class="bp-panel bp-custom-table bp-labels-admin"><div class="bp-panel-head"><div><h3>Marker</h3><p class="bp-muted">Marker kennzeichnen Fälle auf einen Blick (z. B. „Hygiene beachten“ oder „Kostenträger: Sozialamt“). In der Fallliste erscheinen sie als Symbol, im Fallkopf als Chip. Farben und Symbole stammen aus einer festen Auswahl. Technische Schlüssel bleiben beim Umbenennen stabil; mitgelieferte Marker lassen sich nur deaktivieren.</p></div><div class="bp-head-actions">${data.canEdit ? `${inactiveBundled ? `<button type="button" class="bp-secondary" id="label-activate-bundled">Alle mitgelieferten aktivieren (${inactiveBundled})</button>` : ''}<button type="button" class="bp-primary" id="label-new">+ Marker</button>` : ''}</div></div>${warning}<p class="bp-muted">${activeCount} von ${labels.length} Marker aktiv.</p><div class="bp-table-wrap bp-labels-table-wrap"><table class="bp-labels-table"><colgroup><col class="bp-col-symbol"><col><col class="bp-col-kind"><col class="bp-col-preview"><col class="bp-col-status"><col class="bp-col-usage"><col class="bp-col-actions"></colgroup><thead><tr><th>Symbol</th><th>Name</th><th>Art</th><th>Vorschau</th><th>Status</th><th>Fälle</th><th>Aktionen</th></tr></thead><tbody>${rows || '<tr><td colspan="7" class="bp-empty">Noch keine Marker.</td></tr>'}</tbody></table></div><h4>Automatische Hinweise</h4><p class="bp-muted">Diese Hinweise berechnet die App aus vorhandenen Falldaten. Sie erscheinen neben den Markern (gestrichelt), lassen sich aber nicht von Hand setzen.</p><ul class="bp-label-hint-list">${hints}</ul></section>`
	}

	async function saveLabelOrder(keys) {
		state.labels = await api(`${labelsUrl()}/reorder`, { method: 'PUT', feedback: false, body: new URLSearchParams({ keys: keys.join(',') }) })
		render()
	}

	function bindLabelsAdmin() {
		if (!root.querySelector('.bp-labels-admin')) return
		root.querySelector('#label-new')?.addEventListener('click', () => editLabel(null))
		root.querySelector('#label-activate-bundled')?.addEventListener('click', async () => {
			try { const result = await api(`${labelsUrl()}/activate`, { method: 'POST', feedback: false, body: new URLSearchParams({ keys: '*' }) }); state.labels = result; notifySuccess(`${(result.activated || []).length} Marker aktiviert.`); render() } catch (error) { notifyError(error.message) }
		})
		root.querySelectorAll('[data-label-edit]').forEach((button) => button.addEventListener('click', () => editLabel(button.dataset.labelEdit)))
		root.querySelectorAll('[data-label-active]').forEach((input) => input.addEventListener('change', async () => {
			const label = definition(input.dataset.labelActive)
			try { await api(`${labelsUrl()}/${encodeURIComponent(label.key)}`, { method: 'PUT', feedback: input.checked ? 'Marker aktiviert.' : 'Marker deaktiviert.', body: new URLSearchParams({ label: JSON.stringify({ ...label, active: input.checked }) }) }); await loadLabels(); render() } catch (error) { input.checked = !input.checked; notifyError(error.message) }
		}))
		root.querySelectorAll('[data-label-move]').forEach((button) => button.addEventListener('click', async () => {
			const keys = (catalog().labels || []).map((label) => label.key)
			const index = keys.indexOf(button.dataset.labelMove)
			const target = index + Number(button.dataset.direction)
			if (index < 0 || target < 0 || target >= keys.length) return
			;[keys[index], keys[target]] = [keys[target], keys[index]]
			try { await saveLabelOrder(keys) } catch (error) { notifyError(error.message) }
		}))
		root.querySelectorAll('[data-label-delete]').forEach((button) => button.addEventListener('click', async () => {
			const label = definition(button.dataset.labelDelete)
			if (!await confirmAction({ title: 'Marker löschen', message: `Der Marker „${label.name}“ wurde noch in keinem Fall verwendet und wird endgültig gelöscht.`, confirmLabel: 'Löschen', danger: true })) return
			try { await api(`${labelsUrl()}/${encodeURIComponent(label.key)}`, { method: 'DELETE', feedback: 'Marker gelöscht.' }); await loadLabels(); render() } catch (error) { notifyError(error.message) }
		}))
	}

	function iconPicker(selected, color) {
		const data = catalog().icons || { groups: [], icons: [] }
		const groups = (data.groups || []).map((group) => {
			const icons = (data.icons || []).filter((icon) => icon.group === group.key)
			return `<div class="bp-icon-group" data-icon-group="${esc(group.key)}"><p class="bp-icon-group-title">${esc(group.name)}</p><div class="bp-icon-grid" role="radiogroup" aria-label="${esc(group.name)}">${icons.map((icon) => `<label class="bp-icon-choice" data-icon-terms="${esc(`${icon.name} ${icon.terms} ${icon.key}`.toLowerCase())}" title="${esc(icon.name)}"><input type="radio" name="icon" value="${esc(icon.key)}" ${icon.key === selected ? 'checked' : ''}><span class="bp-label-dot ${colorClass(color)}" aria-hidden="true">${iconSvg(icon.key)}</span><span>${esc(icon.name)}</span></label>`).join('')}</div></div>`
		}).join('')
		return `<div class="bp-icon-picker"><input type="search" class="bp-icon-search" placeholder="Symbol suchen, z. B. Ausland" aria-label="Symbol suchen">${groups}<p class="bp-muted" data-icon-none hidden>Kein passendes Symbol.</p></div>`
	}

	function valueRow(value = { key: '', name: '', active: true }, locked = false) {
		return `<div class="bp-label-value-row" data-value-row><input data-value-name value="${esc(value.name)}" placeholder="z. B. Sozialamt" maxlength="100" aria-label="Wert"><input data-value-key value="${esc(value.key)}" ${locked ? 'readonly' : ''} placeholder="Schlüssel (automatisch)" aria-label="Technischer Schlüssel" pattern="[A-Za-z][A-Za-z0-9_]*"><label class="bp-inline-check"><input type="checkbox" data-value-active ${value.active !== false ? 'checked' : ''}> aktiv</label><button type="button" class="bp-secondary" data-value-up aria-label="Nach oben">↑</button><button type="button" class="bp-danger" data-value-remove aria-label="Wert entfernen">×</button></div>`
	}

	function editLabel(key) {
		const data = catalog()
		const existing = key ? definition(key) : null
		const label = existing ? JSON.parse(JSON.stringify(existing)) : { key: '', name: '', description: '', kind: 'BOOL', color: 'SLATE', icon: 'tag', active: true, keepOnAnonymize: false, noteMode: 'OPTIONAL', noteHint: '', values: [] }
		const noteModes = data.noteModes || { NONE: 'keine Notiz', OPTIONAL: 'Notiz möglich', REQUIRED: 'Notiz erforderlich' }
		const colors = Object.entries(data.colors || {}).map(([colorKey, name]) => `<label class="bp-color-choice ${colorClass(colorKey)}" title="${esc(name)}"><input type="radio" name="color" value="${esc(colorKey)}" ${colorKey === label.color ? 'checked' : ''}><span class="bp-color-swatch" aria-hidden="true"></span><span>${esc(name)}</span></label>`).join('')
		root.insertAdjacentHTML('beforeend', `<div class="bp-modal" role="dialog" aria-modal="true" aria-labelledby="label-dialog-title"><form class="bp-wide-modal bp-process-dialog bp-label-dialog"><div class="bp-panel-head"><div><p class="bp-eyebrow">Customizing → Marker</p><h2 id="label-dialog-title">${existing ? `Marker „${esc(existing.name)}“ bearbeiten` : 'Neuer Marker'}</h2></div><button type="button" class="bp-secondary" data-close-dialog>Schließen</button></div><div class="bp-process-dialog-body">
			<div class="bp-label-preview" aria-live="polite"></div>
			<fieldset><legend>Bezeichnung</legend><div class="bp-form-grid"><label class="bp-span-2"><span>Name</span><input name="name" value="${esc(label.name)}" maxlength="100" required></label><label><span>Art</span><select name="kind" ${existing ? 'disabled' : ''}><option value="BOOL" ${label.kind === 'BOOL' ? 'selected' : ''}>Ja/Nein</option><option value="LIST" ${label.kind === 'LIST' ? 'selected' : ''}>Liste mit Werten</option></select></label><label class="bp-span-3"><span>Beschreibung (erscheint als Hinweis am Marker)</span><textarea name="description" rows="2" maxlength="500">${esc(label.description || '')}</textarea></label></div><p class="bp-muted">Bitte sachlich und neutral formulieren – Marker sind Teil der Fallakte und erscheinen bei Auskunftsersuchen.</p></fieldset>
			<fieldset data-values-section ${label.kind === 'LIST' ? '' : 'hidden'}><legend>Auswahlwerte</legend><div data-values>${(label.values || []).map((value) => valueRow(value, Boolean(existing))).join('')}</div><button type="button" class="bp-secondary" data-value-add>+ Wert</button><p class="bp-muted">Werte, die schon in Fällen verwendet wurden, werden beim Entfernen nur deaktiviert.</p></fieldset>
			<fieldset><legend>Notiz</legend><div class="bp-form-grid"><label><span>Notiz zum gesetzten Marker</span><select name="noteMode">${Object.entries(noteModes).map(([mode, name]) => `<option value="${esc(mode)}" ${(label.noteMode || 'OPTIONAL') === mode ? 'selected' : ''}>${esc(name)}</option>`).join('')}</select></label><label class="bp-span-2" data-note-hint-wrap><span>Platzhaltertext im Notizfeld</span><input name="noteHint" value="${esc(label.noteHint || '')}" maxlength="150" placeholder="z. B. Wer, bis wann, Telefonnummer"></label></div><p class="bp-muted">Die Notiz ist intern: Sie steht im Fall-Verlauf und in Aufgaben, die der Marker auslöst, aber nie in Dokumenten. Bei „Notiz erforderlich“ fragt die App die Notiz schon beim Setzen ab.</p></fieldset>
			<fieldset><legend>Farbe</legend><div class="bp-color-choices" role="radiogroup" aria-label="Farbe">${colors}</div></fieldset>
			<fieldset><legend>Symbol</legend>${iconPicker(label.icon, label.color)}</fieldset>
			<fieldset><legend>Einstellungen</legend><label class="bp-inline-check"><input type="checkbox" name="active" ${label.active ? 'checked' : ''}> aktiv (im Fall auswählbar)</label><label class="bp-inline-check"><input type="checkbox" name="keepOnAnonymize" ${label.keepOnAnonymize ? 'checked' : ''}> bei Anonymisierung behalten (nur für statistische Angaben ohne Personenbezug, z. B. Kostenträger)</label>${existing ? `<p class="bp-muted">Technischer Schlüssel: <code>${esc(existing.key)}</code> · Platzhalter: <code>{{marker.${esc(existing.key)}}}</code></p>` : '<label><span>Technischer Schlüssel (leer = aus dem Namen)</span><input name="key" pattern="[A-Za-z][A-Za-z0-9_]*" maxlength="60"></label>'}</fieldset>
		</div><p class="bp-save-state" aria-live="polite"></p><div class="bp-modal-actions"><button type="button" class="bp-secondary" data-close-dialog>Abbrechen</button><button class="bp-primary">Speichern</button></div></form></div>`)
		const modal = [...root.querySelectorAll('.bp-modal')].pop()
		const form = modal.querySelector('form')
		const close = () => modal.remove()
		modal.querySelectorAll('[data-close-dialog]').forEach((button) => { button.onclick = close })
		modal.addEventListener('keydown', (event) => { if (event.key === 'Escape') close() })
		const collect = () => ({
			key: existing ? existing.key : (form.elements.key?.value || '').trim().toUpperCase(),
			name: form.elements.name.value.trim(), description: form.elements.description.value.trim(),
			kind: existing ? existing.kind : form.elements.kind.value,
			color: form.querySelector('input[name="color"]:checked')?.value || 'SLATE',
			icon: form.querySelector('input[name="icon"]:checked')?.value || 'tag',
			active: form.elements.active.checked, keepOnAnonymize: form.elements.keepOnAnonymize.checked,
			noteMode: form.elements.noteMode.value, noteHint: form.elements.noteMode.value === 'NONE' ? '' : form.elements.noteHint.value.trim(),
			values: [...form.querySelectorAll('[data-value-row]')].map((row) => ({ name: row.querySelector('[data-value-name]').value.trim(), key: row.querySelector('[data-value-key]').value.trim().toUpperCase(), active: row.querySelector('[data-value-active]').checked })).filter((value) => value.name),
		})
		const preview = () => {
			const current = collect()
			const value = current.kind === 'LIST' ? current.values.find((entry) => entry.active)?.name || '' : ''
			form.querySelector('.bp-label-preview').innerHTML = `<span class="bp-muted">Vorschau:</span> <span class="bp-label-dot ${colorClass(current.color)}" title="Fallliste">${iconSvg(current.icon)}</span> ${labelChip({ ...current, text: value ? `${current.name || 'Marker'}: ${value}` : (current.name || 'Marker') })}`
			form.querySelector('[data-values-section]').hidden = current.kind !== 'LIST'
			form.querySelector('[data-note-hint-wrap]').hidden = current.noteMode === 'NONE'
			form.querySelectorAll('.bp-icon-choice .bp-label-dot').forEach((dot) => { dot.className = `bp-label-dot ${colorClass(current.color)}` })
		}
		form.addEventListener('input', (event) => {
			if (event.target.classList.contains('bp-icon-search')) {
				const needle = event.target.value.trim().toLowerCase()
				let visible = 0
				form.querySelectorAll('.bp-icon-choice').forEach((choice) => { const show = !needle || choice.dataset.iconTerms.includes(needle); choice.hidden = !show; if (show) visible++ })
				form.querySelectorAll('.bp-icon-group').forEach((group) => { group.hidden = ![...group.querySelectorAll('.bp-icon-choice')].some((choice) => !choice.hidden) })
				form.querySelector('[data-icon-none]').hidden = visible > 0
				return
			}
			preview()
		})
		form.addEventListener('change', preview)
		form.addEventListener('click', (event) => {
			if (event.target.closest('[data-value-add]')) { form.querySelector('[data-values]').insertAdjacentHTML('beforeend', valueRow()); form.querySelector('[data-values] [data-value-row]:last-child [data-value-name]').focus(); preview() }
			const remove = event.target.closest('[data-value-remove]')
			if (remove) { remove.closest('[data-value-row]').remove(); preview() }
			const up = event.target.closest('[data-value-up]')
			if (up) { const row = up.closest('[data-value-row]'); if (row.previousElementSibling) row.parentElement.insertBefore(row, row.previousElementSibling); preview() }
		})
		form.onsubmit = async (event) => {
			event.preventDefault()
			const payload = collect()
			const status = form.querySelector('.bp-save-state')
			if (payload.kind === 'LIST' && !payload.values.length) { status.textContent = 'Ein Listen-Marker braucht mindestens einen Wert.'; return }
			try {
				await api(existing ? `${labelsUrl()}/${encodeURIComponent(existing.key)}` : labelsUrl(), { method: existing ? 'PUT' : 'POST', feedback: existing ? 'Marker gespeichert.' : 'Marker angelegt.', body: new URLSearchParams({ label: JSON.stringify(payload) }) })
				await loadLabels()
				close()
				render()
			} catch (error) { status.textContent = error.message }
		}
		preview()
		form.elements.name.focus()
	}

	return { loadLabels, iconSvg, labelChip, hintChip, caseLabelsHead, caseListSymbols, caseListFilterBar, caseRowMenuButton, openLabelPicker, openNoteEditor, bindLabels, labelsAdminView, closeLabelPopover: closePopover }
}
