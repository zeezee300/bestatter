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
	const serviceGroupStateKey = (groupKey) => `${state.currentCase?.id || 0}:${groupKey}`
	const rememberServiceGroups = () => { try { if (typeof sessionStorage !== 'undefined') sessionStorage.setItem(serviceGroupStorageKey, JSON.stringify(serviceGroupOpen)) } catch { /* Sitzungsspeicher ist optional. */ } }

	function orderField(name, label, value = '', type = 'text', options = [], attributes = '') {
		// Optionen als Wert oder als [Wert, Bezeichnung] (0.88.0: Vertragsschluss).
		const control = options.length
			? `<select class="bp-field" name="${name}" ${attributes}>${options.map((option) => { const [key, text] = Array.isArray(option) ? option : [option, option]; return `<option value="${esc(key)}" ${String(key) === String(value) ? 'selected' : ''}>${esc(text)}</option>` }).join('')}</select>`
			: type === 'textarea' ? `<textarea class="bp-field" name="${name}" rows="3" ${attributes}>${esc(value)}</textarea>`
				: `<input class="bp-field" name="${name}" type="${type}" value="${esc(value)}" ${type === 'number' ? 'min="0" step="1"' : ''} ${attributes}>`
		return `<label><span>${esc(label)}</span>${control}</label>`
	}


	const euro = new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' })
	const formatMoney = (cents) => euro.format(Number(cents || 0) / 100)
	const costTypeLabels = { INTERNAL: 'Eigene Leistungen', EXPENSE: 'Auslagen und Gebühren', THIRD_PARTY: 'Fremdleistungen' }
	const unitLabels = { STK: 'Stück', PAUSCHAL: 'Pauschale', STD: 'Stunden', KM: 'Kilometer', TAG: 'Tage', KG: 'Kilogramm', L: 'Liter', SATZ: 'Satz', PERS: 'Person' }
	const selectionRuleBadges = { MAX_ONE: 'Höchstens eine Position', EXACTLY_ONE: 'Genau eine Position' }
	const printModeFallback = [{ value: 'HEADER_ONLY', label: 'nur Paketzeile' }, { value: 'ITEMS_NO_PRICES', label: 'Paket mit Bestandteilen ohne Einzelpreise' }, { value: 'ITEMS_WITH_PRICES', label: 'Paket mit Bestandteilen und Einzelpreisen' }]
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
	const articleById = (id) => (state.articles || []).find((article) => Number(article.id) === Number(id))
	const groupByKey = (key) => (state.catalogGroups || []).find((group) => group.key === key)
	const catalogSort = (left, right) => Number(left.areaSortOrder ?? 9999) - Number(right.areaSortOrder ?? 9999) || Number(left.groupSortOrder ?? 9999) - Number(right.groupSortOrder ?? 9999) || String(left.articleNumber).localeCompare(String(right.articleNumber), 'de', { numeric: true })
	const isHeaderLine = (line) => Boolean(line?.isPackageHeader) || line?.itemCategory === 'PACKAGE'
	const isChildLine = (line) => Number(line?.parentServiceId || 0) > 0
	// Hinweis, wenn ein Artikel zusätzlich zu einem gleichen Paketbestandteil erfasst ist (z. B. zweite Überführung).
	const packageDuplicateHint = (item) => {
		if (!Number(item?.articleId || 0) || isChildLine(item) || isHeaderLine(item)) return ''
		const twins = (state.serviceLinesDraft || []).filter((line) => isChildLine(line) && Number(line.articleId) === Number(item.articleId) && line.serviceStatus !== 'STORNIERT')
		if (!twins.length) return ''
		const labels = twins.map((line) => line.positionLabel || (line.position && line.subPosition ? `${line.position}.${line.subPosition}` : '')).filter(Boolean)
		return `<small class="bp-line-hint">Bereits im Paket enthalten${labels.length ? ` (Pos. ${esc(labels.join(', '))})` : ''} – nur behalten, wenn die Leistung zusätzlich beauftragt ist.</small>`
	}
	const branchPrice = (article) => { const price = state.caseServices?.prices?.[article.id]; return price === undefined || price === null ? Number(article.salesPriceCents || 0) : Number(price) }
	const emptyServiceLine = () => ({ id: -(Date.now() + Math.floor(Math.random() * 1000)), articleId: 0, articleNumber: '', title: '', longText: '', note: '', category: 'Freitext', articleGroup: 'Freitext', quantity: 1, quantityDecimals: 3, unit: 'STK', unitPriceCents: 0, vatRate: 19, positionType: 'EL', costType: 'INTERNAL', origin: 'FREE_TEXT', position: 0 })
	// A typed but not selected catalog prefix is only search state, not a position.
	const isEmptyServiceLine = (line) => Number(line.articleId || 0) === 0 && !String(line.title || '').trim()
	/** Positionsnummern vergibt der Server; neue Zeilen stehen bis zum Speichern am Ende. */
	const normalizeServiceLines = (lines) => {
		const entered = (lines || []).filter((line) => !isEmptyServiceLine(line)).map((line, index) => ({ line, index }))
		entered.sort((left, right) => {
			const leftPosition = Number(left.line.position || 0) || Number.MAX_SAFE_INTEGER, rightPosition = Number(right.line.position || 0) || Number.MAX_SAFE_INTEGER
			return leftPosition - rightPosition || Number(left.line.subPosition || 0) - Number(right.line.subPosition || 0) || left.index - right.index
		})
		return [...entered.map((entry) => entry.line), emptyServiceLine()]
	}
	const positionLabel = (line) => line.positionLabel || (Number(line.position || 0) > 0 ? String(line.position) : 'neu')
	const childrenOf = (header) => (state.serviceLinesDraft || []).filter((line) => Number(line.parentServiceId || 0) > 0 && Number(line.parentServiceId) === Number(header.id))
	const lineNetCents = (line) => isHeaderLine(line) ? 0 : Math.round(Number(line.quantity || 0) * Number(line.unitPriceCents || 0))
	const lineVatCents = (line) => Math.round(lineNetCents(line) * Number(line.vatRate || 0) / 100)
	const lineGrossCents = (line) => lineNetCents(line) + lineVatCents(line)
	const packageTotals = (header) => childrenOf(header).reduce((sum, child) => ({ netCents: sum.netCents + lineNetCents(child), grossCents: sum.grossCents + lineGrossCents(child) }), { netCents: 0, grossCents: 0 })
	const validationForLine = (line) => {
		if (isEmptyServiceLine(line) || isHeaderLine(line)) return { errors: [], warnings: [] }
		const errors = []
		const warnings = []
		if (Number(line.quantity || 0) <= 0) errors.push('Menge muss größer als 0 sein.')
		if (!String(line.unit || '').trim()) errors.push('Mengeneinheit fehlt.')
		if (Number(line.articleId || 0) === 0) {
			if (!String(line.title || '').trim()) errors.push('Bezeichnung fehlt.')
			if (Number(line.unitPriceCents || 0) <= 0) errors.push('Preis muss größer als 0,00 € sein.')
			if (!(state.allowedVatRates?.length ? state.allowedVatRates : [0, 7, 19]).map(Number).includes(Number(line.vatRate))) errors.push('MwSt.-Satz ist nicht zulässig.')
		} else if (line.priceOnEntry && Number(line.unitPriceCents || 0) <= 0) warnings.push(`Tatsächlichen Preis im Fall erfassen${line.guidePriceCents !== null && line.guidePriceCents !== undefined ? ` (Richtwert ${formatMoney(line.guidePriceCents)})` : ''} – Pflicht vor der Festschreibung.`)
		else if (Number(line.unitPriceCents || 0) <= 0 && !isChildLine(line)) warnings.push('Katalogposition ohne Preis – vor Festschreibung prüfen.')
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
	/** Der Bestattungsbezug hängt seit 0.69.0 an der Warengruppe (z. B. Urnen nur bei Feuerbestattung). */
	const matchesFuneralScope = (article, scope = funeralScope()) => scope === 'ALL' || !article.funeralScope || article.funeralScope === 'ALL' || article.funeralScope === scope

	function hydrateServiceDraft() {
		const items = (state.caseServices.items || []).filter((item) => item.serviceStatus !== 'STORNIERT')
		state.serviceLinesDraft = normalizeServiceLines(items.map((item) => isHeaderLine(item)
			? { ...item, packageConfig: { selected: items.filter((child) => Number(child.parentServiceId) === Number(item.id)).map((child) => Number(child.bomItemId || 0)).filter(Boolean) } }
			: { ...item }))
		state.serviceDraft = {}
		for (const line of state.serviceLinesDraft) if (Number(line.articleId || 0) > 0 && !isChildLine(line)) state.serviceDraft[line.articleId] = { selected: true }
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

	/** Auswahlregeln der Warengruppen (höchstens/genau eine Position) inkl. Paketbestandteilen. */
	function serviceConflictMessages() {
		const restricted = new Map((state.catalogGroups || []).filter((group) => group.selectionRule && group.selectionRule !== 'MANY').map((group) => [group.key, group]))
		const byGroup = new Map()
		const add = (article, source = '') => {
			if (!article) return
			const group = restricted.get(article.articleGroup)
			if (!group) return
			const entries = byGroup.get(group.key) || new Map()
			entries.set(Number(article.id), `${article.shortName}${source}`)
			byGroup.set(group.key, entries)
		}
		for (const line of (state.serviceLinesDraft || []).filter((entry) => !isEmptyServiceLine(entry))) {
			if (isHeaderLine(line)) {
				if (childrenOf(line).length) continue
				const bom = articleById(line.articleId)?.bom
				const selected = line.packageConfig?.selected
				for (const item of bom?.items || []) {
					const include = selected ? selected.includes(Number(item.id)) : (item.itemType !== 'ALTERNATIVE' || item.isDefault)
					if (include) add(articleById(item.articleId), ` (aus Paket ${line.title})`)
				}
				continue
			}
			add(articleById(line.articleId), isChildLine(line) ? ` (aus Paket ${line.packageTitle || ''})` : '')
		}
		return [...byGroup.entries()].filter(([, entries]) => entries.size > 1).map(([key, entries]) => `Warengruppe „${restricted.get(key).name}“: ${[...entries.values()].join(', ')}`)
	}

	async function saveCaseServices(applyResult = true) {
		if (!state.currentCase?.id) return
		const conflicts = serviceConflictMessages()
		if (conflicts.length) throw new Error(`Die Auswahl enthält sich ausschließende Positionen. ${conflicts.join(' · ')}`)
		const lines = (state.serviceLinesDraft || []).filter((line) => !isEmptyServiceLine(line) && !isChildLine(line))
		const allowedVatRates = (state.allowedVatRates?.length ? state.allowedVatRates : [0, 7, 19]).map(Number)
		for (const line of lines.filter((entry) => Number(entry.articleId || 0) === 0)) {
			if (!String(line.title || '').trim()) throw new Error('Eine Freitextposition benötigt eine Bezeichnung.')
			if (Number(line.unitPriceCents || 0) <= 0) throw new Error(`Freitextposition ${positionLabel(line)}: Bitte einen Preis größer als 0,00 € erfassen.`)
			if (!allowedVatRates.includes(Number(line.vatRate))) throw new Error(`Freitextposition ${positionLabel(line)}: Der Mehrwertsteuersatz ist nicht zulässig.`)
		}
		const items = lines.map((line) => {
			const serviceId = Number(line.id || 0) > 0 ? Number(line.id) : 0
			if (isHeaderLine(line)) {
				const payload = { serviceId, articleId: Number(line.articleId), printMode: line.printMode || '', note: line.note || '' }
				if (line.packageConfig?.selected) payload.packageConfig = { selected: line.packageConfig.selected.map(Number) }
				if (line.packagePriceCents !== null && line.packagePriceCents !== undefined && line.packagePricing === 'HEADER') payload.packagePriceCents = Number(line.packagePriceCents)
				return payload
			}
			return { serviceId, articleId: Number(line.articleId || 0), title: line.title || '', longText: line.longText || '', note: line.note || '', quantity: Number(line.quantity || 1), unit: line.unit || 'STK', unitPriceCents: Number(line.unitPriceCents || 0), priceEdited: Boolean(line.priceEdited), vatRate: Number(line.vatRate ?? 19), positionType: line.positionType || 'EL' }
		})
		const sideOrderId = Number(state.activeSideOrderId || 0)
		const payload = new URLSearchParams({ items: JSON.stringify(items), amendmentReason: state.contractAmendmentReason || '' })
		if (sideOrderId > 0) payload.set('sideOrderId', String(sideOrderId))
		const result = await api(caseServicesUrl(state.currentCase.id), { method: 'PUT', feedback: false, body: payload })
		if (applyResult) { state.caseServices = result; hydrateServiceDraft() }
		return result
	}

	function serviceSelectionPanel() {
		const protection = state.caseServices.contractProtection || { active: false, amendmentsAllowed: true }
		const amendmentActive = protection.active && protection.amendmentsAllowed && Boolean(state.contractAmendmentReason)
		const contractLocked = protection.editable === false && !amendmentActive
		const serviceDisabled = contractLocked ? 'disabled' : ''
		const scope = funeralScope()
		const query = String(state.serviceQuery || '').toLocaleLowerCase('de-DE')
		state.serviceLinesDraft = normalizeServiceLines(state.serviceLinesDraft || state.caseServices.items || [])
		const selectedArticleIds = new Set((state.serviceLinesDraft || []).filter((line) => !isEmptyServiceLine(line) && !isChildLine(line)).map((line) => Number(line.articleId || 0)))
		const eligible = (state.articles || []).filter((article) => article.active && matchesFuneralScope(article, scope)).sort(catalogSort)
		const areas = (state.catalogAreas || []).filter((area) => eligible.some((article) => article.areaKey === area.key))
		const groupOptions = (state.catalogGroups || []).filter((group) => eligible.some((article) => article.articleGroup === group.key) && (state.serviceArea === 'ALL' || !state.serviceArea || group.areaKey === state.serviceArea))
		const filtered = eligible
			.filter((article) => !state.serviceArea || state.serviceArea === 'ALL' || article.areaKey === state.serviceArea)
			.filter((article) => state.serviceArticleGroup === 'ALL' || article.articleGroup === state.serviceArticleGroup)
			.filter((article) => !state.serviceArticleType || state.serviceArticleType === 'ALL' || article.articleType === state.serviceArticleType)
			.filter((article) => !state.serviceSelectedOnly || selectedArticleIds.has(Number(article.id)))
			.filter((article) => !query || [article.articleNumber, article.searchTerm, article.shortName, article.longText, article.articleGroupName, ...(article.bom?.items || []).flatMap((item) => [item.articleNumber, item.shortName])].join(' ').toLocaleLowerCase('de-DE').includes(query))
		const pageSize = Math.max(10, Number(state.servicePageSize) || 20)
		const pageCount = Math.max(1, Math.ceil(filtered.length / pageSize))
		state.servicePage = Math.min(Math.max(1, Number(state.servicePage) || 1), pageCount)
		const pageStart = (state.servicePage - 1) * pageSize
		const visible = filtered.slice(pageStart, pageStart + pageSize)
		const groupKeys = [...new Set(visible.map((article) => article.articleGroup))]
		const priceText = (article) => article.articleType === 'PAKET' && article.bom?.pricing === 'ITEMS' ? 'Summe der Positionen' : article.priceOnEntry ? `Preis im Fall${article.guidePriceCents !== null && article.guidePriceCents !== undefined ? ` · Richtwert ${formatMoney(article.guidePriceCents)}` : ''}` : `${formatMoney(branchPrice(article))} netto${article.articleType === 'PAKET' ? ' (Festpreis)' : ''}`
		const bomSummary = (article) => article.articleType !== 'PAKET' ? '' : `<div class="bp-package-summary"><b>Stückliste · ${article.bom?.pricing === 'HEADER' ? 'Festpreis am Paket' : 'Summe der Positionen'}</b>${(article.bom?.items || []).map((item) => `<span>${esc(item.shortName)} · ${formatQuantity(item.quantity, 3)}${item.itemType === 'OPTIONAL' ? ' · optional' : item.itemType === 'ALTERNATIVE' ? ` · Alternative ${esc(item.altGroup)}${item.isDefault ? ' (Standard)' : ''}` : ''}</span>`).join('') || '<span>Noch keine Stückliste gepflegt</span>'}</div>`
		const catalog = groupKeys.map((groupKey) => {
			const group = groupByKey(groupKey) || { key: groupKey, name: groupKey, areaName: '' }
			const groupArticles = visible.filter((article) => article.articleGroup === groupKey)
			const selectedCount = groupArticles.filter((article) => selectedArticleIds.has(Number(article.id))).length
			const stateKey = serviceGroupStateKey(groupKey)
			const manuallySet = Object.prototype.hasOwnProperty.call(serviceGroupOpen, stateKey)
			const isOpen = manuallySet ? serviceGroupOpen[stateKey] : Boolean(query || state.serviceSelectedOnly || state.serviceArticleGroup !== 'ALL')
			const badge = selectionRuleBadges[group.selectionRule] ? `<b class="bp-rule-badge">${selectionRuleBadges[group.selectionRule]}</b> ` : ''
			return `<details class="bp-service-group" data-service-group="${esc(stateKey)}" ${isOpen ? 'open' : ''}><summary class="bp-service-group-title"><div><small>${esc(group.areaName || '')}</small><h4>${esc(group.key)} · ${esc(group.name)}</h4></div><span>${badge}${groupArticles.length} auf dieser Seite${selectedCount ? ` · ${selectedCount} ausgewählt` : ''}</span></summary><div class="bp-service-catalog">${groupArticles.map((article) => {
				const selected = selectedArticleIds.has(Number(article.id))
				return `<article class="bp-service-card ${selected ? 'selected' : ''}"><label class="bp-service-select"><input type="checkbox" data-service-select="${article.id}" ${selected ? 'checked' : ''} ${serviceDisabled}><span><b>${esc(article.shortName)}</b><small>${esc([article.articleNumber, article.searchTerm, article.articleTypeLabel, article.articleType === 'PAKET' ? '' : `Steuerklasse ${article.taxClass}`].filter(Boolean).join(' · '))}</small></span></label><button type="button" class="bp-secondary bp-catalog-add" data-service-add="${article.id}" ${contractLocked ? 'disabled' : ''}>Hinzufügen</button><p>${esc(article.longText || '')}</p>${bomSummary(article)}<div class="bp-service-values"><span>${esc(article.unit || 'STK')} – ${esc(unitLabel(article.unit || 'STK'))}</span><span><b>${esc(priceText(article))}</b></span></div></article>`
			}).join('')}</div></details>`
		}).join('')
		const conflicts = serviceConflictMessages()
		const selectedLines = contractLocked ? state.serviceLinesDraft.filter((item) => !isEmptyServiceLine(item)) : state.serviceLinesDraft
		// Neue Zeilen erhalten bis zum Speichern eine vorläufige Positionsnummer (fortlaufend nach der höchsten vergebenen).
		const provisional = new Map()
		let nextProvisional = Math.max(0, ...selectedLines.filter((item) => !isChildLine(item)).map((item) => Number(item.position || 0)))
		for (const item of selectedLines) if (!isChildLine(item) && !(Number(item.position || 0) > 0)) { nextProvisional += 10; provisional.set(item, String(nextProvisional)) }
		const displayPosition = (item) => provisional.get(item) || positionLabel(item)
		const positionQuery = String(state.servicePositionQuery || '').trim().toLocaleLowerCase('de-DE')
		const matchesPositionQuery = (item) => !positionQuery || [item.articleNumber, item.title, item.packageTitle].join(' ').toLocaleLowerCase('de-DE').includes(positionQuery)
		const displayedLines = selectedLines.filter((item) => isEmptyServiceLine(item) || matchesPositionQuery(item) || (isHeaderLine(item) && childrenOf(item).some(matchesPositionQuery)))
		const positionTypes = valueListItems('POSITION_TYPE', positionTypeFallback).filter((item) => ['EL', 'FK', 'DP'].includes(item.value))
		const quantityUnits = valueListItems('QUANTITY_UNIT', quantityUnitFallback)
		const printModes = state.printModes?.length ? state.printModes : printModeFallback
		const disabledAttr = contractLocked ? 'disabled' : ''
		const headerRow = (item) => {
			const article = articleById(item.articleId)
			const totals = Number(item.id || 0) > 0 && childrenOf(item).length ? packageTotals(item) : { netCents: 0, grossCents: 0 }
			const fixedPrice = (item.packagePricing || article?.bom?.pricing) === 'HEADER'
			const priceCell = fixedPrice
				? `<input class="bp-compact-input number" type="number" min="0" step="0.01" data-package-price="${item.id}" value="${(Number(item.packagePriceCents ?? branchPrice(article || {})) / 100).toFixed(2)}" aria-label="Paketpreis netto (Festpreis)" title="Vereinbarter Paket-Festpreis netto; Mehrpreise für Alternativen und Minderpreise für abgewählte Bestandteile kommen hinzu." ${disabledAttr}>`
				: '<span class="bp-readonly-value" title="Summe der Unterpositionen">Summe</span>'
			const printSelect = `<select class="bp-compact-input" data-package-print="${item.id}" aria-label="Druckart des Pakets" ${disabledAttr}>${printModes.map((mode) => `<option value="${esc(mode.value)}" ${mode.value === (item.printMode || article?.bom?.printMode || 'ITEMS_NO_PRICES') ? 'selected' : ''}>${esc(mode.label)}</option>`).join('')}</select>`
			const pending = Number(item.id || 0) <= 0 || !childrenOf(item).length ? '<small class="bp-muted">Die Bestandteile werden beim Speichern aus der Stückliste übernommen.</small>' : ''
			const selected = item.packageConfig?.selected || []
			const excluded = (article?.bom?.items || []).filter((bomItem) => bomItem.itemType === 'OPTIONAL' && Number(item.id || 0) > 0 && childrenOf(item).length && !selected.includes(Number(bomItem.id)))
			const excludedRows = excluded.map((bomItem) => `<tr class="bp-service-row-excluded"><td class="bp-position-number">–</td><td>${esc(bomItem.articleNumber)}</td><td><span class="bp-origin-code" title="Optionaler Bestandteil, abgewählt">OPT</span></td><td class="bp-service-title-cell bp-package-child"><s>${esc(bomItem.shortName)}</s> <button type="button" class="bp-link-button" data-package-include="${item.id}" data-bom-item="${bomItem.id}" ${disabledAttr}>wieder aufnehmen</button>${bomItem.creditCents ? `<small>Minderpreis bei Abwahl: ${formatMoney(bomItem.creditCents)}</small>` : ''}</td><td colspan="7"></td></tr>`).join('')
			return `<tr data-service-row="${item.id || ''}" class="bp-service-row-package"><td class="bp-position-number">${esc(displayPosition(item))}</td><td><span class="bp-readonly-value">${esc(item.articleNumber || '')}</span></td><td><span class="bp-origin-code" title="Paket (Stückliste)">PKT</span></td><td class="bp-service-title-cell"><b>${esc(item.title || '')}</b><small class="bp-service-catalog-meta">${fixedPrice ? 'Festpreis am Paket, anteilig auf die Bestandteile verteilt' : 'Preis ergibt sich aus den Bestandteilen'} · Druck: </small>${printSelect}${pending}</td><td class="number">1</td><td>PAUSCHAL</td><td>${priceCell}</td><td class="number">${formatMoney(totals.netCents)}</td><td>–</td><td>EL</td><td class="number">${formatMoney(totals.grossCents)}<span class="bp-line-actions">${trashButton('', 'Paket entfernen', `tabindex="-1" data-remove-service-line="${item.id || ''}" ${disabledAttr}`)}</span></td></tr>${excludedRows}`
		}
		const childRow = (item) => {
			const header = (state.serviceLinesDraft || []).find((line) => Number(line.id) === Number(item.parentServiceId))
			const bom = articleById(header?.articleId)?.bom
			const bomItem = (bom?.items || []).find((entry) => Number(entry.id) === Number(item.bomItemId))
			const fixedPrice = (header?.packagePricing || item.packagePricingOfParent) === 'HEADER'
			let control = ''
			if (bomItem?.itemType === 'ALTERNATIVE') {
				const options = (bom.items || []).filter((entry) => entry.itemType === 'ALTERNATIVE' && entry.altGroup === bomItem.altGroup)
				control = `<select class="bp-compact-input" data-package-alternative="${item.id}" aria-label="Alternative wählen" ${disabledAttr}>${options.map((entry) => `<option value="${entry.id}" ${Number(entry.id) === Number(bomItem.id) ? 'selected' : ''}>${esc(entry.shortName)}${entry.isDefault ? ' (Standard)' : ''}</option>`).join('')}</select>`
			} else if (bomItem?.itemType === 'OPTIONAL') control = `<button type="button" class="bp-link-button" data-package-exclude="${item.id}" ${disabledAttr}>abwählen</button>`
			const surcharge = Number(item.surchargeCents || 0) ? `<small>${Number(item.surchargeCents) > 0 ? 'Mehrpreis' : 'Minderpreis'} ${formatMoney(Math.abs(Number(item.surchargeCents)))}</small>` : ''
			const invoicedQuantity = Number(item.invoicedQuantityMilli || 0) / 1000
			return `<tr data-service-row="${item.id || ''}" class="bp-service-row-child ${invoicedQuantity > 0 ? 'bp-service-row-invoiced' : ''}"><td class="bp-position-number">${esc(positionLabel(item))}</td><td><span class="bp-readonly-value">${esc(item.articleNumber || '')}</span></td><td><span class="bp-origin-code" title="Bestandteil der Stückliste">STL</span></td><td class="bp-service-title-cell bp-package-child"><span>${esc(item.title || '')}</span> ${control}${surcharge}<small class="bp-service-catalog-meta">${esc([item.articleGroup, bomItem?.itemType === 'OPTIONAL' ? 'optional' : bomItem?.itemType === 'ALTERNATIVE' ? `Alternative ${bomItem.altGroup}` : 'fester Bestandteil'].filter(Boolean).join(' · '))}</small></td><td class="number">${formatQuantity(item.quantity, item.quantityDecimals)}</td><td>${esc(item.unit || 'STK')}</td><td class="number"><span class="bp-readonly-value" title="${fixedPrice ? 'Anteil am Paket-Festpreis' : 'Preis des Bestandteils'}">${formatMoney(item.unitPriceCents)}${fixedPrice ? ' anteilig' : ''}</span></td><td class="number">${formatMoney(lineNetCents(item))}</td><td>${esc(item.vatRate ?? 0)} %</td><td>${esc(item.positionType || 'EL')}</td><td class="number">${formatMoney(lineGrossCents(item))}</td></tr>`
		}
		const lines = displayedLines.map((item) => {
			if (isHeaderLine(item)) return headerRow(item)
			if (isChildLine(item)) return childRow(item)
			const empty = isEmptyServiceLine(item)
			const validation = validationForLine(item)
			const validationMessage = [...validation.errors, ...validation.warnings].join(' ')
			const unitOptions = quantityUnits.map((entry) => `<option value="${esc(entry.value)}" data-option-label="${esc(entry.label)}" ${entry.value === (item.unit || 'STK') ? 'selected' : ''}>${esc(entry.value)}</option>`).join('')
			const typeOptions = positionTypes.map((entry) => `<option value="${esc(entry.value)}" data-option-label="${esc(entry.label)}" ${entry.value === (item.positionType || 'EL') ? 'selected' : ''}>${esc(entry.value)}</option>`).join('')
			const catalogMeta = Number(item.articleId || 0) === 0 ? 'Freitextposition' : [item.articleGroup, item.category].filter(Boolean).join(' · ')
			const originCode = empty ? '–' : item.origin === 'NACHTRAG' ? 'NTR' : Number(item.articleId || 0) > 0 ? 'KAT' : 'FREI'
			const originLabel = originCode === 'NTR' ? 'Nachtragsposition' : originCode === 'KAT' ? 'Katalogposition' : originCode === 'FREI' ? 'Freitextposition' : 'Noch keine Position'
			const invoicedQuantity = Number(item.invoicedQuantityMilli || 0) / 1000
			const billingState = invoicedQuantity > 0 ? `Fakturiert: ${formatQuantity(invoicedQuantity, item.quantityDecimals)} von ${formatQuantity(item.quantity, item.quantityDecimals)} ${item.unit || 'STK'}` : ''
			const vatControl = Number(item.articleId || 0) > 0
				? `<span class="bp-readonly-value" title="Steuerklasse ${esc(item.taxClass || '')} aus dem Leistungskatalog; echte Auslagen (DP) sind ohne Umsatzsteuer.">${esc(item.vatRate ?? 19)} %</span>`
				: `<select class="bp-compact-input bp-vat-select" data-line-field="vatRate" data-service-id="${item.id || ''}" aria-label="Mehrwertsteuer" ${disabledAttr}>${(state.allowedVatRates?.length ? state.allowedVatRates : [0, 7, 19]).map((rate) => `<option value="${rate}" ${Number(rate) === Number(item.vatRate ?? 19) ? 'selected' : ''}>${rate} %</option>`).join('')}</select>`
			const rowClass = [empty ? 'bp-service-entry-row' : '', invoicedQuantity > 0 ? 'bp-service-row-invoiced' : '', item.origin === 'NACHTRAG' ? 'bp-service-row-amendment' : '', validation.errors.length ? 'bp-service-row-error' : '', !validation.errors.length && validation.warnings.length ? 'bp-service-row-warning' : ''].filter(Boolean).join(' ')
			const detailRow = !empty && Number(state.openServiceDetailId || 0) === Number(item.id || 0) ? `<tr class="bp-service-detail-row" data-service-detail-row="${item.id || ''}"><td></td><td colspan="10"><div class="bp-service-detail-content"><div><b>Positionsdetails ${esc(positionLabel(item))}</b><p>${esc(item.longText || 'Kein Verkaufstext hinterlegt.')}</p><small>Warengruppe: ${esc(item.articleGroup || '–')} · Artikelart: ${esc(item.category || '–')} · Steuerklasse: ${esc(item.taxClass || '–')} · Einheit: ${esc(item.unit || 'STK')} – ${esc(unitLabel(item.unit || 'STK'))} · Steuersatz: ${esc(item.vatRate ?? 19)} % · Herkunft: ${esc(item.origin || 'ORDER')} · Status: ${esc(item.serviceStatusLabel || item.serviceStatus || 'beauftragt')} · Abrechenbarkeit: ${esc(item.billability || 'ABRECHENBAR')}</small></div><label><span>Bemerkung zur Position</span><textarea rows="2" data-line-field="note" data-service-id="${item.id || ''}" ${disabledAttr}>${esc(item.note || '')}</textarea></label></div></td></tr>` : ''
			const pricePlaceholder = item.priceOnEntry && item.guidePriceCents !== null && item.guidePriceCents !== undefined ? `placeholder="Richtwert ${(Number(item.guidePriceCents) / 100).toFixed(2)}"` : ''
			const priceValue = item.priceOnEntry && Number(item.unitPriceCents || 0) === 0 ? '' : (Number(item.unitPriceCents || 0) / 100).toFixed(2)
			return `<tr data-service-row="${item.id || ''}" class="${rowClass}" ${validationMessage ? `title="${esc(validationMessage)}"` : ''}><td class="bp-position-number">${esc(displayPosition(item))}</td><td><div class="bp-article-input-wrap"><input class="bp-compact-input" data-line-field="articleNumber" data-article-input data-service-id="${item.id || ''}" value="${esc(item.articleNumber || '')}" placeholder="Nummer oder Suchbegriff" aria-label="Artikelnummer oder Suchbegriff" autocomplete="off" aria-autocomplete="list" aria-expanded="false" ${disabledAttr}><div class="bp-article-suggestions" data-article-suggestions="${item.id || ''}" role="listbox" hidden></div></div></td><td><span class="bp-origin-code" title="${esc(originLabel)}">${originCode}</span></td><td class="bp-service-title-cell"><div class="bp-title-input-row"><input class="bp-compact-input" data-line-field="title" data-service-id="${item.id || ''}" value="${esc(item.title || '')}" aria-label="Bezeichnung" ${disabledAttr} ${Number(item.articleId || 0) === 0 ? '' : 'readonly'}>${empty ? '' : `<button type="button" class="bp-link-button" tabindex="-1" data-show-service-details="${item.id || ''}" aria-expanded="${Number(state.openServiceDetailId || 0) === Number(item.id || 0)}">Details</button>`}</div>${validationMessage ? `<small class="bp-line-validation">${esc(validationMessage)}</small>` : empty ? '' : `<small class="bp-service-catalog-meta">${esc(catalogMeta)}${billingState ? ` · ${esc(billingState)}` : ''}</small>`}${item.sourcePackageName ? `<small>aus Paket ${esc(item.sourcePackageName)}</small>` : ''}${packageDuplicateHint(item)}</td><td><input class="bp-compact-input number" type="number" min="${quantityStep(item.quantityDecimals)}" step="${quantityStep(item.quantityDecimals)}" data-line-field="quantity" data-service-id="${item.id || ''}" value="${esc(item.quantity)}" aria-label="Menge" ${disabledAttr}></td><td><select class="bp-compact-input bp-coded-select bp-unit-select" data-line-field="unit" data-service-id="${item.id || ''}" aria-label="Mengeneinheit" title="${esc(`${item.unit || 'STK'} – ${unitLabel(item.unit || 'STK')}`)}" ${disabledAttr}>${unitOptions}</select></td><td><input class="bp-compact-input number" type="number" min="0" step="0.01" data-line-field="unitPriceCents" data-service-id="${item.id || ''}" value="${priceValue}" ${pricePlaceholder} aria-label="Einzelpreis netto" ${disabledAttr}></td><td class="number">${empty ? '–' : formatMoney(lineNetCents(item))}</td><td>${vatControl}</td><td><select class="bp-compact-input bp-coded-select bp-position-type-select" data-line-field="positionType" data-service-id="${item.id || ''}" aria-label="Positionstyp" title="${esc(`${item.positionType || 'EL'} – ${positionTypes.find((entry) => entry.value === (item.positionType || 'EL'))?.label || ''}`)}" ${disabledAttr}>${typeOptions}</select></td><td class="number">${empty ? '–' : formatMoney(lineGrossCents(item))}${empty ? '' : `<span class="bp-line-actions">${trashButton('', 'Position löschen', `tabindex="-1" data-remove-service-line="${item.id || ''}" ${disabledAttr}`)}</span>`}</td></tr>${detailRow}`
		}).join('')
		const enteredLines = selectedLines.filter((item) => !isEmptyServiceLine(item))
		const pricedLines = enteredLines.filter((item) => !isHeaderLine(item))
		const draftTotals = pricedLines.reduce((sum, item) => ({ netCents: sum.netCents + lineNetCents(item), vatCents: sum.vatCents + lineVatCents(item), grossCents: sum.grossCents + lineGrossCents(item) }), { netCents: 0, vatCents: 0, grossCents: 0 })
		const typeTotals = Object.fromEntries(['EL', 'FK', 'DP'].map((type) => [type, pricedLines.filter((item) => (item.positionType || 'EL') === type).reduce((sum, item) => sum + lineGrossCents(item), 0)]))
		const selectionSummary = enteredLines.filter((item) => !isChildLine(item)).length ? enteredLines.filter((item) => !isChildLine(item)).map((item) => `<li><span><b>${esc(item.title)}</b>${isHeaderLine(item) ? `<small>Paket · ${childrenOf(item).length} Bestandteile</small>` : ''}</span><strong>${formatMoney(isHeaderLine(item) ? packageTotals(item).grossCents : lineGrossCents(item))}</strong></li>`).join('') : '<li class="bp-empty">Noch keine Position ausgewählt.</li>'
		const protectionNotice = protection.active ? `<aside class="bp-compliance-note"><b>Vertragspreise geschützt:</b> ${protection.documentType === 'QUOTE' ? 'Kostenvoranschlag' : 'Auftrag'} ${esc(protection.documentNumber || '')} ist festgeschrieben${protection.validUntil ? ` · gültig bis ${esc(new Date(`${protection.validUntil}T00:00:00`).toLocaleDateString('de-DE'))}` : ''}. Positionsnummern bleiben ab jetzt unverändert. ${protection.hasActiveFinalInvoice ? 'Da bereits eine aktive Schlussrechnung angelegt wurde, sind Vertragsänderungen gesperrt.' : amendmentActive ? `Nachtragsbearbeitung aktiv · Grund: ${esc(state.contractAmendmentReason)}` : protection.hasInvoice ? 'Teilrechnung vorhanden: Nicht fakturierte Positionen können als begründeter Nachtrag ergänzt oder geändert werden.' : 'Bewusste Änderungen sind ausschließlich als protokollierter Nachtrag möglich.'}<div class="bp-row-actions">${protection.amendmentsAllowed && !amendmentActive ? '<button type="button" class="bp-secondary" id="begin-contract-amendment">Vertragsnachtrag erfassen</button>' : ''}${amendmentActive ? '<button type="button" class="bp-secondary" id="end-contract-amendment">Nachtragsbearbeitung beenden</button>' : ''}</div></aside>` : ''
		const variantNotices = burialGuidance(state)
		const variantNotice = variantNotices.length ? `<aside class="bp-compliance-note"><b>Bestattungsvariante – interne Hinweise</b><ul>${variantNotices.map((notice) => `<li>${esc(notice.text)}${notice.ruleId ? ` <button type="button" class="bp-secondary" data-burial-defer="${Number(notice.ruleId)}">Später entscheiden</button>` : ''}${notice.articleId ? ` <button type="button" class="bp-secondary" data-burial-find-article="${Number(notice.articleId)}">Im Katalog suchen</button>` : ''}</li>`).join('')}</ul></aside>` : ''
		const articleTypes = state.articleTypes?.length ? state.articleTypes : []
		const catalogToolbar = `<div class="bp-service-toolbar"><label><span>Suche</span><input id="service-search" value="${esc(state.serviceQuery)}" placeholder="Nummer, Suchbegriff, Text oder Paketinhalt"></label><label><span>Bereich</span><select id="service-area-filter"><option value="ALL">Alle Bereiche</option>${areas.map((area) => `<option value="${esc(area.key)}" ${state.serviceArea === area.key ? 'selected' : ''}>${esc(area.key)} · ${esc(area.name)}</option>`).join('')}</select></label><label><span>Warengruppe</span><select id="service-group-filter"><option value="ALL">Alle Warengruppen</option>${groupOptions.map((group) => `<option value="${esc(group.key)}" ${state.serviceArticleGroup === group.key ? 'selected' : ''}>${esc(group.key)} · ${esc(group.name)}</option>`).join('')}</select></label><label><span>Artikelart</span><select id="service-type-filter"><option value="ALL">Alle Artikelarten</option>${articleTypes.map((type) => `<option value="${esc(type.value)}" ${state.serviceArticleType === type.value ? 'selected' : ''}>${esc(type.label)}</option>`).join('')}</select></label><label><span>Positionen je Seite</span><select id="service-page-size">${[10, 20, 40].map((size) => `<option value="${size}" ${pageSize === size ? 'selected' : ''}>${size}</option>`).join('')}</select></label><label class="bp-inline-check bp-selected-filter"><input id="service-selected-only" type="checkbox" ${state.serviceSelectedOnly ? 'checked' : ''}> Nur ausgewählte Positionen</label><button type="button" class="bp-secondary" id="reset-service-filters">Filter zurücksetzen</button></div><div class="bp-catalog-result"><span><b>${filtered.length ? pageStart + 1 : 0}–${Math.min(pageStart + pageSize, filtered.length)}</b> von ${filtered.length} Treffern · ${eligible.length} Positionen für die Bestattungsart</span><span class="bp-pagination"><button type="button" class="bp-secondary" data-service-page="${state.servicePage - 1}" ${state.servicePage === 1 ? 'disabled' : ''}>Zurück</button><b>Seite ${state.servicePage} von ${pageCount}</b><button type="button" class="bp-secondary" data-service-page="${state.servicePage + 1}" ${state.servicePage === pageCount ? 'disabled' : ''}>Weiter</button></span></div>`
		const catalogDialog = state.serviceCatalogOpen ? `<div class="bp-modal" role="dialog" aria-modal="true" aria-labelledby="service-catalog-title"><section class="bp-wide-modal bp-service-catalog-dialog"><div class="bp-panel-head"><div><p class="bp-eyebrow">Leistungskatalog</p><h2 id="service-catalog-title">Leistung suchen und hinzufügen</h2><p class="bp-muted">Gegliedert nach Bereich und Warengruppe. Die Katalogsuche ergänzt die schnelle Suche direkt in der Positionszeile.</p></div><button type="button" class="bp-secondary" id="close-service-catalog">Schließen</button></div>${catalogToolbar}<div class="bp-service-workspace"><div class="bp-service-browser">${catalog || '<p class="bp-empty">Für den Filter und die Bestattungsart wurden keine Leistungen gefunden.</p>'}</div><aside class="bp-selection-summary" aria-label="Aktuelle Auswahl"><div><p class="bp-eyebrow">Aktuelle Auswahl</p><h4>${enteredLines.filter((item) => !isChildLine(item)).length} Position${enteredLines.filter((item) => !isChildLine(item)).length === 1 ? '' : 'en'}</h4></div><ul>${selectionSummary}</ul><div class="bp-selection-total"><span>Gesamtsumme brutto</span><b>${formatMoney(draftTotals.grossCents)}</b></div><button type="button" class="bp-secondary" id="show-selected-services">Nur ausgewählte anzeigen</button></aside></div></section></div>` : ''
		const activeSideOrder = (state.sideOrders || []).find((order) => Number(order.id) === Number(state.activeSideOrderId))
		const scopeLabel = activeSideOrder ? `Nebenauftrag ${activeSideOrder.sideOrderNumber} · ${`${activeSideOrder.firstName} ${activeSideOrder.lastName}`.trim()}` : 'Hauptauftrag'
		return `<section class="bp-field-section bp-services-section"><div class="bp-panel-head"><div><h3>Beauftragte Leistungen und Kosten</h3><p class="bp-muted">${esc(scopeLabel)} · Artikelnummer oder Suchbegriff direkt eingeben oder den Leistungskatalog öffnen.</p></div><div class="bp-row-actions"><button type="button" class="bp-secondary" id="open-service-catalog">Leistungskatalog durchsuchen</button>${Number(state.activeSideOrderId || 0) > 0 ? '' : '<button type="button" class="bp-secondary" id="open-order-form">Nach Auftragsformular erfassen</button>'}<button type="button" class="bp-secondary" id="back-to-side-orders" ${Number(state.activeSideOrderId || 0) > 0 ? '' : 'hidden'}>Zur Nebenauftragsübersicht</button><span class="bp-save-state" id="service-save-state">${contractLocked ? 'Vertragspreise sind geschützt.' : amendmentActive ? 'Änderungen werden als Vertragsnachtrag protokolliert.' : 'Auswahl wird automatisch gespeichert.'}</span></div></div>${protectionNotice}${variantNotice}
			<div id="service-conflicts" class="bp-conflict-box ${conflicts.length ? '' : 'bp-hidden'}" role="alert"><b>Auswahlkonflikt – noch nicht gespeichert</b>${conflicts.map((message) => `<span>${esc(message)}</span>`).join('')}</div>
			<div class="bp-cost-summary"><div class="bp-panel-head"><div><h4>Positionen</h4><p class="bp-muted">KAT Katalog, FREI Freitext, PKT Paket mit Bestandteilen (STL), NTR Nachtrag. Echte Auslagen im Namen der Angehörigen als Typ DP (ohne Umsatzsteuer) erfassen.</p></div><label class="bp-position-filter"><span>Positionen filtern</span><input id="service-position-filter" value="${esc(state.servicePositionQuery || '')}" placeholder="Nummer oder Bezeichnung"></label></div><div class="bp-table-scroll"><table class="bp-service-position-table"><thead><tr><th>Pos.</th><th>Artikelnummer</th><th>Quelle</th><th>Bezeichnung</th><th>Menge</th><th>Einheit</th><th>Einzel netto</th><th>Gesamt netto</th><th>MwSt.-Satz</th><th>Typ</th><th>Gesamt brutto</th></tr></thead><tbody>${lines}</tbody></table></div><div class="bp-totals bp-type-totals"><span>EL eigene Leistung <b>${formatMoney(typeTotals.EL)}</b></span><span>FK Fremdleistung <b>${formatMoney(typeTotals.FK)}</b></span><span>DP verauslagt <b>${formatMoney(typeTotals.DP)}</b></span></div><div class="bp-totals"><span>Netto <b>${formatMoney(draftTotals.netCents)}</b></span><span>MwSt. <b>${formatMoney(draftTotals.vatCents)}</b></span><span class="grand">Gesamtsumme <b>${formatMoney(draftTotals.grossCents)}</b></span></div></div>${catalogDialog}
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
		// orderTypes deckt die im Entwurf frei wählbaren Werte ab. "abgeschlossen" (Befund #14,
		// CommercialService::closeOrderWithoutInvoice()) ist dort bewusst NICHT enthalten – es soll
		// nicht als roher Dropdown-Wert im noch bearbeitbaren Entwurf auswählbar sein, sondern nur
		// über die eigene, protokollierte Aktion erreichbar sein. Für die gesperrte Anzeige wird der
		// tatsächliche ermittelte Status defensiv ergänzt, damit die Auswahl ihn trotzdem zeigen kann.
		const disabledOrderStatusOptions = orderTypes.includes(effectiveOrderStatus) ? orderTypes : [...orderTypes, effectiveOrderStatus]
		const orderStatusControl = protection.editable === false
			? `<label><span>Auftragsstatus</span><input type="hidden" name="order_status" value="${esc(effectiveOrderStatus)}"><select class="bp-field" disabled>${disabledOrderStatusOptions.map((option) => `<option ${option === effectiveOrderStatus ? 'selected' : ''}>${esc(option)}</option>`).join('')}</select><small>Der Status wird aus dem verbindlichen Dokument- und Rechnungsstand ermittelt.</small></label>`
			: orderField('order_status', 'Auftragsstatus', effectiveOrderStatus, 'text', orderTypes)
		const closeOrderAction = effectiveOrderStatus === 'beauftragt'
			? `<aside class="bp-compliance-note"><b>Auftrag ohne weitere Rechnungsstellung abschließen</b><p>Ein bereits beauftragter Auftrag kann nicht mehr storniert werden. Folgt keine weitere Rechnung mehr (z. B. weil sich der Auftrag anderweitig erledigt hat), kann er stattdessen als erledigt markiert werden. Diese Aktion ist endgültig, wird protokolliert und sperrt keine bereits erfassten Positionen oder Dokumente zusätzlich.</p><div class="bp-row-actions"><button type="button" class="bp-secondary" id="close-order-without-invoice">Auftrag als erledigt markieren</button></div></aside>`
			: protection.closedWithoutInvoice
				? `<aside class="bp-compliance-note"><b>Auftrag abgeschlossen</b><p>Dieser Auftrag wurde ohne weitere Rechnungsstellung als erledigt markiert${protection.orderClosedReason ? `: ${esc(protection.orderClosedReason)}` : '.'}</p></aside>`
				: ''
		return `<form id="order-form" class="bp-master-form bp-order-form">
			${commercialVersionPanel(mode)}
			<section class="bp-field-section"><div class="bp-panel-head"><div><h3>Auftrag und Kostenvoranschlag</h3><p class="bp-muted">${activeOrder ? `Der Auftrag ${esc(activeOrder.documentNumber)} ist verbindlich. Der zugrunde liegende KVA dient nur noch als unveränderlicher Nachweis.` : 'Wählen Sie eindeutig, ob zunächst ein unverbindlicher Kostenvoranschlag oder direkt ein verbindlicher Auftrag bearbeitet wird.'}</p></div><fieldset class="bp-mode-choice" ${activeOrder ? 'disabled' : ''}><legend>Art des kaufmännischen Dokuments</legend><label><input type="radio" name="order_mode_choice" value="KVA" ${mode === 'KVA' ? 'checked' : ''}><span><b>Kostenvoranschlag</b><small>unverbindliche Angebotsgrundlage</small></span></label><label><input type="radio" name="order_mode_choice" value="A" ${mode === 'A' ? 'checked' : ''}><span><b>Auftrag</b><small>verbindliche Beauftragung</small></span></label></fieldset></div>
			<input type="hidden" name="order_mode" value="${esc(mode)}"><div class="bp-form-grid">
			${orderField('order_number', mode === 'KVA' ? 'KVA-Nummer' : 'Auftragsnummer', number)}
			${mode === 'KVA' ? orderField('kva_date', 'KVA-Datum', data.kva_date || today, 'date') + orderField('kva_valid_until', 'G\u00fcltig bis', data.kva_valid_until || '', 'date') : orderField('order_date', 'Auftragsdatum', data.order_date || today, 'date') + orderField('commissioning_type', 'Art der Beauftragung', data.commissioning_type || commissioningTypes[0], 'text', commissioningTypes)}
			${orderStatusControl}</div>${closeOrderAction}</section>
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
			${mode === 'KVA' ? '' : `<section class="bp-field-section"><div class="bp-panel-head"><div><h3>Vertragsschluss und Vollmacht</h3><p class="bp-muted">Angaben für „Bestattungsauftrag und Vollmacht“. Bei Vertragsschluss außerhalb der Geschäftsräume oder im Fernabsatz wird die Widerrufsbelehrung mit Muster-Widerrufsformular beigefügt.</p></div></div><div class="bp-form-grid">
			${orderField('order_contract_conclusion', 'Vertragsschluss', data.order_contract_conclusion || '', 'text', [['', 'Bitte wählen'], ['GESCHAEFTSRAEUME', 'In den Geschäftsräumen'], ['AUSSERHALB', 'Außerhalb der Geschäftsräume (Hausbesuch, Klinik, Heim)'], ['FERNABSATZ', 'Fernabsatz (Telefon, E-Mail, online)']])}
			${orderField('order_payment_terms', 'Zahlungsziel / Abschläge', data.order_payment_terms)}
			${orderField('order_special_terms', 'Besondere Abreden / Kostenobergrenze', data.order_special_terms, 'textarea')}
			${orderField('order_poa_other', 'Vollmacht für weitere Stellen (Bezeichnung, Zweck, Umfang)', data.order_poa_other, 'textarea')}</div></section>`}
			<section class="bp-signature-box"><div><p class="bp-eyebrow">Unterschrift</p><b>${esc(data.order_signature_name ? `Digital best\u00e4tigt von ${data.order_signature_name}` : 'Noch nicht unterschrieben')}</b><small>${esc(data.order_signature_date || '')}</small></div><div class="bp-head-actions"><button type="button" class="bp-secondary" id="sign-order">Digital unterschreiben</button><button type="button" class="bp-primary" id="preview-order-docs">${mode === 'KVA' ? 'KVA-Vorschau' : 'Auftrag und Vollmacht prüfen'}</button></div></section>
			<p class="bp-save-state bp-save-mirrored" id="order-save-state">\u00c4nderungen werden automatisch gespeichert.</p></form>`
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
		const draftLine = (id) => (state.serviceLinesDraft || []).find((entry) => Number(entry.id || 0) === Number(id || 0))
		root.querySelectorAll('[data-service-row]').forEach((row) => {
			const line = draftLine(row.dataset.serviceRow)
			if (Number(line?.invoicedQuantityMilli || 0) <= 0) return
			row.querySelectorAll('[data-line-field], [data-remove-service-line], [data-package-alternative], [data-package-exclude]').forEach((control) => { control.disabled = true; control.title = 'Bereits fakturierte Position – Vertragsdaten sind geschützt.' })
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
			const eligible = (state.articles || []).filter((article) => article.active && matchesFuneralScope(article, scope))
			const rank = (article) => {
				const number = String(article.articleNumber || '').toLocaleLowerCase('de-DE')
				const term = String(article.searchTerm || '').toLocaleLowerCase('de-DE')
				const texts = [article.shortName, article.longText, article.articleGroupName].map((entry) => String(entry || '').toLocaleLowerCase('de-DE'))
				if (number === query || term === query) return 0
				if (number.startsWith(query) || term.startsWith(query)) return 1
				if (texts.some((text) => text.split(/[^\p{L}\p{N}]+/u).some((word) => word.startsWith(query)))) return 2
				if (number.includes(query) || term.includes(query) || texts.some((text) => text.includes(query))) return 3
				return 99
			}
			return eligible.map((article) => ({ article, rank: rank(article) })).filter((entry) => entry.rank < 99).sort((left, right) => left.rank - right.rank || catalogSort(left.article, right.article)).map((entry) => ({ ...entry.article, matchRank: entry.rank }))
		}
		const applyCatalogArticle = (line, article) => {
			if (article.articleType === 'PAKET') {
				Object.assign(line, { articleId: Number(article.id), articleNumber: article.articleNumber, title: article.shortName, longText: article.longText || '', category: article.articleTypeLabel, articleGroup: article.articleGroupName, articleGroupKey: article.articleGroup, itemCategory: 'PACKAGE', isPackageHeader: true, quantity: 1, quantityDecimals: 0, unit: 'PAUSCHAL', unitPriceCents: 0, vatRate: 0, positionType: 'EL', origin: 'ORDER', packagePricing: article.bom?.pricing || 'ITEMS', packagePriceCents: article.bom?.pricing === 'HEADER' ? branchPrice(article) : null, printMode: article.bom?.printMode || 'ITEMS_NO_PRICES', packageConfig: null, priceEdited: false })
			} else {
				Object.assign(line, { articleId: Number(article.id), articleNumber: article.articleNumber, title: article.shortName, longText: article.longText || '', category: article.articleTypeLabel, articleGroup: article.articleGroupName, articleGroupKey: article.articleGroup, taxClass: article.taxClass, unit: article.unit || 'STK', quantityDecimals: Number(article.quantityDecimals || 0), unitPriceCents: article.priceOnEntry ? 0 : branchPrice(article), priceOnEntry: Boolean(article.priceOnEntry), guidePriceCents: article.guidePriceCents ?? null, vatRate: article.positionType === 'DP' ? 0 : Number(article.vatRate ?? 19), costType: article.costType, origin: 'ORDER', positionType: article.positionType || 'EL', quantity: Number(line.quantity || 1), itemCategory: 'NORMAL', isPackageHeader: false, priceEdited: false })
			}
			state.serviceDraft[article.id] = { selected: true }
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
			box.innerHTML = matches.map((article, index) => `<button type="button" role="option" aria-selected="false" id="bp-article-option-${itemKey(line.id)}-${index}" class="bp-article-suggestion" data-article-suggestion="${article.id}"><span class="bp-match-type">${article.matchRank <= 1 ? 'Nummer' : 'Bezeichnung'}</span><b>${highlightMatch(article.articleNumber, input.value)}</b><span>${highlightMatch(article.shortName, input.value)}</span><small>${esc([article.searchTerm, `${article.articleGroup} ${article.articleGroupName}`, article.articleTypeLabel, `${article.unit || 'STK'} – ${unitLabel(article.unit || 'STK')}`].filter(Boolean).join(' · '))}</small></button>`).join('') + (allMatches.length > 8 ? `<div class="bp-more-matches">+${allMatches.length - 8} weitere Treffer – Suche verfeinern</div>` : '')
			if (!matches.length) { hideArticleMatches(input, box); return }
			box.hidden = false
			if (box.parentElement !== document.body) document.body.append(box)
			positionArticleMatches(input, box)
			trackArticleMatches(input, box)
			input.setAttribute('aria-expanded', String(matches.length > 0))
			input.removeAttribute('aria-activedescendant')
			box.querySelectorAll('[data-article-suggestion]').forEach((button) => {
				const select = (event) => { event.preventDefault(); chooseCatalogArticle(line, articleById(button.dataset.articleSuggestion), box) }
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
		document.getElementById('open-order-form')?.addEventListener('click', () => ctx.openOrderForm())
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
				if (conflictBox) { conflictBox.classList.remove('bp-hidden'); conflictBox.innerHTML = `<b>Auswahlkonflikt – noch nicht gespeichert</b>${conflicts.map((message) => `<span>${esc(message)}</span>`).join('')}` }
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
		const removeLine = (line) => {
			if (!line) return
			const removeIds = new Set([Number(line.id || 0)])
			if (isHeaderLine(line)) childrenOf(line).forEach((child) => removeIds.add(Number(child.id)))
			state.serviceLinesDraft = (state.serviceLinesDraft || []).filter((entry) => !removeIds.has(Number(entry.id || 0)))
			if (line.articleId && !(state.serviceLinesDraft || []).some((entry) => Number(entry.articleId) === Number(line.articleId) && !isChildLine(entry))) state.serviceDraft[line.articleId] = { selected: false }
		}
		const addArticleLine = (article) => {
			state.serviceLinesDraft = (state.serviceLinesDraft || []).filter((line) => !isEmptyServiceLine(line))
			const line = emptyServiceLine()
			applyCatalogArticle(line, article)
			state.serviceLinesDraft.push(line)
		}
		root.querySelectorAll('[data-service-select]').forEach((input) => input.addEventListener('change', () => {
			const id = Number(input.dataset.serviceSelect)
			const article = articleById(id)
			if (!article) return
			if (input.checked && !(state.serviceLinesDraft || []).some((line) => Number(line.articleId) === id && !isChildLine(line))) addArticleLine(article)
			else if (!input.checked) (state.serviceLinesDraft || []).filter((line) => Number(line.articleId) === id && !isChildLine(line)).forEach(removeLine)
			input.closest('.bp-service-card')?.classList.toggle('selected', input.checked)
			persist()
		}))
		root.querySelectorAll('[data-service-add]').forEach((button) => button.addEventListener('click', () => {
			state.serviceCatalogOpen = true
			const input = root.querySelector(`[data-service-select="${button.dataset.serviceAdd}"]`)
			if (input && !input.checked) { input.checked = true; input.dispatchEvent(new Event('change', { bubbles: true })) }
		}))
		root.querySelectorAll('[data-line-field]').forEach((input) => input.addEventListener('input', () => {
			const line = draftLine(input.dataset.serviceId)
			if (!line) return
			const fieldName = input.dataset.lineField
			if (fieldName === 'articleNumber') {
				line.articleNumber = input.value
				showArticleMatches(input, line)
				return
			}
			line[fieldName] = fieldName === 'unitPriceCents' ? Math.max(0, Math.round(Number(input.value || 0) * 100)) : fieldName === 'quantity' ? Math.max(Number(quantityStep(line.quantityDecimals)), Number(input.value || 1)) : input.value
			if (fieldName === 'unitPriceCents') line.priceEdited = true
			if (fieldName === 'positionType') {
				line.positionType = String(input.value || 'EL').toUpperCase()
				if (line.positionType === 'DP') line.vatRate = 0
			}
			updateLineValidation(line)
			if (line.origin === 'FREE_TEXT' && fieldName === 'title' && !String(line.title || '').trim()) return
			persist()
		}))
		root.querySelectorAll('[data-article-input]').forEach((input) => {
			input.addEventListener('keydown', (event) => {
				const line = draftLine(input.dataset.serviceId)
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
					const typed = String(input.value).trim().toLocaleLowerCase('de-DE')
					const matches = catalogMatches(input.value); const activeIndex = Number(input.dataset.activeSuggestion ?? -1); const exact = matches.find((article) => String(article.articleNumber).toLocaleLowerCase('de-DE') === typed || String(article.searchTerm || '').toLocaleLowerCase('de-DE') === typed)
					const chosen = activeIndex >= 0 ? matches[activeIndex] : exact || (matches.length === 1 ? matches[0] : null)
					if (chosen) { event.preventDefault(); chooseCatalogArticle(line, chosen, box) }
				}
			})
			input.addEventListener('blur', () => setTimeout(() => {
				const line = draftLine(input.dataset.serviceId)
				const box = articleMatchesBox(input)
				hideArticleMatches(input, box)
				if (!line) return
				const typed = String(input.value || '').trim().toLocaleLowerCase('de-DE')
				const exact = catalogMatches(input.value).find((article) => String(article.articleNumber || '').toLocaleLowerCase('de-DE') === typed || String(article.searchTerm || '').toLocaleLowerCase('de-DE') === typed)
				if (exact && Number(exact.id) !== Number(line.articleId || 0)) { chooseCatalogArticle(line, exact, box); return }
				const selected = articleById(line.articleId)
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
			removeLine(draftLine(button.dataset.removeServiceLine))
			persist()
			render()
		}))
		root.querySelectorAll('[data-show-service-details]').forEach((button) => button.addEventListener('click', () => {
			const id = Number(button.dataset.showServiceDetails || 0)
			state.openServiceDetailId = Number(state.openServiceDetailId || 0) === id ? 0 : id
			render()
		}))
		// Paket: Festpreis, Druckart, Alternativen tauschen, optionale Bestandteile ab-/zuwählen.
		const headerOf = (child) => draftLine(child?.parentServiceId)
		const currentSelection = (header) => header.packageConfig?.selected ? [...header.packageConfig.selected] : childrenOf(header).map((child) => Number(child.bomItemId || 0)).filter(Boolean)
		root.querySelectorAll('[data-package-price]').forEach((input) => input.addEventListener('input', () => {
			const header = draftLine(input.dataset.packagePrice)
			if (!header) return
			header.packagePriceCents = Math.max(0, Math.round(Number(input.value || 0) * 100))
			persist()
		}))
		root.querySelectorAll('[data-package-print]').forEach((select) => select.addEventListener('change', () => {
			const header = draftLine(select.dataset.packagePrint)
			if (!header) return
			header.printMode = select.value
			persist()
		}))
		root.querySelectorAll('[data-package-alternative]').forEach((select) => select.addEventListener('change', () => {
			const child = draftLine(select.dataset.packageAlternative)
			const header = headerOf(child)
			if (!header) return
			const bom = articleById(header.articleId)?.bom
			const current = (bom?.items || []).find((entry) => Number(entry.id) === Number(child.bomItemId))
			const groupIds = (bom?.items || []).filter((entry) => entry.itemType === 'ALTERNATIVE' && entry.altGroup === current?.altGroup).map((entry) => Number(entry.id))
			header.packageConfig = { selected: [...currentSelection(header).filter((id) => !groupIds.includes(id)), Number(select.value)] }
			persist()
		}))
		root.querySelectorAll('[data-package-exclude]').forEach((button) => button.addEventListener('click', () => {
			const child = draftLine(button.dataset.packageExclude)
			const header = headerOf(child)
			if (!header) return
			header.packageConfig = { selected: currentSelection(header).filter((id) => id !== Number(child.bomItemId)) }
			persist()
		}))
		root.querySelectorAll('[data-package-include]').forEach((button) => button.addEventListener('click', () => {
			const header = draftLine(button.dataset.packageInclude)
			if (!header) return
			header.packageConfig = { selected: [...new Set([...currentSelection(header), Number(button.dataset.bomItem)])] }
			persist()
		}))
		root.querySelectorAll('[data-service-page]').forEach((button) => button.addEventListener('click', () => { state.servicePage = Number(button.dataset.servicePage); resetCatalogViewport(); render() }))
		document.getElementById('show-selected-services')?.addEventListener('click', () => { state.serviceSelectedOnly = true; state.servicePage = 1; resetCatalogViewport(); render() })
		document.getElementById('service-area-filter')?.addEventListener('change', (event) => { state.serviceArea = event.target.value; state.serviceArticleGroup = 'ALL'; state.servicePage = 1; resetCatalogViewport(); render() })
		document.getElementById('service-group-filter')?.addEventListener('change', (event) => { state.serviceArticleGroup = event.target.value; state.servicePage = 1; resetCatalogViewport(); render() })
		document.getElementById('service-type-filter')?.addEventListener('change', (event) => { state.serviceArticleType = event.target.value; state.servicePage = 1; resetCatalogViewport(); render() })
		document.getElementById('service-page-size')?.addEventListener('change', (event) => { state.servicePageSize = Number(event.target.value); state.servicePage = 1; resetCatalogViewport(); render() })
		document.getElementById('service-selected-only')?.addEventListener('change', (event) => { state.serviceSelectedOnly = event.target.checked; state.servicePage = 1; resetCatalogViewport(); render() })
		document.getElementById('reset-service-filters')?.addEventListener('click', () => { state.serviceQuery = ''; state.serviceArea = 'ALL'; state.serviceArticleGroup = 'ALL'; state.serviceArticleType = 'ALL'; state.serviceSelectedOnly = false; state.servicePage = 1; resetCatalogViewport(); render() })
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
		const typeLabel=invoiceType==='FINAL'?'Schlussrechnung':'Teilrechnung';const eligible=(state.caseServices.items||[]).filter((item)=>item.billability==='ABRECHENBAR'&&!item.isPackageHeader&&item.serviceStatus!=='STORNIERT'&&Number(item.performedQuantityMilli)>Number(item.invoicedQuantityMilli));
		if(!eligible.length){ctx.notifyWarning('Es sind keine abrechenbaren Restmengen vorhanden.');return}
		const sideOrderId=Number(state.activeSideOrderId||0)||0;const sideOrderQuery=sideOrderId?`&sideOrderId=${sideOrderId}`:'';const periodFrom=String(state.currentCase.createdAt||state.currentCase.masterData?.order_date||new Date().toISOString()).slice(0,10);const periodTo=new Date().toISOString().slice(0,10);
		const rows=eligible.map((item)=>{const remaining=(Number(item.performedQuantityMilli)-Number(item.invoicedQuantityMilli))/1000;return `<tr><td><input type="checkbox" data-invoice-select="${item.id}" ${invoiceType==='FINAL'?'checked disabled':'checked'} aria-label="${esc(item.title)} auswählen"></td><td><b>${esc(item.positionLabel||'')} ${esc(item.title)}</b><small>${esc(item.articleNumber)}${item.packageTitle?` · aus Paket ${esc(item.packageTitle)}`:''}</small></td><td class="number">${formatQuantity(item.performedQuantityMilli/1000,item.quantityDecimals)}</td><td class="number">${formatQuantity(item.invoicedQuantityMilli/1000,item.quantityDecimals)}</td><td><input class="bp-compact-input" data-invoice-quantity="${item.id}" type="number" min="${quantityStep(item.quantityDecimals)}" max="${remaining}" step="${quantityStep(item.quantityDecimals)}" value="${remaining}" ${invoiceType==='FINAL'?'readonly':''}></td><td class="number">${formatMoney(Math.round(remaining*item.unitPriceCents*(1+item.vatRate/100)))}</td></tr>`}).join('');
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


	// 0.70.0: Gutschrift (Rechnungskorrektur) zu einzelnen Positionen einer ausgestellten Rechnung
	function showCreditNoteForm(invoiceId) {
		const invoice = (state.commercial.invoices || []).find((item) => Number(item.id) === Number(invoiceId))
		if (!invoice) return
		const items = (invoice.items || []).filter((item) => Number(item.creditRemainingCents || 0) > 0)
		if (!items.length) { ctx.notifyWarning('Zu dieser Rechnung ist kein gutschreibbarer Betrag mehr offen.'); return }
		const rows = items.map((item) => `<tr><td><input type="checkbox" data-credit-select="${item.id}" aria-label="${esc(item.description)} auswählen"></td><td><b>${esc(item.positionLabel)} ${esc(item.description)}</b><small>${esc(String(item.vatRate))} % MwSt. · noch gutschreibbar ${formatMoney(item.creditRemainingCents)} netto</small></td><td class="number">${esc(String(item.quantity))}</td><td><input class="bp-compact-input" type="number" min="0.001" step="0.001" max="${item.quantity}" value="${item.quantity}" data-credit-quantity="${item.id}" aria-label="Menge"></td><td><input class="bp-compact-input" type="number" min="0.01" step="0.01" max="${(item.unitPriceCents / 100).toFixed(2)}" value="${(Math.floor(Math.min(item.unitPriceCents, Number(item.creditRemainingCents) / Math.max(0.001, Number(item.quantity)))) / 100).toFixed(2)}" data-credit-price="${item.id}" aria-label="Einzelpreis netto"><small>Rechnung ${formatMoney(item.unitPriceCents)}</small></td><td class="number" data-credit-sum="${item.id}">–</td></tr>`).join('')
		root.insertAdjacentHTML('beforeend', `<div class="bp-modal" role="dialog" aria-modal="true" aria-labelledby="credit-note-title"><form id="credit-note-form" class="bp-modal-card bp-wide-modal"><div class="bp-panel-head"><div><p class="bp-eyebrow">Rechnungskorrektur</p><h2 id="credit-note-title">Gutschrift zur Rechnung ${esc(invoice.invoiceNumber)}</h2><p class="bp-muted">Positionen auswählen und Menge und/oder Einzelpreis der Gutschrift angeben (z. B. Preisnachlass: gleiche Menge, Minderbetrag als Einzelpreis). Der Steuersatz folgt der Position. Die Gutschrift erhält eine eigene Nummer und wird wie eine Rechnung geprüft und freigegeben.</p></div></div><div class="bp-table-scroll"><table><thead><tr><th>Auswahl</th><th>Position</th><th class="number">Menge Rechnung</th><th>Menge Gutschrift</th><th>Einzelpreis netto</th><th class="number">Gutschrift netto</th></tr></thead><tbody>${rows}</tbody></table></div><div class="bp-form-grid"><label class="bp-span-2"><span>Grund der Gutschrift</span><input name="reason" required placeholder="z. B. Preisnachlass Trauerfeier, Menge falsch berechnet"></label><label class="bp-inline-check bp-span-2"><input type="checkbox" name="releaseQuantities"> Gutgeschriebene Mengen nach der Freigabe wieder zur Abrechnung freigeben (nur bei voller Menge zum Rechnungspreis, z. B. wenn neu berechnet wird)</label></div><p class="bp-save-state" id="credit-note-state" aria-live="polite"></p><div class="bp-row-actions"><button type="button" class="bp-secondary" id="cancel-credit-note">Abbrechen</button><button class="bp-primary">Gutschrift anlegen</button></div></form></div>`)
		const modal = root.querySelector('.bp-modal:last-child'); const form = modal.querySelector('#credit-note-form')
		const update = () => items.forEach((item) => { const on = modal.querySelector(`[data-credit-select="${item.id}"]`).checked; const quantity = Number(modal.querySelector(`[data-credit-quantity="${item.id}"]`).value || 0); const price = Number(modal.querySelector(`[data-credit-price="${item.id}"]`).value || 0); modal.querySelector(`[data-credit-sum="${item.id}"]`).textContent = on ? formatMoney(Math.round(quantity * price * 100)) : '–' })
		modal.querySelectorAll('input').forEach((input) => input.addEventListener('input', update)); update()
		modal.querySelector('#cancel-credit-note').onclick = () => modal.remove()
		form.onsubmit = async (event) => {
			event.preventDefault()
			const status = modal.querySelector('#credit-note-state')
			const lines = items.filter((item) => modal.querySelector(`[data-credit-select="${item.id}"]`).checked).map((item) => ({ itemId: item.id, quantity: modal.querySelector(`[data-credit-quantity="${item.id}"]`).value, unitPrice: modal.querySelector(`[data-credit-price="${item.id}"]`).value }))
			if (!lines.length) { status.textContent = 'Bitte mindestens eine Position auswählen.'; status.dataset.state = 'error'; return }
			try {
				const created = await api(`${ctx.apiBase}/commercial/invoices/${invoice.id}/credit-note`, { method: 'POST', feedback: false, body: new URLSearchParams({ lines: JSON.stringify(lines), reason: form.elements.reason.value, releaseQuantities: form.elements.releaseQuantities.checked ? '1' : '' }) })
				modal.remove(); ctx.notifySuccess(`Gutschrift ${created.invoiceNumber} wurde als Entwurf angelegt.`); await ctx.loadCommercial(); render()
			} catch (error) { status.textContent = error.message; status.dataset.state = 'error' }
		}
	}

	return { orderField, funeralScope, hydrateServiceDraft, loadCaseServices, serviceConflictMessages, saveCaseServices, serviceSelectionPanel, orderPanel, bindServiceSelection, showInvoiceDraftForm, showCreditNoteForm, finalizeCommercialDocument, relationOptions, orderTypes, commissioningTypes, euro, formatMoney, costTypeLabels, unitLabels, quantityStep, formatQuantity, serviceSaveTimer }
}
