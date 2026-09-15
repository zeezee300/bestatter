export function createPaperlessModule(ctx) {
	const { root, state, esc, api, notifySuccess, notifyError } = ctx
	const base = `${ctx.apiBase}/integrations/paperless`

	async function loadPaperlessInbox() {
		state.paperlessInbox = await api(`${base}/inbox`, { feedback: false, loadingLabel: 'Paperless-Belegeingang wird geladen …' })
		return state.paperlessInbox
	}

	async function loadPaperlessConfiguration() {
		state.paperlessConfiguration = await api(`${base}/configuration`, { feedback: false })
		return state.paperlessConfiguration
	}

	function paperlessInboxView() {
		const inbox = state.paperlessInbox || { enabled: false, mode: 'OFF', items: [], manualFallback: true }
		const rows = (inbox.items || []).map((item) => {
			const href = inbox.baseUrl && item.externalDocumentId ? `${String(inbox.baseUrl).replace(/\/$/, '')}/documents/${encodeURIComponent(item.externalDocumentId)}/details` : ''
			return `<article class="bp-paperless-card"><div><p class="bp-eyebrow">Paperless-Dokument ${esc(item.externalDocumentId || '–')}</p><h3>${esc(item.title || 'Unbenannter Eingangsbeleg')}</h3><p><span class="bp-status">${esc(item.syncStatus)}</span> · Eingang ${new Date(item.createdAt).toLocaleString('de-DE')}</p><small>Der Beleg ist noch keinem Sterbefall zugeordnet. Rechnungswerte werden in dieser Ausbaustufe bewusst manuell bestätigt.</small></div><div class="bp-row-actions">${href ? `<button type="button" class="bp-secondary open-paperless" data-url="${esc(href)}">In Paperless ansehen</button>` : ''}<button type="button" class="bp-primary assign-paperless" data-id="${item.id}">Fall zuordnen</button></div></article>`
		}).join('')
		return `<div class="bp-page-head"><div><p class="bp-eyebrow">Dokumenteingang</p><h2>Belegeingang</h2><p class="bp-muted">Neue Eingangsrechnungen aus Paperless kontrolliert einem Sterbefall zuordnen.</p></div><button type="button" class="bp-secondary" id="refresh-paperless-inbox">Aktualisieren</button></div><section class="bp-panel"><div class="bp-process-explainer"><b>${inbox.enabled && inbox.mode === 'FULL' ? 'Paperless-Eingang ist aktiv' : 'Paperless-Eingang ist nicht aktiv'}</b><span>Die Bestatter-App bleibt unabhängig: Belege können in jedem Fall direkt unter Fallakte → Finanzen erfasst werden.</span><small>Paperless unterstützt Eingang, OCR, Vorschau und Suche. Fallzuordnung, Prüfung und Abrechnung erfolgen ausschließlich hier.</small></div><div class="bp-paperless-list">${rows || '<p class="bp-empty">Keine unzugeordneten Paperless-Belege vorhanden.</p>'}</div></section>`
	}

	function showAssignment(item) {
		const cases = (state.cases || []).map((entry) => `<option value="${entry.id}">${esc(entry.caseNumber)} · ${esc(`${entry.firstName || ''} ${entry.lastName || ''}`.trim())}</option>`).join('')
		const today = new Date().toISOString().slice(0, 10)
		root.insertAdjacentHTML('beforeend', `<div class="bp-modal"><form id="paperless-assignment" class="bp-wide-modal"><div class="bp-panel-head"><div><p class="bp-eyebrow">Paperless-Dokument ${esc(item.externalDocumentId)}</p><h2>Beleg einem Fall zuordnen</h2><p class="bp-muted">Die Werte werden nicht automatisch gebucht. Nach dem Import öffnet die Fallakte den bekannten Prüfprozess.</p></div><button type="button" class="bp-secondary" data-close-paperless>Schließen</button></div><div class="bp-form-grid"><label class="bp-span-2"><span>Sterbefall</span><select name="caseId" required><option value="">Bitte auswählen</option>${cases}</select></label><label><span>Lieferant</span><input name="supplierName" required></label><label><span>Lieferanten-Rechnungsnummer</span><input name="supplierInvoiceNumber" required></label><label><span>Rechnungsdatum</span><input type="date" name="invoiceDate" value="${today}" required></label><label><span>Eingangsdatum</span><input type="date" name="receivedDate" value="${today}" required></label><label><span>Fällig am</span><input type="date" name="dueDate"></label><label><span>Bruttobetrag</span><input type="number" name="gross" min="0.01" step="0.01" required></label><label class="bp-span-2"><span>Erste Belegposition</span><input name="description" value="Eingangsrechnung ${esc(item.title || '')}" required><small>Weitere Positionen können anschließend im regulären Eingangsrechnungsdialog ergänzt werden.</small></label><label><span>Umsatzsteuer</span><select name="vatRate"><option value="19">19 %</option><option value="7">7 %</option><option value="0">0 %</option></select></label><label class="bp-span-2"><span>Prüfnotiz</span><textarea name="notes" rows="2">Importiert aus Paperless; alle Werte manuell bestätigt.</textarea></label></div><div class="bp-row-actions"><span id="paperless-assignment-state" class="bp-save-state" aria-live="polite"></span><button class="bp-primary">Als Entwurf übernehmen</button></div></form></div>`)
		const form = document.getElementById('paperless-assignment')
		form.querySelector('[data-close-paperless]').onclick = () => form.closest('.bp-modal').remove()
		form.onsubmit = async (event) => {
			event.preventDefault(); const values = Object.fromEntries(new FormData(form)); const status = document.getElementById('paperless-assignment-state')
			const grossCents = Math.round(Number(values.gross) * 100); const vatRate = Number(values.vatRate); const netCents = vatRate ? Math.round(grossCents / (1 + vatRate / 100)) : grossCents
			const data = { supplierName: values.supplierName, supplierInvoiceNumber: values.supplierInvoiceNumber, invoiceDate: values.invoiceDate, receivedDate: values.receivedDate, dueDate: values.dueDate, declaredGrossCents: grossCents, notes: values.notes, items: [{ description: values.description, supplierQuantityMilli: 1000, unit: 'STK', supplierUnitCents: netCents, supplierVatRate: vatRate, classification: 'CLASSIFICATION_PENDING', customerQuantityMilli: 1000, customerUnitCents: netCents, customerVatRate: vatRate }] }
			status.textContent = 'Original wird in den Nextcloud-Fallordner übernommen …'; status.dataset.state = 'saving'
			try {
				const result = await api(`${base}/inbox/${item.id}/assign`, { method: 'POST', feedback: false, body: new URLSearchParams({ caseId: values.caseId, data: JSON.stringify(data) }), loadingLabel: 'Paperless-Beleg wird übernommen …' })
				form.closest('.bp-modal').remove(); await loadPaperlessInbox(); state.currentCase = await api(`${ctx.urls.cases}/${values.caseId}`, { feedback: false }); state.currentCaseId = Number(values.caseId); state.view = 'case-detail'; state.caseTab = 'finances'; await ctx.loadCommercial(); ctx.render(); notifySuccess(result.nextStep || 'Beleg wurde als kontrollierbarer Entwurf übernommen.')
			} catch (error) { status.textContent = error.message; status.dataset.state = 'error' }
		}
	}

	function bindPaperlessInbox() {
		document.getElementById('refresh-paperless-inbox')?.addEventListener('click', async () => { await loadPaperlessInbox(); ctx.render() })
		root.querySelectorAll('.open-paperless').forEach((button) => button.addEventListener('click', () => window.open(button.dataset.url, '_blank', 'noopener')))
		root.querySelectorAll('.assign-paperless').forEach((button) => button.addEventListener('click', () => { const item=(state.paperlessInbox?.items||[]).find((entry)=>entry.id===Number(button.dataset.id)); if(item)showAssignment(item) }))
	}

	function paperlessSettingsView() {
		const s = { mode: 'OFF', baseUrl: '', tagIds: [], captureTagIds: [], ...(state.paperlessConfiguration || {}), ...(state.paperlessConfigurationDraft || {}) }
		return `<section class="bp-panel"><div class="bp-panel-head"><div><h3>Optionale Paperless-ngx-Anbindung</h3><p class="bp-muted">Paperless übernimmt optional Eingang, OCR, Volltext und Vorschau. Die Bestatter-App bleibt führend für Fall, Prüfung und Abrechnung.</p></div><span class="bp-status ${s.enabled ? 'ready' : ''}">${s.enabled ? 'Aktiv' : 'Nicht aktiv'}</span></div><form id="paperless-settings" class="bp-form-grid"><label><span>Betriebsart</span><select name="mode"><option value="OFF" ${s.mode==='OFF'?'selected':''}>Deaktiviert</option><option value="EXPORT" ${s.mode==='EXPORT'?'selected':''}>Nur Kopie an Paperless</option><option value="FULL" ${s.mode==='FULL'?'selected':''}>Beidseitiger Belegeingang</option></select></label><label><span>Paperless-Basisadresse</span><input type="url" name="baseUrl" value="${esc(s.baseUrl || '')}" placeholder="https://paperless.example.de"></label><label><span>API-Token</span><input type="password" name="apiToken" autocomplete="new-password" placeholder="${s.tokenConfigured ? 'Gespeichert – leer lassen zum Beibehalten' : 'Token des technischen Benutzers'}"></label><label class="bp-inline-check"><input type="checkbox" name="clearApiToken" ${s.clearApiToken ? 'checked' : ''}> Gespeicherten API-Token entfernen</label><label><span>Dokumenttyp-ID: Eingangsrechnung</span><input type="number" min="0" name="documentTypeId" value="${Number(s.documentTypeId || 0)}"><small>0 lässt die Zuordnung Paperless überlassen.</small></label><label><span>Tag-IDs: Eingangsrechnung</span><input name="tagIds" value="${esc(Array.isArray(s.tagIds) ? s.tagIds.join(', ') : (s.tagIds || ''))}" placeholder="12, 18"><small>IDs durch Komma trennen.</small></label><label><span>Dokumenttyp-ID: Auftragserfassungsbogen</span><input type="number" min="0" name="captureDocumentTypeId" value="${Number(s.captureDocumentTypeId || 0)}"><small>Eigener Dokumenttyp verhindert eine Vermischung mit Rechnungen.</small></label><label><span>Tag-IDs: Auftragserfassungsbogen</span><input name="captureTagIds" value="${esc(Array.isArray(s.captureTagIds) ? s.captureTagIds.join(', ') : (s.captureTagIds || ''))}" placeholder="21, 22"><small>Empfohlen: eigener Tag „Bestatter-Auftragserfassung“.</small></label><label class="bp-span-2"><span>Webhook-Geheimnis</span><input type="password" name="webhookSecret" autocomplete="new-password" placeholder="${s.webhookSecretConfigured ? 'Gespeichert – leer lassen zum Beibehalten' : 'Wird bei Vollbetrieb automatisch erzeugt'}"></label><div class="bp-span-2 bp-compliance-note"><b>Webhook in Paperless</b><span>URL: <code>${esc(s.webhookUrl || '')}</code></span><span>Header: <code>X-Bestatter-Webhook-Token</code></span><span>JSON: <code>{"event":"document_added","document_id":"{{doc_id}}","title":"{{doc_title}}"}</code></span><small>Das Geheimnis wird nur beim erstmaligen Erzeugen angezeigt. OCR-Daten aus Erfassungsbögen werden ausschließlich als bestätigungspflichtige Vorschläge angezeigt.</small></div><div id="paperless-settings-message" class="bp-span-2 bp-save-state" aria-live="polite">${state.paperlessConfigurationDraft ? 'Ungespeicherte Eingaben sind vorgemerkt.' : ''}</div><div class="bp-span-2 bp-row-actions"><button type="button" class="bp-secondary" id="test-paperless">Verbindung testen</button><button class="bp-primary">Einstellungen speichern</button></div></form></section>`
	}

	function bindPaperlessSettings() {
		const form = document.getElementById('paperless-settings'); if (!form) return
		const message = document.getElementById('paperless-settings-message')
		const rememberDraft = () => {
			form.classList.add('is-dirty')
			state.paperlessConfigurationDraft = {
				mode: form.elements.mode.value,
				baseUrl: form.elements.baseUrl.value,
				documentTypeId: form.elements.documentTypeId.value,
				tagIds: form.elements.tagIds.value,
				captureDocumentTypeId: form.elements.captureDocumentTypeId.value,
				captureTagIds: form.elements.captureTagIds.value,
				clearApiToken: form.elements.clearApiToken.checked,
			}
			message.textContent = 'Ungespeicherte Eingaben – bitte speichern.'
			message.dataset.state = 'warning'
		}
		form.querySelectorAll('input,select').forEach((field) => {
			field.addEventListener('input', rememberDraft)
			field.addEventListener('change', rememberDraft)
		})
		form.onsubmit = async (event) => { event.preventDefault(); const values=Object.fromEntries(new FormData(form)); values.clearApiToken=form.elements.clearApiToken.checked; message.textContent='Speichert …'; message.dataset.state='saving'; try { state.paperlessConfiguration=await api(`${base}/configuration`,{method:'PUT',feedback:false,body:new URLSearchParams({configuration:JSON.stringify(values)})}); state.paperlessConfigurationDraft=null; form.classList.remove('is-dirty'); const generated=state.paperlessConfiguration.generatedWebhookSecret; message.textContent=generated?`Gespeichert. Webhook-Geheimnis jetzt sicher kopieren: ${generated}`:'Gespeichert.';message.dataset.state='saved';notifySuccess('Paperless-Konfiguration wurde gespeichert.'); } catch(error){message.textContent=error.message;message.dataset.state='error'} }
		document.getElementById('test-paperless')?.addEventListener('click',async()=>{message.textContent='Verbindung wird geprüft …';message.dataset.state='saving';try{const result=await api(`${base}/test`,{method:'POST',feedback:false});if(result.status==='OK'){message.textContent=`${result.apiResponse} (${result.durationMs} ms)`;message.dataset.state='saved'}else{message.textContent=`${result.message} ${result.recommendation||''}`.trim();message.dataset.state='error';notifyError(message.textContent)}}catch(error){message.textContent=error.message;message.dataset.state='error';notifyError(error.message)}})
	}

	return { loadPaperlessInbox, loadPaperlessConfiguration, paperlessInboxView, bindPaperlessInbox, paperlessSettingsView, bindPaperlessSettings }
}
