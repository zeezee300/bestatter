import { trashButton } from './icons.js'
import { burialGuidance } from './burial-guidance.js'

export function createCommercialModule(ctx) {
	const { root, state } = ctx
	const api = (...args) => ctx.api(...args)
	const caseServicesUrl = (...args) => ctx.caseServicesUrl(...args)
	const commercialVersionPanel = (...args) => ctx.commercialVersionPanel(...args)
	const esc = (...args) => ctx.esc(...args)
	const field = (...args) => ctx.field(...args)
	const render = (...args) => ctx.render(...args)
	const title = (...args) => ctx.title(...args)
	const promptAction = (...args) => ctx.promptAction(...args)

	const relationOptions = ['Ehepartner', 'Tochter', 'Sohn', 'Bruder', 'Schwester', 'Vater', 'Mutter', 'Amt', 'Betreuer', 'Onkel', 'Tante', 'Nichte', 'Neffe', 'Vormund']
	const orderTypes = ['Entwurf', 'KVA versendet', 'beauftragt', 'storniert']
	const commissioningTypes = ['unterschriebener Bestattungsauftrag', 'M\u00fcndliche Beauftragung', 'Telefonische Beauftragung', 'Digitale Beauftragung']
	const serviceGroupStorageKey = 'bestatter.service-groups.open'
	let serviceGroupOpen = {}
	try { serviceGroupOpen = JSON.parse(typeof sessionStorage !== 'undefined' ? sessionStorage.getItem(serviceGroupStorageKey) || '{}' : '{}') } catch { serviceGroupOpen = {} }
	const serviceGroupStateKey = (costType, articleGroup) => `${state.currentCase?.id || 0}:${costType}:${articleGroup}`
	const rememberServiceGroups = () => { try { if (typeof sessionStorage !== 'undefined') sessionStorage.setItem(serviceGroupStorageKey, JSON.stringify(serviceGroupOpen)) } catch { /* Sitzungsspeicher ist optional. */ } }

	function orderField(name, label, value = '', type = 'text', options = [], attributes = '') {
		const control = options.length
			? `<select class="bp-field" name="${name}" ${attributes}>${options.map((option) => `<option value="${esc(option)}" ${String(option) === String(value) ? 'selected' : ''}>${esc(option)}</option>`).join('')}</select>`
			: type === 'textarea' ? `<textarea class="bp-field" name="${name}" rows="3" ${attributes}>${esc(value)}</textarea>`
				: `<input class="bp-field" name="${name}" type="${type}" value="${esc(value)}" ${type === 'number' ? 'min="0" step="1"' : ''} ${attributes}>`
		return `<label><span>${esc(label)}</span>${control}</label>`
	}


	const euro = new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' })
	const formatMoney = (cents) => euro.format(Number(cents || 0) / 100)
	const costTypeLabels = { INTERNAL: 'Eigene Leistungen', EXPENSE: 'Auslagen und Gebühren', THIRD_PARTY: 'Fremdleistungen' }
	const logicalGroupOrder = ['Paket', 'Überführung', 'Bekleidung', 'Sarg', 'Urne', 'Beerdigung/Trauerfeier', 'Trauerfeier', 'Drucksachen', 'Friedhof', 'Krematorium', 'Blumen', 'Auslagen', 'Gebühren', 'Sonstiges']
	const logicalArticleSort = (left, right) => {
		const rank = (article) => { const index = logicalGroupOrder.findIndex((name) => String(article.articleGroup || article.category || '').toLocaleLowerCase('de-DE').includes(name.toLocaleLowerCase('de-DE'))); return index < 0 ? 999 : index }
		return rank(left) - rank(right) || Number(left.sortOrder || 0) - Number(right.sortOrder || 0) || String(left.shortName).localeCompare(String(right.shortName), 'de')
	}
	const unitLabels = { STK: 'Stück', PAUSCHAL: 'Pauschale', STD: 'Stunden', KM: 'Kilometer', TAG: 'Tage', KG: 'Kilogramm', L: 'Liter' }
	const positionTypeFallback = [{ value: 'EL', label: 'eigene Leistung' }, { value: 'FK', label: 'Fremdkosten/Fremdleistung' }, { value: 'DP', label: 'echter durchlaufender Posten' }]
	const quantityUnitFallback = Object.entries(unitLabels).map(([value, label]) => ({ value, label }))
	const valueListItems = (key, fallback) => {
		const fromCatalog = key === 'POSITION_TYPE' ? state.positionTypes : state.quantityUnits
		const fromCustomizing = (state.customizing || []).find((list) => list.key === key)?.items
		return (fromCatalog?.length ? fromCatalog : fromCustomizing?.length ? fromCustomizing : fallback).filter((item) => item?.value)
	}
	const unitLabel = (unit) => valueListItems('QUANTITY_UNIT', quantityUnitFallback).find((item) => item.value === unit)?.label || unitLabels[unit] || unit
	const quantityStep = (decimals = 0) => (1 / (10 ** Math.max(0, Math.min(3, Number(decimals) || 0)))).toFixed(Math.max(0, Math.min(3, Number(decimals) || 0)))
	const formatQuantity = (value, decimals = 0) => Number(value || 0).toLocaleString('de-DE', { minimumFractionDigits: 0, maximumFractionDigits: Math.max(0, Math.min(3, Number(decimals) || 0)) })
	const emptyServiceLine = (position) => ({ id: -(Date.now() + Number(position || 0)), articleId: 0, articleNumber: '', title: '', longText: '', note: '', category: 'Freitext', articleGroup: 'Freitext', quantity: 1, quantityDecimals: 3, unit: 'STK', unitPriceCents: 0, vatRate: 19, positionType: 'EL', costType: 'INTERNAL', origin: 'FREE_TEXT', position })
	// A typed but not selected catalog prefix is only search state, not a position.
	const isEmptyServiceLine = (line) => Number(line.articleId || 0) === 0 && !String(line.title || '').trim()
	const normalizeServiceLines = (lines) => {
		const entered = (lines || []).filter((line) => !isEmptyServiceLine(line)).map((line, index) => ({ ...line, position: (index + 1) * 10 }))
		return [...entered, emptyServiceLine((entered.length + 1) * 10)]
	}
	const lineNetCents = (line) => Math.round(Number(line.quantity || 0) * Number(line.unitPriceCents || 0))
	const lineVatCents = (line) => Math.round(lineNetCents(line) * Number(line.vatRate || 0) / 100)
	const lineGrossCents = (line) => lineNetCents(line) + lineVatCents(line)
	const validationForLine = (line) => {
		if (isEmptyServiceLine(line)) return { errors: [], warnings: [] }
		const errors = []
		const warnings = []
		if (Number(line.quantity || 0) <= 0) errors.push('Menge muss größer als 0 sein.')
		if (!String(line.unit || '').trim()) errors.push('Mengeneinheit fehlt.')
		if (Number(line.articleId || 0) === 0) {
			if (!String(line.title || '').trim()) errors.push('Bezeichnung fehlt.')
			if (Number(line.unitPriceCents || 0) <= 0) errors.push('Preis muss größer als 0,00 € sein.')
			if (!(state.allowedVatRates?.length ? state.allowedVatRates : [0, 7, 19]).map(Number).includes(Number(line.vatRate))) errors.push('MwSt.-Satz ist nicht zulässig.')
		} else if (Number(line.unitPriceCents || 0) <= 0) warnings.push('Katalogposition ohne Preis – vor Festschreibung prüfen.')
		return { errors, warnings }
	}
	const highlightMatch = (value, query) => {
		const text = String(value || '')
		const needle = String(query || '').trim()
		if (!needle) return esc(text)
		const index = text.toLocaleLowerCase('de-DE').indexOf(needle.toLocaleLowerCase('de-DE'))
		if (index < 0) return esc(text)
		return `${esc(text.slice(0, index))}<mark>${esc(text.slice(index, index + needle.length))}</mark>${esc(text.slice(index + needle.length))}`
	}
	const itemKey = (value) => String(value || 0).replace(/[^A-Za-z0-9_-]/g, '_')

	function funeralScope() {
		const code = String(state.currentCase?.burialVariantCode || state.currentCase?.masterData?.burial_variant_code || '')
		const item = (state.customizing?.find((list) => list.key === 'BURIAL_VARIANT')?.items || []).find((entry) => entry.value === code)
		if (item) return ['BURIAL', 'CREMATION'].includes(item.metadata?.funeralScope) ? item.metadata.funeralScope : 'ALL'
		const legacy = String(state.currentCase?.masterData?.funeral_type || state.currentCase?.funeralType || '').trim().toLocaleLowerCase('de-DE')
		if (legacy === 'erdbestattung') return 'BURIAL'
		if (['feuerbestattung', 'seebestattung', 'baumbestattung'].includes(legacy)) return 'CREMATION'
		return 'ALL'
	}

	function hydrateServiceDraft() {
		const draft = {}
		state.serviceLinesDraft = normalizeServiceLines((state.caseServices.items || []).filter((item) => item.serviceStatus !== 'STORNIERT'))
		for (const article of state.articles) {
			if (article.itemType === 'PACKAGE') {
				const line = state.caseServices.items.find((item) => item.sourcePackageId === article.id)
				const component = article.components.find((item) => item.articleId === line?.articleId)
				if (line) draft[article.id] = { selected: true, quantity: component ? line.quantity / component.quantity : 1, unitPriceCents: article.salesPriceCents }
			} else {
				const line = state.caseServices.items.find((item) => item.articleId === article.id && !item.sourcePackageId)
				if (line) draft[article.id] = { selected: true, quantity: line.quantity, unitPriceCents: line.unitPriceCents }
			}
		}
		state.serviceDraft = draft
	}

	async function loadCaseServices() {
		if (!state.currentCase?.id) return
		const orderScope = `${Number(state.currentCase.id)}:${Number(state.activeSideOrderId || 0)}`
		if (state.contractAmendmentCaseId !== orderScope) {
			state.contractAmendmentReason = ''
			state.contractAmendmentCaseId = orderScope
		}
		const sideOrderQuery = Number(state.activeSideOrderId || 0) > 0 ? `?sideOrderId=${Number(state.activeSideOrderId)}` : ''
		state.caseServices = await api(`${caseServicesUrl(state.currentCase.id)}${sideOrderQuery}`)
		hydrateServiceDraft()
	}

	function serviceConflictMessages() {
		const articles = new Map(state.articles.map((article) => [Number(article.id), article]))
		const exclusiveGroups = new Set((state.articleGroupRules || []).filter((rule) => rule.exclusiveSelection).map((rule) => rule.groupName))
		if (!exclusiveGroups.size) state.articles.filter((article) => article.exclusiveGroup).forEach((article) => exclusiveGroups.add(article.articleGroup))
		const grouped = new Map()
		for (const [articleId, draft] of Object.entries(state.serviceDraft)) {
			if (!draft.selected) continue
			const selected = articles.get(Number(articleId)); if (!selected) continue
			const effective = selected.itemType === 'PACKAGE' && selected.components?.length
				? selected.components.map((component) => ({ article: articles.get(Number(component.articleId)), source: selected.shortName }))
				: [{ article: selected, source: '' }]
			for (const entry of effective) {
				if (!entry.article || !exclusiveGroups.has(entry.article.articleGroup)) continue
				const group = grouped.get(entry.article.articleGroup) || new Map()
				group.set(Number(entry.article.id), `${entry.article.shortName}${entry.source ? ` (aus Paket ${entry.source})` : ''}`)
				grouped.set(entry.article.articleGroup, group)
			}
		}
		return [...grouped.entries()].filter(([, entries]) => entries.size > 1).map(([group, entries]) => `Artikelgruppe „${group}“: ${[...entries.values()].join(', ')}`)
	}

	async function saveCaseServices(applyResult = true) {
		if (!state.currentCase?.id) return
		const conflicts = serviceConflictMessages()
		if (conflicts.length) throw new Error(`Die Auswahl enthält sich ausschließende Positionen. ${conflicts.join(' · ')}`)
		const lines = (state.serviceLinesDraft || []).filter((line) => !isEmptyServiceLine(line))
		const allowedVatRates = (state.allowedVatRates?.length ? state.allowedVatRates : [0, 7, 19]).map(Number)
		for (const line of lines.filter((entry) => Number(entry.articleId || 0) === 0)) {
			if (!String(line.title || '').trim()) throw new Error('Eine Freitextposition benötigt eine Bezeichnung.')
			if (Number(line.unitPriceCents || 0) <= 0) throw new Error(`Freitextposition ${line.position}: Bitte einen Preis größer als 0,00 € erfassen.`)
			if (!allowedVatRates.includes(Number(line.vatRate))) throw new Error(`Freitextposition ${line.position}: Der Mehrwertsteuersatz ist nicht zulässig.`)
		}
		const items = lines.map((line) => ({ serviceId: Number(line.id || 0), articleId: Number(line.articleId || 0), positionNo: Number(line.position || 0), title: line.title || '', longText: line.longText || '', note: line.note || '', quantity: Number(line.quantity || 1), unit: line.unit || 'STK', unitPriceCents: Number(line.unitPriceCents || 0), vatRate: Number(line.vatRate ?? 19), positionType: line.positionType || 'EL' }))
		for (const [articleId, entry] of Object.entries(state.serviceDraft)) {
			const article = state.articles.find((item) => Number(item.id) === Number(articleId))
			if (!entry.selected || !article || article.itemType !== 'PACKAGE' || lines.some((line) => Number(line.sourcePackageId) === Number(articleId))) continue
			items.push({ articleId: Number(articleId), quantity: Number(entry.quantity || 1), unitPriceCents: Number(entry.unitPriceCents || 0), positionType: entry.positionType || 'EL' })
		}
		const result = await api(caseServicesUrl(state.currentCase.id), { method: 'PUT', feedback: false, body: new URLSearchParams({ items: JSON.stringify(items), amendmentReason: state.contractAmendmentReason || '', sideOrderId: Number(state.activeSideOrderId || 0) || '' }) })
		if (applyResult) { state.caseServices = result; hydrateServiceDraft() }
		return result
	}

	function serviceSelectionPanel() {
		const protection = state.caseServices.contractProtection || { active: false, amendmentsAllowed: true }
		const amendmentActive = protection.active && protection.amendmentsAllowed && Boolean(state.contractAmendmentReason)
		const contractLocked = protection.editable === false && !amendmentActive
		const serviceDisabled = contractLocked ? 'disabled' : ''
		const scope = funeralScope()
		const query = state.serviceQuery.toLowerCase()
		const eligible = state.articles.filter((article) => article.active && (article.funeralScope === 'ALL' || scope === 'ALL' || article.funeralScope === scope)).sort(logicalArticleSort)
		const articleGroups = [...new Set(eligible.map((article) => article.articleGroup))]
		const filtered = eligible
			.filter((article) => state.serviceCostType === 'ALL' || article.costType === state.serviceCostType)
			.filter((article) => state.serviceArticleGroup === 'ALL' || article.articleGroup === state.serviceArticleGroup)
			.filter((article) => state.serviceItemType === 'ALL' || article.itemType === state.serviceItemType)
			.filter((article) => !state.serviceSelectedOnly || state.serviceDraft[article.id]?.selected)
			.filter((article) => !query || [article.articleNumber, article.shortName, article.longText, article.articleGroup, ...(article.components || []).flatMap((item) => [item.articleNumber, item.shortName])].join(' ').toLowerCase().includes(query))
		const pageSize = Math.max(10, Number(state.servicePageSize) || 20)
		const pageCount = Math.max(1, Math.ceil(filtered.length / pageSize))
		state.servicePage = Math.min(Math.max(1, Number(state.servicePage) || 1), pageCount)
		const pageStart = (state.servicePage - 1) * pageSize
		const visible = filtered.slice(pageStart, pageStart + pageSize)
		const groups = [...new Set(visible.map((article) => `${article.costType}\u0000${article.articleGroup}`))]
		const catalog = groups.map((groupKey) => { const [costType,articleGroup]=groupKey.split('\u0000'); const groupArticles=visible.filter((article)=>article.costType===costType&&article.articleGroup===articleGroup); const selectedCount=groupArticles.filter((article)=>state.serviceDraft[article.id]?.selected).length; const exclusive=(state.articleGroupRules||[]).some((rule)=>rule.groupName===articleGroup&&rule.exclusiveSelection)||eligible.some((article)=>article.articleGroup===articleGroup&&article.exclusiveGroup); const stateKey=serviceGroupStateKey(costType,articleGroup); const manuallySet=Object.prototype.hasOwnProperty.call(serviceGroupOpen,stateKey); const isOpen=manuallySet?serviceGroupOpen[stateKey]:Boolean(query||state.serviceSelectedOnly); return `<details class="bp-service-group" data-service-group="${esc(stateKey)}" ${isOpen?'open':''}><summary class="bp-service-group-title"><div><small>${esc(costTypeLabels[costType] || costType)}</small><h4>${esc(articleGroup)}</h4></div><span>${exclusive?'<b class="bp-rule-badge">Nur eine Position zulässig</b> ':''}${groupArticles.length} auf dieser Seite${selectedCount?` · ${selectedCount} ausgewählt`:''}</span></summary><div class="bp-service-catalog">${groupArticles.map((article) => {
			const draft = state.serviceDraft[article.id] || { selected: false, quantity: 1, unitPriceCents: article.salesPriceCents }
			const packageText = article.itemType === 'PACKAGE' ? `<div class="bp-package-summary"><b>Paketbestandteile</b>${article.components.map((item) => `<span>${esc(item.shortName)} · ${formatQuantity(item.quantity, 3)}${item.exclusiveGroup ? ' · Exklusivgruppe' : ''}</span>`).join('') || '<span>Noch keine Bestandteile</span>'}</div>` : ''
			const step = quantityStep(article.quantityDecimals)
			return `<article class="bp-service-card ${draft.selected ? 'selected' : ''}"><label class="bp-service-select"><input type="checkbox" data-service-select="${article.id}" ${draft.selected ? 'checked' : ''} ${serviceDisabled}><span><b>${esc(article.shortName)}</b><small>${esc(article.articleNumber)} · ${esc(article.category)} · ${esc(article.articleGroup)} · ${esc(costTypeLabels[article.costType] || article.costType)}${article.exclusiveGroup ? ' · Gruppe exklusiv prüfen' : ''}</small></span></label><button type="button" class="bp-secondary bp-catalog-add" data-service-add="${article.id}" ${contractLocked ? 'disabled' : ''}>Hinzufügen</button><p>${esc(article.longText || '')}</p>${packageText}<div class="bp-service-values"><label>Menge (${esc(article.unit || 'STK')} – ${esc(unitLabel(article.unit || 'STK'))})<input type="number" min="${step}" step="${step}" data-service-quantity="${article.id}" value="${esc(draft.quantity)}" ${draft.selected && !contractLocked ? '' : 'disabled'}></label><label>VK netto<input type="number" min="0" step="0.01" data-service-price="${article.id}" value="${(Number(draft.unitPriceCents ?? article.salesPriceCents) / 100).toFixed(2)}" ${draft.selected && article.itemType !== 'PACKAGE' && !contractLocked ? '' : 'disabled'}></label><span>${article.itemType === 'PACKAGE' ? 'Paket' : `${article.vatRate}% MwSt.`}</span></div></article>`
		}).join('')}</div></details>` }).join('')
		const conflicts = serviceConflictMessages()
		state.serviceLinesDraft = normalizeServiceLines(state.serviceLinesDraft || state.caseServices.items || [])
		const selectedLines = (contractLocked ? state.serviceLinesDraft.filter((item) => !isEmptyServiceLine(item)) : state.serviceLinesDraft).sort((left, right) => Number(left.position || 0) - Number(right.position || 0))
		const positionQuery = String(state.servicePositionQuery || '').trim().toLocaleLowerCase('de-DE')
		const displayedLines = selectedLines.filter((item) => isEmptyServiceLine(item) || !positionQuery || [item.articleNumber, item.title].join(' ').toLocaleLowerCase('de-DE').includes(positionQuery))
		const positionTypes = valueListItems('POSITION_TYPE', positionTypeFallback).filter((item) => ['EL', 'FK', 'DP'].includes(item.value))
		const quantityUnits = valueListItems('QUANTITY_UNIT', quantityUnitFallback)
		const lines = displayedLines.map((item) => {
			const empty = isEmptyServiceLine(item)
			const validation = validationForLine(item)
			const validationMessage = [...validation.errors, ...validation.warnings].join(' ')
			const unitOptions = quantityUnits.map((entry) => `<option value="${esc(entry.value)}" data-option-label="${esc(entry.label)}" ${entry.value === (item.unit || 'STK') ? 'selected' : ''}>${esc(entry.value)}</option>`).join('')
			const typeOptions = positionTypes.map((entry) => `<option value="${esc(entry.value)}" data-option-label="${esc(entry.label)}" ${entry.value === (item.positionType || 'EL') ? 'selected' : ''}>${esc(entry.value)}</option>`).join('')
			const catalogMeta = Number(item.articleId || 0) === 0 ? 'Freitextposition' : [item.category, item.articleGroup, costTypeLabels[item.costType] || item.costType].filter(Boolean).join(' · ')
			const originCode = empty ? '–' : item.origin === 'NACHTRAG' ? 'NTR' : Number(item.articleId || 0) > 0 ? 'KAT' : 'FREI'
			const originLabel = originCode === 'NTR' ? 'Nachtragsposition' : originCode === 'KAT' ? 'Katalogposition' : originCode === 'FREI' ? 'Freitextposition' : 'Noch keine Position'
			const invoicedQuantity = Number(item.invoicedQuantityMilli || 0) / 1000
			const billingState = invoicedQuantity > 0 ? `Fakturiert: ${formatQuantity(invoicedQuantity, item.quantityDecimals)} von ${formatQuantity(item.quantity, item.quantityDecimals)} ${item.unit || 'STK'}` : ''
			const vatControl = Number(item.articleId || 0) > 0
				? `<span class="bp-readonly-value" title="Mehrwertsteuer aus dem Leistungskatalog">${esc(item.vatRate ?? 19)} %</span>`
				: `<select class="bp-compact-input bp-vat-select" data-line-field="vatRate" data-service-id="${item.id || ''}" aria-label="Mehrwertsteuer" ${contractLocked ? 'disabled' : ''}>${(state.allowedVatRates?.length ? state.allowedVatRates : [0, 7, 19]).map((rate) => `<option value="${rate}" ${Number(rate) === Number(item.vatRate ?? 19) ? 'selected' : ''}>${rate} %</option>`).join('')}</select>`
			const rowClass = [empty ? 'bp-service-entry-row' : '', invoicedQuantity > 0 ? 'bp-service-row-invoiced' : '', item.origin === 'NACHTRAG' ? 'bp-service-row-amendment' : '', validation.errors.length ? 'bp-service-row-error' : '', !validation.errors.length && validation.warnings.length ? 'bp-service-row-warning' : ''].filter(Boolean).join(' ')
			const detailRow = !empty && Number(state.openServiceDetailId || 0) === Number(item.id || 0) ? `<tr class="bp-service-detail-row" data-service-detail-row="${item.id || ''}"><td></td><td colspan="10"><div class="bp-service-detail-content"><div><b>Positionsdetails ${esc(item.position || '')}</b><p>${esc(item.longText || 'Kein Langtext hinterlegt.')}</p><small>Kategorie: ${esc(item.category || '–')} · Gruppe: ${esc(item.articleGroup || '–')} · Kostenart: ${esc(costTypeLabels[item.costType] || item.costType || '–')} · Einheit: ${esc(item.unit || 'STK')} – ${esc(unitLabel(item.unit || 'STK'))} · Steuersatz: ${esc(item.vatRate ?? 19)} % · Herkunft: ${esc(item.origin || 'ORDER')} · Leistungsstatus: ${esc(item.serviceStatus || 'BEAUFTRAGT')} · Abrechenbarkeit: ${esc(item.billability || 'ABRECHENBAR')}</small></div><label><span>Bemerkung zur Position</span><textarea rows="2" data-line-field="note" data-service-id="${item.id || ''}" ${contractLocked ? 'disabled' : ''}>${esc(item.note || '')}</textarea></label></div></td></tr>` : ''
			return `<tr data-service-row="${item.id || ''}" class="${rowClass}" ${validationMessage ? `title="${esc(validationMessage)}"` : ''}><td class="bp-position-number">${Number(item.position || 0) || '–'}</td><td><div class="bp-article-input-wrap"><input class="bp-compact-input" data-line-field="articleNumber" data-article-input data-service-id="${item.id || ''}" value="${esc(item.articleNumber || '')}" placeholder="Material-/Leistungsnummer" aria-label="Material-/Leistungsnummer" autocomplete="off" aria-autocomplete="list" aria-expanded="false" ${contractLocked ? 'disabled' : ''}><div class="bp-article-suggestions" data-article-suggestions="${item.id || ''}" role="listbox" hidden></div></div></td><td><span class="bp-origin-code" title="${esc(originLabel)}">${originCode}</span></td><td class="bp-service-title-cell"><div class="bp-title-input-row"><input class="bp-compact-input" data-line-field="title" data-service-id="${item.id || ''}" value="${esc(item.title || '')}" aria-label="Bezeichnung" ${contractLocked ? 'disabled' : ''} ${Number(item.articleId || 0) === 0 ? '' : 'readonly'}>${empty ? '' : `<button type="button" class="bp-link-button" tabindex="-1" data-show-service-details="${item.id || ''}" aria-expanded="${Number(state.openServiceDetailId || 0) === Number(item.id || 0)}">Details</button>`}</div>${validationMessage ? `<small class="bp-line-validation">${esc(validationMessage)}</small>` : empty ? '' : `<small class="bp-service-catalog-meta">${esc(catalogMeta)}${billingState ? ` · ${esc(billingState)}` : ''}</small>`}${item.sourcePackageName ? `<small>aus Paket ${esc(item.sourcePackageName)}</small>` : ''}</td><td><input class="bp-compact-input number" type="number" min="${quantityStep(item.quantityDecimals)}" step="${quantityStep(item.quantityDecimals)}" data-line-field="quantity" data-service-id="${item.id || ''}" value="${esc(item.quantity)}" aria-label="Menge" ${contractLocked ? 'disabled' : ''}></td><td><select class="bp-compact-input bp-coded-select bp-unit-select" data-line-field="unit" data-service-id="${item.id || ''}" aria-label="Mengeneinheit" title="${esc(`${item.unit || 'STK'} – ${unitLabel(item.unit || 'STK')}`)}" ${contractLocked ? 'disabled' : ''}>${unitOptions}</select></td><td><input class="bp-compact-input number" type="number" min="0" step="0.01" data-line-field="unitPriceCents" data-service-id="${item.id || ''}" value="${(Number(item.unitPriceCents || 0) / 100).toFixed(2)}" aria-label="Einzelpreis netto" ${contractLocked ? 'disabled' : ''}></td><td class="number">${empty ? '–' : formatMoney(lineNetCents(item))}</td><td>${vatControl}</td><td><select class="bp-compact-input bp-coded-select bp-position-type-select" data-line-field="positionType" data-service-id="${item.id || ''}" aria-label="Positionstyp" title="${esc(`${item.positionType || 'EL'} – ${positionTypes.find((entry) => entry.value === (item.positionType || 'EL'))?.label || ''}`)}" ${contractLocked ? 'disabled' : ''}>${typeOptions}</select></td><td class="number">${empty ? '–' : formatMoney(lineGrossCents(item))}${empty ? '' : `<span class="bp-line-actions">${trashButton('', 'Position löschen', `tabindex="-1" data-remove-service-line="${item.id || ''}" ${contractLocked ? 'disabled' : ''}`)}</span>`}</td></tr>${detailRow}`
		}).join('')
		const enteredLines = selectedLines.filter((item) => !isEmptyServiceLine(item))
		const draftTotals = enteredLines.reduce((sum, item) => ({ netCents: sum.netCents + lineNetCents(item), vatCents: sum.vatCents + lineVatCents(item), grossCents: sum.grossCents + lineGrossCents(item) }), { netCents: 0, vatCents: 0, grossCents: 0 })
		const typeTotals = Object.fromEntries(['EL', 'FK', 'DP'].map((type) => [type, enteredLines.filter((item) => (item.positionType || 'EL') === type).reduce((sum, item) => sum + lineGrossCents(item), 0)]))
		const selectionSummary = enteredLines.length ? enteredLines.map((item) => `<li><span><b>${esc(item.title)}</b>${item.sourcePackageName ? `<small>aus Paket ${esc(item.sourcePackageName)}</small>` : ''}</span><strong>${formatMoney(item.grossCents)}</strong></li>`).join('') : '<li class="bp-empty">Noch keine Position ausgewählt.</li>'
		const protectionNotice = protection.active ? `<aside class="bp-compliance-note"><b>Vertragspreise geschützt:</b> ${protection.documentType === 'QUOTE' ? 'Kostenvoranschlag' : 'Auftrag'} ${esc(protection.documentNumber || '')} ist festgeschrieben${protection.validUntil ? ` · gültig bis ${esc(new Date(`${protection.validUntil}T00:00:00`).toLocaleDateString('de-DE'))}` : ''}. ${protection.hasActiveFinalInvoice ? 'Da bereits eine aktive Schlussrechnung angelegt wurde, sind Vertragsänderungen gesperrt.' : amendmentActive ? `Nachtragsbearbeitung aktiv · Grund: ${esc(state.contractAmendmentReason)}` : protection.hasInvoice ? 'Teilrechnung vorhanden: Nicht fakturierte Positionen können als begründeter Nachtrag ergänzt oder geändert werden.' : 'Bewusste Änderungen sind ausschließlich als protokollierter Nachtrag möglich.'}<div class="bp-row-actions">${protection.amendmentsAllowed && !amendmentActive ? '<button type="button" class="bp-secondary" id="begin-contract-amendment">Vertragsnachtrag erfassen</button>' : ''}${amendmentActive ? '<button type="button" class="bp-secondary" id="end-contract-amendment">Nachtragsbearbeitung beenden</button>' : ''}</div></aside>` : ''
		const variantNotices = burialGuidance(state)
		const variantNotice = variantNotices.length ? `<aside class="bp-compliance-note"><b>Bestattungsvariante – interne Hinweise</b><ul>${variantNotices.map((notice)=>`<li>${esc(notice.text)}${notice.ruleId?` <button type="button" class="bp-secondary" data-burial-defer="${Number(notice.ruleId)}">Später entscheiden</button>`:''}${notice.articleId?` <button type="button" class="bp-secondary" data-burial-find-article="${Number(notice.articleId)}">Im Katalog suchen</button>`:''}</li>`).join('')}</ul></aside>` : ''
		const catalogToolbar = `<div class="bp-service-toolbar"><label><span>Suche</span><input id="service-search" value="${esc(state.serviceQuery)}" placeholder="Leistung, Artikelnummer oder Paketinhalt"></label><label><span>Kostenart</span><select id="service-cost-filter"><option value="ALL">Alle Kostenarten</option>${Object.entries(costTypeLabels).map(([value, label]) => `<option value="${value}" ${state.serviceCostType === value ? 'selected' : ''}>${esc(label)}</option>`).join('')}</select></label><label><span>Artikelgruppe</span><select id="service-group-filter"><option value="ALL">Alle Artikelgruppen</option>${articleGroups.map((value)=>`<option value="${esc(value)}" ${state.serviceArticleGroup===value?'selected':''}>${esc(value)}</option>`).join('')}</select></label><label><span>Artikeltyp</span><select id="service-item-type-filter"><option value="ALL">Einzel und Pakete</option><option value="SINGLE" ${state.serviceItemType==='SINGLE'?'selected':''}>Nur Einzelpositionen</option><option value="PACKAGE" ${state.serviceItemType==='PACKAGE'?'selected':''}>Nur Paketleistungen</option></select></label><label><span>Positionen je Seite</span><select id="service-page-size">${[10,20,40].map((size)=>`<option value="${size}" ${pageSize===size?'selected':''}>${size}</option>`).join('')}</select></label><label class="bp-inline-check bp-selected-filter"><input id="service-selected-only" type="checkbox" ${state.serviceSelectedOnly?'checked':''}> Nur ausgewählte Positionen</label><button type="button" class="bp-secondary" id="reset-service-filters">Filter zurücksetzen</button></div><div class="bp-catalog-result"><span><b>${filtered.length ? pageStart + 1 : 0}–${Math.min(pageStart + pageSize, filtered.length)}</b> von ${filtered.length} Treffern · ${eligible.length} Positionen insgesamt</span><span class="bp-pagination"><button type="button" class="bp-secondary" data-service-page="${state.servicePage - 1}" ${state.servicePage===1?'disabled':''}>Zurück</button><b>Seite ${state.servicePage} von ${pageCount}</b><button type="button" class="bp-secondary" data-service-page="${state.servicePage + 1}" ${state.servicePage===pageCount?'disabled':''}>Weiter</button></span></div>`
		const catalogDialog = state.serviceCatalogOpen ? `<div class="bp-modal" role="dialog" aria-modal="true" aria-labelledby="service-catalog-title"><section class="bp-wide-modal bp-service-catalog-dialog"><div class="bp-panel-head"><div><p class="bp-eyebrow">Artikelliste / Leistungskatalog</p><h2 id="service-catalog-title">Leistung suchen und hinzufügen</h2><p class="bp-muted">Die vollständige Katalogsuche ergänzt die schnelle Suche direkt in der Positionszeile.</p></div><button type="button" class="bp-secondary" id="close-service-catalog">Schließen</button></div>${catalogToolbar}<div class="bp-service-workspace"><div class="bp-service-browser">${catalog || '<p class="bp-empty">Für den Filter und die Bestattungsart wurden keine Leistungen gefunden.</p>'}</div><aside class="bp-selection-summary" aria-label="Aktuelle Auswahl"><div><p class="bp-eyebrow">Aktuelle Auswahl</p><h4>${enteredLines.length} Position${enteredLines.length === 1 ? '' : 'en'}</h4></div><ul>${selectionSummary}</ul><div class="bp-selection-total"><span>Gesamtsumme brutto</span><b>${formatMoney(draftTotals.grossCents)}</b></div><button type="button" class="bp-secondary" id="show-selected-services">Nur ausgewählte anzeigen</button></aside></div></section></div>` : ''
		const activeSideOrder = (state.sideOrders || []).find((order) => Number(order.id) === Number(state.activeSideOrderId))
		const scopeLabel = activeSideOrder ? `Nebenauftrag ${activeSideOrder.sideOrderNumber} · ${`${activeSideOrder.firstName} ${activeSideOrder.lastName}`.trim()}` : 'Hauptauftrag'
		return `<section class="bp-field-section bp-services-section"><div class="bp-panel-head"><div><h3>Beauftragte Leistungen und Kosten</h3><p class="bp-muted">${esc(scopeLabel)} · Material-/Leistungsnummer direkt eingeben oder den vollständigen Leistungskatalog öffnen.</p></div><div class="bp-row-actions"><button type="button" class="bp-secondary" id="open-service-catalog">Artikelliste / Leistungskatalog durchsuchen</button><button type="button" class="bp-secondary" id="back-to-side-orders" ${Number(state.activeSideOrderId || 0) > 0 ? '' : 'hidden'}>Zur Nebenauftragsübersicht</button><span class="bp-save-state" id="service-save-state">${contractLocked ? 'Vertragspreise sind geschützt.' : amendmentActive ? 'Änderungen werden als Vertragsnachtrag protokolliert.' : 'Auswahl wird automatisch gespeichert.'}</span></div></div>${protectionNotice}${variantNotice}
			<div id="service-conflicts" class="bp-conflict-box ${conflicts.length?'':'bp-hidden'}" role="alert"><b>Auswahlkonflikt – noch nicht gespeichert</b>${conflicts.map((message)=>`<span>${esc(message)}</span>`).join('')}</div>
			<div class="bp-cost-summary"><div class="bp-panel-head"><div><h4>Positionen</h4><p class="bp-muted">Materialnummer auswählen oder bei leerer Materialnummer eine Freitextbezeichnung mit Menge, Preis und MwSt. erfassen. KAT und FREI zeigen die Herkunft.</p></div><label class="bp-position-filter"><span>Positionen filtern</span><input id="service-position-filter" value="${esc(state.servicePositionQuery || '')}" placeholder="Materialnummer oder Bezeichnung"></label></div><div class="bp-table-scroll"><table class="bp-service-position-table"><thead><tr><th>Pos.</th><th>Materialnummer</th><th>Quelle</th><th>Bezeichnung</th><th>Menge</th><th>Einheit</th><th>Einzel netto</th><th>Gesamt netto</th><th>MwSt.-Satz</th><th>Typ</th><th>Gesamt brutto</th></tr></thead><tbody>${lines}</tbody></table></div><div class="bp-totals bp-type-totals"><span>EL eigene Leistung <b>${formatMoney(typeTotals.EL)}</b></span><span>FK Fremdkosten <b>${formatMoney(typeTotals.FK)}</b></span><span>DP durchlaufend <b>${formatMoney(typeTotals.DP)}</b></span></div><div class="bp-totals"><span>Netto <b>${formatMoney(draftTotals.netCents)}</b></span><span>MwSt. <b>${formatMoney(draftTotals.vatCents)}</b></span><span class="grand">Gesamtsumme <b>${formatMoney(draftTotals.grossCents)}</b></span></div></div>${catalogDialog}
		</section>`
	}
	function orderPanel(data) {
		const today = new Date().toISOString().slice(0, 10)
		const activeOrder = (state.commercial.documents || []).find((item) => item.documentType === 'ORDER')
		const mode = activeOrder ? 'A' : (data.order_mode || 'A')
		const sameRecipient = String(data.invoice_same_as_client ?? 'JA').toUpperCase() !== 'NEIN'
		const number = activeOrder?.documentNumber || data.order_number || `${mode}-${state.currentCase.caseNumber}`
		const paymentMethod = data.payment_method || 'TRANSFER'
		const isDirectDebit = paymentMethod === 'SEPA_DIRECT_DEBIT'
		const protection = state.caseServices.contractProtection || {}
		const effectiveOrderStatus = protection.effectiveOrderStatus || data.order_status || orderTypes[0]
		const orderStatusControl = protection.editable === false
			? `<label><span>Auftragsstatus</span><input type="hidden" name="order_status" value="${esc(effectiveOrderStatus)}"><select class="bp-field" disabled>${orderTypes.map((option) => `<option ${option === effectiveOrderStatus ? 'selected' : ''}>${esc(option)}</option>`).join('')}</select><small>Der Status wird aus dem verbindlichen Dokument- und Rechnungsstand ermittelt.</small></label>`
			: orderField('order_status', 'Auftragsstatus', effectiveOrderStatus, 'text', orderTypes)
		return `<form id="order-form" class="bp-master-form bp-order-form">
			${commercialVersionPanel(mode)}
			<section class="bp-field-section"><div class="bp-panel-head"><div><h3>Auftrag und Kostenvoranschlag</h3><p class="bp-muted">${activeOrder ? `Der Auftrag ${esc(activeOrder.documentNumber)} ist verbindlich. Der zugrunde liegende KVA dient nur noch als unveränderlicher Nachweis.` : 'Wählen Sie eindeutig, ob zunächst ein unverbindlicher Kostenvoranschlag oder direkt ein verbindlicher Auftrag bearbeitet wird.'}</p></div><fieldset class="bp-mode-choice" ${activeOrder ? 'disabled' : ''}><legend>Art des kaufmännischen Dokuments</legend><label><input type="radio" name="order_mode_choice" value="KVA" ${mode === 'KVA' ? 'checked' : ''}><span><b>Kostenvoranschlag</b><small>unverbindliche Angebotsgrundlage</small></span></label><label><input type="radio" name="order_mode_choice" value="A" ${mode === 'A' ? 'checked' : ''}><span><b>Auftrag</b><small>verbindliche Beauftragung</small></span></label></fieldset></div>
			<input type="hidden" name="order_mode" value="${esc(mode)}"><div class="bp-form-grid">
			${orderField('order_number', mode === 'KVA' ? 'KVA-Nummer' : 'Auftragsnummer', number)}
			${mode === 'KVA' ? orderField('kva_date', 'KVA-Datum', data.kva_date || today, 'date') + orderField('kva_valid_until', 'G\u00fcltig bis', data.kva_valid_until || '', 'date') : orderField('order_date', 'Auftragsdatum', data.order_date || today, 'date') + orderField('commissioning_type', 'Art der Beauftragung', data.commissioning_type || commissioningTypes[0], 'text', commissioningTypes)}
			${orderStatusControl}</div></section>
			<section class="bp-field-section"><h3>Auftraggeber / Angeh\u00f6riger</h3><div class="bp-form-grid">
			${orderField('order_client_salutation', 'Anrede', data.order_client_salutation || '', 'text', ['', 'Frau', 'Herr', 'Divers'])}
			${orderField('order_client_name', 'Name', data.order_client_name)}${orderField('order_client_first_name', 'Vorname', data.order_client_first_name)}
			${orderField('order_client_relation', 'Beziehung zum Verstorbenen', data.order_client_relation || relationOptions[0], 'text', relationOptions)}
			${orderField('order_client_street', 'Stra\u00dfe', data.order_client_street)}${orderField('order_client_postal_city', 'PLZ / Ort', data.order_client_postal_city)}${orderField('order_client_country', 'Land', data.order_client_country || 'Deutschland')}
			${orderField('order_client_phone', 'Telefon', data.order_client_phone, 'tel')}${orderField('order_client_mobile', 'Mobilfunknummer', data.order_client_mobile, 'tel')}${orderField('order_client_email', 'E-Mail', data.order_client_email, 'email')}</div></section>
			<section class="bp-field-section"><div class="bp-panel-head"><h3>Rechnungsempf\u00e4nger</h3><label class="bp-inline-check"><input type="checkbox" name="invoice_same_as_client" value="JA" ${sameRecipient ? 'checked' : ''}> Wie Auftraggeber</label></div>
			<div class="bp-form-grid bp-invoice-fields ${sameRecipient ? 'bp-hidden' : ''}">
			${orderField('invoice_salutation', 'Anrede', data.invoice_salutation || '', 'text', ['', 'Frau', 'Herr', 'Divers'])}
			${orderField('invoice_name', 'Name', data.invoice_name)}${orderField('invoice_first_name', 'Vorname', data.invoice_first_name)}
			${orderField('invoice_relation', 'Beziehung zum Verstorbenen', data.invoice_relation || relationOptions[0], 'text', relationOptions)}
			${orderField('invoice_street', 'Stra\u00dfe', data.invoice_street)}${orderField('invoice_postal_city', 'PLZ / Ort', data.invoice_postal_city)}${orderField('invoice_country', 'Land', data.invoice_country || 'Deutschland')}
			${orderField('invoice_phone', 'Telefon', data.invoice_phone, 'tel')}${orderField('invoice_mobile', 'Mobilfunknummer', data.invoice_mobile, 'tel')}${orderField('invoice_email', 'E-Mail', data.invoice_email, 'email')}</div></section>
			<section class="bp-field-section"><div class="bp-panel-head"><div><h3>Zahlungsart</h3><p class="bp-muted">Bei Überweisung wird – sofern aktiviert – ein SEPA-Zahlungs-QR ausgegeben. Bei Lastschrift ersetzt ein Einzugshinweis den QR-Code.</p></div></div><div class="bp-form-grid">
			${orderField('payment_method', 'Zahlungsart', paymentMethod, 'text', ['TRANSFER', 'SEPA_DIRECT_DEBIT'])}</div>
			<div id="sepa-direct-debit-fields" class="bp-form-grid ${isDirectDebit ? '' : 'bp-hidden'}">
			${orderField('sepa_mandate_reference', 'SEPA-Mandatsreferenz', data.sepa_mandate_reference)}
			${orderField('sepa_mandate_date', 'Mandat erteilt am', data.sepa_mandate_date, 'date')}
			${orderField('sepa_collection_date', 'Geplanter Einzug am', data.sepa_collection_date, 'date')}
			${orderField('sepa_debtor_name', 'Kontoinhaber/in', data.sepa_debtor_name || `${data.order_client_first_name || ''} ${data.order_client_name || ''}`.trim())}
			${orderField('sepa_debtor_iban', 'IBAN des Zahlungspflichtigen', data.sepa_debtor_iban)}
			${orderField('sepa_debtor_bic', 'BIC des Zahlungspflichtigen (optional)', data.sepa_debtor_bic)}</div>
			<p class="bp-muted">Die Kontodaten des Zahlungspflichtigen werden nicht auf der Rechnung abgedruckt. Auf der Rechnung erscheinen nur Einzugshinweis, Mandatsreferenz und Gläubiger-ID.</p></section>
			${serviceSelectionPanel()}
			<section class="bp-field-section"><h3>Sterbeurkunden und Hinweise</h3><div class="bp-form-grid">
			${orderField('certificate_free_count', 'Sterbeurkunden geb\u00fchrenfrei', data.certificate_free_count ?? 2, 'number')}
			${orderField('urkunden_gebuehrenpflichtig', 'Sterbeurkunden geb\u00fchrenpflichtig', data.urkunden_gebuehrenpflichtig ?? 0, 'number')}
			${orderField('order_notes', 'Auftragsnotiz', data.order_notes, 'textarea')}</div></section>
			<section class="bp-signature-box"><div><p class="bp-eyebrow">Unterschrift</p><b>${esc(data.order_signature_name ? `Digital best\u00e4tigt von ${data.order_signature_name}` : 'Noch nicht unterschrieben')}</b><small>${esc(data.order_signature_date || '')}</small></div><div class="bp-head-actions"><button type="button" class="bp-secondary" id="sign-order">Digital unterschreiben</button><button type="button" class="bp-primary" id="preview-order-docs">${mode === 'KVA' ? 'KVA-Vorschau' : 'Auftrag und Vollmacht prüfen'}</button></div></section>
			<p class="bp-save-state" id="order-save-state">\u00c4nderungen werden automatisch gespeichert.</p></form>`
	}

	let serviceSaveTimer = null
	let serviceSaveRevision = 0
	let serviceSaveQueue = Promise.resolve()

	function bindServiceSelection() {
		document.getElementById('back-to-side-orders')?.addEventListener('click', () => { state.activeSideOrderId = 0; state.caseTab = 'side-orders'; state.sideOrderView = 'list'; render() })
		const status = document.getElementById('service-save-state')
		const catalogDialog = () => root.querySelector('.bp-service-catalog-dialog')
		const catalogBrowser = () => root.querySelector('.bp-service-catalog-dialog .bp-service-browser')
		const rememberCatalogViewport = () => {
			if (!state.serviceCatalogOpen) return
			state.serviceCatalogDialogScrollTop = catalogDialog()?.scrollTop || 0
			state.serviceCatalogScrollTop = catalogBrowser()?.scrollTop || 0
		}
		const resetCatalogViewport = () => { state.serviceCatalogDialogScrollTop = 0; state.serviceCatalogScrollTop = 0 }
		if (state.serviceCatalogOpen) window.requestAnimationFrame(() => window.requestAnimationFrame(() => {
			const dialog = catalogDialog(), browser = catalogBrowser()
			if (dialog) dialog.scrollTop = Number(state.serviceCatalogDialogScrollTop || 0)
			if (browser) browser.scrollTop = Number(state.serviceCatalogScrollTop || 0)
		}))
		root.querySelectorAll('[data-service-row]').forEach((row) => {
			const line = (state.serviceLinesDraft || state.caseServices.items || []).find((item) => Number(item.id || 0) === Number(row.dataset.serviceRow || 0))
			if (Number(line?.invoicedQuantityMilli || 0) <= 0) return
			row.querySelectorAll('[data-line-field], [data-remove-service-line]').forEach((control) => { control.disabled = true; control.title = 'Bereits fakturierte Position – Vertragsdaten sind geschützt.' })
		})
		const updateLineValidation = (line) => {
			const row = root.querySelector(`[data-service-row="${line.id || ''}"]`)
			if (!row) return
			const validation = validationForLine(line)
			row.classList.toggle('bp-service-row-error', validation.errors.length > 0)
			row.classList.toggle('bp-service-row-warning', validation.errors.length === 0 && validation.warnings.length > 0)
			const message = [...validation.errors, ...validation.warnings].join(' ')
			let label = row.querySelector('.bp-line-validation')
			if (message && !label) { label = document.createElement('small'); label.className = 'bp-line-validation'; row.querySelector('.bp-service-title-cell')?.append(label) }
			if (label) { label.textContent = message; label.hidden = !message }
			row.title = message
		}
		const catalogMatches = (value) => {
			const query = String(value || '').trim().toLocaleLowerCase('de-DE')
			if (!query) return []
			const scope = funeralScope()
			const eligible = state.articles.filter((article) => article.active && (article.funeralScope === 'ALL' || scope === 'ALL' || article.funeralScope === scope))
			const rank = (article) => {
				const number = String(article.articleNumber || '').toLocaleLowerCase('de-DE')
				const texts = [article.shortName, article.longText, article.articleGroup, article.category].map((entry) => String(entry || '').toLocaleLowerCase('de-DE'))
				if (number === query) return 0
				if (number.startsWith(query)) return 1
				if (texts.some((text) => text.split(/[^\p{L}\p{N}]+/u).some((word) => word.startsWith(query)))) return 2
				if (number.includes(query) || texts.some((text) => text.includes(query))) return 3
				return 99
			}
			return eligible.map((article) => ({ article, rank: rank(article) })).filter((entry) => entry.rank < 99).sort((left, right) => left.rank - right.rank || logicalArticleSort(left.article, right.article)).map((entry) => ({ ...entry.article, matchRank: entry.rank }))
		}
		const applyCatalogArticle = (line, article) => {
			line.articleId = Number(article.id); line.articleNumber = article.articleNumber; line.title = article.shortName; line.longText = article.longText || ''; line.category = article.category || ''; line.articleGroup = article.articleGroup || ''; line.unit = article.unit || 'STK'; line.quantityDecimals = Number(article.quantityDecimals || 0); line.unitPriceCents = Number(article.salesPriceCents || 0); line.vatRate = Number(article.vatRate ?? 19); line.costType = article.costType; line.origin = 'ORDER'; line.positionType = article.costType === 'THIRD_PARTY' ? 'FK' : article.costType === 'EXPENSE' ? 'DP' : 'EL'; line.quantity = Number(line.quantity || 1)
			state.serviceDraft[article.id] = { selected: true, quantity: line.quantity, unitPriceCents: line.unitPriceCents, positionType: line.positionType }
		}
		const chooseCatalogArticle = (line, article, box) => {
			if (!article) return
			applyCatalogArticle(line, article)
			if (box) hideArticleMatches(box._articleInput, box)
			persist()
			render()
		}
		const articleMatchesBox = (input) => input?._articleSuggestions || input?.closest('.bp-article-input-wrap')?.querySelector('[data-article-suggestions]')
		const stopArticleMatchTracking = (box) => {
			if (!box?._stopPositionTracking) return
			box._stopPositionTracking()
			delete box._stopPositionTracking
		}
		const hideArticleMatches = (input, box = articleMatchesBox(input)) => {
			if (!box) return
			box.hidden = true
			stopArticleMatchTracking(box)
			input?.setAttribute('aria-expanded', 'false')
			input?.removeAttribute('aria-activedescendant')
			if (input) delete input.dataset.activeSuggestion
			const home = input?.closest('.bp-article-input-wrap')
			if (home && box.parentElement !== home) home.append(box)
		}
		const positionArticleMatches = (input, box) => {
			const rect = input.getBoundingClientRect()
			const viewportWidth = document.documentElement?.clientWidth || window.innerWidth || 1280
			const viewportHeight = document.documentElement?.clientHeight || window.innerHeight || 720
			const margin = 12
			const gap = 6
			const availableWidth = Math.max(240, viewportWidth - (margin * 2))
			const width = Math.min(760, availableWidth, Math.max(rect.width, 560))
			const left = Math.min(Math.max(margin, rect.left), Math.max(margin, viewportWidth - margin - width))
			const availableBelow = Math.max(0, viewportHeight - rect.bottom - gap - margin)
			const availableAbove = Math.max(0, rect.top - gap - margin)
			const desiredHeight = Math.min(320, Math.max(120, box.scrollHeight || 0))
			const openAbove = availableBelow < Math.min(220, desiredHeight) && availableAbove > availableBelow
			const availableHeight = openAbove ? availableAbove : availableBelow
			box.style.width = `${width}px`
			box.style.left = `${left}px`
			box.style.maxHeight = `${Math.max(96, Math.min(320, availableHeight))}px`
			box.dataset.placement = openAbove ? 'above' : 'below'
			if (openAbove) {
				box.style.top = 'auto'
				box.style.bottom = `${Math.max(margin, viewportHeight - rect.top + gap)}px`
			} else {
				box.style.top = `${Math.min(viewportHeight - margin, rect.bottom + gap)}px`
				box.style.bottom = 'auto'
			}
		}
		const trackArticleMatches = (input, box) => {
			stopArticleMatchTracking(box)
			const reposition = () => { if (!box.hidden) positionArticleMatches(input, box) }
			window.addEventListener('resize', reposition, { passive: true })
			document.addEventListener('scroll', reposition, { capture: true, passive: true })
			box._stopPositionTracking = () => {
				window.removeEventListener('resize', reposition)
				document.removeEventListener('scroll', reposition, true)
			}
		}
		const showArticleMatches = (input, line) => {
			const box = articleMatchesBox(input)
			if (!box) return
			input._articleSuggestions = box
			box._articleInput = input
			const allMatches = catalogMatches(input.value)
			const matches = allMatches.slice(0, 8)
			box.innerHTML = matches.map((article, index) => `<button type="button" role="option" aria-selected="false" id="bp-article-option-${itemKey(line.id)}-${index}" class="bp-article-suggestion" data-article-suggestion="${article.id}"><span class="bp-match-type">${article.matchRank <= 1 ? 'Nummer' : 'Bezeichnung'}</span><b>${highlightMatch(article.articleNumber, input.value)}</b><span>${highlightMatch(article.shortName, input.value)}</span><small>${esc([article.category, article.articleGroup, costTypeLabels[article.costType], `${article.unit || 'STK'} – ${unitLabel(article.unit || 'STK')}`].filter(Boolean).join(' · '))}</small></button>`).join('') + (allMatches.length > 8 ? `<div class="bp-more-matches">+${allMatches.length - 8} weitere Treffer – Suche verfeinern</div>` : '')
			if (!matches.length) { hideArticleMatches(input, box); return }
			box.hidden = false
			if (box.parentElement !== document.body) document.body.append(box)
			positionArticleMatches(input, box)
			trackArticleMatches(input, box)
			input.setAttribute('aria-expanded', String(matches.length > 0))
			input.removeAttribute('aria-activedescendant')
			box.querySelectorAll('[data-article-suggestion]').forEach((button) => {
				const select = (event) => { event.preventDefault(); chooseCatalogArticle(line, state.articles.find((entry) => Number(entry.id) === Number(button.dataset.articleSuggestion)), box) }
				button.addEventListener('mousedown', select)
				button.addEventListener('click', select)
			})
		}
		document.getElementById('begin-contract-amendment')?.addEventListener('click', async () => {
			const reason = await promptAction('Bitte begründen Sie die beabsichtigte Änderung. Der ursprüngliche KVA beziehungsweise Auftrag bleibt unverändert; die Abweichung wird als Nachtrag im Audit-Protokoll festgehalten.', '', { title: 'Vertragsnachtrag erfassen', label: 'Änderungsgrund', multiline: true })
			if (!reason || reason.trim().length < 5) return
			state.contractAmendmentReason = reason.trim(); state.contractAmendmentCaseId = Number(state.currentCase.id); render()
		})
		document.getElementById('end-contract-amendment')?.addEventListener('click', () => { state.contractAmendmentReason = ''; render() })
		document.getElementById('open-service-catalog')?.addEventListener('click', () => { state.serviceCatalogOpen = true; render() })
		document.getElementById('close-service-catalog')?.addEventListener('click', () => { state.serviceCatalogOpen = false; render() })
		root.querySelectorAll('[data-service-group]').forEach((details) => details.addEventListener('toggle', () => { serviceGroupOpen[details.dataset.serviceGroup] = details.open; rememberServiceGroups() }))
		const persist = () => {
			rememberCatalogViewport()
			clearTimeout(serviceSaveTimer)
			const revision = ++serviceSaveRevision
			const conflicts = serviceConflictMessages()
			const conflictBox = document.getElementById('service-conflicts')
			if (conflicts.length) {
				if (status) { status.textContent = 'Auswahlkonflikt – bitte eine der betroffenen Positionen abwählen.'; status.dataset.state = 'error' }
				if (conflictBox) { conflictBox.classList.remove('bp-hidden'); conflictBox.innerHTML = `<b>Auswahlkonflikt – noch nicht gespeichert</b>${conflicts.map((message)=>`<span>${esc(message)}</span>`).join('')}` }
				return
			}
			if (conflictBox) conflictBox.classList.add('bp-hidden')
			if (status) { status.textContent = 'Speichert …'; status.dataset.state = 'saving' }
			serviceSaveTimer = setTimeout(() => {
				serviceSaveQueue = serviceSaveQueue.catch(() => {}).then(async () => {
					if (revision !== serviceSaveRevision) return
					try {
						const result = await saveCaseServices(false)
						if (revision !== serviceSaveRevision) return
						state.caseServices = result
						hydrateServiceDraft()
						render()
						const current = document.getElementById('service-save-state')
						if (current) { current.textContent = 'Gespeichert'; current.dataset.state = 'saved' }
					} catch (error) {
						const current = document.getElementById('service-save-state')
						if (current) { current.textContent = error.message; current.dataset.state = 'error' }
					}
				})
			}, 450)
		}
		root.querySelectorAll('[data-service-select]').forEach((input) => input.addEventListener('change', () => {
			const id = Number(input.dataset.serviceSelect)
			const article = state.articles.find((entry) => entry.id === id)
			const entry = state.serviceDraft[id] || { quantity: 1, unitPriceCents: article?.salesPriceCents || 0 }
			entry.selected = input.checked
			state.serviceDraft[id] = entry
			state.serviceLinesDraft = state.serviceLinesDraft || []
			if (input.checked && article?.itemType !== 'PACKAGE' && !state.serviceLinesDraft.some((line) => Number(line.articleId) === id && !line.sourcePackageId)) {
				state.serviceLinesDraft.push({ id: -(Date.now() + id), articleId: id, articleNumber: article.articleNumber, title: article.shortName, longText: article.longText || '', category: article.category || '', articleGroup: article.articleGroup || '', quantity: Number(entry.quantity || 1), quantityDecimals: Number(article.quantityDecimals || 0), unit: article.unit || 'STK', unitPriceCents: Number(entry.unitPriceCents ?? article.salesPriceCents ?? 0), vatRate: Number(article.vatRate ?? 19), positionType: entry.positionType || (article.costType === 'THIRD_PARTY' ? 'FK' : article.costType === 'EXPENSE' ? 'DP' : 'EL'), costType: article.costType, origin: 'ORDER', sourcePackageId: null })
			} else if (!input.checked) state.serviceLinesDraft = state.serviceLinesDraft.filter((line) => Number(line.articleId) !== id && Number(line.sourcePackageId) !== id)
			root.querySelector(`[data-service-quantity="${id}"]`).disabled = !input.checked
			const price = root.querySelector(`[data-service-price="${id}"]`)
			if (price) price.disabled = !input.checked || article?.itemType === 'PACKAGE'
			input.closest('.bp-service-card')?.classList.toggle('selected', input.checked)
			persist()
		}))
		root.querySelectorAll('[data-service-add]').forEach((button) => button.addEventListener('click', () => {
			state.serviceCatalogOpen = true
			const input = root.querySelector(`[data-service-select="${button.dataset.serviceAdd}"]`)
			if (input && !input.checked) { input.checked = true; input.dispatchEvent(new Event('change', { bubbles: true })) }
		}))
		root.querySelectorAll('[data-line-field]').forEach((input) => input.addEventListener('input', () => {
			const id = Number(input.dataset.serviceId || 0)
			const line = (state.serviceLinesDraft || []).find((entry) => Number(entry.id || 0) === id)
			if (!line) return
			const fieldName = input.dataset.lineField
			if (fieldName === 'articleNumber') {
				line.articleNumber = input.value
				showArticleMatches(input, line)
				return
			}
			line[fieldName] = fieldName === 'unitPriceCents' ? Math.max(0, Math.round(Number(input.value || 0) * 100)) : fieldName === 'quantity' ? Math.max(Number(quantityStep(line.quantityDecimals)), Number(input.value || 1)) : input.value
			if (fieldName === 'positionType') line.positionType = String(input.value || 'EL').toUpperCase()
			updateLineValidation(line)
			if (line.origin === 'FREE_TEXT' && fieldName === 'title' && !String(line.title || '').trim()) return
			persist()
		}))
		root.querySelectorAll('[data-article-input]').forEach((input) => {
			input.addEventListener('keydown', (event) => {
				const line = (state.serviceLinesDraft || []).find((entry) => Number(entry.id || 0) === Number(input.dataset.serviceId || 0))
				const box = articleMatchesBox(input)
				if (!line || !box) return
				const options = [...box.querySelectorAll('[data-article-suggestion]')]
				const activate = (index) => {
					options.forEach((option, optionIndex) => { option.classList.toggle('active', optionIndex === index); option.setAttribute('aria-selected', String(optionIndex === index)) })
					const active = options[index]
					if (active) { input.dataset.activeSuggestion = String(index); input.setAttribute('aria-activedescendant', active.id) }
				}
				if ((event.key === 'ArrowDown' || event.key === 'ArrowUp') && !box.hidden && options.length) {
					event.preventDefault()
					const current = Number(input.dataset.activeSuggestion ?? -1)
					activate(event.key === 'ArrowDown' ? (current + 1) % options.length : (current <= 0 ? options.length - 1 : current - 1))
					return
				}
				if (event.key === 'Escape' && !box.hidden) { event.preventDefault(); hideArticleMatches(input, box); return }
				if (event.key === 'Enter') {
					const matches = catalogMatches(input.value); const activeIndex = Number(input.dataset.activeSuggestion ?? -1); const exact = matches.find((article) => String(article.articleNumber).toLocaleLowerCase('de-DE') === String(input.value).trim().toLocaleLowerCase('de-DE'))
					const chosen = activeIndex >= 0 ? matches[activeIndex] : exact || (matches.length === 1 ? matches[0] : null)
					if (chosen) { event.preventDefault(); chooseCatalogArticle(line, chosen, box) }
				}
			})
			input.addEventListener('blur', () => setTimeout(() => {
				const line = (state.serviceLinesDraft || []).find((entry) => Number(entry.id || 0) === Number(input.dataset.serviceId || 0))
				const box = articleMatchesBox(input)
				hideArticleMatches(input, box)
				if (!line) return
				const typedNumber = String(input.value || '').trim().toLocaleLowerCase('de-DE')
				const exact = catalogMatches(input.value).find((article) => String(article.articleNumber || '').toLocaleLowerCase('de-DE') === typedNumber)
				if (exact && Number(exact.id) !== Number(line.articleId || 0)) { chooseCatalogArticle(line, exact, box); return }
				const selected = state.articles.find((article) => Number(article.id) === Number(line.articleId || 0))
				if (selected) { line.articleNumber = selected.articleNumber; input.value = selected.articleNumber }
				else if (!String(line.title || '').trim()) { line.articleNumber = ''; input.value = '' }
			}, 120))
		})
		root.querySelectorAll('[data-line-field="positionType"],[data-line-field="unit"],[data-line-field="vatRate"]').forEach((input) => input.addEventListener('change', () => { input.dispatchEvent(new Event('input', { bubbles: true })) }))
		root.querySelectorAll('.bp-coded-select').forEach((select) => {
			const showLabels = () => [...select.options].forEach((option) => { option.textContent = `${option.value} – ${option.dataset.optionLabel || ''}` })
			const showCodes = () => [...select.options].forEach((option) => { option.textContent = option.value })
			select.addEventListener('focus', showLabels)
			select.addEventListener('blur', showCodes)
			select.addEventListener('change', () => { const option = select.selectedOptions[0]; select.title = `${select.value} – ${option?.dataset.optionLabel || ''}` })
		})
		root.querySelectorAll('[data-remove-service-line]').forEach((button) => button.addEventListener('click', () => {
			const id = Number(button.dataset.removeServiceLine || 0)
			const line = (state.serviceLinesDraft || []).find((entry) => Number(entry.id || 0) === id)
			state.serviceLinesDraft = (state.serviceLinesDraft || []).filter((entry) => Number(entry.id || 0) !== id)
			if (line?.articleId) state.serviceDraft[line.articleId] = { ...(state.serviceDraft[line.articleId] || {}), selected: false }
			persist()
		}))
		root.querySelectorAll('[data-show-service-details]').forEach((button) => button.addEventListener('click', () => {
			const id = Number(button.dataset.showServiceDetails || 0)
			state.openServiceDetailId = Number(state.openServiceDetailId || 0) === id ? 0 : id
			render()
		}))
		root.querySelectorAll('[data-service-quantity]').forEach((input) => input.addEventListener('input', () => {
			const id = Number(input.dataset.serviceQuantity)
			const article = state.articles.find((entry) => entry.id === id)
			const minimum = Number(quantityStep(article?.quantityDecimals))
			state.serviceDraft[id] = { ...(state.serviceDraft[id] || {}), selected: true, quantity: Math.max(minimum, Number(input.value || 1)) }
			const line = (state.serviceLinesDraft || []).find((entry) => Number(entry.articleId) === id && !entry.sourcePackageId)
			if (line) line.quantity = state.serviceDraft[id].quantity
			persist()
		}))
		root.querySelectorAll('[data-service-price]').forEach((input) => input.addEventListener('input', () => {
			const id = Number(input.dataset.servicePrice)
			state.serviceDraft[id] = { ...(state.serviceDraft[id] || {}), selected: true, unitPriceCents: Math.max(0, Math.round(Number(input.value || 0) * 100)) }
			const line = (state.serviceLinesDraft || []).find((entry) => Number(entry.articleId) === id && !entry.sourcePackageId)
			if (line) line.unitPriceCents = state.serviceDraft[id].unitPriceCents
			persist()
		}))
		root.querySelectorAll('[data-service-page]').forEach((button) => button.addEventListener('click', () => { state.servicePage = Number(button.dataset.servicePage); resetCatalogViewport(); render() }))
		document.getElementById('show-selected-services')?.addEventListener('click', () => { state.serviceSelectedOnly = true; state.servicePage = 1; resetCatalogViewport(); render() })
		document.getElementById('service-cost-filter')?.addEventListener('change', (event) => { state.serviceCostType = event.target.value; state.servicePage=1; resetCatalogViewport(); render() })
		document.getElementById('service-group-filter')?.addEventListener('change', (event) => { state.serviceArticleGroup = event.target.value; state.servicePage=1; resetCatalogViewport(); render() })
		document.getElementById('service-item-type-filter')?.addEventListener('change', (event) => { state.serviceItemType = event.target.value; state.servicePage=1; resetCatalogViewport(); render() })
		document.getElementById('service-page-size')?.addEventListener('change', (event) => { state.servicePageSize = Number(event.target.value); state.servicePage=1; resetCatalogViewport(); render() })
		document.getElementById('service-selected-only')?.addEventListener('change', (event) => { state.serviceSelectedOnly = event.target.checked; state.servicePage=1; resetCatalogViewport(); render() })
		document.getElementById('reset-service-filters')?.addEventListener('click', () => { state.serviceQuery=''; state.serviceCostType='ALL'; state.serviceArticleGroup='ALL'; state.serviceItemType='ALL'; state.serviceSelectedOnly=false; state.servicePage=1; resetCatalogViewport(); render() })
		document.getElementById('service-search')?.addEventListener('input', (event) => {
			state.serviceQuery = event.target.value
			state.serviceCatalogOpen = true
			state.servicePage = 1
			resetCatalogViewport()
			clearTimeout(event.target._renderTimer)
			event.target._renderTimer = setTimeout(() => { render(); const search = document.getElementById('service-search'); search?.focus(); search?.setSelectionRange(search.value.length, search.value.length) }, 180)
		})
		document.getElementById('service-position-filter')?.addEventListener('input', (event) => {
			state.servicePositionQuery = event.target.value
			clearTimeout(event.target._renderTimer)
			event.target._renderTimer = setTimeout(() => { render(); const filter = document.getElementById('service-position-filter'); filter?.focus(); filter?.setSelectionRange(filter.value.length, filter.value.length) }, 120)
		})
	}

	async function showInvoiceDraftForm(invoiceType) {
		const typeLabel=invoiceType==='FINAL'?'Schlussrechnung':'Teilrechnung';const eligible=(state.caseServices.items||[]).filter((item)=>item.billability==='ABRECHENBAR'&&['ABRECHENBAR','ABGERECHNET'].includes(item.serviceStatus)&&Number(item.performedQuantityMilli)>Number(item.invoicedQuantityMilli));
		if(!eligible.length){ctx.notifyWarning('Es sind keine abrechenbaren Restmengen vorhanden.');return}
		const sideOrderId=Number(state.activeSideOrderId||0)||0;const sideOrderQuery=sideOrderId?`&sideOrderId=${sideOrderId}`:'';const periodFrom=String(state.currentCase.createdAt||state.currentCase.masterData?.order_date||new Date().toISOString()).slice(0,10);const periodTo=new Date().toISOString().slice(0,10);
		const rows=eligible.map((item)=>{const remaining=(Number(item.performedQuantityMilli)-Number(item.invoicedQuantityMilli))/1000;return `<tr><td><input type="checkbox" data-invoice-select="${item.id}" ${invoiceType==='FINAL'?'checked disabled':'checked'} aria-label="${esc(item.title)} auswählen"></td><td><b>${esc(item.title)}</b><small>${esc(item.articleNumber)}</small></td><td class="number">${formatQuantity(item.performedQuantityMilli/1000,item.quantityDecimals)}</td><td class="number">${formatQuantity(item.invoicedQuantityMilli/1000,item.quantityDecimals)}</td><td><input class="bp-compact-input" data-invoice-quantity="${item.id}" type="number" min="${quantityStep(item.quantityDecimals)}" max="${remaining}" step="${quantityStep(item.quantityDecimals)}" value="${remaining}" ${invoiceType==='FINAL'?'readonly':''}></td><td class="number">${formatMoney(Math.round(remaining*item.unitPriceCents*(1+item.vatRate/100)))}</td></tr>`}).join('');
		root.insertAdjacentHTML('beforeend',`<div class="bp-modal" role="dialog" aria-modal="true" aria-labelledby="invoice-draft-title"><form id="invoice-draft-form" class="bp-modal-card bp-wide-modal"><div class="bp-panel-head"><div><p class="bp-eyebrow">Kontrollierte Fakturierung</p><h2 id="invoice-draft-title">${typeLabel} vorbereiten</h2><p class="bp-muted">${invoiceType==='FINAL'?'Die Schlussrechnung übernimmt sämtliche offenen Restmengen und sperrt weitere Rechnungen.':'Wählen Sie Positionen und Teilmengen bewusst aus. Nicht ausgewählte Restmengen bleiben für spätere Teilrechnungen verfügbar.'}</p></div></div><div class="bp-form-grid"><label><span>Leistungszeitraum von</span><input type="date" name="servicePeriodFrom" value="${esc(periodFrom)}"></label><label><span>Leistungszeitraum bis</span><input type="date" name="servicePeriodTo" value="${esc(periodTo)}"></label></div><div class="bp-table-scroll"><table><thead><tr><th>Auswahl</th><th>Leistung</th><th>Erbracht</th><th>Bereits fakturiert</th><th>Jetzt abrechnen</th><th>Brutto ca.</th></tr></thead><tbody>${rows}</tbody></table></div><aside class="bp-compliance-note"><b>Rechnungsprüfung:</b> Nach der Anlage bleibt die Rechnung ein Entwurf. PDF, QR-Code, E-Rechnung und externer EN16931-Nachweis werden erst im Prüfschritt erzeugt.</aside><p class="bp-save-state" id="invoice-draft-state" aria-live="polite"></p><div class="bp-row-actions"><button type="button" class="bp-secondary" id="cancel-invoice-draft">Abbrechen</button><button class="bp-primary">${typeLabel} anlegen</button></div></form></div>`);
		const modal=root.querySelector('.bp-modal:last-child');const form=modal.querySelector('#invoice-draft-form');modal.querySelector('#cancel-invoice-draft').onclick=()=>modal.remove();form.onsubmit=async(event)=>{event.preventDefault();const status=modal.querySelector('#invoice-draft-state');const selections=eligible.filter((item)=>invoiceType==='FINAL'||modal.querySelector(`[data-invoice-select="${item.id}"]`)?.checked).map((item)=>({caseServiceId:item.id,quantity:Number(modal.querySelector(`[data-invoice-quantity="${item.id}"]`).value)}));if(!selections.length){status.textContent='Bitte mindestens eine Position auswählen.';status.dataset.state='error';return}let override=false;let overrideReason='';try{const currentCheck=await api(`${ctx.commercialUrl(state.currentCase.id)}/billing-check?invoiceType=${encodeURIComponent(invoiceType)}${sideOrderQuery}`,{method:'POST',feedback:false});const selectedIds=new Set(selections.map((selection)=>Number(selection.caseServiceId)));const relevantExceptions=(currentCheck?.exceptions||[]).filter((exception)=>invoiceType==='FINAL'||Number(exception.serviceId||0)===0||selectedIds.has(Number(exception.serviceId)));if(relevantExceptions.some((exception)=>exception.severity==='KRITISCH')){overrideReason=await ctx.promptAction('Die ausgewählten Positionen enthalten kritische Prüfpunkte. Für eine manuelle Freigabe ist eine nachvollziehbare Begründung erforderlich.','',{title:'Manuelle Freigabe',label:'Begründung',multiline:true})||'';if(!overrideReason)return;override=true}status.textContent='Rechnungsentwurf wird angelegt …';status.dataset.state='saving';await api(`${ctx.commercialUrl(state.currentCase.id)}/invoices`,{method:'POST',body:new URLSearchParams({data:JSON.stringify({invoiceType,selections,sideOrderId:sideOrderId||null,servicePeriodFrom:form.elements.servicePeriodFrom.value,servicePeriodTo:form.elements.servicePeriodTo.value,override,overrideReason})})});modal.remove();ctx.notifySuccess(`${typeLabel} wurde mit ${selections.length} Position(en) als Entwurf angelegt.`);await ctx.loadCommercial();render()}catch(error){status.textContent=error.message;status.dataset.state='error'}};form.elements.servicePeriodFrom.focus()
	}

	async function finalizeCommercialDocument(documentType) {
		const form = document.getElementById('order-form')
		try {
			if (form?._saveOrder && !await form._saveOrder()) return false
			await saveCaseServices()
			const master = state.currentCase.masterData || {}
			const isQuote = documentType === 'QUOTE'
			const data = { number: master.order_number || `${isQuote ? 'KVA' : 'A'}-${state.currentCase.caseNumber}`, date: isQuote ? (master.kva_date || '') : (master.order_date || new Date().toISOString().slice(0, 10)), validUntil: master.kva_valid_until || '', commissioningType: master.commissioning_type || '' }
			const check = await api(`${ctx.commercialUrl(state.currentCase.id)}/finalization-check?documentType=${documentType}`, { method: 'POST', feedback: false, body: new URLSearchParams({ data: JSON.stringify(data) }) })
			if ((check.errors || []).length) {
				const first = check.errors[0]
				ctx.notifyError(`Festschreibung nicht möglich:\n${check.errors.map((item) => `• ${item.label}: ${item.message}`).join('\n')}`)
				const target = first.field ? form?.elements[first.field] : first.serviceId ? root.querySelector(`[data-service-row="${first.serviceId}"] [data-line-field]`) : null
				target?.focus(); target?.scrollIntoView({ block: 'center', behavior: 'smooth' })
				return false
			}
			const warningCodes = (check.warnings || []).map((item) => item.code)
			if (warningCodes.length && !await ctx.confirmAction(`Die Pflichtprüfungen sind erfüllt. Bitte prüfen Sie noch folgende Hinweise:\n\n${check.warnings.map((item) => `• ${item.label}: ${item.message}`).join('\n')}\n\nTrotzdem verbindlich festschreiben?`, { title: 'Hinweise zur Festschreibung', confirmLabel: 'Hinweise bestätigen und fortfahren' })) return false
			const question = isQuote ? 'Den aktuellen Kostenvoranschlag mit allen Positionen unveränderlich festschreiben?' : 'Den aktuellen Auftrag mit allen Positionen verbindlich und unveränderlich festschreiben?'
			if (!await ctx.confirmAction(question, { title: isQuote ? 'KVA festschreiben' : 'Auftrag festschreiben', confirmLabel: 'Verbindlich festschreiben' })) return false
			data.confirmedWarningCodes = warningCodes
			const result = await api(`${ctx.commercialUrl(state.currentCase.id)}/${isQuote ? 'quotes' : 'orders'}`, { method: 'POST', feedback: false, body: new URLSearchParams({ data: JSON.stringify(data) }) })
			if (result.pdfWarning) ctx.notifyWarning(result.pdfWarning); else ctx.notifySuccess(isQuote ? 'Der KVA wurde unveränderlich festgeschrieben und als PDF abgelegt.' : 'Der Auftrag wurde verbindlich festgeschrieben und als PDF abgelegt.')
			state.currentCase = await api(`${ctx.urls.cases}/${state.currentCase.id}`)
			await ctx.loadCommercial(); render()
			return true
		} catch (error) { ctx.notifyError(error.message); return false }
	}


	return { orderField, funeralScope, hydrateServiceDraft, loadCaseServices, serviceConflictMessages, saveCaseServices, serviceSelectionPanel, orderPanel, bindServiceSelection, showInvoiceDraftForm, finalizeCommercialDocument, relationOptions, orderTypes, commissioningTypes, euro, formatMoney, costTypeLabels, unitLabels, quantityStep, formatQuantity, serviceSaveTimer }
}
