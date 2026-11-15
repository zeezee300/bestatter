import { handbookMoreLink } from './handbook.js'
// 0.89.7 (OP-074/OP-075): Customizing → Auftragsarten (Aufträge ohne Sterbefall) und Customizing → Vorsorge
// (Wiedervorlage, Vorlauf der Arbeitsübersicht, Link zum Treuhand- bzw. Versicherungsportal). Speichern nur die
// Bestatter-Administration; der Server prüft das.

export function createCaseKindSettingsModule(ctx) {
	const { root, apiBase, state } = ctx
	const api = (...args) => ctx.api(...args)
	const esc = (...args) => ctx.esc(...args)
	const render = (...args) => ctx.render(...args)

	async function loadOrderTypeSettings() {
		try { state.orderTypeSettings = await api(`${apiBase}/order-types`, { feedback: false, loadingLabel: false }) } catch (error) { state.orderTypeSettings = { types: [], error: error.message } }
		return state.orderTypeSettings
	}
	async function loadPrecareSettings() {
		try { state.precareSettings = await api(`${apiBase}/precare-settings`, { feedback: false, loadingLabel: false }) } catch (error) { state.precareSettings = { error: error.message } }
		return state.precareSettings
	}

	function typeRow(type, settings, disabled) {
		const folders = (settings.folderPlan || []).map((folder) => `<label class="bp-inline-check"><input type="checkbox" data-type-folder value="${esc(folder.key)}" ${(type.folders || []).includes(folder.key) ? 'checked' : ''} ${disabled}> ${esc(folder.name)}</label>`).join('')
		const groups = (settings.articleGroups || []).map((group) => `<label class="bp-inline-check"><input type="checkbox" data-type-group value="${esc(group.key)}" ${(type.articleGroups || []).includes(group.key) ? 'checked' : ''} ${disabled}> ${esc(group.key)} · ${esc(group.name)}</label>`).join('')
		const used = Number(type.usage || 0)
		return `<article class="bp-order-type" data-order-type-row data-key="${esc(type.key || '')}">
			<div class="bp-form-grid">
				<label><span>Bezeichnung</span><input data-type-field="label" maxlength="80" value="${esc(type.label || '')}" ${disabled} required></label>
				<label><span>Schlüssel</span><input data-type-field="key" maxlength="60" value="${esc(type.key || '')}" ${type.key ? 'readonly' : ''} placeholder="wird aus der Bezeichnung gebildet" ${disabled}></label>
				<label class="bp-inline-check"><input type="checkbox" data-type-field="requiresDeceased" ${type.requiresDeceased ? 'checked' : ''} ${disabled}> Mit Angaben zur verstorbenen Person</label>
				<label class="bp-inline-check"><input type="checkbox" data-type-field="active" ${type.active !== false ? 'checked' : ''} ${disabled}> Aktiv</label>
			</div>
			<details><summary>Ordner beim Anlegen${(type.folders || []).length ? ` (${type.folders.length})` : ' (wie Aufträge allgemein)'}</summary><p class="bp-muted">Ohne Auswahl gelten die Ordner, die im Ordnerplan „bei Aufträgen“ angelegt werden. Weitere Ordner entstehen bei Bedarf.</p><div class="bp-check-grid">${folders || '<p class="bp-muted">Kein Ordnerplan geladen.</p>'}</div></details>
			<details><summary>Warengruppen für die Leistungsauswahl${(type.articleGroups || []).length ? ` (${type.articleGroups.length})` : ''}</summary><p class="bp-muted">Die Leistungsauswahl dieses Auftrags schlägt zuerst Artikel dieser Warengruppen vor; alle anderen bleiben erreichbar.</p><div class="bp-check-grid">${groups || '<p class="bp-muted">Noch keine Warengruppen im Leistungskatalog.</p>'}</div></details>
			<div class="bp-row-actions"><small class="bp-muted">${used ? `In ${used} Auftrag/Aufträgen verwendet – nur deaktivieren, nicht entfernen.` : 'Noch nicht verwendet.'}</small>${disabled || used ? '' : '<button type="button" class="bp-secondary" data-type-remove>Entfernen</button>'}</div>
		</article>`
	}

	function orderTypesView() {
		const settings = state.orderTypeSettings
		if (!settings) return '<section class="bp-panel"><p class="bp-muted">Auftragsarten werden geladen …</p></section>'
		if (settings.error) return `<section class="bp-panel"><p class="bp-warning">${esc(settings.error)}</p></section>`
		const disabled = settings.canEdit ? '' : 'disabled'
		return `<section class="bp-panel bp-order-types" data-order-types>
			<div class="bp-panel-head"><div><h3>Auftragsarten</h3><p class="bp-muted">Aufträge ohne Sterbefall (Navigation „Aufträge“) – zum Beispiel Verkauf, Überführung, Urnenbeisetzung/Seebestattung, Trauerfeier/Abschiednahme. Bei Arten „mit Angaben zur verstorbenen Person“ fragt die Anlage Verstorbene/n und Auftraggeber getrennt ab.${handbookMoreLink('customizing-auftragsarten', esc)}</p></div></div>
			${settings.canEdit ? '' : '<p class="bp-muted">Nur Bestatter-Administratoren können die Auftragsarten ändern.</p>'}
			${settings.isDefault ? '<p class="bp-muted">Es gelten die vier Grundarten. Warengruppen und Ordner ergänzt das Einrichtungspaket oder die Pflege hier.</p>' : ''}
			<div data-order-type-list>${(settings.types || []).map((type) => typeRow(type, settings, disabled)).join('')}</div>
			<p class="bp-card-state" data-order-types-state aria-live="polite"></p>
			${settings.canEdit ? '<div class="bp-row-actions"><button type="button" class="bp-secondary" data-type-add>Auftragsart hinzufügen</button><button type="button" class="bp-primary" data-types-save>Speichern</button></div>' : ''}
		</section>`
	}

	function precareView() {
		const settings = state.precareSettings
		if (!settings) return '<section class="bp-panel"><p class="bp-muted">Einstellungen werden geladen …</p></section>'
		if (settings.error) return `<section class="bp-panel"><p class="bp-warning">${esc(settings.error)}</p></section>`
		const disabled = settings.canEdit ? '' : 'disabled'
		return `<section class="bp-panel bp-precare-settings" data-precare-settings>
			<div class="bp-panel-head"><div><h3>Vorsorge</h3><p class="bp-muted">Betreuung laufender Vorsorgen. Die Frist für Beratungen ohne Vertragsabschluss steht in der Aufbewahrungsrichtlinie (Administration → Datenschutz).${handbookMoreLink('customizing-vorsorge', esc)}</p></div></div>
			${settings.canEdit ? '' : '<p class="bp-muted">Nur Bestatter-Administratoren können diese Einstellungen ändern.</p>'}
			<form class="bp-form-grid" data-precare-settings-form>
				<label><span>Wiedervorlage alle … Monate</span><input type="number" name="reviewMonths" min="1" max="120" value="${Number(settings.reviewMonths || 24)}" ${disabled}><small>Standard für neue Vorsorgen; je Vorsorge änderbar.</small></label>
				<label><span>Vorlauf in der Arbeitsübersicht (Tage)</span><input type="number" name="leadDays" min="0" max="180" value="${Number(settings.leadDays ?? 30)}" ${disabled}></label>
				<label><span>Portal (Bezeichnung)</span><input name="portalLabel" maxlength="80" value="${esc(settings.portalLabel || '')}" placeholder="z. B. Treuhandportal" ${disabled}></label>
				<label><span>Portal (Adresse)</span><input name="portalUrl" type="url" maxlength="500" value="${esc(settings.portalUrl || '')}" placeholder="https://" ${disabled}><small>Abschluss und Abfrage beim Treuhänder bzw. Versicherer erfolgen dort, nicht in der App.</small></label>
			</form>
			<p class="bp-card-state" data-precare-settings-state aria-live="polite"></p>
			${settings.canEdit ? '<div class="bp-row-actions"><button type="button" class="bp-primary" data-precare-settings-save>Speichern</button></div>' : ''}
		</section>`
	}

	function bindOrderTypes() {
		if (!state.orderTypeSettings) { loadOrderTypeSettings().then(() => { if (state.view === 'customizing' && state.customizingTab === 'order-types') render() }); return }
		const panel = root.querySelector('[data-order-types]')
		if (!panel) return
		const message = panel.querySelector('[data-order-types-state]')
		const bindRemove = () => panel.querySelectorAll('[data-type-remove]').forEach((button) => { button.onclick = () => button.closest('[data-order-type-row]').remove() })
		bindRemove()
		panel.querySelector('[data-type-add]')?.addEventListener('click', () => {
			panel.querySelector('[data-order-type-list]').insertAdjacentHTML('beforeend', typeRow({ key: '', label: '', requiresDeceased: false, active: true, folders: [], articleGroups: [] }, state.orderTypeSettings, ''))
			bindRemove()
			;[...panel.querySelectorAll('[data-type-field="label"]')].at(-1)?.focus()
		})
		panel.querySelector('[data-types-save]')?.addEventListener('click', async () => {
			const types = [...panel.querySelectorAll('[data-order-type-row]')].map((row, index) => ({
				key: row.querySelector('[data-type-field="key"]').value.trim(), label: row.querySelector('[data-type-field="label"]').value.trim(),
				requiresDeceased: row.querySelector('[data-type-field="requiresDeceased"]').checked, active: row.querySelector('[data-type-field="active"]').checked,
				folders: [...row.querySelectorAll('[data-type-folder]:checked')].map((input) => input.value), articleGroups: [...row.querySelectorAll('[data-type-group]:checked')].map((input) => input.value), sortOrder: (index + 1) * 10,
			})).filter((type) => type.key || type.label)
			try {
				state.orderTypeSettings = await api(`${apiBase}/order-types`, { method: 'PUT', feedback: false, body: new URLSearchParams({ settings: JSON.stringify({ types }) }) })
				state.orderTypes = null
				render(); ctx.notifySuccess('Auftragsarten gespeichert.')
			} catch (error) { message.textContent = error.message; message.dataset.state = 'error' }
		})
	}

	function bindPrecare() {
		if (!state.precareSettings) { loadPrecareSettings().then(() => { if (state.view === 'customizing' && state.customizingTab === 'precare') render() }); return }
		const panel = root.querySelector('[data-precare-settings]')
		if (!panel) return
		panel.querySelector('[data-precare-settings-save]')?.addEventListener('click', async () => {
			const data = Object.fromEntries(new FormData(panel.querySelector('form')).entries())
			const message = panel.querySelector('[data-precare-settings-state]')
			try {
				state.precareSettings = await api(`${apiBase}/precare-settings`, { method: 'PUT', feedback: false, body: new URLSearchParams({ settings: JSON.stringify({ reviewMonths: Number(data.reviewMonths), leadDays: Number(data.leadDays), portalLabel: data.portalLabel || '', portalUrl: data.portalUrl || '' }) }) })
				render(); ctx.notifySuccess('Einstellungen der Vorsorge gespeichert.')
			} catch (error) { message.textContent = error.message; message.dataset.state = 'error' }
		})
	}

	function view(tab) {
		if (tab === 'order-types') return orderTypesView()
		if (tab === 'precare') return precareView()
		return null
	}

	function bind() {
		if (state.view !== 'customizing') return
		if (state.customizingTab === 'order-types') bindOrderTypes()
		if (state.customizingTab === 'precare') bindPrecare()
	}

	return { view, bind, loadOrderTypeSettings, loadPrecareSettings }
}
