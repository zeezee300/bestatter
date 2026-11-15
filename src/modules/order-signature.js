/* global OC */
// 0.89.0: Unterschrift des Bestattungsauftrags vor Ort am Tablet
// - die Mitarbeiterin startet die Ansicht im Auftrag („Am Tablet unterschreiben lassen“); ein noch nicht festgeschriebener
//   Auftrag wird mit der Unterschrift verbindlich (Prüfung und Hinweise vorher wie beim Festschreiben)
// - der Kunde sieht den Auftrag (PDF und Übersicht), kreuzt die eigenen Erklärungen zum Leistungsbeginn selbst an (nicht
//   vorbelegt), bestätigt das Lesen, unterschreibt mit Finger oder Stift und erhält auf Wunsch eine Ausfertigung per E-Mail
// - „Verbindlich unterschreiben“ erzeugt die unterschriebene PDF (Unterschriftsbild, Ort, Datum, Uhrzeit, Prüfwert)

import { orderDocumentNumber } from './case-kinds.js'

/** Zeichenfläche für die Unterschrift (Maus, Finger, Stift). Striche bleiben als Punkte erhalten (Neuzeichnen, Export). */
export function createSignaturePad(canvas, onChange = () => {}) {
	const strokes = []
	let current = null
	const color = '#0c2d3e'
	const width = 2.6
	const draw = (target, scale = 1, offsetX = 0, offsetY = 0) => {
		target.lineCap = 'round'; target.lineJoin = 'round'; target.strokeStyle = color; target.fillStyle = color; target.lineWidth = width * scale
		for (const stroke of strokes) {
			if (stroke.length === 1) { target.beginPath(); target.arc((stroke[0].x - offsetX) * scale, (stroke[0].y - offsetY) * scale, (width * scale) / 2, 0, Math.PI * 2); target.fill(); continue }
			target.beginPath(); target.moveTo((stroke[0].x - offsetX) * scale, (stroke[0].y - offsetY) * scale)
			for (const point of stroke.slice(1)) target.lineTo((point.x - offsetX) * scale, (point.y - offsetY) * scale)
			target.stroke()
		}
	}
	const resize = () => {
		const rect = canvas.getBoundingClientRect()
		const ratio = window.devicePixelRatio || 1
		canvas.width = Math.max(1, Math.round(rect.width * ratio)); canvas.height = Math.max(1, Math.round(rect.height * ratio))
		const context = canvas.getContext('2d')
		context.setTransform(ratio, 0, 0, ratio, 0, 0)
		context.clearRect(0, 0, rect.width, rect.height)
		draw(context)
	}
	const point = (event) => { const rect = canvas.getBoundingClientRect(); return { x: event.clientX - rect.left, y: event.clientY - rect.top } }
	const segment = (from, to) => { const context = canvas.getContext('2d'); context.lineCap = 'round'; context.strokeStyle = color; context.lineWidth = width; context.beginPath(); context.moveTo(from.x, from.y); context.lineTo(to.x, to.y); context.stroke() }
	canvas.addEventListener('pointerdown', (event) => {
		if (event.button !== undefined && event.button > 0) return
		event.preventDefault()
		try { canvas.setPointerCapture(event.pointerId) } catch { /* ältere Browser */ }
		current = [point(event)]; strokes.push(current)
		const start = current[0]; segment(start, { x: start.x + 0.1, y: start.y + 0.1 })
	})
	canvas.addEventListener('pointermove', (event) => {
		if (!current) return
		event.preventDefault()
		const events = typeof event.getCoalescedEvents === 'function' ? event.getCoalescedEvents() : [event]
		for (const item of (events.length ? events : [event])) { const next = point(item); segment(current[current.length - 1], next); current.push(next) }
	})
	const end = () => { if (!current) return; current = null; onChange(api.state()) }
	canvas.addEventListener('pointerup', end); canvas.addEventListener('pointercancel', end); canvas.addEventListener('pointerleave', end)
	const bounds = () => {
		const points = strokes.flat()
		if (!points.length) return null
		const xs = points.map((item) => item.x), ys = points.map((item) => item.y)
		return { left: Math.min(...xs), top: Math.min(...ys), right: Math.max(...xs), bottom: Math.max(...ys) }
	}
	const api = {
		resize,
		clear() { strokes.length = 0; current = null; resize(); onChange(api.state()) },
		/** Genug „Tinte“ für eine Unterschrift? (Länge der Striche und Breite) */
		state() {
			let length = 0
			for (const stroke of strokes) for (let index = 1; index < stroke.length; index++) length += Math.hypot(stroke[index].x - stroke[index - 1].x, stroke[index].y - stroke[index - 1].y)
			const box = bounds()
			return { strokes: strokes.length, length: Math.round(length), width: box ? Math.round(box.right - box.left) : 0, enough: length >= 60 && !!box && box.right - box.left >= 40 }
		},
		/** Zugeschnittene PNG (transparenter Hintergrund), höchstens 900 px breit. */
		toPng() {
			const box = bounds()
			if (!box) return ''
			const pad = 8
			const sourceWidth = box.right - box.left + pad * 2, sourceHeight = box.bottom - box.top + pad * 2
			const scale = Math.min(2, 900 / sourceWidth, 400 / sourceHeight)
			const output = document.createElement('canvas')
			output.width = Math.max(40, Math.round(sourceWidth * scale)); output.height = Math.max(15, Math.round(sourceHeight * scale))
			draw(output.getContext('2d'), scale, box.left - pad, box.top - pad)
			return output.toDataURL('image/png')
		},
	}
	resize()
	return api
}

