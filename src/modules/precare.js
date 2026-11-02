/* global OC */
// 0.89.7 (OP-074/OP-075, Schritte B–D): Vorsorge in der Akte (Reiter „Vorsorge“ und „Finanzierung“), Einlösen in einen
// Sterbefall (Vorschau, dann Übernahme), Hinweis auf passende Vorsorgen im Sterbefall, Herkunft im Sterbefall mit
// Finanzierungen („ausgezahlt“), „Als Sterbefall weiterführen“ im Auftrag und Wiedervorlagen in der Arbeitsübersicht.
import { caseKindOf } from './case-kinds.js'

export const PRECARE_CASE_TABS = [['precare', 'Vorsorge'], ['funding', 'Finanzierung']]

export function createPrecareModule(ctx) {
	const { state, apiBase } = ctx
	const api = (...args) => ctx.api(...args)
	const esc = (...args) => ctx.esc(...args)
	const render = (...args) => ctx.render(...args)
	const notifyError = (...args) => ctx.notifyError(...args)
	const notifySuccess = (...args) => ctx.notifySuccess(...args)
	const notifyWarning = (...args) => ctx.notifyWarning(...args)
	const money = (cents) => cents === null || cents === undefined ? '–' : (Number(cents) / 100).toLocaleString('de-DE', { style: 'currency', currency: 'EUR' })
	const date = (value) => value ? new Date(`${String(value).slice(0, 10)}T12:00:00`).toLocaleDateString('de-DE') : '–'
	const caseUrl = (caseId, suffix = '') => `${apiBase}/cases/${Number(caseId)}${suffix}`
	const option = (value, label, selected) => `<option value="${esc(value)}" ${String(value) === String(selected) ? 'selected' : ''}>${esc(label)}</option>`
	const token = () => (globalThis.crypto?.randomUUID ? globalThis.crypto.randomUUID().replaceAll('-', '') : `${Date.now()}${Math.random().toString(36).slice(2, 10)}`)

	// ---------------------------------------------------------------------------------------------- Laden

	async function loadOrderTypes(force = false) {
		if (state.orderTypes && !force) return state.orderTypes
		try { state.orderTypes = await api(`${apiBase}/order-types`, { feedback: false, loadingLabel: false }) } catch { state.orderTypes = { types: [] } }
		return state.orderTypes
	}

	/** Beim Öffnen einer Akte: Vorsorgestand, Herkunft/abgeleitete Akten und – im Sterbefall – passende Vorsorgen. */
	async function loadCaseKindData(caseId) {
		const item = state.currentCase
		state.precare = null; state.caseRelations = null; state.precareCandidates = []
		if (!item || Number(item.id) !== Number(caseId)) return
		const kind = caseKindOf(item)
		const jobs = [loadOrderTypes(), api(caseUrl(caseId, '/relations'), { feedback: false, loadingLabel: false }).then((result) => { state.caseRelations = result }).catch(() => { state.caseRelations = null })]
		if (kind === 'VORSORGE') jobs.push(api(caseUrl(caseId, '/precare'), { feedback: false, loadingLabel: false }).then((result) => { state.precare = result }).catch((error) => { state.precare = { error: error.message } }))
		if (kind === 'STERBEFALL' && !Number(item.originCaseId || 0)) jobs.push(api(caseUrl(caseId, '/precare-candidates'), { feedback: false, loadingLabel: false }).then((result) => { state.precareCandidates = result.candidates || [] }).catch(() => { state.precareCandidates = [] }))
		await Promise.all(jobs)
	}

	async function reloadCase() {
		const id = state.currentCase?.id
		if (!id) return
		state.currentCase = await api(`${ctx.urls.cases}/${id}`, { feedback: false })
		await loadCaseKindData(id)
		if (ctx.loadProcess) await ctx.loadProcess(id).catch(() => {})
	}

	// ---------------------------------------------------------------------------------------------- Reiter

	function extraTabs(item) {
		return caseKindOf(item) === 'VORSORGE' ? PRECARE_CASE_TABS : []
	}

	function tabBody(tab) {
		if (tab === 'precare') return precarePanel()
		if (tab === 'funding') return fundingPanel()
		return null
	}

	function statusChip(precare) {
		const tone = precare.status === 'ABGESCHLOSSEN' ? 'ready' : ''
		return `<span class="bp-status ${tone}">${esc(precare.statusLabel || precare.status)}</span>`
	}

	function precarePanel() {
		const precare = state.precare
		if (!precare) return '<section class="bp-panel"><p class="bp-muted">Vorsorge wird geladen …</p></section>'
		if (precare.error) return `<section class="bp-panel"><p class="bp-warning">${esc(precare.error)}</p></section>`
		const settings = precare.settings || {}
		const disabled = precare.ended ? 'disabled' : ''
		const redeemed = precare.status === 'EINGELOEST'
		const statusText = {
			BERATUNG: 'Beratung: Wünsche unter Stammdaten → Wünsche erfassen, Leistungen und Kostenvoranschlag im Reiter „Auftrag / KVA“. Ein festgeschriebener Kostenvoranschlag macht daraus ein Angebot.',
			ANGEBOT: 'Angebot: Der Kostenvoranschlag ist festgeschrieben. Mit dem festgeschriebenen bzw. am Tablet unterschriebenen Vorsorgevertrag (Reiter „Auftrag / KVA“, Modus Auftrag) ist der Vertrag abgeschlossen.',
			ABGESCHLOSSEN: 'Vertrag abgeschlossen: Die Vorsorge wird betreut und regelmäßig wiedervorgelegt (Anschrift, Wünsche, Preise prüfen).',
			GEKUENDIGT: 'Die Vorsorge ist gekündigt.', WIDERRUFEN: 'Die Vorsorge ist widerrufen.', EINGELOEST: 'Die Vorsorge ist eingelöst.',
		}[precare.status] || ''
		const review = precare.active ? `<article class="bp-precare-review ${precare.reviewOverdue ? 'is-overdue' : precare.reviewDue ? 'is-due' : ''}"><p class="bp-eyebrow">Wiedervorlage</p><h3>${esc(date(precare.nextReviewAt))}${precare.reviewOverdue ? ' · überfällig' : precare.reviewDue ? ' · fällig' : ''}</h3><p class="bp-muted">Alle ${Number(precare.reviewIntervalMonths || 24)} Monate${precare.reviewedAt ? ` · zuletzt erledigt am ${esc(date(precare.reviewedAt))}` : ''}.</p><div class="bp-row-actions"><button type="button" class="bp-secondary" data-precare-review>Wiedervorlage erledigt</button></div></article>` : ''
		const ended = precare.ended ? `<aside class="bp-compliance-note"><b>${esc(precare.statusLabel)}${precare.endedAt ? ` am ${esc(date(precare.endedAt))}` : ''}</b>${precare.endReason ? `<p>${esc(precare.endReason)}</p>` : ''}${redeemed && precare.redeemedCaseId ? `<p>Sterbefall <button type="button" class="bp-link-button" data-open-case="${Number(precare.redeemedCaseId)}">${esc(precare.redeemedCaseNumber || precare.redeemedCaseId)}</button></p>` : ''}</aside>` : ''
		const portal = settings.portalUrl ? `<a class="bp-secondary" href="${esc(settings.portalUrl)}" target="_blank" rel="noopener noreferrer">${esc(settings.portalLabel || 'Portal öffnen')}</a>` : ''
		return `<section class="bp-panel bp-precare" data-precare-panel>
			<div class="bp-panel-head"><div><p class="bp-eyebrow">Bestattungsvorsorge</p><h3>Vorsorgevertrag</h3><p class="bp-muted">${esc(statusText)}</p></div>${statusChip(precare)}</div>
			${ended}
			<form class="bp-form-grid" data-precare-form>
				<label><span>Preise bei Ausführung</span><select name="priceMode" ${disabled}>${Object.entries(settings.priceModes || {}).map(([key, label]) => option(key, label, precare.priceMode)).join('')}</select><small>${precare.priceMode === 'GUARANTEED' ? 'Die vereinbarten Preise der eigenen Leistungen gelten auch bei der Ausführung.' : 'Abgerechnet werden die dann gültigen Preise; die Übernahme zeigt die Abweichung je Position.'}</small></label>
				<label><span>Vertragsdatum</span><input type="date" name="contractDate" value="${esc(precare.contractDate || '')}" ${disabled}><small>Wird beim Festschreiben des Vorsorgevertrags gesetzt.</small></label>
				<label><span>Wiedervorlage alle … Monate</span><input type="number" min="1" max="120" name="reviewIntervalMonths" value="${Number(precare.reviewIntervalMonths || 24)}" ${disabled}></label>
				<label><span>Nächste Wiedervorlage</span><input type="date" name="nextReviewAt" value="${esc(precare.nextReviewAt || '')}" ${disabled}></label>
			</form>
			${precare.ended ? '' : '<div class="bp-row-actions"><button type="button" class="bp-primary" data-precare-save>Speichern</button><span class="bp-card-state" data-precare-state aria-live="polite"></span></div>'}
			<div class="bp-case-summary bp-precare-summary">${review}<article><p class="bp-eyebrow">Wünsche</p><h3>${esc(state.currentCase?.masterData?.funeral_type || 'Noch keine Bestattungsart')}</h3><button type="button" class="bp-secondary" data-precare-wishes>Wünsche bearbeiten</button></article><article><p class="bp-eyebrow">Finanzierung</p><h3>${(precare.fundings || []).length ? `${(precare.fundings || []).length} erfasst · ${esc(money(precare.fundingTotalCents))}` : 'Keine erfasst'}</h3><button type="button" class="bp-secondary" data-case-tab="funding">Finanzierung öffnen</button></article></div>
			${precare.active ? `<section class="bp-field-section"><h3>Sterbefall eintragen</h3><p class="bp-muted">Ist die vorsorgende Person verstorben, übernimmt „Vorsorge einlösen“ Person, Wünsche, Positionen des Vorsorgevertrags, Vertrag und Verfügungen (als Kopie), Kontakte und Marker in einen neuen Sterbefall – nach einer Vorschau. Für Treuhand und Sterbegeldversicherung entsteht je eine Sterbefallmeldung unter „Abmeldungen“.</p><div class="bp-row-actions"><button type="button" class="bp-primary" data-precare-redeem="${Number(state.currentCase.id)}">Vorsorge einlösen (Sterbefall eintragen)</button></div></section>
			<section class="bp-field-section"><h3>Vorsorge beenden</h3><p class="bp-muted">Kündigung oder Widerruf schließen die Akte; die Aufbewahrungsfrist beginnt mit dem Ende. Eine Rückzahlung aus der Treuhand erfolgt außerhalb der App.</p><div class="bp-row-actions"><button type="button" class="bp-secondary" data-precare-end="GEKUENDIGT">Kündigung eintragen</button><button type="button" class="bp-secondary" data-precare-end="WIDERRUFEN">Widerruf eintragen</button>${portal}</div></section>` : ''}
		</section>`
	}

	function fundingPanel() {
		const precare = state.precare
		if (!precare) return '<section class="bp-panel"><p class="bp-muted">Finanzierung wird geladen …</p></section>'
		if (precare.error) return `<section class="bp-panel"><p class="bp-warning">${esc(precare.error)}</p></section>`
		const settings = precare.settings || {}
		const editable = precare.status !== 'EINGELOEST'
		const rows = (precare.fundings || []).map((funding) => `<tr><td data-label="Art">${esc(funding.fundingTypeLabel)}</td><td data-label="Anbieter">${esc(funding.providerName || '–')}</td><td data-label="Vertragsnummer">${esc(funding.contractNumber || '–')}</td><td data-label="Betrag">${esc(money(funding.amountCents))}</td><td data-label="Abschluss">${esc(date(funding.contractedAt))}</td><td data-label="Status"><span class="bp-status ${funding.status === 'AKTIV' ? 'ready' : ''}">${esc(funding.statusLabel)}</span></td><td>${editable ? `<button type="button" class="bp-secondary" data-funding-edit="${Number(funding.id)}">Bearbeiten</button> <button type="button" class="bp-secondary" data-funding-delete="${Number(funding.id)}">Entfernen</button>` : ''}</td></tr>`).join('')
		const portal = settings.portalUrl ? `<a class="bp-secondary" href="${esc(settings.portalUrl)}" target="_blank" rel="noopener noreferrer">${esc(settings.portalLabel || 'Portal öffnen')}</a>` : ''
		return `<section class="bp-panel bp-precare-funding">
			<div class="bp-panel-head"><div><p class="bp-eyebrow">Bestattungsvorsorge</p><h3>Finanzierung</h3><p class="bp-muted">Treuhand, Sterbegeldversicherung oder andere Absicherung. Der Abschluss beim Treuhänder bzw. Versicherer erfolgt außerhalb der App; hier stehen Anbieter, Vertragsnummer und Betrag für den Sterbefall bereit. Nachweise legen Sie unter „Dokumente“ ab.</p></div><span class="bp-status">${esc(money(precare.fundingTotalCents))}</span></div>
			<div class="bp-table-scroll"><table><thead><tr><th>Art</th><th>Anbieter</th><th>Vertragsnummer</th><th>Betrag</th><th>Abschluss</th><th>Status</th><th><span class="hidden-visually">Aktionen</span></th></tr></thead><tbody>${rows || '<tr><td colspan="7" class="bp-empty">Noch keine Finanzierung erfasst (Selbstzahler bzw. Regelung durch die Erben).</td></tr>'}</tbody></table></div>
			<div class="bp-row-actions">${editable ? '<button type="button" class="bp-primary" data-funding-add>Finanzierung erfassen</button>' : ''}${portal}</div>
		</section>`
	}

	// ---------------------------------------------------------------------------------------------- Übersicht der Akte

	function caseKindOverview() {
		const item = state.currentCase
		if (!item?.id) return ''
		const kind = caseKindOf(item)
		const relations = state.caseRelations || {}
		const derived = (relations.derived || []).filter((entry) => entry.caseKind === 'STERBEFALL')
		const openCase = (entry) => `<button type="button" class="bp-link-button" data-open-case="${Number(entry.id)}">${esc(entry.caseNumber)}</button>`
		if (kind === 'VORSORGE') {
			const precare = state.precare
			if (!precare || precare.error) return ''
			const review = precare.active && precare.nextReviewAt ? ` · Wiedervorlage ${esc(date(precare.nextReviewAt))}${precare.reviewOverdue ? ' (überfällig)' : ''}` : ''
			return `<aside class="bp-compliance-note bp-precare-note"><b>Vorsorge: ${esc(precare.statusLabel)}</b><p>${esc(precare.priceModeLabel || '')}${precare.contractDate ? ` · Vertrag vom ${esc(date(precare.contractDate))}` : ''}${review}${precare.redeemedCaseId ? ` · Sterbefall ${openCase({ id: precare.redeemedCaseId, caseNumber: precare.redeemedCaseNumber || precare.redeemedCaseId })}` : ''}</p><div class="bp-row-actions"><button type="button" class="bp-secondary" data-case-tab="precare">Vorsorge öffnen</button>${precare.reviewDue ? '<button type="button" class="bp-secondary" data-precare-review>Wiedervorlage erledigt</button>' : ''}</div></aside>`
		}
		if (kind === 'AUFTRAG') {
			if (derived.length) return `<aside class="bp-compliance-note"><b>Als Sterbefall weitergeführt</b><p>Sterbefall ${derived.map(openCase).join(', ')}</p></aside>`
			if (['ABGESCHLOSSEN', 'STORNIERT'].includes(String(item.status || '').toUpperCase())) return ''
			return `<aside class="bp-compliance-note"><b>Wird daraus eine Bestattung?</b><p>Eine Überführung oder Urnenbeisetzung kann zum vollständigen Sterbefall werden. „Als Sterbefall weiterführen“ legt einen verknüpften Sterbefall mit den Angaben und offenen Positionen an; dieser Auftrag behält seine Nummer.</p><div class="bp-row-actions"><button type="button" class="bp-secondary" data-continue-as-death="${Number(item.id)}">Als Sterbefall weiterführen</button></div></aside>`
		}
		const origin = relations.origin
		if (origin?.caseKind === 'VORSORGE') {
			const fundings = (origin.fundings || []).filter((funding) => funding.status !== 'GEKUENDIGT')
			const rows = fundings.map((funding) => `<li>${esc(funding.fundingTypeLabel)}${funding.providerName ? ` · ${esc(funding.providerName)}` : ''}${funding.contractNumber ? ` · Vertrag ${esc(funding.contractNumber)}` : ''}${funding.amountCents !== null ? ` · ${esc(money(funding.amountCents))}` : ''} · <b>${esc(funding.statusLabel)}</b>${funding.status !== 'AUSGEZAHLT' ? ` <button type="button" class="bp-secondary" data-funding-paid="${Number(funding.id)}">Als ausgezahlt markieren</button>` : ''}</li>`).join('')
			return `<aside class="bp-compliance-note bp-precare-note"><b>Aus Vorsorge ${openCase(origin)} übernommen</b><p>${esc(origin.priceModeLabel || '')}. Die Sterbefallmeldungen an Treuhand bzw. Versicherung stehen unter „Abmeldungen“.</p>${rows ? `<ul>${rows}</ul>` : ''}</aside>`
		}
		if (origin?.caseKind === 'AUFTRAG') return `<aside class="bp-compliance-note"><b>Aus Auftrag ${openCase(origin)} weitergeführt</b></aside>`
		const candidates = state.precareCandidates || []
		if (!candidates.length) return ''
		return `<aside class="bp-warning bp-precare-candidates" role="status"><b>${candidates.length === 1 ? 'Passende Vorsorge gefunden' : 'Passende Vorsorgen gefunden'}</b><ul>${candidates.map((candidate) => `<li>${openCase(candidate)} · ${esc(`${candidate.lastName}, ${candidate.firstName}`)}${candidate.dateOfBirth ? ` · geboren ${esc(date(candidate.dateOfBirth))}` : ''} · ${candidate.match === 'SICHER' ? 'Name und Geburtsdatum stimmen überein' : 'Name stimmt überein, Geburtsdatum prüfen'} <button type="button" class="bp-secondary" data-precare-redeem="${Number(candidate.id)}" data-redeem-into="${Number(item.id)}">Vorsorge übernehmen</button></li>`).join('')}</ul></aside>`
	}

	/** Arbeitsübersicht: aktive Vorsorgen mit fälliger Wiedervorlage. */
	function precareReviewsPanel() {
		const reviews = state.dashboard?.precareReviews
		if (!reviews?.count) return ''
		const rows = (reviews.items || []).map((item) => `<button type="button" class="bp-work-item" data-open-case="${Number(item.caseId)}"><span></span><div><b><span class="bp-case-number">${esc(item.caseNumber)}</span>${esc(item.name)}</b><div class="bp-dashboard-record-meta"><em>Wiedervorlage: ${esc(date(item.nextReviewAt))}</em>${item.overdue ? '<span class="bp-status">überfällig</span>' : ''}</div></div></button>`).join('')
		return `<section class="bp-panel bp-precare-reviews"><div class="bp-panel-head"><div><p class="bp-eyebrow">Vorsorgen</p><h3>Wiedervorlagen (${Number(reviews.count)})</h3><p class="bp-muted">Fällig in den nächsten ${Number(reviews.leadDays ?? 30)} Tagen oder überfällig.</p></div><button class="bp-secondary" data-view="precare">Alle Vorsorgen</button></div><div class="bp-work-list">${rows}</div></section>`
	}

	// ---------------------------------------------------------------------------------------------- Dialoge

	function modal(html) {
		ctx.root.insertAdjacentHTML('beforeend', `<div class="bp-modal" role="dialog" aria-modal="true">${html}</div>`)
		const element = [...ctx.root.querySelectorAll('.bp-modal')].pop()
		element.querySelectorAll('[data-close-modal]').forEach((button) => { button.onclick = () => element.remove() })
		return element
	}

	function showFundingDialog(funding = null) {
		const settings = state.precare?.settings || {}
		const amount = funding?.amountCents !== null && funding?.amountCents !== undefined ? (Number(funding.amountCents) / 100).toLocaleString('de-DE', { minimumFractionDigits: 2 }) : ''
		const element = modal(`<form class="bp-modal-card" data-funding-form><div class="bp-panel-head"><div><p class="bp-eyebrow">Vorsorge</p><h2>${funding ? 'Finanzierung bearbeiten' : 'Finanzierung erfassen'}</h2></div></div>
			<div class="bp-form-grid bp-new-kind-grid">
				<label><span>Art</span><select name="fundingType" required>${Object.entries(settings.fundingTypes || {}).map(([key, label]) => option(key, label, funding?.fundingType || 'TREUHAND')).join('')}</select></label>
				<label><span>Status</span><select name="fundingStatus">${Object.entries(settings.fundingStatuses || {}).map(([key, label]) => option(key, label, funding?.status || 'BEANTRAGT')).join('')}</select></label>
				<label><span>Anbieter (Treuhänder, Versicherer)</span><input name="providerName" maxlength="200" value="${esc(funding?.providerName || '')}"></label>
				<label><span>Vertrags-/Versicherungsnummer</span><input name="contractNumber" maxlength="100" value="${esc(funding?.contractNumber || '')}"></label>
				<label><span>Betrag (€)</span><input name="amount" inputmode="decimal" value="${esc(amount)}"></label>
				<label><span>Abgeschlossen am</span><input type="date" name="contractedAt" value="${esc(funding?.contractedAt || '')}"></label>
				<label class="bp-span-2"><span>Notiz</span><textarea name="notes" rows="2">${esc(funding?.notes || '')}</textarea></label>
			</div><p class="bp-error" data-funding-error hidden></p>
			<div class="bp-actions"><button type="button" class="bp-secondary" data-close-modal>Abbrechen</button><button type="submit" class="bp-primary">Speichern</button></div></form>`)
		const form = element.querySelector('form')
		form.onsubmit = async (event) => {
			event.preventDefault()
			const data = Object.fromEntries(new FormData(form).entries())
			try {
				state.precare = await api(caseUrl(state.currentCase.id, `/precare/fundings${funding ? `/${Number(funding.id)}` : ''}`), { method: funding ? 'PUT' : 'POST', feedback: 'Finanzierung gespeichert.', body: new URLSearchParams({ data: JSON.stringify(data) }) })
				element.remove(); render()
			} catch (error) { const box = form.querySelector('[data-funding-error]'); box.textContent = error.message; box.hidden = false }
		}
	}

	function showEndDialog(target) {
		const label = target === 'WIDERRUFEN' ? 'Widerruf' : 'Kündigung'
		const element = modal(`<form class="bp-modal-card"><div class="bp-panel-head"><div><p class="bp-eyebrow">Vorsorge beenden</p><h2>${label} eintragen</h2><p class="bp-muted">Die Akte wird abgeschlossen; Finanzierungen, Dokumente und Verlauf bleiben erhalten. Die Frist der Aufbewahrungsrichtlinie beginnt mit diesem Datum.</p></div></div>
			<div class="bp-form-grid bp-new-kind-grid"><label><span>Datum</span><input type="date" name="date" value="${new Date().toISOString().slice(0, 10)}" required></label><label class="bp-span-2"><span>Grund</span><textarea name="reason" rows="2" required minlength="3"></textarea></label></div>
			<div class="bp-actions"><button type="button" class="bp-secondary" data-close-modal>Abbrechen</button><button type="submit" class="bp-danger">${label} eintragen</button></div></form>`)
		const form = element.querySelector('form')
		form.onsubmit = async (event) => {
			event.preventDefault()
			const data = Object.fromEntries(new FormData(form).entries())
			try {
				const result = await api(caseUrl(state.currentCase.id, '/precare/end'), { method: 'POST', feedback: `${label} eingetragen.`, body: new URLSearchParams({ status: target, reason: data.reason, date: data.date }) })
				element.remove()
				;(result.warnings || []).forEach((warning) => notifyWarning(warning))
				await reloadCase(); render()
			} catch (error) { notifyError(error.message) }
		}
	}

	async function showRedeemDialog(precareId, deathCaseId = 0) {
		let preview
		try { preview = await api(`${caseUrl(precareId, '/precare/redeem')}${deathCaseId ? `?deathCaseId=${Number(deathCaseId)}` : ''}`, { feedback: false, loadingLabel: 'Übernahme wird vorbereitet …' }) } catch (error) { notifyError(error.message); return }
		const labels = ctx.labels || {}
		const into = preview.target
		const fieldRows = (preview.fields || []).map((field) => `<tr><td>${esc(labels[field.key] || field.key)}</td><td>${esc(field.value)}</td><td>${field.action === 'SET' ? 'wird übernommen' : field.action === 'SAME' ? 'gleich' : `bleibt: ${esc(field.current)}`}</td></tr>`).join('')
		const positionRows = (preview.positions || []).map((position) => `<tr class="${position.skipped ? 'is-cancelled' : ''}"><td>${esc(position.title)}${position.note ? `<small> · ${esc(position.note)}</small>` : ''}</td><td>${esc(String(position.quantity).replace('.', ','))}</td><td>${esc(money(position.contractCents))}</td><td>${esc(money(position.currentCents))}</td><td><b>${position.skipped ? 'nicht übernommen' : esc(money(position.appliedCents))}</b>${!position.skipped && position.currentCents !== null && position.currentCents !== position.contractCents ? `<small> (${position.appliedCents === position.contractCents ? 'Vertragspreis' : 'heutiger Preis'})</small>` : ''}</td></tr>`).join('')
		const fundingRows = (preview.fundings || []).map((funding) => `<li>${esc(funding.fundingTypeLabel)}${funding.providerName ? ` · ${esc(funding.providerName)}` : ''}${funding.contractNumber ? ` · ${esc(funding.contractNumber)}` : ''} · ${esc(funding.statusLabel)}${funding.notification ? ' → Sterbefallmeldung wird vorbereitet' : ''}</li>`).join('')
		const fileRows = (preview.files || []).map((file) => `<label class="bp-inline-check"><input type="checkbox" name="fileIds" value="${Number(file.fileId)}" ${file.selected ? 'checked' : ''}> ${esc(file.subfolder ? `${file.subfolder}/` : '')}${esc(file.name)}</label>`).join('')
		const client = preview.orderClient || {}
		const element = modal(`<form class="bp-modal-card bp-redeem-dialog"><div class="bp-panel-head"><div><p class="bp-eyebrow">Vorsorge ${esc(preview.precare.caseNumber)} einlösen</p><h2>${into ? `In Sterbefall ${esc(into.caseNumber)} übernehmen` : 'Sterbefall eintragen'}</h2><p class="bp-muted">${esc(preview.precare.name)}${preview.precare.dateOfBirth ? `, geboren ${esc(date(preview.precare.dateOfBirth))}` : ''} · ${esc(preview.precare.statusLabel)} · ${esc(preview.precare.priceModeLabel)}. Nichts wird ohne „Übernehmen“ verändert; die Vorsorge bleibt danach als eingelöster Vertrag mit Verweis erhalten.</p></div></div>
			${into ? '' : `<div class="bp-form-grid bp-new-kind-grid"><label><span>Sterbedatum</span><input type="date" name="dateOfDeath" required></label><label><span>Sterbezeit</span><input type="time" name="timeOfDeath"></label><label class="bp-span-2"><span>Sterbeort / Einrichtung</span><input name="placeOfDeath" maxlength="200"></label></div>`}
			<details ${into ? 'open' : ''}><summary>Stammdaten und Wünsche (${(preview.fields || []).length})</summary><div class="bp-table-scroll"><table><thead><tr><th>Feld</th><th>Aus der Vorsorge</th><th>${into ? 'Im Sterbefall' : ''}</th></tr></thead><tbody>${fieldRows || '<tr><td colspan="3" class="bp-empty">Keine Angaben.</td></tr>'}</tbody></table></div></details>
			<details open><summary>Positionen des Vorsorgevertrags (${(preview.positions || []).length})</summary>${preview.positionsBlocked ? '<p class="bp-warning">Im Sterbefall sind bereits Positionen erfasst. Die Positionen werden nicht automatisch übernommen.</p>' : ''}<div class="bp-table-scroll"><table><thead><tr><th>Leistung</th><th>Menge</th><th>Vertrag (netto)</th><th>Heute (netto)</th><th>Übernommen</th></tr></thead><tbody>${positionRows || '<tr><td colspan="5" class="bp-empty">Keine Positionen.</td></tr>'}</tbody></table></div><label class="bp-inline-check"><input type="checkbox" name="transferPositions" ${preview.positionsBlocked ? 'disabled' : 'checked'}> Positionen als Auftragsentwurf übernehmen</label></details>
			<details open><summary>Finanzierungen</summary>${fundingRows ? `<ul>${fundingRows}</ul>` : '<p class="bp-muted">Keine Finanzierung erfasst.</p>'}<label class="bp-inline-check"><input type="checkbox" name="createNotifications" checked> Sterbefallmeldungen unter „Abmeldungen“ vorbereiten</label></details>
			<details open><summary>Dokumente als Kopie in den Sterbefall</summary>${fileRows || '<p class="bp-muted">Keine Dokumente in der Vorsorge.</p>'}</details>
			<details><summary>Kontakte und Auftraggeber</summary><p>${client.order_client_name ? `Vorschlag Auftraggeber/in: <b>${esc(`${client.order_client_first_name || ''} ${client.order_client_name}`.trim())}</b>${client.order_client_relation ? ` (${esc(client.order_client_relation)})` : ''}` : 'Keine Vertrauensperson erfasst – Auftraggeber/in bitte im Sterbefall eintragen.'}</p>${(preview.contacts || []).length ? `<ul>${preview.contacts.map((contact) => `<li>${esc(contact.title)}${contact.role ? ` · ${esc(contact.role)}` : ''}</li>`).join('')}</ul><label class="bp-inline-check"><input type="checkbox" name="copyContacts" checked> Kontakte übernehmen</label>` : ''}${(preview.labels || []).length ? `<p>Marker: ${preview.labels.map((label) => esc(label.text)).join(', ')}</p>` : ''}</details>
			<div class="bp-actions"><button type="button" class="bp-secondary" data-close-modal>Abbrechen</button><button type="submit" class="bp-primary">Übernehmen</button></div></form>`)
		const form = element.querySelector('form')
		const creationToken = token()
		form.onsubmit = async (event) => {
			event.preventDefault()
			const data = new FormData(form)
			const payload = { deathCaseId: Number(deathCaseId || 0), dateOfDeath: String(data.get('dateOfDeath') || ''), timeOfDeath: String(data.get('timeOfDeath') || ''), placeOfDeath: String(data.get('placeOfDeath') || ''),
				transferPositions: Boolean(form.elements.transferPositions?.checked), createNotifications: Boolean(form.elements.createNotifications?.checked), copyContacts: form.elements.copyContacts ? Boolean(form.elements.copyContacts.checked) : true,
				fileIds: data.getAll('fileIds').map(Number), creationToken }
			try {
				const result = await api(caseUrl(precareId, '/precare/redeem'), { method: 'POST', feedback: false, loadingLabel: 'Vorsorge wird übernommen …', body: new URLSearchParams({ data: JSON.stringify(payload) }) })
				element.remove()
				notifySuccess(`Vorsorge eingelöst: Sterbefall ${result.case?.caseNumber || ''}.`)
				;(result.warnings || []).forEach((warning) => notifyWarning(warning))
				if (ctx.openCaseById) await ctx.openCaseById(result.case.id)
			} catch (error) { notifyError(error.message) }
		}
	}

	async function showContinueDialog(orderId) {
		let preview
		try { preview = await api(caseUrl(orderId, '/continue-as-death'), { feedback: false, loadingLabel: 'Weiterführung wird vorbereitet …' }) } catch (error) { notifyError(error.message); return }
		const person = preview.person || {}
		const positions = (preview.positions || []).map((position) => `<li>${esc(position.title)} · ${esc(String(position.quantity).replace('.', ','))} × ${esc(money(position.appliedCents))}${position.note ? ` · ${esc(position.note)}` : ''}</li>`).join('')
		const element = modal(`<form class="bp-modal-card"><div class="bp-panel-head"><div><p class="bp-eyebrow">Auftrag ${esc(preview.order.caseNumber)} · ${esc(preview.order.orderTypeLabel || preview.order.orderType)}</p><h2>Als Sterbefall weiterführen</h2><p class="bp-muted">Es entsteht ein neuer, verknüpfter Sterbefall mit eigener Fallnummer. Auftraggeber, Angaben und offene Positionen (zu den vereinbarten Preisen) werden übernommen; der Auftrag behält seine Nummer.</p></div></div>
			<div class="bp-form-grid bp-new-kind-grid"><label><span>Vorname der verstorbenen Person</span><input name="firstName" value="${esc(preview.requiresDeceased ? person.firstName : '')}" ${preview.requiresDeceased ? '' : 'required'}></label><label><span>Nachname der verstorbenen Person</span><input name="lastName" value="${esc(preview.requiresDeceased ? person.lastName : '')}" ${preview.requiresDeceased ? '' : 'required'}></label><label><span>Sterbedatum</span><input type="date" name="dateOfDeath" value="${esc(person.dateOfDeath || '')}"></label>
			<label><span>Dieser Auftrag danach</span><select name="closeOrder"><option value="ABGESCHLOSSEN">abschließen</option><option value="STORNIERT">stornieren</option><option value="">offen lassen</option></select></label></div>
			<details open><summary>Offene Positionen (${(preview.positions || []).length})</summary>${positions ? `<ul>${positions}</ul>` : '<p class="bp-muted">Keine offenen Positionen.</p>'}<label class="bp-inline-check"><input type="checkbox" name="transferPositions" checked> Positionen übernehmen</label></details>
			<div class="bp-actions"><button type="button" class="bp-secondary" data-close-modal>Abbrechen</button><button type="submit" class="bp-primary">Sterbefall anlegen</button></div></form>`)
		const form = element.querySelector('form')
		const creationToken = token()
		form.onsubmit = async (event) => {
			event.preventDefault()
			const data = Object.fromEntries(new FormData(form).entries())
			const payload = { firstName: data.firstName || '', lastName: data.lastName || '', dateOfDeath: data.dateOfDeath || '', closeOrder: data.closeOrder || '', transferPositions: Boolean(form.elements.transferPositions.checked), creationToken }
			try {
				const result = await api(caseUrl(orderId, '/continue-as-death'), { method: 'POST', feedback: false, loadingLabel: 'Sterbefall wird angelegt …', body: new URLSearchParams({ data: JSON.stringify(payload) }) })
				element.remove()
				notifySuccess(`Sterbefall ${result.case?.caseNumber || ''} angelegt.`)
				;(result.warnings || []).forEach((warning) => notifyWarning(warning))
				if (ctx.openCaseById) await ctx.openCaseById(result.case.id)
			} catch (error) { notifyError(error.message) }
		}
	}

	// ---------------------------------------------------------------------------------------------- Bindung

	function bind() {
		const root = ctx.root
		root.querySelectorAll('[data-open-case]').forEach((button) => button.addEventListener('click', async (event) => { event.stopPropagation(); if (ctx.openCaseById) await ctx.openCaseById(Number(button.dataset.openCase)) }))
		root.querySelectorAll('[data-precare-redeem]').forEach((button) => button.addEventListener('click', () => showRedeemDialog(Number(button.dataset.precareRedeem), Number(button.dataset.redeemInto || 0))))
		root.querySelectorAll('[data-continue-as-death]').forEach((button) => button.addEventListener('click', () => showContinueDialog(Number(button.dataset.continueAsDeath))))
		root.querySelectorAll('[data-precare-end]').forEach((button) => button.addEventListener('click', () => showEndDialog(button.dataset.precareEnd)))
		root.querySelectorAll('[data-precare-wishes]').forEach((button) => button.addEventListener('click', () => { state.caseTab = 'master'; state.masterTab = 'Wünsche'; render() }))
		root.querySelectorAll('[data-precare-review]').forEach((button) => button.addEventListener('click', async () => {
			try { state.precare = await api(caseUrl(state.currentCase.id, '/precare/review'), { method: 'POST', feedback: 'Wiedervorlage erledigt – nächster Termin eingetragen.' }); render() } catch (error) { notifyError(error.message) }
		}))
		root.querySelector('[data-precare-save]')?.addEventListener('click', async () => {
			const form = root.querySelector('[data-precare-form]'); const status = root.querySelector('[data-precare-state]')
			const data = Object.fromEntries(new FormData(form).entries())
			try { state.precare = await api(caseUrl(state.currentCase.id, '/precare'), { method: 'PUT', feedback: 'Vorsorge gespeichert.', body: new URLSearchParams({ data: JSON.stringify(data) }) }); render() } catch (error) { if (status) { status.textContent = error.message; status.dataset.state = 'error' } }
		})
		root.querySelector('[data-funding-add]')?.addEventListener('click', () => showFundingDialog())
		root.querySelectorAll('[data-funding-edit]').forEach((button) => button.addEventListener('click', () => showFundingDialog((state.precare?.fundings || []).find((funding) => Number(funding.id) === Number(button.dataset.fundingEdit)))))
		root.querySelectorAll('[data-funding-delete]').forEach((button) => button.addEventListener('click', async () => {
			if (!await ctx.confirmAction('Finanzierung entfernen?', { title: 'Finanzierung entfernen', confirmLabel: 'Entfernen', danger: true })) return
			try { state.precare = await api(caseUrl(state.currentCase.id, `/precare/fundings/${Number(button.dataset.fundingDelete)}`), { method: 'DELETE', feedback: 'Finanzierung entfernt.' }); render() } catch (error) { notifyError(error.message) }
		}))
		root.querySelectorAll('[data-funding-paid]').forEach((button) => button.addEventListener('click', async () => {
			try { state.caseRelations = await api(caseUrl(state.currentCase.id, `/relations/fundings/${Number(button.dataset.fundingPaid)}/paid`), { method: 'POST', feedback: 'Als ausgezahlt markiert.' }); render() } catch (error) { notifyError(error.message) }
		}))
	}

	return { loadOrderTypes, loadCaseKindData, extraTabs, tabBody, caseKindOverview, precareReviewsPanel, bindCaseKinds: bind, showRedeemDialog, showContinueDialog }
}
