// 0.88.2: Customizing → Dokumentvorlagen übersichtlich: Suche und Bereichsfilter, Gruppen nach Kategorie, je Vorlage eine
// Zeile mit voller Bezeichnung, Datei, Ablageort und Zustand der Datei; Bearbeiten klappt das Formular unter der Zeile auf.
// Der Bestattungsauftrag zeigt, ob die Datei den Vollmachtsteil enthält, und bietet die neue Standardfassung an.

/** Zustände der Vorlagendatei: [Text, Ton, Erklärung]. */
export const FILE_STATES = {
	STANDARD: ['Standardfassung', 'ok', 'Die Datei entspricht der mit dieser Version mitgelieferten Fassung.'],
	OUTDATED_STANDARD: ['Ältere Standardfassung', 'info', 'Unverändert aus einer früheren Version – wird beim nächsten Erzeugen automatisch durch die aktuelle Fassung ersetzt.'],
	CUSTOM: ['Eigene Fassung', 'neutral', 'Die Datei wurde angepasst. Die App überschreibt sie nicht.'],
	OWN: ['Eigene Vorlage', 'neutral', 'Eigene Datei ohne mitgelieferte Standardfassung.'],
	NOT_PROVISIONED: ['Noch nicht im Vorlagenordner', 'muted', 'Die mitgelieferte Fassung entsteht beim ersten Erzeugen oder mit „Fehlende Standardvorlagen ergänzen“.'],
	MISSING: ['Datei fehlt', 'error', 'Die zugeordnete Datei liegt nicht im Vorlagenordner. Bitte im Formular eine Datei auswählen.'],
}
/** Diese drei verwendet die App fest – nicht deaktivierbar. */
export const LOCKED_ACTIVE_KEYS = ['BESTATTUNGSAUFTRAG', 'RECHNUNG', 'KORREKTUR']

/** Braucht die Vorlage Aufmerksamkeit? (fehlende Datei, Bestattungsauftrag ohne Vollmachtsteil) */
export function templateNeedsAttention(file) {
	if (!file) return false
	if (file.state === 'MISSING') return true
	return file.key === 'BESTATTUNGSAUFTRAG' && file.state === 'CUSTOM' && file.powerOfAttorney === false
}

/** Vorlagen nach Kategorie gruppieren; Reihenfolge der Gruppen nach der kleinsten Sortierung darin. */
export function groupTemplates(templates = []) {
	const groups = new Map()
	for (const entry of templates) {
		const category = String(entry.category || '').trim() || 'Allgemein'
		if (!groups.has(category)) groups.set(category, [])
		groups.get(category).push(entry)
	}
	return [...groups.entries()]
		.map(([category, items]) => ({ category, items: items.sort((left, right) => (Number(left.sortOrder || 0) - Number(right.sortOrder || 0)) || String(left.name).localeCompare(String(right.name), 'de')) }))
		.sort((left, right) => (Number(left.items[0]?.sortOrder || 0) - Number(right.items[0]?.sortOrder || 0)) || left.category.localeCompare(right.category, 'de'))
}

