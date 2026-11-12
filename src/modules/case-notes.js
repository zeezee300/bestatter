// 0.91.0: Fallnotizen – Reiter „Notizen“, „+ Notiz“ im Fallkopf, Karte in der Übersicht, Symbol in der Fallliste,
// @-Erwähnungen (Benachrichtigung in Nextcloud), Übernahme aus der Nextcloud-App „Notizen“ und Download als Markdown.

const NOTE_ICON = '<svg class="bp-note-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M13 20H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v7"></path><path d="M13 20l7-7h-5a2 2 0 0 0-2 2z"></path><path d="M8 9h8"></path><path d="M8 13h4"></path></svg>'
const PIN_ICON = '<svg class="bp-note-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M15 4.5l-4 4l-4 1.5l-1.5 1.5l7 7l1.5-1.5l1.5-4l4-4"></path><path d="M9 15l-4.5 4.5"></path><path d="M14.5 4l5.5 5.5"></path></svg>'

/** Steht der Cursor hinter „@Name…“? Liefert Anfang und Suchtext der Erwähnung, sonst null. */
export function noteMentionQuery(text, caret) {
	const before = String(text || '').slice(0, Math.max(0, Number(caret) || 0))
	const match = before.match(/(^|[\s(])@([^\s@]{0,30})$/u)
	if (!match) return null
	return { start: before.length - match[2].length - 1, query: match[2] }
}

/** Setzt „@Anzeigename “ an die Stelle der begonnenen Erwähnung. */
export function insertMention(text, caret, mention, displayName) {
	const value = String(text || '')
	const insert = `@${displayName} `
	return { text: `${value.slice(0, mention.start)}${insert}${value.slice(caret)}`, caret: mention.start + insert.length }
}

/** Erwähnte Personen, deren „@Anzeigename“ noch im Text steht (gelöschte Erwähnungen fallen weg). */
export function mentionsInText(text, members, selected) {
	const value = String(text || '')
	return [...new Set(selected || [])].filter((uid) => {
		const member = (members || []).find((entry) => entry.uid === uid)
		const name = member?.displayName || uid
		return value.includes(`@${name}`) || value.includes(`@${uid}`)
	})
}

/** Notizen nach Kategorie, Verfasser und Suchtext filtern. */
export function filterNotes(items, filter = {}) {
	const query = String(filter.query || '').trim().toLocaleLowerCase('de')
	return (items || []).filter((note) => (!filter.category || note.category === filter.category)
		&& (!filter.author || note.createdBy === filter.author)
		&& (!query || `${note.text} ${note.categoryLabel} ${note.createdByName}`.toLocaleLowerCase('de').includes(query)))
}

/** Notiztext für die Anzeige: maskiert, **fett**, erwähnte Personen hervorgehoben (Zeilenumbrüche über CSS). */
export function renderNoteText(text, mentions, esc) {
	let html = esc(String(text || ''))
	html = html.replace(/\*\*([^*\n][^*\n]*?)\*\*/g, '<strong>$1</strong>')
	for (const mention of [...(mentions || [])].sort((left, right) => String(right.displayName || '').length - String(left.displayName || '').length)) {
		const name = esc(`@${mention.displayName || mention.uid}`)
		html = html.split(name).join(`<span class="bp-note-mention">${name}</span>`)
	}
	return html
}

export function createCaseNotesModule(ctx) {
	const { root, state, api, apiBase, esc, render, notifySuccess, notifyError, confirmAction } = ctx
	const notesUrl = (caseId) => `${apiBase}/cases/${Number(caseId)}/notes`
	let loadingCaseId = 0
	let filter = { category: '', author: '', query: '' }

	const currentCaseId = () => Number(state.currentCase?.id || 0)
	const notes = () => (state.caseNotes && Number(state.caseNotes.caseId) === currentCaseId() ? state.caseNotes : null)
	const members = () => state.team?.members || []
	const memberName = (uid) => members().find((member) => member.uid === uid)?.displayName || uid
	const dateTime = (value) => { const date = new Date(value); return Number.isNaN(date.getTime()) ? '' : date.toLocaleString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) }
	const unsaved = () => !currentCaseId() || Boolean(state.newCase)

	async function loadCaseNotes(caseId = currentCaseId(), options = {}) {
		if (!caseId) return null
		loadingCaseId = Number(caseId)
		try {
			state.caseNotes = { ...await api(notesUrl(caseId), { feedback: false }), caseId: Number(caseId) }
			syncSummary()
			if (options.render !== false) render()
			return state.caseNotes
		} finally {
			loadingCaseId = 0
		}
	}

	/** Lädt die Notizen eines Falls einmalig nach (beim ersten Anzeigen von Reiter, Übersicht oder Fallkopf). */
	function ensureNotes() {
		const caseId = currentCaseId()
		if (!caseId || unsaved() || notes() || loadingCaseId === caseId) return
		loadCaseNotes(caseId).catch(() => { state.caseNotes = { caseId, items: [], categories: [], failed: true } })
	}

	/** Zähler und angeheftete Notizen auch in der geladenen Fallliste nachziehen. */
	function syncSummary() {
		const data = notes()
		if (!data) return
		const summary = { count: (data.items || []).length, pinned: (data.items || []).filter((note) => note.pinned).map((note) => note.title) }
		for (const item of [...(state.caseSearch?.items || []), ...(state.cases || [])]) if (Number(item.id) === Number(data.caseId)) item.noteSummary = summary
		if (state.currentCase) state.currentCase.noteSummary = summary
	}

	function caseNotesTabLabel() {
		const count = (notes()?.items || []).length
		return count ? `Notizen (${count})` : 'Notizen'
	}

	/** „+ Notiz“ im Fallkopf und, wenn vorhanden, Hinweis auf angeheftete Notizen. */
	function quickNoteButton() {
		if (unsaved()) return ''
		ensureNotes()
		const pinned = (notes()?.items || []).filter((note) => note.pinned)
		const chip = pinned.length ? `<button type="button" class="bp-note-pinned-chip" data-case-tab="notes" title="${esc(pinned.map((note) => note.title).join(' · '))}">${PIN_ICON}<span>${pinned.length === 1 ? '1 angeheftete Notiz' : `${pinned.length} angeheftete Notizen`}</span></button>` : ''
		return `${chip}<button type="button" class="bp-secondary bp-note-quick" data-note-quick title="Kurze Notiz zu diesem Fall schreiben (Taste N)">${NOTE_ICON}<span>+ Notiz</span></button>`
	}

	function noteMeta(note) {
		const edited = note.edited ? ` · bearbeitet von ${esc(note.editedByName || note.editedBy)} am ${esc(dateTime(note.editedAt))}` : ''
		const source = note.sourceLabel ? ` · ${esc(String(note.sourceLabel).replace(/^Aus /, 'aus ').replace(/^Beim /, 'beim '))}` : (note.source === 'NEXTCLOUD_NOTES' ? ' · aus Nextcloud Notizen übernommen' : '')
		return `<span class="bp-note-author">${esc(note.createdByName || note.createdBy || 'unbekannt')}</span><span class="bp-muted"> · ${esc(dateTime(note.createdAt))}${edited}${source}</span>`
	}

	function noteCard(note, options = {}) {
		const category = note.categoryLabel ? `<span class="bp-note-category">${esc(note.categoryLabel)}</span>` : ''
		const pinned = note.pinned ? `<span class="bp-note-pinned" title="Angeheftet">${PIN_ICON}<span class="hidden-visually">Angeheftet</span></span>` : ''
		const actions = options.compact ? '' : `<div class="bp-row-actions bp-note-actions"><button type="button" class="bp-link-button" data-note-edit="${Number(note.id)}">Bearbeiten</button><button type="button" class="bp-link-button" data-note-pin="${Number(note.id)}" data-pinned="${note.pinned ? '1' : '0'}">${note.pinned ? 'Lösen' : 'Anheften'}</button><button type="button" class="bp-link-button" data-note-task="${Number(note.id)}" title="Aufgabe mit diesem Text anlegen">Aufgabe daraus</button><button type="button" class="bp-link-button bp-danger-link" data-note-delete="${Number(note.id)}">Löschen</button></div>`
		const text = options.compact && note.text.length > 240 ? `${note.text.slice(0, 240)} …` : note.text
		return `<article class="bp-note${note.pinned ? ' is-pinned' : ''}" data-note-id="${Number(note.id)}"><header class="bp-note-head">${pinned}${category}<span class="bp-note-meta">${noteMeta(note)}</span></header><div class="bp-note-text">${renderNoteText(text, note.mentions, esc)}</div>${actions}</article>`
	}

	function categoryOptions(selected = '') {
		const categories = notes()?.categories || []
		const known = categories.some((entry) => entry.value === selected)
		return `<option value="">Ohne Kategorie</option>${categories.map((entry) => `<option value="${esc(entry.value)}" ${entry.value === selected ? 'selected' : ''}>${esc(entry.label)}</option>`).join('')}${selected && !known ? `<option value="${esc(selected)}" selected>${esc(selected)}</option>` : ''}`
	}

	/** Eingabeformular (Reiter „Notizen“ und Fenster); Erwähnungen über „@“. */
	function noteForm(note = null, options = {}) {
		const max = Number(notes()?.maxLength || 5000)
		const id = options.id || 'case-note-form'
		return `<form class="bp-note-form" id="${esc(id)}" data-note-form="${note ? Number(note.id) : 0}" novalidate><label class="hidden-visually" for="${esc(id)}-text">Notiz</label><div class="bp-note-input"><textarea id="${esc(id)}-text" name="text" rows="${options.rows || 3}" maxlength="${max}" placeholder="Notiz schreiben … (@ erwähnt eine Kollegin oder einen Kollegen)" required>${esc(note?.text || '')}</textarea><ul class="bp-note-suggestions" role="listbox" aria-label="Personen erwähnen" hidden></ul></div><div class="bp-note-form-row"><label class="bp-note-form-category"><span>Kategorie</span><select name="category">${categoryOptions(note?.category || '')}</select></label><label class="bp-inline-check"><input type="checkbox" name="pinned" ${note?.pinned ? 'checked' : ''}> Anheften</label><span class="bp-note-count bp-muted" aria-live="polite">${(note?.text || '').length}/${max}</span><div class="bp-note-form-actions">${options.cancel ? '<button type="button" class="bp-secondary" data-note-cancel>Abbrechen</button>' : ''}<button type="submit" class="bp-primary">${note ? 'Speichern' : 'Notiz speichern'}</button></div></div><p class="bp-note-mentions bp-muted" data-note-mentions></p><p class="bp-save-state" aria-live="polite"></p><small class="bp-muted">Strg+Enter speichert. Notizen sind intern: Alle mit Zugriff auf den Fall sehen und bearbeiten sie; sie stehen im Fall-Verlauf. In Dokumente gelangen nur Gesprächsnotizen und angeheftete Notizen über den Platzhalter {{case.notes}} (Stammdatenblatt).</small></form>`
	}

	function caseNotesPanel() {
		ensureNotes()
		const data = notes()
		if (!data) return '<section class="bp-panel bp-notes-panel"><p class="bp-muted">Notizen werden geladen …</p></section>'
		const items = data.items || []
		const authors = [...new Map(items.map((note) => [note.createdBy, note.createdByName || note.createdBy])).entries()]
		const visible = filterNotes(items, filter)
		const filters = items.length > 1 ? `<div class="bp-note-filters" role="search"><label><span>Kategorie</span><select data-note-filter="category"><option value="">Alle</option>${(data.categories || []).map((entry) => `<option value="${esc(entry.value)}" ${filter.category === entry.value ? 'selected' : ''}>${esc(entry.label)}</option>`).join('')}</select></label><label><span>Verfasst von</span><select data-note-filter="author"><option value="">Alle</option>${authors.map(([uid, name]) => `<option value="${esc(uid)}" ${filter.author === uid ? 'selected' : ''}>${esc(name)}</option>`).join('')}</select></label><label class="bp-note-search"><span>Suchen</span><input type="search" data-note-filter="query" value="${esc(filter.query)}" placeholder="Text in Notizen"></label></div>` : ''
		const importButton = data.notesApp?.notesAppEnabled ? '<button type="button" class="bp-secondary" data-notes-import title="Notizen aus der Nextcloud-App „Notizen“ (Kategorie „Bestatter“) übernehmen">Aus Nextcloud Notizen übernehmen</button>' : ''
		const list = visible.length ? visible.map((note) => noteCard(note)).join('') : `<p class="bp-empty">${items.length ? 'Keine Notiz passt zum Filter.' : 'Noch keine Notizen zu diesem Fall.'}</p>`
		return `<section class="bp-panel bp-notes-panel" aria-labelledby="case-notes-title"><div class="bp-panel-head"><div><p class="bp-eyebrow">Interne Notizen</p><h3 id="case-notes-title">Notizen</h3></div><div class="bp-head-actions">${importButton}${items.length ? '<button type="button" class="bp-secondary" data-notes-export title="Alle Notizen als Markdown-Datei herunterladen">Als Markdown herunterladen</button>' : ''}</div></div>${noteForm(null, { id: 'case-note-composer' })}${filters}<div class="bp-note-list" aria-live="polite">${list}</div></section>`
	}

	/** Übersicht: angeheftete Notizen oben. */
	function caseNotesPinnedAside() {
		ensureNotes()
		const pinned = (notes()?.items || []).filter((note) => note.pinned)
		if (!pinned.length) return ''
		return `<section class="bp-panel bp-notes-pinned" aria-label="Angeheftete Notizen"><div class="bp-panel-head"><div><p class="bp-eyebrow">Angeheftete Notizen</p></div><button type="button" class="bp-link-button" data-case-tab="notes">Alle Notizen</button></div>${pinned.map((note) => noteCard(note, { compact: true })).join('')}</section>`
	}

	/** Übersicht: Karte „Notizen“ mit der neuesten Notiz. */
	function caseNotesSummaryCard() {
		ensureNotes()
		const items = notes()?.items || []
		const latest = [...items].sort((left, right) => String(right.createdAt).localeCompare(String(left.createdAt)))[0]
		const text = latest ? `${latest.title}` : 'Noch keine Notizen.'
		const meta = latest ? `<small class="bp-muted">${esc(latest.createdByName || latest.createdBy)} · ${esc(dateTime(latest.createdAt))}</small>` : ''
		return `<article class="bp-note-summary"><p class="bp-eyebrow">Notizen${items.length ? ` (${items.length})` : ''}</p><h3>${esc(text)}</h3>${meta}<div class="bp-row-actions"><button class="bp-secondary" data-case-tab="notes">Notizen öffnen</button><button type="button" class="bp-secondary" data-note-quick>+ Notiz</button></div></article>`
	}

	/** Fallliste: Symbol, wenn es Notizen gibt; hervorgehoben bei angehefteten Notizen. */
	function caseNotesListSymbol(item) {
		const summary = item?.noteSummary
		if (!summary?.count) return ''
		const pinned = summary.pinned || []
		const title = pinned.length ? `Angeheftet: ${pinned.join(' · ')}` : `${summary.count} ${summary.count === 1 ? 'Notiz' : 'Notizen'}`
		return `<span class="bp-note-dot${pinned.length ? ' is-pinned' : ''}" title="${esc(title)}" role="img" aria-label="${esc(title)}">${pinned.length ? PIN_ICON : NOTE_ICON}<small>${Number(summary.count)}</small></span>`
	}

	// ------------------------------------------------------------------ Bearbeiten

	function bindNoteForm(form, note, onDone) {
		const textarea = form.elements.text
		const suggestions = form.querySelector('.bp-note-suggestions')
		const mentionsInfo = form.querySelector('[data-note-mentions]')
		const status = form.querySelector('.bp-save-state')
		const max = Number(textarea.getAttribute('maxlength') || 5000)
		const selected = new Set((note?.mentions || []).map((mention) => mention.uid))
		let options = []
		let active = 0
		const showMentions = () => {
			const current = mentionsInText(textarea.value, members(), [...selected])
			mentionsInfo.textContent = current.length ? `Wird benachrichtigt: ${current.map(memberName).join(', ')}` : ''
		}
		const closeSuggestions = () => { suggestions.hidden = true; suggestions.innerHTML = ''; options = [] }
		const choose = (member) => {
			const mention = noteMentionQuery(textarea.value, textarea.selectionStart)
			if (!mention) return closeSuggestions()
			const result = insertMention(textarea.value, textarea.selectionStart, mention, member.displayName || member.uid)
			textarea.value = result.text
			textarea.setSelectionRange(result.caret, result.caret)
			selected.add(member.uid)
			closeSuggestions(); showMentions(); textarea.focus()
			textarea.dispatchEvent(new Event('input'))
		}
		const showSuggestions = () => {
			const mention = noteMentionQuery(textarea.value, textarea.selectionStart)
			if (!mention) return closeSuggestions()
			const query = mention.query.toLocaleLowerCase('de')
			options = members().filter((member) => `${member.displayName} ${member.uid}`.toLocaleLowerCase('de').includes(query)).slice(0, 8)
			if (!options.length) return closeSuggestions()
			active = Math.min(active, options.length - 1)
			suggestions.innerHTML = options.map((member, index) => `<li role="option" id="${esc(form.id)}-mention-${index}" aria-selected="${index === active}" data-mention-uid="${esc(member.uid)}">${esc(member.displayName || member.uid)} <small>${esc(member.uid)}</small></li>`).join('')
			suggestions.hidden = false
			suggestions.querySelectorAll('[data-mention-uid]').forEach((element) => element.addEventListener('mousedown', (event) => { event.preventDefault(); choose(options.find((member) => member.uid === element.dataset.mentionUid)) }))
		}
		textarea.addEventListener('input', () => { form.querySelector('.bp-note-count').textContent = `${textarea.value.length}/${max}`; status.textContent = ''; showSuggestions(); showMentions() })
		textarea.addEventListener('keydown', (event) => {
			if (!suggestions.hidden && options.length) {
				if (event.key === 'ArrowDown' || event.key === 'ArrowUp') { event.preventDefault(); active = (active + (event.key === 'ArrowDown' ? 1 : options.length - 1)) % options.length; showSuggestions(); return }
				if (event.key === 'Enter' || event.key === 'Tab') { event.preventDefault(); choose(options[active]); return }
				if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); closeSuggestions(); return }
			}
			if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) { event.preventDefault(); form.requestSubmit() }
		})
		textarea.addEventListener('blur', () => setTimeout(closeSuggestions, 150))
		form.querySelector('[data-note-cancel]')?.addEventListener('click', () => onDone(null))
		form.addEventListener('submit', async (event) => {
			event.preventDefault()
			const text = textarea.value.trim()
			if (!text) { status.textContent = 'Bitte einen Notiztext eingeben.'; textarea.setAttribute('aria-invalid', 'true'); textarea.focus(); return }
			const body = new URLSearchParams({ text, category: form.elements.category.value, pinned: form.elements.pinned.checked ? '1' : '0', mentions: JSON.stringify(mentionsInText(text, members(), [...selected])) })
			form.setAttribute('aria-busy', 'true')
			try {
				const caseId = currentCaseId()
				const saved = await api(note ? `${notesUrl(caseId)}/${Number(note.id)}` : notesUrl(caseId), { method: note ? 'PUT' : 'POST', body, feedback: false, loadingLabel: 'Notiz wird gespeichert …' })
				const mentioned = (saved.mentions || []).filter((mention) => !(note?.mentions || []).some((entry) => entry.uid === mention.uid))
				notifySuccess(note ? 'Notiz gespeichert.' : `Notiz gespeichert.${mentioned.length ? ` ${mentioned.map((mention) => mention.displayName).join(', ')} ${mentioned.length === 1 ? 'wird' : 'werden'} benachrichtigt.` : ''}`)
				await loadCaseNotes(caseId, { render: false })
				onDone(saved)
			} catch (error) {
				status.textContent = error.message
			} finally {
				form.removeAttribute('aria-busy')
			}
		})
		showMentions()
	}

	/** Fenster für „+ Notiz“ und „Bearbeiten“. */
	function openNoteDialog(note = null) {
		if (unsaved()) return
		const open = () => {
			root.querySelector('.bp-note-modal')?.remove()
			root.insertAdjacentHTML('beforeend', `<div class="bp-modal bp-note-modal" role="dialog" aria-modal="true" aria-labelledby="note-dialog-title"><div class="bp-note-dialog"><div class="bp-panel-head"><div><p class="bp-eyebrow">${esc(state.currentCase?.caseNumber || '')}</p><h2 id="note-dialog-title">${note ? 'Notiz bearbeiten' : 'Neue Notiz'}</h2></div></div>${noteForm(note, { id: 'case-note-dialog', cancel: true, rows: 6 })}</div></div>`)
			const modal = root.querySelector('.bp-note-modal')
			const close = () => { modal.remove(); document.removeEventListener('keydown', onKey, true) }
			const onKey = (event) => { if (event.key === 'Escape' && !modal.querySelector('.bp-note-suggestions:not([hidden])')) { event.preventDefault(); close() } }
			document.addEventListener('keydown', onKey, true)
			modal.addEventListener('mousedown', (event) => { if (event.target === modal) close() })
			bindNoteForm(modal.querySelector('form'), note, (saved) => { close(); if (saved) render() })
			requestAnimationFrame(() => { const textarea = modal.querySelector('textarea'); textarea.focus(); textarea.setSelectionRange(textarea.value.length, textarea.value.length) })
		}
		if (notes()) open()
		else loadCaseNotes(currentCaseId(), { render: false }).then(open).catch((error) => notifyError(error.message))
	}

	const findNote = (id) => (notes()?.items || []).find((note) => Number(note.id) === Number(id))

	async function togglePin(id, pinned) {
		try {
			await api(`${notesUrl(currentCaseId())}/${Number(id)}`, { method: 'PUT', body: new URLSearchParams({ pinned: pinned ? '1' : '0' }), feedback: false })
			await loadCaseNotes(currentCaseId())
			notifySuccess(pinned ? 'Notiz angeheftet.' : 'Notiz gelöst.')
		} catch (error) { notifyError(error.message) }
	}

	async function deleteNote(id) {
		const note = findNote(id)
		if (!note) return
		if (!await confirmAction({ title: 'Notiz löschen', message: `Notiz „${note.title}“ löschen? Der Text bleibt im Fall-Verlauf nachvollziehbar.`, confirmLabel: 'Löschen', danger: true })) return
		try {
			await api(`${notesUrl(currentCaseId())}/${Number(id)}`, { method: 'DELETE', feedback: false })
			await loadCaseNotes(currentCaseId())
			notifySuccess('Notiz gelöscht.')
		} catch (error) { notifyError(error.message) }
	}

	function taskFromNote(id) {
		const note = findNote(id)
		if (!note || !ctx.showRecordForm) return
		ctx.showRecordForm('task', currentCaseId(), null, { title: note.title.slice(0, 200), data: { description: `${note.text}\n\n(Aus der Notiz von ${note.createdByName || note.createdBy}, ${dateTime(note.createdAt)})` } })
	}

	/** Übernahme aus der Nextcloud-App „Notizen“ (Kategorie „Bestatter“ des angemeldeten Benutzers). */
	async function openImportDialog() {
		let data
		try { data = await api(`${notesUrl(currentCaseId())}/nextcloud-notes`, { feedback: false, loadingLabel: 'Notizen werden gelesen …' }) } catch (error) { notifyError(error.message); return }
		const items = data.items || []
		const intro = data.folderFound
			? `Notizen aus Ihrer Kategorie „${esc(data.category)}“. Notizen mit der Fallnummer ${esc(data.caseNumber)} im Titel oder Text sind vorgewählt. Übernommene Notizen werden in den Unterordner „${esc(data.category)}/${esc(data.doneFolder)}“ verschoben.`
			: `In Ihren Notizen gibt es noch keine Kategorie „${esc(data.category)}“. Legen Sie in der App „Notizen“ (auch am Handy) eine Notiz in dieser Kategorie an und schreiben Sie die Fallnummer in den Titel, z. B. „${esc(data.caseNumber)} Rückruf Sohn“.`
		const rows = items.map((item) => `<li><label class="bp-inline-check"><input type="checkbox" name="fileIds" value="${Number(item.fileId)}" ${item.matchesCase && !item.tooLong ? 'checked' : ''} ${item.tooLong ? 'disabled' : ''}> <span><b>${esc(item.title)}</b>${item.matchesCase ? ' <span class="bp-note-category">passt zum Fall</span>' : ''}<small class="bp-muted">${esc(item.name)} · ${esc(dateTime(item.modifiedAt))}${item.tooLong ? ' · zu lang für eine Notiz' : ''}</small><small class="bp-note-import-preview">${esc(item.preview)}</small></span></label></li>`).join('')
		root.insertAdjacentHTML('beforeend', `<div class="bp-modal bp-note-modal" role="dialog" aria-modal="true" aria-labelledby="note-import-title"><form class="bp-note-dialog bp-note-import"><div class="bp-panel-head"><div><p class="bp-eyebrow">${esc(data.caseNumber || '')}</p><h2 id="note-import-title">Aus Nextcloud Notizen übernehmen</h2></div></div><p class="bp-muted">${intro}</p>${items.length ? `<ul class="bp-note-import-list">${rows}</ul><label class="bp-note-form-category"><span>Kategorie für die übernommenen Notizen</span><select name="category">${categoryOptions('')}</select></label>` : (data.folderFound ? '<p class="bp-empty">Keine Notizen in dieser Kategorie.</p>' : '')}<p class="bp-save-state" aria-live="polite"></p><div class="bp-modal-actions"><button type="button" class="bp-secondary" data-note-cancel>${items.length ? 'Abbrechen' : 'Schließen'}</button>${items.length ? '<button type="submit" class="bp-primary">Übernehmen</button>' : ''}</div></form></div>`)
		const modal = root.querySelector('.bp-note-modal:last-of-type') || [...root.querySelectorAll('.bp-note-modal')].pop()
		const form = modal.querySelector('form')
		const close = () => { modal.remove(); document.removeEventListener('keydown', onKey, true) }
		const onKey = (event) => { if (event.key === 'Escape') { event.preventDefault(); close() } }
		document.addEventListener('keydown', onKey, true)
		form.querySelector('[data-note-cancel]').addEventListener('click', close)
		form.addEventListener('submit', async (event) => {
			event.preventDefault()
			const ids = [...form.querySelectorAll('input[name="fileIds"]:checked')].map((input) => input.value)
			if (!ids.length) { form.querySelector('.bp-save-state').textContent = 'Bitte mindestens eine Notiz auswählen.'; return }
			try {
				const result = await api(`${notesUrl(currentCaseId())}/nextcloud-notes`, { method: 'POST', body: new URLSearchParams({ fileIds: ids.join(','), category: form.elements.category?.value || '' }), feedback: false, loadingLabel: 'Notizen werden übernommen …' })
				close()
				await loadCaseNotes(currentCaseId())
				const created = (result.created || []).length
				const skipped = result.skipped || []
				notifySuccess(`${created === 1 ? '1 Notiz' : `${created} Notizen`} übernommen.${skipped.length ? ` Nicht übernommen: ${skipped.map((entry) => `${entry.name || entry.fileId} (${entry.reason})`).join(', ')}` : ''}`)
			} catch (error) { form.querySelector('.bp-save-state').textContent = error.message }
		})
	}

	function bindCaseNotes() {
		root.querySelectorAll('[data-note-quick]').forEach((button) => button.addEventListener('click', () => openNoteDialog()))
		const composer = root.querySelector('#case-note-composer')
		if (composer) bindNoteForm(composer, null, () => render())
		bindNoteActions(root)
		root.querySelector('[data-notes-import]')?.addEventListener('click', () => openImportDialog())
		root.querySelector('[data-notes-export]')?.addEventListener('click', async () => {
			try { await ctx.download(`${notesUrl(currentCaseId())}/export.md`, `Notizen-${state.currentCase?.caseNumber || currentCaseId()}.md`) } catch (error) { notifyError(error.message) }
		})
		root.querySelectorAll('[data-note-filter]').forEach((input) => input.addEventListener(input.tagName === 'INPUT' ? 'input' : 'change', () => {
			filter = { ...filter, [input.dataset.noteFilter]: input.value }
			const list = root.querySelector('.bp-note-list')
			if (!list) return
			const items = notes()?.items || []
			const visible = filterNotes(items, filter)
			list.innerHTML = visible.length ? visible.map((note) => noteCard(note)).join('') : '<p class="bp-empty">Keine Notiz passt zum Filter.</p>'
			bindNoteActions(list)
		}))
	}

	function bindNoteActions(scope) {
		scope.querySelectorAll('[data-note-edit]').forEach((button) => button.addEventListener('click', () => { const note = findNote(button.dataset.noteEdit); if (note) openNoteDialog(note) }))
		scope.querySelectorAll('[data-note-pin]').forEach((button) => button.addEventListener('click', () => togglePin(button.dataset.notePin, button.dataset.pinned !== '1')))
		scope.querySelectorAll('[data-note-delete]').forEach((button) => button.addEventListener('click', () => deleteNote(button.dataset.noteDelete)))
		scope.querySelectorAll('[data-note-task]').forEach((button) => button.addEventListener('click', () => taskFromNote(button.dataset.noteTask)))
	}

	// Tastenkürzel „N“ im geöffneten Fall (nicht in Eingabefeldern, nicht bei offenem Dialog).
	document.addEventListener('keydown', (event) => {
		if (event.key !== 'n' && event.key !== 'N') return
		if (event.ctrlKey || event.metaKey || event.altKey || event.repeat) return
		if (state.view !== 'case-detail' || unsaved()) return
		const target = event.target
		if (target && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))) return
		if (root.querySelector('.bp-modal, .bp-ui-dialog-overlay, .bp-label-picker') || document.querySelector('.bp-ui-dialog-overlay')) return
		event.preventDefault()
		openNoteDialog()
	})

	return { loadCaseNotes, caseNotesTabLabel, caseNotesPanel, caseNotesPinnedAside, caseNotesSummaryCard, caseNotesListSymbol, quickNoteButton, openNoteDialog, bindCaseNotes }
}