export function createOrderSignature(ctx) {
	const { root, state } = ctx
	const esc = (...args) => ctx.esc(...args)
	const money = (cents) => (Number(cents || 0) / 100).toLocaleString('de-DE', { style: 'currency', currency: 'EUR' })
	const statusUrl = () => `${ctx.apiBase}/cases/${state.currentCase.id}/order-signature`
	const uuid = () => (crypto.randomUUID ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (char) => { const random = (crypto.getRandomValues(new Uint8Array(1))[0] & 15); return (char === 'x' ? random : (random & 3) | 8).toString(16) }))

	/** Mitarbeiterseite: speichern, Voraussetzungen prüfen, ggf. Festschreibungsprüfung bestätigen, dann Kundenansicht. */
	async function open() {
		try {
			const form = document.getElementById('order-form')
			if (form?._saveOrder && !await form._saveOrder()) return false
			await ctx.saveCaseServices()
			const status = await ctx.api(statusUrl(), { feedback: false })
			if (!status.ready) { ctx.notifyWarning(`Die Unterschrift am Tablet ist noch nicht möglich:\n${status.problems.map((problem) => `• ${problem}`).join('\n')}`); return false }
			let confirmedWarningCodes = []
			if (!status.orderFrozen) {
				const master = state.currentCase.masterData || {}
				const data = { number: master.order_number || orderDocumentNumber(state.currentCase.caseNumber), date: master.order_date || new Date().toISOString().slice(0, 10), commissioningType: master.commissioning_type || '' }
				const check = await ctx.api(`${ctx.commercialUrl(state.currentCase.id)}/finalization-check?documentType=ORDER`, { method: 'POST', feedback: false, body: new URLSearchParams({ data: JSON.stringify(data) }) })
				if ((check.errors || []).length) { ctx.notifyError(`Der Auftrag kann noch nicht unterschrieben werden:\n${check.errors.map((item) => `• ${item.label}: ${item.message}`).join('\n')}`); return false }
				const warnings = (check.warnings || []).filter((item) => item.code !== 'NO_SIGNATURE')
				confirmedWarningCodes = warnings.map((item) => item.code)
				const hints = warnings.length ? `\n\nBitte vorher noch prüfen:\n${warnings.map((item) => `• ${item.label}: ${item.message}`).join('\n')}` : ''
				if (!await ctx.confirmAction(`Mit der Unterschrift wird der Auftrag verbindlich festgeschrieben; Positionen und Preise sind danach geschützt.${hints}\n\nJetzt das Tablet an den Auftraggeber übergeben?`, { title: 'Am Tablet unterschreiben lassen', confirmLabel: 'Weiter zur Unterschrift' })) return false
			}
			show(status, confirmedWarningCodes)
			return true
		} catch (error) { ctx.notifyError(error.message); return false }
	}

	function documentSummary(status) {
		// 0.89.5: Schätzbeträge „ca.“, Gebühren ohne Betrag „nach Bescheid“ (wie im Dokument)
		const amount = (item) => item.marker === 'NOTICE' ? 'nach Bescheid' : `${item.marker === 'ESTIMATE' ? 'ca. ' : ''}${money(item.grossCents)}`
		const rows = (status.services || []).map((item) => `<li><span>${esc(item.title)}${item.package ? ` <small>aus ${esc(item.package)}</small>` : ''}</span><b>${esc(amount(item))}</b></li>`).join('')
		// 0.89.3: Abschnitte aus Customizing → Ankreuzfelder (in der Reihenfolge der Felder)
		const sections = [...new Set((status.selections || []).map((item) => item.sectionLabel || item.group))]
		const selections = sections.map((label) => { const items = (status.selections || []).filter((item) => (item.sectionLabel || item.group) === label); return `<div><h4>${esc(label)}</h4><ul class="bp-sign-checks">${items.map((item) => `<li><span aria-hidden="true">${item.checked ? '☒' : '☐'}</span> ${esc(item.label)}</li>`).join('')}</ul></div>` }).join('')
		return `<section class="bp-sign-summary" aria-label="Übersicht"><h3>Das Wichtigste in Kürze</h3><ul class="bp-sign-services">${rows || '<li class="bp-empty">Keine Positionen.</li>'}</ul><p class="bp-sign-total"><span>${status.totalOpen ? 'Voraussichtliche Gesamtsumme' : 'Gesamtsumme'}</span><b>${esc(money(status.totalGrossCents))}${status.totalNotice ? ' <small>zzgl. Gebühren nach Bescheid</small>' : ''}</b></p>${status.priceTerms ? `<p class="bp-sign-terms">${esc(status.priceTerms)}</p>` : ''}${selections}</section>`
	}

	/** 0.89.3: Felder, die der Auftraggeber selbst ankreuzt (Customizing → Ankreuzfelder, „Kunde bei der Unterschrift“). */
	function customerFieldsView(status) {
		const fields = status.customerFields || []
		const sections = [...new Set(fields.map((item) => item.sectionLabel))]
		return sections.map((label) => `<fieldset class="bp-sign-declarations" data-sign-customer-section><legend>${esc(label)}</legend><p class="bp-muted">Bitte nur ankreuzen, was zutrifft.</p>${fields.filter((item) => item.sectionLabel === label).map((item) => `<label class="bp-sign-check"><input type="checkbox" data-customer-choice="${esc(item.field)}"> <span>${esc(item.label)}</span></label>`).join('')}</fieldset>`).join('')
	}

	function show(status, confirmedWarningCodes) {
		const withdrawal = status.withdrawalApplies
		const mailOption = status.mailAvailable ? `<label class="bp-inline-check bp-sign-mail"><input type="checkbox" id="sign-send-copy" ${status.email ? 'checked' : ''}> <span>Ausfertigung per E-Mail an</span></label><input type="email" id="sign-email" value="${esc(status.email || '')}" placeholder="E-Mail-Adresse" autocomplete="email" aria-label="E-Mail-Adresse für die Ausfertigung">` : '<p class="bp-muted">Ihre Ausfertigung erhalten Sie ausgedruckt oder nachträglich per E-Mail.</p>'
		root.insertAdjacentHTML('beforeend', `<div class="bp-sign-view" role="dialog" aria-modal="true" aria-labelledby="sign-title" data-order-signature>
			<header class="bp-sign-head"><div><p class="bp-eyebrow">${esc(status.orderNumber)} · zur Unterschrift</p><h2 id="sign-title">${esc(status.documentTitle || 'Bestattungsauftrag und Vollmacht')}</h2></div><button type="button" class="bp-secondary" data-sign-cancel>Abbrechen</button></header>
			<div class="bp-sign-body">
				<section class="bp-sign-document"><div class="bp-sign-pdf" aria-busy="true"><p class="bp-empty" data-sign-pdf-state>Dokument wird geladen …</p><iframe title="${esc(status.documentTitle || 'Bestattungsauftrag und Vollmacht')}" data-sign-pdf hidden></iframe></div><button type="button" class="bp-secondary" data-sign-open-pdf disabled>Dokument groß öffnen</button>${documentSummary(status)}</section>
				<form class="bp-sign-form" data-sign-form novalidate>
					${withdrawal ? `<fieldset class="bp-sign-declarations"><legend>Ihre Erklärungen zum Beginn der Leistungen</legend><p class="bp-muted">Sie haben ein gesetzliches Widerrufsrecht; die Widerrufsbelehrung erhalten Sie mit dem Auftrag. Die folgenden Erklärungen sind freiwillig – bitte nur ankreuzen, was zutrifft.</p>
						<label class="bp-sign-check"><input type="checkbox" name="withdrawalRequest"> <span>${esc(status.withdrawalTexts?.request || '')}</span></label>
						<label class="bp-sign-check"><input type="checkbox" name="withdrawalAck"> <span>${esc(status.withdrawalTexts?.ack || '')}</span></label></fieldset>` : ''}
					${customerFieldsView(status)}
					<label class="bp-sign-check bp-sign-read"><input type="checkbox" name="readConfirmed" required> <span>Ich habe den Auftrag mit Leistungs- und Preisaufstellung und die Vollmacht gelesen und erteile den Auftrag.</span></label>
					<div class="bp-sign-fields"><label><span>Name</span><input name="signerName" value="${esc(status.signerName || '')}" autocomplete="name" required></label><label><span>Ort</span><input name="place" value="${esc(status.place || '')}" required></label></div>
					<div class="bp-sign-pad-wrap"><div class="bp-sign-pad-head"><span>Bitte hier mit Finger oder Stift unterschreiben</span><button type="button" class="bp-link-button" data-sign-clear>Löschen</button></div><canvas class="bp-sign-pad" data-sign-pad aria-label="Unterschriftsfeld"></canvas><div class="bp-sign-line" aria-hidden="true"></div></div>
					${mailOption}
					<p class="bp-muted bp-sign-missing" data-sign-missing aria-live="polite" hidden></p>
					<p class="bp-save-state" data-sign-state aria-live="polite"></p>
					<div class="bp-sign-actions"><button type="submit" class="bp-primary" data-sign-submit disabled>Verbindlich unterschreiben</button></div>
					<p class="bp-muted bp-sign-legal">Ihre Unterschrift wird mit Datum und Uhrzeit in den Auftrag übernommen (elektronische Unterschrift). Angenommen von ${esc(status.staffName || 'dem Bestattungsunternehmen')}.</p>
				</form>
			</div></div>`)
		const view = [...root.querySelectorAll('[data-order-signature]')].pop()
		const form = view.querySelector('[data-sign-form]')
		const submit = view.querySelector('[data-sign-submit]')
		const message = view.querySelector('[data-sign-state]')
		const frame = view.querySelector('[data-sign-pdf]')
		const openPdf = view.querySelector('[data-sign-open-pdf]')
		let pdfUrl = ''
		let busy = false
		const update = () => {
			const ink = pad.state()
			view.dataset.signatureInk = ink.enough ? 'ok' : 'none'
			const sendCopy = view.querySelector('#sign-send-copy')?.checked
			// 0.89.3: sagen, was noch fehlt (statt eines stumm gesperrten Knopfs)
			const missing = [
				!form.elements.readConfirmed.checked ? 'Bestätigung, dass der Auftrag gelesen wurde' : '',
				!form.elements.signerName.value.trim() ? 'Name' : '', !form.elements.place.value.trim() ? 'Ort' : '',
				!ink.enough ? 'Unterschrift im Feld' : '', sendCopy && !view.querySelector('#sign-email').checkValidity() ? 'gültige E-Mail-Adresse' : '',
			].filter(Boolean)
			submit.disabled = busy || missing.length > 0
			const hint = view.querySelector('[data-sign-missing]')
			if (hint) { hint.textContent = missing.length && !busy ? `Noch offen: ${missing.join(', ')}.` : ''; hint.hidden = !missing.length || busy }
		}
		const pad = createSignaturePad(view.querySelector('[data-sign-pad]'), update)
		const onResize = () => pad.resize()
		window.addEventListener('resize', onResize)
		document.documentElement.classList.add('bp-signing')
		const close = async (signed) => {
			window.removeEventListener('resize', onResize)
			document.documentElement.classList.remove('bp-signing')
			if (pdfUrl) URL.revokeObjectURL(pdfUrl)
			view.remove()
			if (signed) {
				state.currentCase = await ctx.api(`${ctx.urls.cases}/${state.currentCase.id}`, { feedback: false })
				await Promise.all([ctx.loadCommercial(), ctx.loadRecordType('document', state.currentCase.id), ctx.loadCaseFiles?.(state.currentCase.id)])
				ctx.render()
			}
		}
		view.querySelector('[data-sign-cancel]').onclick = async () => { if (await ctx.confirmAction('Unterschrift abbrechen? Es wird nichts gespeichert.', { title: 'Abbrechen', confirmLabel: 'Abbrechen', cancelLabel: 'Weiter unterschreiben' })) close(false) }
		view.querySelector('[data-sign-clear]').onclick = () => pad.clear()
		form.addEventListener('input', update); form.addEventListener('change', update)
		view.querySelector('#sign-email')?.addEventListener('input', update)
		openPdf.onclick = () => { if (pdfUrl) window.open(pdfUrl, '_blank', 'noopener') }
		form.onsubmit = async (event) => {
			event.preventDefault()
			if (submit.disabled) return
			busy = true; update()
			message.textContent = 'Unterschriebener Auftrag wird erstellt …'; message.dataset.state = 'saving'
			const sendCopy = !!view.querySelector('#sign-send-copy')?.checked
			const data = {
				signerName: form.elements.signerName.value, place: form.elements.place.value, readConfirmed: form.elements.readConfirmed.checked,
				withdrawalRequest: !!form.elements.withdrawalRequest?.checked, withdrawalAck: !!form.elements.withdrawalAck?.checked,
				customerChoices: Object.fromEntries([...form.querySelectorAll('[data-customer-choice]')].map((box) => [box.dataset.customerChoice, box.checked])),
				signature: pad.toPng(), sendCopy, email: sendCopy ? view.querySelector('#sign-email').value : '', requestKey: sendCopy ? uuid() : '',
				confirmedWarningCodes, expectedUpdatedAt: state.currentCase.updatedAt || '',
			}
			try {
				const result = await ctx.api(statusUrl(), { method: 'POST', feedback: false, loadingLabel: 'Unterschriebener Auftrag wird erstellt …', body: new URLSearchParams({ data: JSON.stringify(data) }) })
				done(result, data)
			} catch (error) {
				busy = false; update()
				message.textContent = error.message; message.dataset.state = 'error'
			}
		}
		const done = (result, data) => {
			const fileId = Number(result.pdf?.fileId || result.record?.data?.pdf?.fileId || 0)
			const mailText = result.mail ? `Eine Ausfertigung wurde an ${esc(data.email)} gesendet.` : result.mailWarning ? esc(result.mailWarning) : 'Ihre Ausfertigung erhalten Sie ausgedruckt oder per E-Mail.'
			view.querySelector('.bp-sign-body').innerHTML = `<section class="bp-sign-done" data-sign-done><h3>Vielen Dank, ${esc(data.signerName)}.</h3><p>Der Auftrag ist unterschrieben.</p><p class="${result.mailWarning ? 'bp-warning-text' : 'bp-muted'}">${mailText}</p><div class="bp-sign-actions">${fileId ? '<button type="button" class="bp-secondary" data-sign-show-signed>Unterschriebenen Auftrag ansehen</button>' : ''}<button type="button" class="bp-primary" data-sign-finish>Zurück an das Bestattungsunternehmen</button></div></section>`
			view.querySelector('[data-sign-cancel]').hidden = true
			view.querySelector('[data-sign-finish]').onclick = () => close(true)
			view.querySelector('[data-sign-show-signed]')?.addEventListener('click', async () => {
				try {
					const response = await fetch(`${ctx.apiBase}/cases/${state.currentCase.id}/files/${fileId}/content.pdf`, { credentials: 'same-origin', headers: { requesttoken: OC.requestToken } })
					if (!response.ok) throw new Error('Die unterschriebene PDF konnte nicht geladen werden.')
					window.open(URL.createObjectURL(await response.blob()), '_blank', 'noopener')
				} catch (error) { ctx.notifyError(error.message) }
			})
			if (result.mailWarning) ctx.notifyWarning(result.mailWarning)
			ctx.notifySuccess('Der Auftrag wurde am Tablet unterschrieben und als PDF in der Fallakte abgelegt.')
		}
		update()
		// Dokument (ohne Nebenwirkung) laden; die Übersicht steht schon darunter.
		;(async () => {
			const state_ = view.querySelector('[data-sign-pdf-state]')
			try {
				const response = await fetch(`${ctx.apiBase}/cases/${state.currentCase.id}/order-documents/BESTATTUNGSAUFTRAG/preview.pdf`, { credentials: 'same-origin', headers: { requesttoken: OC.requestToken } })
				if (!response.ok) throw new Error('Das Dokument konnte nicht angezeigt werden.')
				const blob = await response.blob()
				if (!view.isConnected) return
				pdfUrl = URL.createObjectURL(blob)
				frame.src = pdfUrl; frame.hidden = false; state_.remove(); openPdf.disabled = false
				view.querySelector('.bp-sign-pdf').setAttribute('aria-busy', 'false')
			} catch (error) {
				if (state_) state_.textContent = `${error.message} Die Übersicht darunter zeigt die wesentlichen Inhalte.`
			}
		})()
		window.requestAnimationFrame(() => pad.resize())
	}

	return { open }
}