export function createDocumentTemplatesModule(ctx) {
	const { root, apiBase, state } = ctx
	const api = (...args) => ctx.api(...args)
	const esc = (...args) => ctx.esc(...args)
	const render = (...args) => ctx.render(...args)
	const notifySuccess = (...args) => ctx.notifySuccess(...args)
	const notifyError = (...args) => ctx.notifyError(...args)
	const confirmAction = (...args) => ctx.confirmAction(...args)
	const fileStatusUrl = () => `${apiBase}/document-templates/file-status`

	const fileOf = (entry) => (state.documentTemplateFiles?.templates || []).find((item) => item.key === entry.key) || null
	const filter = () => state.documentTemplateFilter || (state.documentTemplateFilter = { query: '', category: '', attention: false })

	async function loadTemplateFiles() {
		try { state.documentTemplateFiles = await api(fileStatusUrl(), { feedback: false, loadingLabel: false }) } catch (error) { state.documentTemplateFiles = { templates: [], error: error.message } }
		return state.documentTemplateFiles
	}

	function badges(entry, file) {
		const list = []
		if (file && FILE_STATES[file.state]) { const [text, tone, hint] = FILE_STATES[file.state]; list.push(`<span class="bp-template-badge is-${tone}" title="${esc(hint)}">${esc(text)}</span>`) }
		if (file?.text) list.push('<span class="bp-template-badge is-muted" title="Textvorlage (TXT), zum Beispiel für E-Mail- und Brieftexte der Abmeldungen.">Textvorlage</span>')
		if (entry.key === 'BESTATTUNGSAUFTRAG' && file && file.state !== 'NOT_PROVISIONED' && file.state !== 'MISSING') list.push(file.powerOfAttorney || file.state === 'OUTDATED_STANDARD' ? '<span class="bp-template-badge is-ok" title="Auftrag und Vollmacht in einem Dokument.">Mit Vollmachtsteil</span>' : '<span class="bp-template-badge is-warning" title="Die Vollmacht wird als eigenes Dokument erzeugt.">Ohne Vollmachtsteil</span>')
		if (file?.system) list.push('<span class="bp-template-badge is-muted" title="Die App verwendet diese Vorlage fest unter ihrem Schlüssel. Nicht löschen; nur die Datei ändern oder austauschen.">Fest verwendet</span>')
		if (!entry.active) list.push('<span class="bp-template-badge is-muted">Inaktiv</span>')
		if (entry.supportsPdf && !file?.text) list.push('<span class="bp-template-badge is-plain">mit PDF</span>')
		return list.join('')
	}

	function orderNotice(entry, file) {
		if (entry.key !== 'BESTATTUNGSAUFTRAG' || !file || file.state !== 'CUSTOM' || file.powerOfAttorney !== false) return ''
		return `<div class="bp-template-notice" role="note"><p><b>Ohne Vollmachtsteil:</b> Diese Datei ist eine eigene, ältere Fassung des Bestattungsauftrags. Die App lässt sie unverändert und erzeugt die Bestattungsvollmacht weiter als eigenes Dokument.</p><p>„Neue Standardfassung übernehmen“ aktiviert „Bestattungsauftrag und Vollmacht“. Ihre bisherige Datei bleibt als Kopie im Vorlagenordner.</p><button type="button" class="bp-primary" data-template-adopt="${esc(entry.key)}">Neue Standardfassung übernehmen</button></div>`
	}

	function editor(entry, file) {
		const files = state.documentTemplateOptions?.files || []
		const subfolders = state.documentTemplateOptions?.subfolders || []
		const lockedActive = LOCKED_ACTIVE_KEYS.includes(entry.key)
		const defaultName = file?.defaultName && file.defaultName !== entry.name ? `<small class="bp-template-default-name">Mitgelieferte Bezeichnung: „${esc(file.defaultName)}“ <button type="button" class="bp-link-button" data-template-default-name="${esc(file.defaultName)}">übernehmen</button></small>` : ''
		const textHint = file?.text ? '<p class="bp-template-notice is-plain">Textvorlage (TXT) für Abmeldungen: Die Texte pflegen Sie unter Customizing → „Abmeldungen“; hier wird nichts gespeichert.</p>' : ''
		const adopt = entry.id && file?.packageAvailable && ['CUSTOM', 'OUTDATED_STANDARD'].includes(file.state) ? `<button type="button" class="bp-secondary" data-template-adopt="${esc(entry.key)}" title="Die mitgelieferte Fassung übernehmen; die bisherige Datei bleibt als Kopie erhalten.">Standardfassung übernehmen</button>` : ''
		return `<article class="bp-template-editor" data-document-template="${entry.id}" aria-label="${esc(entry.name || 'Neue Dokumentvorlage')} bearbeiten">${textHint}<div class="bp-template-editor-grid">
			<label><span>Bezeichnung</span><input name="name" value="${esc(entry.name)}" required>${defaultName}</label>
			<label><span>Vorlagenschlüssel</span><input name="key" value="${esc(entry.key)}" pattern="[A-Za-z0-9_\\-]+" ${entry.id ? 'readonly' : 'required'}><small>${entry.id ? 'Nicht änderbar; Abläufe und Abmeldungen verweisen darauf.' : 'Großbuchstaben, Ziffern, _ und -.'}</small></label>
			<label><span>Bereich (Kategorie)</span><input name="category" value="${esc(entry.category)}" list="document-template-categories"></label>
			<label><span>Datei im Vorlagenordner</span><select name="fileName" ${file?.text ? 'disabled' : 'required'}><option value="">Bitte auswählen</option>${files.map((option) => `<option value="${esc(option.fileName)}" ${option.fileName === entry.fileName ? 'selected' : ''}>${esc(option.label || option.fileName)}</option>`).join('')}${entry.fileName && !files.some((option) => option.fileName === entry.fileName) ? `<option value="${esc(entry.fileName)}" selected disabled>${esc(entry.fileName)} (nicht im Vorlagenordner)</option>` : ''}</select></label>
			<label><span>Ablage in der Fallakte</span><select name="outputSubfolder" required><option value="">Bitte auswählen</option>${subfolders.map((folder) => `<option value="${esc(folder)}" ${folder === entry.outputSubfolder ? 'selected' : ''}>${esc(folder)}</option>`).join('')}</select></label>
			<label><span>Sortierung</span><input name="sortOrder" type="number" value="${Number(entry.sortOrder || 0)}"></label>
			<label class="bp-template-span"><span>Beschreibung</span><textarea name="description" rows="3">${esc(entry.description)}</textarea></label>
		</div>
		<details><summary>Technische Pflichtfelder</summary><p class="bp-muted">Kommagetrennte gültige Stammdatenfelder. Unbekannte Schlüssel werden beim Speichern abgewiesen.</p><input name="requiredFields" value="${esc((entry.requiredFields || []).join(', '))}" list="document-required-fields"></details>
		<div class="bp-inline-options"><label class="bp-inline-check"><input name="supportsPdf" type="checkbox" ${entry.supportsPdf ? 'checked' : ''}> Zusätzlich als PDF erzeugen</label><label class="bp-inline-check" ${lockedActive ? 'title="Die App verwendet diese Vorlage fest; sie bleibt aktiv."' : ''}><input name="active" type="checkbox" ${entry.active ? 'checked' : ''} ${lockedActive ? 'disabled' : ''}> Aktiv</label></div>
		<p class="bp-card-state" aria-live="polite"></p>
		<div class="bp-row-actions">${file?.text ? '' : `<button class="bp-primary save-document-template" data-id="${entry.id}">Speichern</button>`}<button type="button" class="bp-secondary" data-template-edit-close>Schließen</button>${entry.id ? `<button class="bp-secondary duplicate-document-template" type="button" data-id="${entry.id}">Duplizieren</button>` : ''}${adopt}${entry.id && !file?.system ? `<button class="bp-danger delete-document-template" data-id="${entry.id}">Löschen</button>` : ''}</div></article>`
	}

	function row(entry) {
		const file = fileOf(entry)
		const editing = Number(state.documentTemplateEditing) === Number(entry.id)
		const search = [entry.name, entry.key, entry.fileName, entry.description, entry.category].join(' ').toLowerCase()
		const meta = [entry.fileName || 'keine Datei', entry.outputSubfolder ? `Ablage: ${entry.outputSubfolder}` : ''].filter(Boolean).join(' · ')
		return `<article class="bp-template-row${entry.active ? '' : ' is-inactive'}${editing ? ' is-editing' : ''}" data-template-row="${entry.id}" data-template-category="${esc(String(entry.category || '').trim() || 'Allgemein')}" data-template-attention="${templateNeedsAttention(file) ? '1' : '0'}" data-template-search="${esc(search)}">
			<div class="bp-template-main"><h5>${esc(entry.name || 'Neue Dokumentvorlage')}</h5><p class="bp-template-meta"><code>${esc(entry.key || '–')}</code> · ${esc(meta)}</p>${entry.description ? `<p class="bp-template-description">${esc(entry.description)}</p>` : ''}<div class="bp-template-badges">${badges(entry, file)}</div></div>
			<div class="bp-template-actions">${file?.text || !entry.fileName ? '' : `<button type="button" class="bp-secondary" data-template-preview="${entry.id}">Vorschau</button>`}<button type="button" class="bp-secondary" data-template-edit="${entry.id}" aria-expanded="${editing ? 'true' : 'false'}">${editing ? 'Zuklappen' : 'Bearbeiten'}</button></div>
			${orderNotice(entry, file)}${editing ? editor(entry, file) : ''}</article>`
	}

	function documentTemplatesView() {
		const templates = state.documentTemplates || []
		const files = state.documentTemplateFiles?.templates || []
		const current = filter()
		const groups = groupTemplates(templates)
		const categories = groups.map((group) => group.category)
		const attention = files.filter(templateNeedsAttention).length
		const custom = files.filter((file) => file.state === 'CUSTOM' || file.state === 'OWN').length
		const path = state.documentTemplateFiles?.templatePath || state.documentTemplateOptions?.templatePath || 'Bestatter/Vorlagen'
		const summary = `${templates.length} Vorlagen · ${templates.filter((entry) => entry.active).length} aktiv · ${custom} eigene Fassungen${attention ? ` · <b class="bp-warning-text">${attention} mit Handlungsbedarf</b>` : ''}`
		const list = groups.map((group) => `<section class="bp-template-group" data-template-group="${esc(group.category)}"><h4>${esc(group.category)} <small>${group.items.length}</small></h4><div class="bp-template-list">${group.items.map(row).join('')}</div></section>`).join('')
		return `<section class="bp-panel bp-config-admin bp-template-admin"><datalist id="document-required-fields">${(state.documentTemplateOptions?.requiredFields || []).map((name) => `<option value="${esc(name)}"></option>`).join('')}</datalist><datalist id="document-template-categories">${categories.map((name) => `<option value="${esc(name)}"></option>`).join('')}</datalist>
			<div class="bp-panel-head"><div><h3>Dokumentvorlagen</h3><p class="bp-muted">Jede Vorlage ist eine Word-Datei (DOCX) im Nextcloud-Ordner „${esc(path)}“. Hier legen Sie Bezeichnung, Ablage in der Fallakte und Aktivierung fest und sehen den Zustand der Datei. Den Inhalt bearbeiten Sie in der Datei, zum Beispiel mit Nextcloud Office. Angepasste Dateien überschreibt die App nie.</p></div>
			<div class="bp-head-actions"><button class="bp-primary" id="new-document-template">+ Dokumentvorlage</button><button class="bp-secondary" id="provision-templates" title="Legt mitgelieferte Vorlagen an, die im Vorlagenordner noch fehlen. Vorhandene Dateien bleiben unverändert.">Fehlende Standardvorlagen ergänzen</button><details class="bp-action-menu" id="template-tools-menu"><summary class="bp-secondary">Werkzeuge</summary><div class="bp-action-menu-list" role="menu"><button type="button" class="bp-menu-item" id="download-template-fields" role="menuitem" title="Alle Platzhalter mit Klarnamen und Datenquelle als CSV-Datei.">Feldkatalog als CSV</button><button type="button" class="bp-menu-item" id="download-logo-placeholder" role="menuitem" title="Dieses Bild an der gewünschten Stelle in eine DOCX-Vorlage einfügen; beim Erzeugen ersetzt es die App durch das Logo der Niederlassung.">Logo-Platzhalter herunterladen</button></div></details></div></div>
			<div class="bp-template-toolbar"><label class="bp-template-search"><span class="hidden-visually">Vorlagen durchsuchen</span><input type="search" id="document-template-search" placeholder="Vorlage suchen – Name, Schlüssel oder Datei" value="${esc(current.query)}"></label><label class="bp-template-filter"><span class="hidden-visually">Bereich</span><select id="document-template-category"><option value="">Alle Bereiche</option>${categories.map((name) => `<option value="${esc(name)}" ${current.category === name ? 'selected' : ''}>${esc(name)}</option>`).join('')}</select></label><label class="bp-inline-check"><input type="checkbox" id="document-template-attention" ${current.attention ? 'checked' : ''}> Nur mit Handlungsbedarf</label></div>
			<p class="bp-template-summary">${summary}</p>${state.documentTemplateFiles?.error ? `<p class="bp-warning">Zustand der Dateien konnte nicht geprüft werden: ${esc(state.documentTemplateFiles.error)}</p>` : ''}
			<div class="bp-template-groups">${list || '<p class="bp-empty">Noch keine Dokumentvorlagen vorhanden.</p>'}<p class="bp-empty" id="document-template-none" hidden>Keine Vorlage passt zur Suche.</p></div></section>`
	}

	function applyFilter() {
		const current = filter()
		const query = current.query.trim().toLowerCase()
		let visible = 0
		root.querySelectorAll('[data-template-row]').forEach((element) => {
			const show = (!query || element.dataset.templateSearch.includes(query)) && (!current.category || element.dataset.templateCategory === current.category) && (!current.attention || element.dataset.templateAttention === '1' || element.classList.contains('is-editing'))
			element.hidden = !show
			if (show) visible++
		})
		root.querySelectorAll('[data-template-group]').forEach((group) => { const shown = group.querySelectorAll('[data-template-row]:not([hidden])').length; group.hidden = !shown; const count = group.querySelector('h4 small'); if (count) count.textContent = String(shown) })
		const none = root.querySelector('#document-template-none')
		if (none) none.hidden = visible > 0 || !root.querySelector('[data-template-row]')
	}

	async function adoptStandard(key) {
		const entry = (state.documentTemplates || []).find((item) => item.key === key)
		const file = entry ? fileOf(entry) : null
		const name = file?.fileName || entry?.fileName || key
		const details = [`Die mitgelieferte Fassung ersetzt „${name}“ im Vorlagenordner.`, 'Ihre bisherige Datei bleibt als Kopie mit dem heutigen Datum im Namen erhalten, zum Beispiel „…-vor-2026-10-13.docx“.']
		if (key === 'BESTATTUNGSAUFTRAG') details.push('Gibt es noch keine Datei „KOSTENVORANSCHLAG.docx“, übernimmt sie das Layout Ihrer bisherigen Datei – der Kostenvoranschlag sieht dann aus wie bisher.', 'Danach enthält der Auftrag den Vollmachtsteil; die gesonderte Bestattungsvollmacht entfällt.')
		if (!await confirmAction(`Standardfassung für „${entry?.name || key}“ übernehmen?`, { title: 'Standardfassung übernehmen', confirmLabel: 'Übernehmen', details })) return
		try {
			const result = await api(`${apiBase}/document-templates/${encodeURIComponent(key)}/adopt-standard`, { method: 'POST', feedback: false, loadingLabel: 'Standardfassung wird übernommen …' })
			await Promise.all([loadTemplateFiles(), ctx.documentTemplateOptionsUrl ? api(ctx.documentTemplateOptionsUrl()).then((options) => { state.documentTemplateOptions = options }) : null])
			render()
			if (!result.changed) notifySuccess(`„${name}“ ist bereits die aktuelle Standardfassung.`)
			else notifySuccess([`Standardfassung für „${entry?.name || key}“ übernommen.`, result.backup ? `Bisherige Datei: ${result.backup}.` : '', result.estimate ? 'Der Kostenvoranschlag behält das bisherige Layout.' : ''].filter(Boolean).join(' '))
		} catch (error) { notifyError(error.message) }
	}

	function bindDocumentTemplates() {
		if (state.view !== 'customizing' || state.customizingTab !== 'documents') return
		if (!state.documentTemplateFiles) { loadTemplateFiles().then(() => { if (state.view === 'customizing' && state.customizingTab === 'documents') render() }); return }
		const current = filter()
		root.querySelector('#document-template-search')?.addEventListener('input', (event) => { current.query = event.target.value; applyFilter() })
		root.querySelector('#document-template-category')?.addEventListener('change', (event) => { current.category = event.target.value; applyFilter() })
		root.querySelector('#document-template-attention')?.addEventListener('change', (event) => { current.attention = event.target.checked; applyFilter() })
		root.querySelectorAll('[data-template-edit]').forEach((button) => button.addEventListener('click', () => {
			const id = Number(button.dataset.templateEdit)
			state.documentTemplateEditing = Number(state.documentTemplateEditing) === id ? null : id
			render()
			if (state.documentTemplateEditing !== null) requestAnimationFrame(() => root.querySelector(`[data-template-row="${id}"]`)?.scrollIntoView({ block: 'start', behavior: 'smooth' }))
		}))
		root.querySelectorAll('[data-template-edit-close]').forEach((button) => button.addEventListener('click', () => {
			const id = state.documentTemplateEditing
			state.documentTemplateEditing = null
			if (!id) state.documentTemplates = (state.documentTemplates || []).filter((entry) => entry.id)
			render()
		}))
		root.querySelectorAll('[data-template-preview]').forEach((button) => button.addEventListener('click', () => {
			const entry = (state.documentTemplates || []).find((item) => Number(item.id) === Number(button.dataset.templatePreview))
			if (entry?.fileName && ctx.showDocumentTemplatePreview) ctx.showDocumentTemplatePreview(entry.fileName, entry.name || entry.fileName)
		}))
		root.querySelectorAll('[data-template-adopt]').forEach((button) => button.addEventListener('click', () => adoptStandard(button.dataset.templateAdopt)))
		root.querySelectorAll('[data-template-default-name]').forEach((button) => button.addEventListener('click', () => { const input = button.closest('label')?.querySelector('input[name="name"]'); if (input) input.value = button.dataset.templateDefaultName }))
		const tools = root.querySelector('#template-tools-menu')
		tools?.querySelectorAll('.bp-menu-item').forEach((item) => item.addEventListener('click', () => { tools.open = false }))
		tools?.addEventListener('keydown', (event) => { if (event.key === 'Escape') { tools.open = false; tools.querySelector('summary')?.focus() } })
		applyFilter()
	}

	return { templateListView: documentTemplatesView, bindDocumentTemplates, loadTemplateFiles }
}
