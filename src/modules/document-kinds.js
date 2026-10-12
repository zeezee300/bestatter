/**
 * 0.80.0 – Dokumentarten (OP-058): Customizing → Dokumentarten.
 *
 * Je Art: Paperless-Dokumenttyp (Zuordnung für den Belegeingang), Ablage im Fall (Schlüssel des Ordnerplans),
 * Kategorie der Dokumentliste, Wirkung im Fall und optional die Frage nach einer Anzahl. Die Liste wird auch vom
 * Belegeingang, vom Upload-Dialog („Dokumente hochladen“) und von den Abläufen („Dokument eingegangen“) verwendet.
 */
export function createDocumentKindsModule(ctx) {
	const { root, state, apiBase } = ctx
	const api = (...args) => ctx.api(...args)
	const esc = (...args) => ctx.esc(...args)
	const render = (...args) => ctx.render(...args)
	const notifyError = (...args) => ctx.notifyError(...args)
	const confirmAction = (...args) => ctx.confirmAction(...args)

	const kindsUrl = () => `${apiBase}/document-kinds`
	const catalog = () => state.documentKinds || { kinds: [], effects: {}, categories: [], classifications: [], folders: [], canEdit: false }
	const kinds = (activeOnly = false) => (catalog().kinds || []).filter((kind) => !activeOnly || kind.active)
	const kindByKey = (key) => (catalog().kinds || []).find((kind) => kind.key === key) || null
	const classificationLabels = {
		CLASSIFICATION_PENDING: 'noch offen (beim Prüfen festlegen)',
		TRUE_PASS_THROUGH: 'Durchlaufender Posten im fremden Namen',
		THIRD_PARTY_SERVICE: 'Fremdleistung',
		EXPENSE_FEE: 'Auslage / Gebühr',
		NOT_BILLABLE: 'nicht weiterberechnen',
	}

	async function loadDocumentKinds() {
		try { state.documentKinds = await api(kindsUrl(), { feedback: false, loadingLabel: false }) } catch (error) { state.documentKinds = state.documentKinds || null; throw error }
		return state.documentKinds
	}

	/** Auswahlliste der aktiven Arten (Belegeingang, Upload). */
	function documentKindOptions(selected = '', emptyLabel = '', effects = null) {
		return `${emptyLabel ? `<option value="">${esc(emptyLabel)}</option>` : ''}${kinds(true).filter((kind) => !effects || effects.includes(kind.effect)).map((kind) => `<option value="${esc(kind.key)}" ${kind.key === selected ? 'selected' : ''}>${esc(kind.name)}</option>`).join('')}`
	}

	async function paperlessTypes() {
		if (state.paperlessDocumentTypes) return state.paperlessDocumentTypes
		try { state.paperlessDocumentTypes = await api(`${apiBase}/integrations/paperless/document-types`, { feedback: false, loadingLabel: false }) } catch (error) { state.paperlessDocumentTypes = { available: false, types: [], message: error.message } }
		return state.paperlessDocumentTypes
	}

	function paperlessTypeName(id) {
		const type = (state.paperlessDocumentTypes?.types || []).find((item) => Number(item.id) === Number(id))
		return type ? `${type.name} (${type.id})` : `Typ ${id}`
	}

	function documentKindsAdminView() {
		const data = catalog()
		const rows = kinds().map((kind) => `<tr data-kind-row="${esc(kind.key)}" class="${kind.active ? '' : 'is-inactive'}"><td><b>${esc(kind.name)}</b>${kind.description ? `<small>${esc(kind.description)}</small>` : ''}</td><td>${kind.paperlessTypeId ? esc(paperlessTypeName(kind.paperlessTypeId)) : '<span class="bp-muted">–</span>'}</td><td>${esc(kind.folderName || kind.folderKey)}</td><td>${esc(kind.category)}</td><td>${esc(kind.effectLabel || data.effects?.[kind.effect] || kind.effect)}${kind.countLabel ? `<small>fragt: Anzahl ${esc(kind.countLabel)}</small>` : ''}${kind.effect === 'INCOMING_INVOICE' && kind.classification ? `<small>${esc(classificationLabels[kind.classification] || kind.classification)}</small>` : ''}</td><td>${data.canEdit ? `<label class="bp-inline-check"><input type="checkbox" data-kind-active="${esc(kind.key)}" ${kind.active ? 'checked' : ''}> aktiv</label>` : (kind.active ? 'aktiv' : 'inaktiv')}</td><td><div class="bp-row-actions">${data.canEdit ? `<button type="button" class="bp-secondary" data-kind-edit="${esc(kind.key)}">Bearbeiten</button>${kind.origin !== 'BUNDLED' ? `<button type="button" class="bp-danger" data-kind-delete="${esc(kind.key)}">Löschen</button>` : ''}` : ''}</div></td></tr>`).join('')
		const unmapped = kinds(true).filter((kind) => !kind.paperlessTypeId).length
		return `<section class="bp-panel bp-custom-table bp-document-kinds-admin"><div class="bp-panel-head"><div><h3>Dokumentarten</h3><p class="bp-muted">Dokumentarten beschreiben eingehende Schriftstücke, zum Beispiel „Sterbeurkunde“ oder „Rückmeldung Abmeldestelle“. Sie bestimmen, in welchem Ordner der Fallakte ein Dokument landet und was danach im Fall passiert. Der Paperless-Dokumenttyp ordnet Dokumente aus dem Belegeingang automatisch der richtigen Art zu; die Auswahl gilt auch für „Dokumente hochladen“ und für den Ablaufauslöser „Dokument eingegangen“. Mitgelieferte Arten lassen sich nur deaktivieren.</p></div><div class="bp-head-actions">${data.canEdit ? '<button type="button" class="bp-primary" id="document-kind-new">+ Dokumentart</button>' : ''}</div></div>${state.paperlessDocumentTypes && !state.paperlessDocumentTypes.available ? `<aside class="bp-compliance-note">${esc(state.paperlessDocumentTypes.message || 'Paperless ist nicht erreichbar.')}</aside>` : ''}<p class="bp-muted">${kinds(true).length} von ${kinds().length} Dokumentarten aktiv${unmapped ? ` · ${unmapped} aktive ohne Paperless-Dokumenttyp (werden im Belegeingang nur vorgeschlagen, wenn Sie sie wählen)` : ''}.</p><div class="bp-table-wrap"><table class="bp-document-kinds-table"><thead><tr><th>Dokumentart</th><th>Paperless-Dokumenttyp</th><th>Ablage im Fall</th><th>Kategorie</th><th>Wirkung im Fall</th><th>Status</th><th>Aktionen</th></tr></thead><tbody>${rows || '<tr><td colspan="7" class="bp-empty">Noch keine Dokumentarten.</td></tr>'}</tbody></table></div></section>`
	}

	function bindDocumentKindsAdmin() {
		if (!root.querySelector('.bp-document-kinds-admin')) return
		root.querySelector('#document-kind-new')?.addEventListener('click', () => editDocumentKind(null))
		root.querySelectorAll('[data-kind-edit]').forEach((button) => button.addEventListener('click', () => editDocumentKind(button.dataset.kindEdit)))
		root.querySelectorAll('[data-kind-active]').forEach((input) => input.addEventListener('change', async () => {
			const kind = kindByKey(input.dataset.kindActive)
			try { await api(`${kindsUrl()}/${encodeURIComponent(kind.key)}`, { method: 'PUT', feedback: input.checked ? 'Dokumentart aktiviert.' : 'Dokumentart deaktiviert.', body: new URLSearchParams({ kind: JSON.stringify({ ...kind, active: input.checked }) }) }); await loadDocumentKinds(); render() } catch (error) { input.checked = !input.checked; notifyError(error.message) }
		}))
		root.querySelectorAll('[data-kind-delete]').forEach((button) => button.addEventListener('click', async () => {
			const kind = kindByKey(button.dataset.kindDelete)
			if (!await confirmAction({ title: 'Dokumentart löschen', message: `Die Dokumentart „${kind.name}“ wird gelöscht. Bereits abgelegte Dokumente bleiben unverändert; Abläufe mit dieser Art zeigen danach einen Prüfhinweis.`, confirmLabel: 'Löschen', danger: true })) return
			try { await api(`${kindsUrl()}/${encodeURIComponent(kind.key)}`, { method: 'DELETE', feedback: 'Dokumentart gelöscht.' }); await loadDocumentKinds(); render() } catch (error) { notifyError(error.message) }
		}))
	}

	async function editDocumentKind(key) {
		const data = catalog()
		const existing = key ? kindByKey(key) : null
		const kind = existing ? { ...existing } : { key: '', name: '', description: '', hint: '', paperlessTypeId: null, folderKey: (data.folders || [])[0]?.key || '', category: 'Korrespondenz', effect: 'FILE', classification: '', countLabel: '', active: true }
		const types = await paperlessTypes()
		const typeField = types.available
			? `<select name="paperlessTypeId"><option value="">– keiner –</option>${types.types.map((type) => `<option value="${type.id}" ${Number(kind.paperlessTypeId) === Number(type.id) ? 'selected' : ''}>${esc(type.name)} (${type.id})</option>`).join('')}${kind.paperlessTypeId && !types.types.some((type) => Number(type.id) === Number(kind.paperlessTypeId)) ? `<option value="${kind.paperlessTypeId}" selected>Typ ${kind.paperlessTypeId} (in Paperless nicht gefunden)</option>` : ''}</select>`
			: `<input name="paperlessTypeId" type="number" min="1" step="1" value="${kind.paperlessTypeId || ''}" placeholder="ID aus Paperless">`
		root.insertAdjacentHTML('beforeend', `<div class="bp-modal" role="dialog" aria-modal="true" aria-labelledby="kind-dialog-title"><form class="bp-wide-modal bp-process-dialog bp-document-kind-dialog"><div class="bp-panel-head"><div><p class="bp-eyebrow">Customizing → Dokumentarten</p><h2 id="kind-dialog-title">${existing ? `Dokumentart „${esc(existing.name)}“ bearbeiten` : 'Neue Dokumentart'}</h2></div><button type="button" class="bp-secondary" data-close-dialog>Schließen</button></div><div class="bp-process-dialog-body">
			<fieldset><legend>Bezeichnung</legend><div class="bp-form-grid"><label class="bp-span-2"><span>Name</span><input name="name" value="${esc(kind.name)}" maxlength="100" required></label><label class="bp-span-3"><span>Beschreibung</span><input name="description" value="${esc(kind.description || '')}" maxlength="500" placeholder="z. B. Ausfertigungen vom Standesamt"></label><label class="bp-span-3"><span>Hinweis bei der Zuordnung (optional)</span><input name="hint" value="${esc(kind.hint || '')}" maxlength="500" placeholder="z. B. Nur den nichtvertraulichen Teil ablegen."></label></div></fieldset>
			<fieldset><legend>Erkennung</legend><div class="bp-form-grid"><label class="bp-span-2"><span>Paperless-Dokumenttyp</span>${typeField}</label></div><p class="bp-muted">Dokumente mit diesem Typ schlägt der Belegeingang als „${esc(kind.name || 'diese Art')}“ vor. Jeder Paperless-Dokumenttyp gehört zu höchstens einer aktiven Art.${types.available ? '' : ` ${esc(types.message || '')}`}</p></fieldset>
			<fieldset><legend>Ablage und Wirkung</legend><div class="bp-form-grid"><label><span>Ablage im Fall</span><select name="folderKey">${(data.folders || []).map((folder) => `<option value="${esc(folder.key)}" ${folder.key === kind.folderKey ? 'selected' : ''}>${esc(folder.name)}</option>`).join('')}</select></label><label><span>Kategorie</span><select name="category">${(data.categories || []).map((category) => `<option ${category === kind.category ? 'selected' : ''}>${esc(category)}</option>`).join('')}</select></label><label><span>Wirkung im Fall</span><select name="effect">${Object.entries(data.effects || {}).map(([effect, label]) => `<option value="${esc(effect)}" ${effect === kind.effect ? 'selected' : ''}>${esc(label)}</option>`).join('')}</select></label><label data-kind-when="INCOMING_INVOICE"><span>Einordnung der Rechnungsposition</span><select name="classification">${(data.classifications || []).map((value) => `<option value="${esc(value)}" ${value === (kind.classification || 'CLASSIFICATION_PENDING') ? 'selected' : ''}>${esc(classificationLabels[value] || value)}</option>`).join('')}</select></label><label data-kind-when="FILE"><span>Anzahl abfragen (optional)</span><input name="countLabel" value="${esc(kind.countLabel || '')}" maxlength="60" placeholder="z. B. Ausfertigungen"></label></div><p class="bp-muted" data-kind-effect-help></p></fieldset>
			<fieldset><legend>Einstellungen</legend><label class="bp-inline-check"><input type="checkbox" name="active" ${kind.active ? 'checked' : ''}> aktiv (im Belegeingang, beim Hochladen und in Abläufen wählbar)</label>${existing ? `<p class="bp-muted">Technischer Schlüssel: <code>${esc(existing.key)}</code></p>` : '<label><span>Technischer Schlüssel (leer = aus dem Namen)</span><input name="key" pattern="[A-Za-z][A-Za-z0-9_]*" maxlength="60"></label>'}</fieldset>
		</div><p class="bp-save-state" aria-live="polite"></p><div class="bp-modal-actions"><button type="button" class="bp-secondary" data-close-dialog>Abbrechen</button><button class="bp-primary">Speichern</button></div></form></div>`)
		const modal = [...root.querySelectorAll('.bp-modal')].pop()
		const form = modal.querySelector('form')
		const close = () => modal.remove()
		modal.querySelectorAll('[data-close-dialog]').forEach((button) => { button.onclick = close })
		modal.addEventListener('keydown', (event) => { if (event.key === 'Escape') close() })
		const help = {
			FILE: 'Das Dokument wird nur abgelegt. Mit „Anzahl abfragen“ fragt die Zuordnung zusätzlich eine Anzahl ab (z. B. Ausfertigungen einer Urkunde).',
			INCOMING_INVOICE: 'Es entsteht eine Eingangsrechnung im Reiter „Finanzen“; Kategorie muss „Rechnung“ sein. Die Einordnung gilt für die erste Rechnungsposition.',
			DEREGISTRATION: 'Bei der Zuordnung lässt sich eine versendete Abmeldung des Falls auswählen; sie wird als bestätigt markiert, die Aufgabe „Rückmeldung prüfen“ erledigt sich.',
			PAPER_CONTRACT: 'Der Scan (nur PDF) wird als unterschriebener Vertrag abgelegt und wartet im Reiter „Dokumente“ auf die Unterschriftenprüfung.',
		}
		const refresh = () => {
			const effect = form.elements.effect.value
			form.querySelectorAll('[data-kind-when]').forEach((element) => { element.hidden = !element.dataset.kindWhen.split(' ').includes(effect) })
			if (effect === 'INCOMING_INVOICE') form.elements.category.value = 'Rechnung'
			form.querySelector('[data-kind-effect-help]').textContent = help[effect] || ''
		}
		form.elements.effect.addEventListener('change', refresh)
		refresh()
		form.onsubmit = async (event) => {
			event.preventDefault()
			const state_ = form.querySelector('.bp-save-state')
			const payload = {
				key: existing ? existing.key : (form.elements.key?.value || '').trim().toUpperCase(),
				name: form.elements.name.value.trim(), description: form.elements.description.value.trim(), hint: form.elements.hint.value.trim(),
				paperlessTypeId: form.elements.paperlessTypeId.value ? Number(form.elements.paperlessTypeId.value) : null,
				folderKey: form.elements.folderKey.value, category: form.elements.category.value, effect: form.elements.effect.value,
				classification: form.elements.effect.value === 'INCOMING_INVOICE' ? form.elements.classification.value : '',
				countLabel: form.elements.effect.value === 'FILE' ? form.elements.countLabel.value.trim() : '',
				active: form.elements.active.checked,
			}
			try {
				await api(existing ? `${kindsUrl()}/${encodeURIComponent(existing.key)}` : kindsUrl(), { method: existing ? 'PUT' : 'POST', feedback: existing ? 'Dokumentart gespeichert.' : 'Dokumentart angelegt.', body: new URLSearchParams({ kind: JSON.stringify(payload) }) })
				close(); await loadDocumentKinds(); render()
			} catch (error) { state_.textContent = error.message; state_.dataset.state = 'error' }
		}
		form.elements.name.focus()
	}

	return { loadDocumentKinds, documentKindOptions, documentKindsAdminView, bindDocumentKindsAdmin, paperlessTypes, documentKindByKey: kindByKey, activeDocumentKinds: () => kinds(true) }
}
