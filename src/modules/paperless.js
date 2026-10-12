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

	const KIND_SOURCE = { PAPERLESS_TYPE: 'aus dem Paperless-Dokumenttyp', DEFAULT: 'Standard – bitte prüfen', LEGACY: 'vor 0.80 als Rechnung gemeldet' }
	const caseOption = (entry) => `<option value="${entry.id}">${esc(entry.caseNumber)} · ${esc(`${entry.firstName || ''} ${entry.lastName || ''}`.trim())}</option>`
	const kindOf = (key) => (state.paperlessInbox?.kinds || []).find((kind) => kind.key === key) || (ctx.documentKindByKey ? ctx.documentKindByKey(key) : null)

	/**
	 * 0.80.0 (OP-058): Belegeingang für alle Dokumentarten. Jede Karte zeigt den Vorschlag (Dokumentart aus dem
	 * Paperless-Dokumenttyp, Fall aus dem Feld „Fallnummer“, einer Fallnummer im Text oder Name und Datum).
	 */
	function paperlessInboxView() {
		const inbox = state.paperlessInbox || { enabled: false, mode: 'OFF', items: [], manualFallback: true, kinds: [] }
		const rows = (inbox.items || []).map((item) => {
			const href = inbox.baseUrl && item.externalDocumentId ? `${String(inbox.baseUrl).replace(/\/$/, '')}/documents/${encodeURIComponent(item.externalDocumentId)}/details` : ''
			const kind = item.suggestedKind ? kindOf(item.suggestedKind) : null
			const caseText = item.suggestedCaseId ? `<span class="bp-status ready">Fall ${esc(item.suggestedCaseNumber)}${item.suggestedCaseName ? ` · ${esc(item.suggestedCaseName)}` : ''}</span> <small>(${esc(item.suggestedCaseReasonLabel)})</small>` : (item.caseCandidates > 1 ? `<span class="bp-status">${item.caseCandidates} mögliche Fälle</span>` : '<span class="bp-status">Fall unbekannt</span>')
			const kindText = kind ? `<span class="bp-status ready">${esc(kind.name)}</span> <small>(${esc(KIND_SOURCE[item.suggestedKindSource] || 'Vorschlag')})</small>` : '<span class="bp-status">Dokumentart offen</span>'
			const problems = [item.enrichError ? `Angaben aus Paperless nicht lesbar: ${item.enrichError}` : '', item.autoFileError ? `Automatische Ablage nicht möglich: ${item.autoFileError}` : ''].filter(Boolean).map((text) => `<small class="bp-warning-text">${esc(text)}</small>`).join('')
			return `<article class="bp-paperless-card" data-inbox-id="${item.id}"><div><p class="bp-eyebrow">Paperless-Dokument ${esc(item.externalDocumentId || '–')} · Eingang ${new Date(item.createdAt).toLocaleString('de-DE')}</p><h3>${esc(item.title || 'Unbenanntes Dokument')}</h3><p class="bp-paperless-suggestion"><span class="bp-status" title="${esc(item.syncStatus)}">${esc(({ INBOX: 'Nicht zugeordnet', LINKED: 'Zugeordnet' })[item.syncStatus] || item.syncStatus)}</span> Vorschlag: ${kindText} · ${caseText}</p>${problems}</div><div class="bp-row-actions">${href ? `<button type="button" class="bp-secondary open-paperless" data-url="${esc(href)}">In Paperless ansehen</button>` : ''}<button type="button" class="bp-secondary refresh-paperless-item" data-id="${item.id}" title="Dokumenttyp, Fallnummer und Text erneut aus Paperless lesen">Vorschlag aktualisieren</button><button type="button" class="bp-secondary discard-paperless" data-id="${item.id}">Nicht übernehmen</button><button type="button" class="bp-primary assign-paperless" data-id="${item.id}">Zuordnen</button></div></article>`
		}).join('')
		const autoFiled = (inbox.autoFiled || []).map((entry) => `<li>${esc(entry.kind)} „${esc(entry.title)}“ → Fall ${esc(entry.caseNumber)}</li>`).join('')
		return `<div class="bp-page-head"><div><p class="bp-eyebrow">Dokumenteingang</p><h2>Belegeingang</h2><p class="bp-muted">Neue Dokumente aus Paperless prüfen, einer Dokumentart und einem Sterbefall zuordnen. Die Dokumentart bestimmt Ablageordner und Folgeschritt.</p></div><button type="button" class="bp-secondary" id="refresh-paperless-inbox">Aktualisieren</button></div>${autoFiled ? `<aside class="bp-compliance-note bp-auto-filed"><b>Automatisch abgelegt (eindeutige Fallnummer aus Paperless):</b><ul>${autoFiled}</ul></aside>` : ''}<section class="bp-panel"><div class="bp-process-explainer"><b>${inbox.enabled && inbox.mode === 'FULL' ? 'Paperless-Eingang ist aktiv' : 'Paperless-Eingang ist nicht aktiv'}</b><span>Die Bestatter-App bleibt unabhängig: Dokumente können in jedem Fall direkt in der Fallakte unter „Dokumente“ hochgeladen werden.</span><small>Paperless unterstützt Eingang, OCR, Vorschau und Suche. Vorschläge kommen aus dem Paperless-Dokumenttyp${inbox.caseNumberField ? `, dem Feld „${esc(inbox.caseNumberField)}“` : ''} und dem erkannten Text; zugeordnet wird hier.</small></div><div class="bp-paperless-list">${rows || '<p class="bp-empty">Keine unzugeordneten Paperless-Dokumente vorhanden.</p>'}</div></section>`
	}

	function invoiceFields(item, kind) {
		const today = new Date().toISOString().slice(0, 10)
		const prefix = kind?.name || 'Eingangsrechnung'
		return `<label><span>Lieferant</span><input name="supplierName" required></label><label><span>Lieferanten-Rechnungsnummer</span><input name="supplierInvoiceNumber" required></label><label><span>Rechnungsdatum</span><input type="date" name="invoiceDate" value="${today}" required></label><label><span>Eingangsdatum</span><input type="date" name="receivedDate" value="${today}" required></label><label><span>Fällig am</span><input type="date" name="dueDate"></label><label><span>Bruttobetrag</span><input type="number" name="gross" min="0.01" step="0.01" required></label><label class="bp-span-2"><span>Erste Belegposition</span><input name="description" value="${esc(prefix)} ${esc(item.title || '')}" required><small>Weitere Positionen können anschließend im regulären Eingangsrechnungsdialog ergänzt werden.</small></label><label><span>Umsatzsteuer</span><select name="vatRate"><option value="19">19 %</option><option value="7">7 %</option><option value="0">0 %</option></select></label><label class="bp-span-2"><span>Prüfnotiz</span><textarea name="notes" rows="2">Importiert aus Paperless; alle Werte manuell bestätigt.</textarea></label>`
	}

	function kindFields(item, kind) {
		if (!kind) return '<p class="bp-muted bp-span-2">Bitte eine Dokumentart wählen.</p>'
		const hint = kind.hint ? `<aside class="bp-compliance-note bp-span-2">${esc(kind.hint)}</aside>` : ''
		const where = `<p class="bp-muted bp-span-2">Ablage im Fall: <b>${esc(kind.folderName || kind.folderKey)}</b> · Kategorie „${esc(kind.category)}“${kind.description ? ` · ${esc(kind.description)}` : ''}</p>`
		if (kind.effect === 'INCOMING_INVOICE') return `${hint}${where}${invoiceFields(item, kind)}`
		if (kind.effect === 'PAPER_CONTRACT') return `${hint}${where}<p class="bp-muted bp-span-2">Der Scan wird als unterschriebener Vertrag abgelegt (nur PDF). Die Unterschriften prüfen und bestätigen Sie danach im Reiter „Dokumente“.</p>`
		const count = kind.countLabel ? `<label><span>Anzahl ${esc(kind.countLabel)}</span><input type="number" name="count" min="1" max="99" step="1"></label>` : ''
		const dereg = kind.effect === 'DEREGISTRATION' ? '<label class="bp-span-2"><span>Bestätigt die Abmeldung</span><select name="deregistrationId" data-deregistration-select><option value="">– erst Fall wählen –</option></select><small>Die gewählte Abmeldung wird als bestätigt markiert; die Aufgabe „Rückmeldung prüfen“ erledigt sich.</small></label>' : ''
		return `${hint}${where}<label class="bp-span-2"><span>Titel in der Fallakte</span><input name="title" maxlength="200" placeholder="${esc(kind.name)}${item.title ? ` – ${esc(item.title)}` : ''}"><small>Leer lassen für den Vorschlag.</small></label>${count}${dereg}`
	}

	function showAssignment(item) {
		const kinds = (state.paperlessInbox?.kinds || [])
		let kind = kindOf(item.suggestedKind) || null
		const cases = (state.cases || []).map(caseOption)
		if (item.suggestedCaseId && !(state.cases || []).some((entry) => Number(entry.id) === Number(item.suggestedCaseId))) cases.unshift(`<option value="${item.suggestedCaseId}">${esc(item.suggestedCaseNumber)} · ${esc(item.suggestedCaseName || '')}</option>`)
		root.insertAdjacentHTML('beforeend', `<div class="bp-modal"><form id="paperless-assignment" class="bp-wide-modal"><div class="bp-panel-head"><div><p class="bp-eyebrow">Paperless-Dokument ${esc(item.externalDocumentId)}</p><h2>Dokument einem Fall zuordnen</h2><p class="bp-muted">${esc(item.title || '')}${item.suggestedCaseReasonLabel ? ` · Fallvorschlag: ${esc(item.suggestedCaseReasonLabel)}` : ''}</p></div><button type="button" class="bp-secondary" data-close-paperless>Schließen</button></div><div class="bp-form-grid"><label class="bp-span-2"><span>Dokumentart</span><select name="kind" required><option value="">Bitte auswählen</option>${kinds.map((entry) => `<option value="${esc(entry.key)}" ${kind && entry.key === kind.key ? 'selected' : ''}>${esc(entry.name)}</option>`).join('')}</select></label><label class="bp-span-2"><span>Sterbefall suchen</span><input type="search" id="paperless-case-search" placeholder="Fallnummer oder Name" autocomplete="off"></label><label class="bp-span-2"><span>Sterbefall</span><select name="caseId" required><option value="">Bitte auswählen</option>${cases.join('')}</select></label><div class="bp-span-2 bp-form-grid bp-paperless-kind-fields" data-kind-fields>${kindFields(item, kind)}</div></div><div class="bp-row-actions"><span id="paperless-assignment-state" class="bp-save-state" aria-live="polite"></span><button class="bp-primary" data-assign-submit>${kind?.effect === 'INCOMING_INVOICE' ? 'Als Entwurf übernehmen' : 'Übernehmen'}</button></div></form></div>`)
		const form = document.getElementById('paperless-assignment')
		form.querySelector('[data-close-paperless]').onclick = () => form.closest('.bp-modal').remove()
		const caseSelect = form.querySelector('select[name="caseId"]')
		if (item.suggestedCaseId) caseSelect.value = String(item.suggestedCaseId)
		const loadDeregistrations = async () => {
			const select = form.querySelector('[data-deregistration-select]'); if (!select) return
			if (!caseSelect.value) { select.innerHTML = '<option value="">– erst Fall wählen –</option>'; return }
			try {
				const items = await api(`${base}/cases/${Number(caseSelect.value)}/deregistrations`, { feedback: false, loadingLabel: false })
				select.innerHTML = `<option value="">– keine Abmeldung bestätigen –</option>${items.map((entry) => `<option value="${entry.id}">${esc(entry.title)}${entry.sentAt ? ` (versendet ${new Date(entry.sentAt).toLocaleDateString('de-DE')})` : ''}</option>`).join('')}`
				if (items.length === 1) select.value = String(items[0].id)
				if (!items.length) select.innerHTML = '<option value="">Keine versendete Abmeldung offen</option>'
			} catch (error) { select.innerHTML = '<option value="">Abmeldungen nicht ladbar</option>' }
		}
		const showKind = () => {
			kind = kindOf(form.elements.kind.value)
			form.querySelector('[data-kind-fields]').innerHTML = kindFields(item, kind)
			form.querySelector('[data-assign-submit]').textContent = kind?.effect === 'INCOMING_INVOICE' ? 'Als Entwurf übernehmen' : 'Übernehmen'
			loadDeregistrations()
		}
		form.elements.kind.addEventListener('change', showKind)
		caseSelect.addEventListener('change', loadDeregistrations)
		loadDeregistrations()
		// 0.79.3: Fälle über die Fallsuche finden (vorher nur die bereits geladenen, anfangs 25 neuesten Fälle wählbar).
		let searchTimer = null; let searchRun = 0
		form.querySelector('#paperless-case-search').addEventListener('input', (event) => {
			clearTimeout(searchTimer)
			searchTimer = setTimeout(async () => {
				const run = ++searchRun; const params = new URLSearchParams({ query: event.target.value.trim(), status: 'ALL', branch: 'ALL', responsible: 'ALL', limit: '50', offset: '0' })
				try {
					const result = await api(`${ctx.apiBase}/cases/search?${params}`, { feedback: false, loadingLabel: false })
					if (run !== searchRun) return
					const selected = caseSelect.value; const items = result.items || []
					caseSelect.innerHTML = `<option value="">${items.length ? 'Bitte auswählen' : 'Kein Fall gefunden'}</option>${items.map(caseOption).join('')}`
					if (items.some((entry) => String(entry.id) === selected)) caseSelect.value = selected
					else if (items.length === 1) caseSelect.value = String(items[0].id)
					loadDeregistrations()
				} catch (error) { /* Suche optional; Auswahl bleibt */ }
			}, 250)
		})
		form.onsubmit = async (event) => {
			event.preventDefault(); const values = Object.fromEntries(new FormData(form)); const status = document.getElementById('paperless-assignment-state')
			if (!kind) { status.textContent = 'Bitte eine Dokumentart wählen.'; status.dataset.state = 'error'; return }
			let data = {}
			if (kind.effect === 'INCOMING_INVOICE') {
				const grossCents = Math.round(Number(values.gross) * 100); const vatRate = Number(values.vatRate); const netCents = vatRate ? Math.round(grossCents / (1 + vatRate / 100)) : grossCents
				data = { supplierName: values.supplierName, supplierInvoiceNumber: values.supplierInvoiceNumber, invoiceDate: values.invoiceDate, receivedDate: values.receivedDate, dueDate: values.dueDate, declaredGrossCents: grossCents, notes: values.notes, items: [{ description: values.description, supplierQuantityMilli: 1000, unit: 'STK', supplierUnitCents: netCents, supplierVatRate: vatRate, classification: kind.classification || 'CLASSIFICATION_PENDING', customerQuantityMilli: 1000, customerUnitCents: netCents, customerVatRate: vatRate }] }
			} else data = { title: values.title || '', count: values.count || '', deregistrationId: values.deregistrationId || '' }
			status.textContent = 'Dokument wird in den Nextcloud-Fallordner übernommen …'; status.dataset.state = 'saving'
			try {
				const result = await api(`${base}/inbox/${item.id}/assign`, { method: 'POST', feedback: false, body: new URLSearchParams({ caseId: values.caseId, kind: kind.key, data: JSON.stringify(data) }), loadingLabel: 'Paperless-Dokument wird übernommen …' })
				form.closest('.bp-modal').remove(); await loadPaperlessInbox(); state.currentCase = await api(`${ctx.urls.cases}/${values.caseId}`, { feedback: false }); state.currentCaseId = Number(values.caseId); state.view = 'case-detail'
				state.caseTab = result.openTab === 'finances' ? 'finances' : 'document'
				if (state.caseTab === 'finances') await ctx.loadCommercial()
				else await Promise.all([ctx.loadCaseFiles(state.currentCase.id), ctx.loadRecordType('document', state.currentCase.id)])
				ctx.render(); notifySuccess(result.nextStep || 'Dokument wurde übernommen.')
			} catch (error) { status.textContent = error.message; status.dataset.state = 'error' }
		}
	}

	function bindPaperlessInbox() {
		document.getElementById('refresh-paperless-inbox')?.addEventListener('click', async () => { await loadPaperlessInbox(); ctx.render() })
		root.querySelectorAll('.open-paperless').forEach((button) => button.addEventListener('click', () => window.open(button.dataset.url, '_blank', 'noopener')))
		root.querySelectorAll('.assign-paperless').forEach((button) => button.addEventListener('click', () => { const item=(state.paperlessInbox?.items||[]).find((entry)=>entry.id===Number(button.dataset.id)); if(item)showAssignment(item) }))
		root.querySelectorAll('.refresh-paperless-item').forEach((button) => button.addEventListener('click', async () => {
			try { await api(`${base}/inbox/${Number(button.dataset.id)}/refresh`, { method: 'POST', feedback: false, loadingLabel: 'Vorschlag wird aus Paperless gelesen …' }); await loadPaperlessInbox(); ctx.render() } catch (error) { notifyError(error.message) }
		}))
		root.querySelectorAll('.discard-paperless').forEach((button) => button.addEventListener('click', async () => {
			const item = (state.paperlessInbox?.items || []).find((entry) => entry.id === Number(button.dataset.id)); if (!item) return
			if (!await ctx.confirmAction({ title: 'Dokument nicht übernehmen', message: `„${item.title || 'Dokument'}“ wird aus dem Belegeingang entfernt und keinem Fall zugeordnet. In Paperless bleibt es unverändert; eine erneute Meldung desselben Dokuments übernimmt die App nicht noch einmal.`, confirmLabel: 'Nicht übernehmen' })) return
			try { await api(`${base}/inbox/${item.id}/discard`, { method: 'POST', feedback: 'Dokument wird nicht übernommen.' }); await loadPaperlessInbox(); ctx.render() } catch (error) { notifyError(error.message) }
		}))
	}

	function paperlessSettingsView() {
		const s = { mode: 'OFF', baseUrl: '', tagIds: [], captureTagIds: [], ...(state.paperlessConfiguration || {}), ...(state.paperlessConfigurationDraft || {}) }
		return `<section class="bp-panel"><div class="bp-panel-head"><div><h3>Optionale Paperless-ngx-Anbindung</h3><p class="bp-muted">Paperless übernimmt optional Eingang, OCR, Volltext und Vorschau. Die Bestatter-App bleibt führend für Fall, Prüfung und Abrechnung.</p></div><span class="bp-status ${s.enabled ? 'ready' : ''}">${s.enabled ? 'Aktiv' : 'Nicht aktiv'}</span></div><form id="paperless-settings" class="bp-form-grid"><label><span>Betriebsart</span><select name="mode"><option value="OFF" ${s.mode==='OFF'?'selected':''}>Deaktiviert</option><option value="EXPORT" ${s.mode==='EXPORT'?'selected':''}>Nur Kopie an Paperless</option><option value="FULL" ${s.mode==='FULL'?'selected':''}>Beidseitiger Belegeingang</option></select></label><label><span>Paperless-Basisadresse</span><input type="url" name="baseUrl" value="${esc(s.baseUrl || '')}" placeholder="https://paperless.example.de"></label><label><span>API-Token</span><input type="password" name="apiToken" autocomplete="new-password" placeholder="${s.tokenConfigured ? 'Gespeichert – leer lassen zum Beibehalten' : 'Token des technischen Benutzers'}"></label><label class="bp-inline-check"><input type="checkbox" name="clearApiToken" ${s.clearApiToken ? 'checked' : ''}> Gespeicherten API-Token entfernen</label><label><span>Dokumenttyp-ID: Eingangsrechnung</span><input type="number" min="0" name="documentTypeId" value="${Number(s.documentTypeId || 0)}"><small>0 lässt die Zuordnung Paperless überlassen.</small></label><label><span>Tag-IDs: Eingangsrechnung</span><input name="tagIds" value="${esc(Array.isArray(s.tagIds) ? s.tagIds.join(', ') : (s.tagIds || ''))}" placeholder="12, 18"><small>IDs durch Komma trennen.</small></label><label><span>Dokumenttyp-ID: Auftragserfassungsbogen</span><input type="number" min="0" name="captureDocumentTypeId" value="${Number(s.captureDocumentTypeId || 0)}"><small>Eigener Dokumenttyp verhindert eine Vermischung mit Rechnungen.</small></label><label><span>Tag-IDs: Auftragserfassungsbogen</span><input name="captureTagIds" value="${esc(Array.isArray(s.captureTagIds) ? s.captureTagIds.join(', ') : (s.captureTagIds || ''))}" placeholder="21, 22"><small>Empfohlen: eigener Tag „Bestatter-Auftragserfassung“.</small></label><label><span>Paperless-Feld für die Fallnummer</span><input name="caseNumberField" value="${esc(s.caseNumberField ?? 'Fallnummer')}" maxlength="80" placeholder="Fallnummer"><small>Name eines benutzerdefinierten Felds in Paperless; leer = nicht verwenden.</small></label><label class="bp-inline-check"><input type="checkbox" name="autoFile" ${s.autoFile !== false ? 'checked' : ''}> Automatisch ablegen, wenn Fallnummer (aus diesem Feld) und Dokumentart (aus dem Paperless-Dokumenttyp) eindeutig sind<small>Nur für Dokumentarten, die keine weiteren Angaben brauchen; Rechnungen und Rückmeldungen bleiben immer zur Prüfung im Belegeingang.</small></label><label class="bp-inline-check"><input type="checkbox" name="archiveIncomingInvoices" ${s.archiveIncomingInvoices !== false ? 'checked' : ''}> Von Hand erfasste Eingangsrechnungen als Archivkopie an Paperless übertragen<small>Aus: Die Rechnung liegt nur in der Fallakte. Belege aus dem Belegeingang sind davon nicht betroffen (ihr Original liegt bereits in Paperless).</small></label><label class="bp-inline-check"><input type="checkbox" name="deleteCaptureAfterOcr" ${s.deleteCaptureAfterOcr ? 'checked' : ''}> Erfassungsbogen in Paperless nach erfolgreicher Texterkennung löschen<small>Der Bogen bleibt in Nextcloud. Paperless verschiebt ihn in den Papierkorb; der Paperless-Benutzer braucht dafür das Recht zum Löschen.</small></label><label class="bp-span-2"><span>Webhook-Geheimnis</span><input type="password" name="webhookSecret" autocomplete="new-password" placeholder="${s.webhookSecretConfigured ? 'Gespeichert – leer lassen zum Beibehalten' : 'Wird bei Vollbetrieb automatisch erzeugt'}"></label><div class="bp-span-2 bp-compliance-note"><b>Webhook in Paperless</b><span>URL: <code>${esc(s.webhookUrl || '')}</code></span><span>Header: <code>X-Bestatter-Webhook-Token</code></span><span>JSON: <code>{"event":"document_added","document_id":"{{doc_id}}","title":"{{doc_title}}"}</code></span><small>Das Geheimnis wird nur beim erstmaligen Erzeugen angezeigt. OCR-Daten aus Erfassungsbögen werden ausschließlich als bestätigungspflichtige Vorschläge angezeigt.</small></div><div id="paperless-settings-message" class="bp-span-2 bp-save-state" aria-live="polite">${state.paperlessConfigurationDraft ? 'Ungespeicherte Eingaben sind vorgemerkt.' : ''}</div><div class="bp-span-2 bp-row-actions"><button type="button" class="bp-secondary" id="test-paperless">Verbindung testen</button><button class="bp-primary">Einstellungen speichern</button></div></form></section>`
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
				caseNumberField: form.elements.caseNumberField.value,
				autoFile: form.elements.autoFile.checked,
				archiveIncomingInvoices: form.elements.archiveIncomingInvoices.checked,
				deleteCaptureAfterOcr: form.elements.deleteCaptureAfterOcr.checked,
				clearApiToken: form.elements.clearApiToken.checked,
			}
			message.textContent = 'Ungespeicherte Eingaben – bitte speichern.'
			message.dataset.state = 'warning'
		}
		form.querySelectorAll('input,select').forEach((field) => {
			field.addEventListener('input', rememberDraft)
			field.addEventListener('change', rememberDraft)
		})
		form.onsubmit = async (event) => { event.preventDefault(); const values=Object.fromEntries(new FormData(form)); values.clearApiToken=form.elements.clearApiToken.checked;values.autoFile=form.elements.autoFile.checked;values.archiveIncomingInvoices=form.elements.archiveIncomingInvoices.checked;values.deleteCaptureAfterOcr=form.elements.deleteCaptureAfterOcr.checked; message.textContent='Speichert …'; message.dataset.state='saving'; try { state.paperlessConfiguration=await api(`${base}/configuration`,{method:'PUT',feedback:false,body:new URLSearchParams({configuration:JSON.stringify(values)})}); state.paperlessConfigurationDraft=null; form.classList.remove('is-dirty'); const generated=state.paperlessConfiguration.generatedWebhookSecret; message.textContent=generated?`Gespeichert. Webhook-Geheimnis jetzt sicher kopieren: ${generated}`:'Gespeichert.';message.dataset.state='saved';notifySuccess('Paperless-Konfiguration wurde gespeichert.'); } catch(error){message.textContent=error.message;message.dataset.state='error'} }
		document.getElementById('test-paperless')?.addEventListener('click',async()=>{message.textContent='Verbindung wird geprüft …';message.dataset.state='saving';try{const result=await api(`${base}/test`,{method:'POST',feedback:false});if(result.status==='OK'){message.textContent=`${result.apiResponse} (${result.durationMs} ms)`;message.dataset.state='saved'}else{message.textContent=`${result.message} ${result.recommendation||''}`.trim();message.dataset.state='error';notifyError(message.textContent)}}catch(error){message.textContent=error.message;message.dataset.state='error';notifyError(error.message)}})
	}

	return { loadPaperlessInbox, loadPaperlessConfiguration, paperlessInboxView, bindPaperlessInbox, paperlessSettingsView, bindPaperlessSettings }
}
