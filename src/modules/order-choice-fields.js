import { handbookMoreLink } from './handbook.js'
// 0.89.3: Customizing → Ankreuzfelder im Bestattungsauftrag
// - je Feld Bezeichnung, fester Schlüssel (Platzhalter {{order.choice.SCHLÜSSEL}}), Abschnitt, wer ankreuzt (Bestattungsunternehmen
//   mit Vorbelegung oder Kunde bei der Unterschrift am Tablet), Vorbelegung aus Warengruppen/Trauerfeier/Feuerbestattung,
//   Abgleich mit den Positionen, aktiv, Reihenfolge
// - die sieben mitgelieferten Felder lassen sich ändern und deaktivieren, nicht löschen; ihre bisherigen Platzhalter gelten weiter

/** Schlüssel aus einer Bezeichnung (Großbuchstaben, Ziffern, _), z. B. „Traueranzeige veröffentlichen“ → TRAUERANZEIGE_VEROEFFENTLICHEN. */
export function choiceKeyFromLabel(label) {
	const key = String(label || '').toUpperCase().replace(/Ä/g, 'AE').replace(/Ö/g, 'OE').replace(/Ü/g, 'UE').replace(/ß/g, 'SS')
		.replace(/[^A-Z0-9]+/g, '_').replace(/^_+|_+$/g, '').replace(/^[0-9_]+/, '').slice(0, 40)
	return key.length >= 2 ? key : ''
}

