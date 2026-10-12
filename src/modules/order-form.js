/**
 * 0.74.0 – Auftragsformular (Stufe 1): Erfassung der Beträge eines Papier-Bestattungsauftrags im Fall
 * und Pflege der Formularvorlagen (Zuordnung Zeile → Katalogartikel, Warengruppe oder Freitext).
 */
export function createOrderFormModule(ctx) {
	const { state, esc, api, apiBase, render, notifySuccess, notifyError, notifyWarning, confirmAction, download } = ctx
	const euro = new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' })
	const money = (cents) => euro.format(Number(cents || 0) / 100)
	const caseUrl = () => `${apiBase}/cases/${state.currentCase.id}/order-form`
	const of = () => (state.orderForm ||= { open: false, data: null, formId: 0, values: {}, declaredTotal: '', amendmentReason: '', report: null })

	/** „1.234,56“, „1234,56“, „1234.56“, „98“ → Cent; leer → null; ungültig → NaN. */
	function parseEuro(value) {
		let text = String(value ?? '').replace(/[\s€ ]/g, '')
		if (text === '') return null
		if (/^\d{1,3}(\.\d{3})+(,\d{1,2})?$/.test(text)) text = text.replace(/\./g, '').replace(',', '.')
		else text = text.replace(',', '.')
		if (!/^\d+(\.\d{1,2})?$/.test(text)) return Number.NaN
		return Math.round(Number(text) * 100)
	}
	const centsToInput = (cents) => cents ? (Number(cents) / 100).toFixed(2).replace('.', ',') : ''

	function currentForm() {
		const data = of().data
		if (!data) return null
		return data.forms.find((form) => Number(form.id) === Number(of().formId)) || data.forms[0] || null
	}
	function entryFor(formId) { return (of().data?.entries || []).find((entry) => Number(entry.formId) === Number(formId)) || null }

	function loadValues(form) {
		const entry = entryFor(form.id)
		const values = {}
		for (const [key, value] of Object.entries(entry?.values || {})) values[key] = { ...value, amount: value.amountCents ? centsToInput(value.amountCents) : '' }
		of().values = values
		of().declaredTotal = entry?.declaredTotalCents ? centsToInput(entry.declaredTotalCents) : ''
		of().report = null
	}

	async function openOrderForm() {
		try {
			of().data = await api(caseUrl(), { loadingLabel: 'Auftragsformular wird geladen …' })
			if (!of().data.forms.length) { notifyWarning('Es ist kein aktives Auftragsformular vorhanden. Bitte unter Einstellungen → Administration → Leistungen → Auftragsformulare anlegen.'); return }
			const lastUsed = of().data.entries[0]?.formId
			of().formId = of().data.forms.some((form) => Number(form.id) === Number(lastUsed)) ? Number(lastUsed) : Number(of().data.forms[0].id)
			loadValues(currentForm())
			of().amendmentReason = ''
			of().caseId = state.currentCase.id
			of().open = true
			render()
		} catch (error) { notifyError(error.message) }
	}

	function lineAmount(line) { const cents = parseEuro(of().values[line.key]?.amount); return Number.isFinite(cents) ? (cents || 0) : 0 }
	function sectionTotal(section) { return section.lines.reduce((sum, line) => sum + (['PRICE', 'MEMO'].includes(line.kind) ? lineAmount(line) : 0), 0) }
	function totals(form) {
		let invoice = 0; let memo = 0
		for (const section of form.definition.sections) for (const line of section.lines) {
			if (line.kind === 'PRICE') invoice += lineAmount(line)
			if (line.kind === 'MEMO') memo += lineAmount(line)
		}
		return { invoice, memo }
	}

	function assignmentLabel(line) {
		const resolution = line.resolution || {}
		if (resolution.mode === 'ARTICLE') return `${resolution.article.articleNumber} · ${resolution.article.name}`
		if (resolution.mode === 'GROUP') return `Warengruppe ${resolution.groupKey} · ${resolution.groupName}`
		if (resolution.mode === 'FREE') return `Freitext · ${line.positionType}${line.positionType === 'DP' ? ' (ohne USt.)' : ` · ${line.vatRate} %`}`
		return ''
	}

	function priceLine(line, entry) {
		const value = of().values[line.key] || {}
		const resolution = line.resolution || {}
		const cents = parseEuro(value.amount)
		const invalid = Number.isNaN(cents)
		const mapped = entry?.services?.[line.key]
		let listGross = resolution.mode === 'ARTICLE' ? resolution.article.listGrossCents : null
		const groupSelect = resolution.mode === 'GROUP' ? `<select data-of-article="${esc(line.key)}" aria-label="${esc(line.label)}: Artikel auswählen"><option value="">Artikel wählen …</option>${resolution.options.map((option) => `<option value="${option.id}" ${Number(value.articleId) === Number(option.id) ? 'selected' : ''}>${esc(option.articleNumber)} · ${esc(option.name)}${option.listGrossCents ? ` (${esc(money(option.listGrossCents))})` : ''}</option>`).join('')}</select>` : ''
		if (resolution.mode === 'GROUP') listGross = resolution.options.find((option) => Number(option.id) === Number(value.articleId))?.listGrossCents ?? null
		const quantity = line.quantityInput ? Number(String(value.quantity || '1').replace(',', '.')) || 1 : 1
		const expected = listGross ? listGross * quantity : null
		const deviation = expected && cents && Number.isFinite(cents) && cents !== expected ? `<small class="bp-of-deviation" title="Katalogpreis inkl. MwSt.">Katalog ${esc(money(expected))}</small>` : (expected && !cents ? `<small class="bp-muted">Katalog ${esc(money(expected))}</small>` : '')
		const hasInputs = resolution.mode === 'GROUP' || line.textInput || line.quantityInput
		return `<div class="bp-of-line ${hasInputs ? 'has-inputs' : ''} ${invalid ? 'invalid' : ''} ${cents ? 'filled' : ''}" data-of-line="${esc(line.key)}">
			<div class="bp-of-label"><span>${esc(line.label)}</span>${mapped ? `<b class="bp-of-pos" title="Auftragsposition">Pos. ${esc(mapped.position)}</b>` : ''}<small>${esc(assignmentLabel(line))}${resolution.hint ? ` · ${esc(resolution.hint)}` : ''}</small></div>
			<div class="bp-of-inputs">${groupSelect}${line.textInput ? `<input type="text" data-of-text="${esc(line.key)}" value="${esc(value.text || '')}" placeholder="${esc(line.textLabel || (line.textRequired ? 'Bezeichnung' : 'Zusatz'))}" aria-label="${esc(line.label)}: ${esc(line.textLabel || 'Bezeichnung')}" maxlength="190">` : ''}${line.quantityInput ? `<label class="bp-of-qty"><input type="number" min="1" step="1" data-of-quantity="${esc(line.key)}" value="${esc(value.quantity || '')}" aria-label="${esc(line.label)}: ${esc(line.quantityLabel)}"><span>${esc(line.quantityLabel)}</span></label>` : ''}</div>
			<div class="bp-of-amount"><input type="text" inputmode="decimal" data-of-amount="${esc(line.key)}" value="${esc(value.amount || '')}" placeholder="" aria-label="${esc(line.label)}: Betrag in Euro inkl. MwSt." aria-invalid="${invalid}"><span aria-hidden="true">€</span>${deviation}</div>
		</div>`
	}

	function memoLine(line) {
		const value = of().values[line.key] || {}
		const cents = parseEuro(value.amount)
		return `<div class="bp-of-line bp-of-memo ${Number.isNaN(cents) ? 'invalid' : ''}" data-of-line="${esc(line.key)}"><div class="bp-of-label"><span>${esc(line.label)}</span><small>Merkposten · nicht Teil der Rechnung</small></div><div class="bp-of-inputs"></div><div class="bp-of-amount"><input type="text" inputmode="decimal" data-of-amount="${esc(line.key)}" value="${esc(value.amount || '')}" placeholder="" aria-label="${esc(line.label)}: voraussichtlicher Betrag"><span aria-hidden="true">€</span></div></div>`
	}

	function noteLine(line) {
		const value = of().values[line.key] || {}
		return `<label class="bp-of-note"><span>${esc(line.label)}</span>${line.multiline ? `<textarea rows="3" data-of-text="${esc(line.key)}" maxlength="2000">${esc(value.text || '')}</textarea>` : `<input type="text" data-of-text="${esc(line.key)}" value="${esc(value.text || '')}" maxlength="2000">`}</label>`
	}

	function reportView() {
		const report = of().report
		if (!report) return ''
		const warnings = (report.warnings || []).map((text) => `<li>${esc(text)}</li>`).join('')
		return `<aside class="bp-of-report ${warnings ? 'has-warnings' : ''}" role="status"><b>${report.lines?.length ? `${report.lines.length} Position(en) aus dem Formular übernommen.` : 'Formularwerte gespeichert.'}</b>${warnings ? `<ul>${warnings}</ul>` : ''}</aside>`
	}

	function orderFormView() {
		const form = currentForm()
		if (!form) return ''
		const entry = entryFor(form.id)
		const protection = of().data.contractProtection || {}
		const { invoice, memo } = totals(form)
		const declared = parseEuro(of().declaredTotal)
		const declaredDiff = Number.isFinite(declared) && declared !== null && declared !== invoice
		const formSelect = of().data.forms.length > 1 ? `<label class="bp-of-select"><span>Formular</span><select id="of-form-select">${of().data.forms.map((item) => `<option value="${item.id}" ${Number(item.id) === Number(form.id) ? 'selected' : ''}>${esc(item.name)}</option>`).join('')}</select></label>` : ''
		const sections = form.definition.sections.map((section) => {
			const lines = section.lines.map((line) => line.kind === 'PRICE' ? priceLine(line, entry) : line.kind === 'MEMO' ? memoLine(line) : noteLine(line)).join('')
			const hasAmounts = section.lines.some((line) => line.kind !== 'NOTE')
			return `<section class="bp-of-section ${section.memo ? 'bp-of-section-memo' : ''}" aria-label="${esc(section.title)}"><h4>${esc(section.title)}</h4>${lines}${hasAmounts ? `<div class="bp-of-subtotal"><span>${section.memo ? 'Summe (nicht in der Rechnung)' : 'Zwischensumme'}</span><b data-of-section-total="${esc(section.key)}">${esc(money(sectionTotal(section)))}</b></div>` : ''}</section>`
		}).join('')
		const locked = protection.active
		return `<section class="bp-panel bp-order-form-entry" id="bp-order-form-entry">
			<div class="bp-panel-head"><div><h3>Nach Auftragsformular erfassen</h3><p class="bp-muted">${esc(form.name)} · Beträge wie auf dem Papier <b>inkl. MwSt.</b> eintragen. „In Positionen übernehmen“ legt daraus Auftragspositionen an oder aktualisiert sie; eine geleerte Zeile entfernt die aus dem Formular stammende Position.${entry?.appliedAt ? ` Zuletzt übernommen am ${esc(new Date(entry.appliedAt).toLocaleString('de-DE'))}.` : ''}</p></div><div class="bp-row-actions">${formSelect}<button type="button" class="bp-secondary" id="of-close">Zurück zu den Positionen</button></div></div>
			${locked ? `<aside class="bp-warning">Der Auftrag ist festgeschrieben. Änderungen werden als begründeter Vertragsnachtrag übernommen.<label class="bp-of-reason"><span>Begründung des Nachtrags</span><input id="of-amendment-reason" value="${esc(of().amendmentReason)}" minlength="5" maxlength="500"></label></aside>` : ''}
			${reportView()}
			<div class="bp-of-grid">${sections}</div>
			<div class="bp-of-footer">
				<div class="bp-of-sum"><span>Summe der Positionen (inkl. MwSt.)</span><b id="of-invoice-total">${esc(money(invoice))}</b></div>
				${memo ? `<div class="bp-of-sum bp-muted"><span>Friedhofskosten / Merkposten (nicht in der Rechnung)</span><b id="of-memo-total">${esc(money(memo))}</b></div>` : ''}
				<label class="bp-of-declared"><span>Summe laut Papierformular (optional)</span><input type="text" inputmode="decimal" id="of-declared-total" value="${esc(of().declaredTotal)}" placeholder="0,00"><small id="of-declared-state" class="${declaredDiff ? 'bp-of-deviation' : 'bp-muted'}">${declaredDiff ? `Abweichung ${esc(money(invoice - declared))} – bitte Beträge prüfen.` : 'Zum Abgleich mit der handschriftlichen Gesamtsumme.'}</small></label>
				<div class="bp-row-actions"><button type="button" class="bp-secondary" id="of-save">Zwischenspeichern</button><button type="button" class="bp-primary" id="of-apply">In Positionen übernehmen</button></div>
			</div>
		</section>`
	}

	function refreshTotals() {
		const form = currentForm(); if (!form) return
		const root = document.getElementById('bp-order-form-entry'); if (!root) return
		for (const section of form.definition.sections) { const node = root.querySelector(`[data-of-section-total="${CSS.escape(section.key)}"]`); if (node) node.textContent = money(sectionTotal(section)) }
		const { invoice, memo } = totals(form)
		root.querySelector('#of-invoice-total').textContent = money(invoice)
		const memoNode = root.querySelector('#of-memo-total'); if (memoNode) memoNode.textContent = money(memo)
		const declared = parseEuro(of().declaredTotal)
		const stateNode = root.querySelector("#of-declared-state")
		const diff = Number.isFinite(declared) && declared !== null && declared !== invoice
		stateNode.className = diff ? "bp-of-deviation" : "bp-muted"
		stateNode.textContent = diff ? `Abweichung ${money(invoice - declared)} – bitte Beträge prüfen.` : 'Zum Abgleich mit der handschriftlichen Gesamtsumme.'
	}

	function payloadValues(form) {
		const values = {}
		for (const section of form.definition.sections) for (const line of section.lines) {
			const value = of().values[line.key]
			if (!value) continue
			const amount = String(value.amount || '').trim()
			if (Number.isNaN(parseEuro(amount))) throw new Error(`„${line.label}“: Der Betrag „${amount}“ ist keine gültige Eurosumme.`)
			values[line.key] = { amount, text: value.text || '', quantity: value.quantity || '', articleId: value.articleId || 0 }
		}
		return values
	}

	async function submit(apply) {
		const form = currentForm(); if (!form) return
		try {
			if (apply && of().data.contractProtection?.active && String(of().amendmentReason || '').trim().length < 5) throw new Error('Bitte die Begründung des Vertragsnachtrags angeben (mindestens 5 Zeichen).')
			if (apply && !await confirmAction('Die Beträge des Formulars werden in die Auftragspositionen übernommen. Aus dem Formular stammende Positionen werden aktualisiert, geleerte Zeilen entfernt. Fortfahren?', { title: 'In Positionen übernehmen', confirmLabel: 'Übernehmen' })) return
			const body = new URLSearchParams({ formId: String(form.id), values: JSON.stringify(payloadValues(form)), declaredTotal: of().declaredTotal || '', apply: apply ? 'true' : 'false', amendmentReason: of().amendmentReason || '' })
			const result = await api(caseUrl(), { method: 'PUT', body, feedback: false, loadingLabel: apply ? 'Positionen werden übernommen …' : 'Formular wird gespeichert …' })
			of().data = await api(caseUrl(), { feedback: false })
			loadValues(currentForm())
			of().report = result.report
			if (apply && ctx.loadCaseServices) await ctx.loadCaseServices()
			if (result.report?.warnings?.length) notifyWarning(result.report.warnings[0])
			notifySuccess(apply ? `${result.report?.lines?.length || 0} Position(en) aus dem Auftragsformular übernommen.` : 'Formularwerte gespeichert.')
			render()
		} catch (error) { notifyError(error.message) }
	}

	function bindOrderFormEntry() {
		const root = document.getElementById('bp-order-form-entry')
		if (!root) return
		const value = (key) => (of().values[key] ||= {})
		root.querySelectorAll('[data-of-amount]').forEach((input) => input.addEventListener('input', () => {
			value(input.dataset.ofAmount).amount = input.value
			input.closest('.bp-of-line')?.classList.toggle('invalid', Number.isNaN(parseEuro(input.value)))
			refreshTotals()
		}))
		root.querySelectorAll('[data-of-text]').forEach((input) => input.addEventListener('input', () => { value(input.dataset.ofText).text = input.value }))
		root.querySelectorAll('[data-of-quantity]').forEach((input) => input.addEventListener('input', () => { value(input.dataset.ofQuantity).quantity = input.value }))
		root.querySelectorAll('[data-of-article]').forEach((select) => select.addEventListener('change', () => { value(select.dataset.ofArticle).articleId = Number(select.value) || 0; render() }))
		root.querySelector('#of-declared-total')?.addEventListener('input', (event) => { of().declaredTotal = event.target.value; refreshTotals() })
		root.querySelector('#of-amendment-reason')?.addEventListener('input', (event) => { of().amendmentReason = event.target.value })
		root.querySelector('#of-form-select')?.addEventListener('change', (event) => { of().formId = Number(event.target.value); loadValues(currentForm()); render() })
		root.querySelector('#of-close')?.addEventListener('click', () => { of().open = false; render() })
		root.querySelector('#of-save')?.addEventListener('click', () => submit(false))
		root.querySelector('#of-apply')?.addEventListener('click', () => submit(true))
	}

	// ---------------------------------------------------------------------------------------------
	// Pflege der Vorlagen (Administration → Leistungen → Auftragsformulare)
	// ---------------------------------------------------------------------------------------------
	const kindLabels = { PRICE: 'Betrag', MEMO: 'Merkposten', NOTE: 'Hinweistext' }
	const blankLine = () => ({ key: '', label: '', kind: 'PRICE', positionType: 'EL', vatRate: 19, textInput: false, textRequired: false, textLabel: '', quantityInput: false, quantityLabel: 'Stück' })

	async function loadOrderForms() {
		state.orderFormTemplates = (await api(`${apiBase}/order-forms`, { feedback: false })).forms || []
	}

	function assignmentOptions(line) {
		const current = line.articleNumber ? `A:${line.articleNumber}` : (line.groupKey ? `G:${line.groupKey}` : '')
		const articles = (state.articles || []).filter((article) => article.articleType !== 'PAKET' && (article.active || `A:${article.articleNumber}` === current))
		const groups = state.catalogGroups || []
		const known = current === '' || articles.some((article) => `A:${article.articleNumber}` === current) || groups.some((group) => `G:${group.key}` === current)
		return `<option value="" ${current === '' ? 'selected' : ''}>Freitext (ohne Katalogartikel)</option>${known ? '' : `<option value="${esc(current)}" selected>${esc(current.slice(2))} (nicht im Katalog)</option>`}<optgroup label="Warengruppe – Artikel im Fall auswählen">${groups.map((group) => `<option value="G:${esc(group.key)}" ${current === `G:${group.key}` ? 'selected' : ''}>${esc(group.key)} · ${esc(group.name)}</option>`).join('')}</optgroup><optgroup label="Fester Katalogartikel">${articles.map((article) => `<option value="A:${esc(article.articleNumber)}" ${current === `A:${article.articleNumber}` ? 'selected' : ''}>${esc(article.articleNumber)} · ${esc(article.shortName)}</option>`).join('')}</optgroup>`
	}

	function editorLine(line, sectionIndex, lineIndex, count) {
		const id = `${sectionIndex}-${lineIndex}`
		const price = line.kind === 'PRICE'
		const rates = (state.allowedVatRates?.length ? state.allowedVatRates : [0, 7, 19]).map(Number)
		return `<tr data-ofe-line="${id}">
			<td class="bp-ofe-label"><input data-ofe-field="label" value="${esc(line.label)}" title="${esc(line.label)}" aria-label="Bezeichnung" maxlength="120" required></td>
			<td><select data-ofe-field="kind" aria-label="Art" title="Betrag = wird Auftragsposition; Merkposten = nur Information, nicht in der Rechnung (z. B. Friedhofskosten als Extrarechnung); Hinweistext = Freitext ohne Betrag">${Object.entries(kindLabels).map(([value, label]) => `<option value="${value}" ${line.kind === value ? 'selected' : ''}>${label}</option>`).join('')}</select></td>
			<td>${price ? `<select data-ofe-field="assignment" aria-label="Zuordnung">${assignmentOptions(line)}</select>` : '<span class="bp-muted">–</span>'}</td>
			<td>${price ? `<select data-ofe-field="positionType" aria-label="Positionstyp (Freitext)">${['EL', 'FK', 'DP'].map((type) => `<option ${line.positionType === type ? 'selected' : ''}>${type}</option>`).join('')}</select>` : ''}</td>
			<td>${price ? `<select data-ofe-field="vatRate" aria-label="MwSt. (Freitext)" ${line.positionType === 'DP' ? 'disabled' : ''}>${rates.map((rate) => `<option value="${rate}" ${Number(line.vatRate) === rate ? 'selected' : ''}>${rate} %</option>`).join('')}</select>` : ''}</td>
			<td class="bp-ofe-text">${price ? `<div class="bp-ofe-checks"><label class="bp-inline-check"><input type="checkbox" data-ofe-field="textInput" ${line.textInput ? 'checked' : ''}> Textfeld</label>${line.textInput ? `<label class="bp-inline-check"><input type="checkbox" data-ofe-field="textRequired" ${line.textRequired ? 'checked' : ''}> Pflicht</label>` : ''}</div>${line.textInput ? `<input data-ofe-field="textLabel" value="${esc(line.textLabel || '')}" placeholder="Beschriftung" aria-label="Beschriftung des Textfelds" title="${esc(line.textLabel || '')}">` : ''}` : (line.kind === 'NOTE' ? `<div class="bp-ofe-checks"><label class="bp-inline-check"><input type="checkbox" data-ofe-field="multiline" ${line.multiline ? 'checked' : ''}> mehrzeilig</label></div>` : '')}</td>
			<td class="bp-ofe-qty">${price ? `<div class="bp-ofe-checks"><label class="bp-inline-check"><input type="checkbox" data-ofe-field="quantityInput" ${line.quantityInput ? 'checked' : ''}> Stückzahl</label></div>` : ''}</td>
			<td class="bp-ofe-actions"><button type="button" class="bp-link-button" data-ofe-move="${id}:-1" ${lineIndex === 0 ? 'disabled' : ''} aria-label="Zeile nach oben">↑</button><button type="button" class="bp-link-button" data-ofe-move="${id}:1" ${lineIndex === count - 1 ? 'disabled' : ''} aria-label="Zeile nach unten">↓</button><button type="button" class="bp-link-button" data-ofe-remove="${id}" aria-label="Zeile entfernen">✕</button></td>
		</tr>`
	}

	function orderFormEditorView() {
		const editor = state.orderFormEditor
		const sections = editor.definition.sections.map((section, sectionIndex) => `<section class="bp-ofe-section" data-ofe-section="${sectionIndex}">
			<div class="bp-ofe-section-head"><label><span>Abschnitt</span><input data-ofe-section-title="${sectionIndex}" value="${esc(section.title)}" maxlength="120"></label><div class="bp-row-actions"><button type="button" class="bp-link-button" data-ofe-section-move="${sectionIndex}:-1" ${sectionIndex === 0 ? 'disabled' : ''}>↑</button><button type="button" class="bp-link-button" data-ofe-section-move="${sectionIndex}:1" ${sectionIndex === editor.definition.sections.length - 1 ? 'disabled' : ''}>↓</button><button type="button" class="bp-link-button" data-ofe-section-remove="${sectionIndex}">Abschnitt entfernen</button></div></div>
			<div class="bp-table-scroll"><table class="bp-ofe-table"><colgroup><col class="bp-ofe-col-label"><col class="bp-ofe-col-kind"><col class="bp-ofe-col-assignment"><col class="bp-ofe-col-type"><col class="bp-ofe-col-vat"><col class="bp-ofe-col-text"><col class="bp-ofe-col-qty"><col class="bp-ofe-col-actions"></colgroup><thead><tr><th>Bezeichnung auf dem Formular</th><th>Art</th><th>Zuordnung zum Leistungskatalog</th><th>Typ</th><th>MwSt.</th><th>Textfeld</th><th>Menge</th><th><span class="hidden-visually">Aktionen</span></th></tr></thead><tbody>${section.lines.map((line, lineIndex) => editorLine(line, sectionIndex, lineIndex, section.lines.length)).join('')}</tbody></table></div>
			<button type="button" class="bp-secondary" data-ofe-add-line="${sectionIndex}">+ Zeile hinzufügen</button>
		</section>`).join('')
		return `<section class="bp-panel bp-order-form-editor" id="bp-order-form-editor">
			<div class="bp-panel-head"><div><h3>${editor.id ? 'Auftragsformular bearbeiten' : 'Neues Auftragsformular'}</h3><p class="bp-muted">Zeilen in der Reihenfolge des Papierformulars. „Zuordnung“: fester Katalogartikel, Warengruppe (Artikel wird im Fall gewählt) oder Freitext. Typ und MwSt. gelten für Freitextzeilen; bei Katalogartikeln bestimmen Artikelart und Steuerklasse. Fehlt ein zugeordneter Artikel später im Katalog, wird die Zeile als Freitext übernommen.</p></div></div>
			<div class="bp-form-grid"><label><span>Name</span><input id="ofe-name" value="${esc(editor.name)}" maxlength="190" required></label><label class="bp-span-2"><span>Beschreibung</span><input id="ofe-description" value="${esc(editor.description || '')}"></label><label class="bp-inline-check"><input type="checkbox" id="ofe-active" ${editor.active ? 'checked' : ''}> Aktiv (im Fall auswählbar)</label></div>
			${sections}
			<div class="bp-row-actions"><button type="button" class="bp-secondary" id="ofe-add-section">+ Abschnitt hinzufügen</button><span></span><button type="button" class="bp-secondary" id="ofe-cancel">Abbrechen</button><button type="button" class="bp-primary" id="ofe-save">Auftragsformular speichern</button></div>
		</section>`
	}

	function orderFormAdminView() {
		if (state.orderFormEditor) return orderFormEditorView()
		const forms = state.orderFormTemplates || []
		const rows = forms.map((form) => {
			const lines = form.definition.sections.reduce((sum, section) => sum + section.lines.length, 0)
			return `<tr><td><b>${esc(form.name)}</b><small class="bp-muted">${esc(form.key)} · Version ${form.version}</small></td><td>${form.definition.sections.length} Abschnitte · ${lines} Zeilen</td><td>${form.active ? '<span class="bp-status ready">aktiv</span>' : '<span class="bp-status">inaktiv</span>'}</td><td class="bp-row-actions"><button type="button" class="bp-secondary" data-ofe-edit="${form.id}">Bearbeiten</button><button type="button" class="bp-secondary" data-ofe-copy="${form.id}">Kopieren</button><button type="button" class="bp-secondary" data-ofe-export="${form.id}" title="Als Datei speichern, z. B. für ein Einrichtungspaket oder zur Übertragung in eine andere Installation">Exportieren</button><button type="button" class="bp-danger" data-ofe-delete="${form.id}">Löschen</button></td></tr>`
		}).join('')
		return `<section class="bp-panel bp-order-form-admin"><div class="bp-panel-head"><div><h3>Auftragsformulare</h3><p class="bp-muted">Bildet ein Papier-Bestattungsauftragsformular nach: Jede Zeile wird einmalig einem Katalogartikel, einer Warengruppe oder einer Freitextposition zugeordnet. Im Fall („Leistungen“ → „Nach Auftragsformular erfassen“) werden die Beträge dann wie auf dem Papier eingetragen und als Positionen übernommen. Ein kundenspezifisches Formular wird als Datei (z. B. aus einem Einrichtungspaket) mit „Importieren“ eingespielt.</p></div><div class="bp-row-actions"><button type="button" class="bp-secondary" id="ofe-sample" title="Neutrales Muster mit Warengruppen statt fester Artikel – passt zu jedem Leistungskatalog">Muster übernehmen</button><button type="button" class="bp-secondary" id="ofe-import">Importieren …</button><input type="file" id="ofe-import-file" accept=".json,application/json" hidden><button type="button" class="bp-primary" id="ofe-new">+ Neues Auftragsformular</button></div></div>
			<div class="bp-table-scroll"><table><thead><tr><th>Formular</th><th>Umfang</th><th>Status</th><th></th></tr></thead><tbody>${rows || '<tr><td colspan="4" class="bp-empty">Noch kein Auftragsformular angelegt. Beginnen Sie mit „Muster übernehmen“, „Importieren …“ oder „+ Neues Auftragsformular“.</td></tr>'}</tbody></table></div></section>`
	}

	function editorLineAt(id) { const [s, l] = id.split('-').map(Number); return state.orderFormEditor.definition.sections[s].lines[l] }

	function bindOrderFormAdmin() {
		const root = document.querySelector('.bp-order-form-admin, #bp-order-form-editor')
		if (!root) return
		root.querySelector('#ofe-new')?.addEventListener('click', () => { state.orderFormEditor = { id: 0, name: '', description: '', active: true, definition: { sections: [{ key: '', title: 'Pos. 1 – Leistungen', lines: [blankLine()] }] } }; render() })
		root.querySelector('#ofe-sample')?.addEventListener('click', async () => { try { await api(`${apiBase}/order-forms/sample`, { method: 'POST', feedback: 'Muster-Auftragsformular angelegt.' }); await loadOrderForms(); render() } catch (error) { notifyError(error.message) } })
		const importFile = root.querySelector('#ofe-import-file')
		root.querySelector('#ofe-import')?.addEventListener('click', () => importFile?.click())
		importFile?.addEventListener('change', async () => {
			const file = importFile.files?.[0]
			importFile.value = ''
			if (!file) return
			try {
				if (file.size > 512000) throw new Error('Die Datei ist größer als 500 KB.')
				const result = await api(`${apiBase}/order-forms/import`, { method: 'POST', body: new URLSearchParams({ content: await file.text() }), feedback: false })
				notifySuccess(`Auftragsformular „${result.form.name}“ importiert.`)
				if (result.warnings?.length) notifyWarning(`${result.warnings.length} Zeile(n) passen nicht zum Leistungskatalog und werden als Freitext übernommen: ${result.warnings.slice(0, 3).join(' ')}${result.warnings.length > 3 ? ' …' : ''} Bitte die Zuordnung unter „Bearbeiten“ prüfen.`)
				await loadOrderForms(); render()
			} catch (error) { notifyError(error.message) }
		})
		root.querySelectorAll('[data-ofe-export]').forEach((button) => button.addEventListener('click', async () => {
			const form = (state.orderFormTemplates || []).find((item) => Number(item.id) === Number(button.dataset.ofeExport))
			try { await download(`${apiBase}/order-forms/${button.dataset.ofeExport}/export`, `Auftragsformular-${form?.key || button.dataset.ofeExport}.json`) } catch (error) { notifyError(error.message) }
		}))
		root.querySelectorAll('[data-ofe-edit],[data-ofe-copy]').forEach((button) => button.addEventListener('click', () => {
			const form = (state.orderFormTemplates || []).find((item) => Number(item.id) === Number(button.dataset.ofeEdit || button.dataset.ofeCopy))
			if (!form) return
			const copy = Boolean(button.dataset.ofeCopy)
			state.orderFormEditor = { id: copy ? 0 : form.id, name: copy ? `${form.name} (Kopie)` : form.name, description: form.description, active: copy ? false : form.active, definition: JSON.parse(JSON.stringify(form.definition)) }
			render()
		}))
		root.querySelectorAll('[data-ofe-delete]').forEach((button) => button.addEventListener('click', async () => {
			if (!await confirmAction('Auftragsformular löschen? Wurde es bereits in Fällen verwendet, wird es nur deaktiviert.', { title: 'Auftragsformular löschen', confirmLabel: 'Löschen', danger: true })) return
			try { const result = await api(`${apiBase}/order-forms/${button.dataset.ofeDelete}`, { method: 'DELETE', feedback: false }); notifySuccess(result.deleted ? 'Auftragsformular gelöscht.' : `Auftragsformular deaktiviert (in ${result.entries} Fall/Fällen verwendet).`); await loadOrderForms(); render() } catch (error) { notifyError(error.message) }
		}))
		if (!state.orderFormEditor) return
		const editor = state.orderFormEditor
		root.querySelector('#ofe-name')?.addEventListener('input', (event) => { editor.name = event.target.value })
		root.querySelector('#ofe-description')?.addEventListener('input', (event) => { editor.description = event.target.value })
		root.querySelector('#ofe-active')?.addEventListener('change', (event) => { editor.active = event.target.checked })
		root.querySelectorAll('[data-ofe-section-title]').forEach((input) => input.addEventListener('input', () => { editor.definition.sections[Number(input.dataset.ofeSectionTitle)].title = input.value }))
		root.querySelectorAll('[data-ofe-line]').forEach((row) => row.querySelectorAll('[data-ofe-field]').forEach((input) => input.addEventListener(input.tagName === 'SELECT' || input.type === 'checkbox' ? 'change' : 'input', () => {
			const line = editorLineAt(row.dataset.ofeLine)
			const field = input.dataset.ofeField
			if (field === 'assignment') { delete line.articleNumber; delete line.groupKey; if (input.value.startsWith('A:')) line.articleNumber = input.value.slice(2); if (input.value.startsWith('G:')) line.groupKey = input.value.slice(2); return }
			line[field] = input.type === 'checkbox' ? input.checked : (field === 'vatRate' ? Number(input.value) : input.value)
			if (['kind', 'textInput', 'positionType'].includes(field)) { if (field === 'positionType' && input.value === 'DP') line.vatRate = 0; render() }
		})))
		const move = (list, index, delta) => { const target = index + delta; if (target < 0 || target >= list.length) return; [list[index], list[target]] = [list[target], list[index]] }
		root.querySelectorAll('[data-ofe-move]').forEach((button) => button.addEventListener('click', () => { const [id, delta] = button.dataset.ofeMove.split(':'); const [s, l] = id.split('-').map(Number); move(editor.definition.sections[s].lines, l, Number(delta)); render() }))
		root.querySelectorAll('[data-ofe-remove]').forEach((button) => button.addEventListener('click', () => { const [s, l] = button.dataset.ofeRemove.split('-').map(Number); editor.definition.sections[s].lines.splice(l, 1); if (!editor.definition.sections[s].lines.length) editor.definition.sections[s].lines.push(blankLine()); render() }))
		root.querySelectorAll('[data-ofe-add-line]').forEach((button) => button.addEventListener('click', () => { editor.definition.sections[Number(button.dataset.ofeAddLine)].lines.push(blankLine()); render() }))
		root.querySelectorAll('[data-ofe-section-move]').forEach((button) => button.addEventListener('click', () => { const [index, delta] = button.dataset.ofeSectionMove.split(':').map(Number); move(editor.definition.sections, index, delta); render() }))
		root.querySelectorAll('[data-ofe-section-remove]').forEach((button) => button.addEventListener('click', () => { if (editor.definition.sections.length > 1) { editor.definition.sections.splice(Number(button.dataset.ofeSectionRemove), 1); render() } }))
		root.querySelector('#ofe-add-section')?.addEventListener('click', () => { editor.definition.sections.push({ key: '', title: `Abschnitt ${editor.definition.sections.length + 1}`, lines: [blankLine()] }); render() })
		root.querySelector('#ofe-cancel')?.addEventListener('click', () => { state.orderFormEditor = null; render() })
		root.querySelector('#ofe-save')?.addEventListener('click', async () => {
			try {
				const body = new URLSearchParams({ form: JSON.stringify({ name: editor.name, description: editor.description, active: editor.active, definition: editor.definition }) })
				await api(`${apiBase}/order-forms/${Number(editor.id || 0)}`, { method: 'PUT', body, feedback: 'Auftragsformular gespeichert.' })
				state.orderFormEditor = null
				await loadOrderForms()
				render()
			} catch (error) { notifyError(error.message) }
		})
	}

	return { openOrderForm, orderFormView, bindOrderFormEntry, loadOrderForms, orderFormAdminView, bindOrderFormAdmin, parseEuro }
}