export function createOrderChoiceFieldsModule(ctx) {
	const { root, apiBase, state } = ctx
	const api = (...args) => ctx.api(...args)
	const esc = (...args) => ctx.esc(...args)
	const render = (...args) => ctx.render(...args)
	const url = () => `${apiBase}/order-choice-fields`

	async function load() {
		try { state.orderChoiceFields = await api(url(), { feedback: false, loadingLabel: false }) } catch (error) { state.orderChoiceFields = { fields: [], error: error.message } }
		return state.orderChoiceFields
	}

	const options = (map, selected) => Object.entries(map || {}).map(([value, label]) => `<option value="${esc(value)}" ${value === selected ? 'selected' : ''}>${esc(label)}</option>`).join('')

	function fieldRow(field, index, catalog) {
		const editable = catalog.canEdit
		const disabled = editable ? '' : 'disabled'
		const customer = field.actor === 'CUSTOMER'
		const rule = field.prefill === 'RULE'
		const groups = catalog.groups || []
		const chosen = new Set(field.groups || [])
		const placeholders = (field.placeholders?.length ? field.placeholders : (field.key ? [`{{order.choice.${field.key}}}`] : [])).map((placeholder) => `<code>${esc(placeholder)}</code>`).join(' oder ')
		return `<article class="bp-choice-field${field.active === false ? ' is-inactive' : ''}" data-choice-field="${index}" data-choice-system="${field.system ? '1' : '0'}" data-choice-new="${field.isNew ? '1' : '0'}">
			<div class="bp-choice-field-head"><span class="bp-position-number">${index + 1}</span>
				<label class="bp-choice-label"><span>Bezeichnung (wie im Dokument)</span><input data-choice="label" value="${esc(field.label)}" maxlength="300" ${disabled} required></label>
				<label class="bp-choice-key"><span>Schlüssel</span><input data-choice="key" value="${esc(field.key)}" ${field.isNew && editable ? '' : 'readonly'} maxlength="40" pattern="[A-Z][A-Z0-9_]{1,39}" placeholder="aus der Bezeichnung"></label>
				<label class="bp-inline-check"><input type="checkbox" data-choice="active" ${field.active !== false ? 'checked' : ''} ${disabled}> aktiv</label>
				${editable ? `<span class="bp-choice-move"><button type="button" class="bp-secondary" data-choice-move="-1" aria-label="nach oben" ${index === 0 ? 'disabled' : ''}>↑</button><button type="button" class="bp-secondary" data-choice-move="1" aria-label="nach unten" ${index === (catalog.fields || []).length - 1 ? 'disabled' : ''}>↓</button>${field.system ? '' : '<button type="button" class="bp-secondary bp-danger-text" data-choice-delete>Löschen</button>'}</span>` : ''}</div>
			<p class="bp-muted bp-choice-placeholder">Platzhalter für die Vorlage: ${placeholders || '<i>entsteht beim Speichern</i>'}${field.system ? ' · mitgeliefert' : ''}</p>
			<div class="bp-choice-grid">
				<label><span>Abschnitt</span><select data-choice="section" ${disabled}>${options(catalog.sections, field.section)}</select></label>
				<label><span>Wer kreuzt an</span><select data-choice="actor" ${field.isNew && editable ? '' : 'disabled'} title="Nach dem Speichern nicht mehr änderbar.">${options(catalog.actors, field.actor)}</select></label>
				${customer ? '<p class="bp-muted bp-choice-customer">Nicht vorbelegt. Der Auftraggeber kreuzt das Feld bei der Unterschrift am Tablet selbst an; auf Papier von Hand.</p>' : `
				<label><span>Vorbelegung</span><select data-choice="prefill" ${disabled}>${options(catalog.prefills, field.prefill)}</select></label>
				<label ${rule ? '' : 'hidden'}><span>Abgleich mit den Positionen</span><select data-choice="check" ${disabled}>${options(catalog.checks, field.check)}</select></label>`}
			</div>
			${!customer && rule ? `<div class="bp-choice-rule"><span>Angekreuzt, wenn</span><label class="bp-inline-check"><input type="checkbox" data-choice="ceremony" ${field.ceremony ? 'checked' : ''} ${disabled}> eine Trauerfeier vorgesehen ist</label><label class="bp-inline-check"><input type="checkbox" data-choice="cremation" ${field.cremation ? 'checked' : ''} ${disabled}> es eine Feuerbestattung ist</label>
				<details class="bp-choice-groups" ${chosen.size ? '' : 'open'}><summary>oder Positionen dieser Warengruppen beauftragt sind (${chosen.size ? [...chosen].join(', ') : 'keine'})</summary><div class="bp-choice-group-list">${groups.map((group) => `<label class="bp-inline-check"><input type="checkbox" data-choice-group="${esc(group.key)}" ${chosen.has(group.key) ? 'checked' : ''} ${disabled}> ${esc(group.key)} · ${esc(group.name)}</label>`).join('')}</div></details></div>` : ''}
		</article>`
	}

	function view() {
		const catalog = state.orderChoiceFields
		if (!catalog) return '<section class="bp-panel"><p class="bp-muted">Ankreuzfelder werden geladen …</p></section>'
		if (catalog.error) return `<section class="bp-panel"><p class="bp-warning">${esc(catalog.error)}</p></section>`
		const fields = catalog.fields || []
		return `<section class="bp-panel bp-choice-admin" data-order-choice-admin>
			<div class="bp-panel-head"><div><h3>Ankreuzfelder im Bestattungsauftrag</h3><p class="bp-muted">Kästchen im Dokument „Bestattungsauftrag und Vollmacht“. Felder des Bestattungsunternehmens belegt die App aus Leistungen und Bestattung vor; sie lassen sich im Auftrag ändern und weisen vor dem Festschreiben auf Widersprüche zu den Positionen hin. Felder des Kunden kreuzt der Auftraggeber bei der Unterschrift am Tablet selbst an. Jedes Feld braucht seinen Platzhalter in der Vorlage (Prüfung unter „Dokumentvorlagen“).${handbookMoreLink('customizing-ankreuzfelder', esc)}</p></div><span class="bp-status">${fields.filter((field) => field.active !== false).length} aktiv</span></div>
			${catalog.canEdit ? '' : '<p class="bp-muted">Nur Bestatter-Administratoren können Ankreuzfelder ändern.</p>'}
			<div class="bp-choice-list">${fields.map((field, index) => fieldRow(field, index, catalog)).join('')}</div>
			<p class="bp-card-state" data-choice-state aria-live="polite"></p>
			${catalog.canEdit ? '<div class="bp-row-actions"><button type="button" class="bp-secondary" id="add-order-choice">+ Ankreuzfeld</button><button type="button" class="bp-primary" id="save-order-choices">Speichern</button></div>' : ''}
		</section>`
	}

	/** Eingaben der Liste in den Zustand übernehmen (vor Neuzeichnen und zum Speichern). */
	function collect() {
		const catalog = state.orderChoiceFields
		const rows = [...root.querySelectorAll('[data-choice-field]')]
		catalog.fields = rows.map((row) => {
			const previous = catalog.fields[Number(row.dataset.choiceField)] || {}
			const value = (name) => row.querySelector(`[data-choice="${name}"]`)
			const actor = value('actor')?.value || previous.actor || 'COMPANY'
			return {
				...previous,
				key: (value('key')?.value || '').trim().toUpperCase(),
				label: value('label')?.value.trim() || '',
				section: value('section')?.value || previous.section,
				actor,
				prefill: actor === 'CUSTOMER' ? 'NEVER' : (value('prefill')?.value || previous.prefill || 'NEVER'),
				check: value('check')?.value || previous.check || 'NONE',
				ceremony: value('ceremony') ? value('ceremony').checked : Boolean(previous.ceremony),
				cremation: value('cremation') ? value('cremation').checked : Boolean(previous.cremation),
				groups: row.querySelector('[data-choice-group]') ? [...row.querySelectorAll('[data-choice-group]:checked')].map((box) => box.dataset.choiceGroup) : (previous.groups || []),
				active: value('active') ? value('active').checked : previous.active !== false,
			}
		})
		return catalog.fields
	}

	function bind() {
		if (state.view !== 'customizing' || state.customizingTab !== 'order-choices') return
		if (!state.orderChoiceFields) { load().then(() => { if (state.view === 'customizing' && state.customizingTab === 'order-choices') render() }); return }
		const panel = root.querySelector('[data-order-choice-admin]')
		if (!panel || !state.orderChoiceFields.canEdit) return
		const message = panel.querySelector('[data-choice-state]')
		panel.querySelectorAll('[data-choice-field]').forEach((row) => {
			const label = row.querySelector('[data-choice="label"]'), key = row.querySelector('[data-choice="key"]')
			if (row.dataset.choiceNew === '1') {
				label.addEventListener('input', () => { if (key.dataset.manual !== '1') key.value = choiceKeyFromLabel(label.value) })
				key.addEventListener('input', () => { key.dataset.manual = '1'; key.value = key.value.toUpperCase() })
			}
			// Wechsel von „Wer kreuzt an“ oder „Vorbelegung“ blendet die passenden Angaben ein.
			row.querySelectorAll('[data-choice="actor"], [data-choice="prefill"]').forEach((select) => select.addEventListener('change', () => { collect(); render() }))
			row.querySelectorAll('[data-choice-move]').forEach((button) => button.addEventListener('click', () => {
				const fields = collect(), index = Number(row.dataset.choiceField), target = index + Number(button.dataset.choiceMove)
				if (target < 0 || target >= fields.length) return
				;[fields[index], fields[target]] = [fields[target], fields[index]]
				render()
			}))
			row.querySelector('[data-choice-delete]')?.addEventListener('click', async () => {
				const fields = collect(), index = Number(row.dataset.choiceField)
				if (!fields[index].isNew && !await ctx.confirmAction(`„${fields[index].label}“ löschen? Ein Platzhalter dieses Feldes in einer Vorlage wird danach nicht mehr ersetzt; deaktivieren behält ihn. Das Löschen wirkt erst mit „Speichern“.`, { title: 'Ankreuzfeld löschen', confirmLabel: 'Löschen', danger: true })) return
				fields.splice(index, 1); render()
			})
		})
		panel.querySelector('#add-order-choice')?.addEventListener('click', () => {
			collect().push({ key: '', label: '', section: 'CONSENT', actor: 'CUSTOMER', prefill: 'NEVER', groups: [], ceremony: false, cremation: false, check: 'NONE', active: true, system: false, isNew: true })
			render()
			requestAnimationFrame(() => [...root.querySelectorAll('[data-choice-field] [data-choice="label"]')].pop()?.focus())
		})
		panel.querySelector('#save-order-choices')?.addEventListener('click', async (event) => {
			const button = event.currentTarget
			const fields = collect().map(({ key, label, section, actor, prefill, check, ceremony, cremation, groups, active }) => ({ key, label, section, actor, prefill, check, ceremony, cremation, groups, active }))
			const missingKey = fields.find((field) => !/^[A-Z][A-Z0-9_]{1,39}$/.test(field.key))
			if (missingKey) { message.textContent = `Bitte für „${missingKey.label || 'neues Feld'}“ einen Schlüssel angeben (Großbuchstaben, Ziffern, _).`; message.dataset.state = 'error'; return }
			button.disabled = true
			try {
				state.orderChoiceFields = await api(url(), { method: 'PUT', feedback: false, body: new URLSearchParams({ fields: JSON.stringify(fields) }) }); state.catalogReferences = undefined
				state.documentTemplateFiles = null
				render()
				ctx.notifySuccess('Ankreuzfelder gespeichert. Neue Felder bitte mit ihrem Platzhalter in die Vorlage „Bestattungsauftrag und Vollmacht“ einsetzen.')
			} catch (error) {
				button.disabled = false
				message.textContent = error.message; message.dataset.state = 'error'
			}
		})
	}

	return { view, bind, load }
}
