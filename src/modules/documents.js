export function createDocumentsModule(ctx) {
	const { root, apiBase, state } = ctx
	const api = (...args) => ctx.api(...args)
	const caseOverview = (...args) => ctx.caseOverview(...args)
	const sideOrdersPanel = (...args) => ctx.sideOrdersPanel(...args)
	const checklistPanel = (...args) => ctx.checklistPanel(...args)
	const contactsByCategory = (...args) => ctx.contactsByCategory(...args)
	const deregistrationUrl = (...args) => ctx.deregistrationUrl(...args)
	const documentOutputRow = (...args) => ctx.documentOutputRow(...args)
	const documentUrl = (...args) => ctx.documentUrl(...args)
	const esc = (...args) => ctx.esc(...args)
	const field = (...args) => ctx.field(...args)
	const financesPanel = (...args) => ctx.financesPanel(...args)
	const loadRecordType = (...args) => ctx.loadRecordType(...args)
	const loadCaseFiles = (...args) => ctx.loadCaseFiles(...args)
	const uploadCaseFile = (...args) => ctx.uploadCaseFile(...args)
	const masterDataForm = (...args) => ctx.masterDataForm(...args)
	const orderPanel = (...args) => ctx.orderPanel(...args)
	const overview = (...args) => ctx.overview(...args)
	const recordItemUrl = (...args) => ctx.recordItemUrl(...args)
	const recordUrl = (...args) => ctx.recordUrl(...args)
	const recordsView = (...args) => ctx.recordsView(...args)
	const render = (...args) => ctx.render(...args)
	const serviceSelectionPanel = (...args) => ctx.serviceSelectionPanel(...args)
	const title = (...args) => ctx.title(...args)
	const notifySuccess = (...args) => ctx.notifySuccess(...args)
	const notifyWarning = (...args) => ctx.notifyWarning(...args)
	const confirmAction = (...args) => ctx.confirmAction(...args)

	function caseContactsPanel() {
		const records = (state.records.case_contact || []).filter((item) => item.caseId === state.currentCase.id)
		const rows = records.map((item) => `<article class="bp-record-row"><span class="bp-record-check">♟</span><button class="bp-record-content edit-case-contact" data-id="${item.id}"><b>${esc(item.title)}</b><small>${esc(item.data?.role || 'Fallkontakt')} · Nextcloud-Kontaktzuordnung</small></button><button class="bp-secondary edit-case-contact" data-id="${item.id}">Bearbeiten</button></article>`).join('')
		return `<div class="bp-page-head"><div><p class="bp-eyebrow">Kontakte</p><h2>Fallbezogene Kontakte</h2><p class="bp-muted">Kontaktdaten bleiben zentral in Nextcloud Contacts; hier werden nur Rolle und Zuordnung zum Fall gespeichert.</p></div><button class="bp-primary" id="assign-case-contact">Kontakt zuordnen</button></div><section class="bp-panel bp-record-panel"><div class="bp-record-list">${rows || '<p class="bp-empty">Noch keine Kontakte zugeordnet.</p>'}</div></section>`
	}

	function showCaseContactForm(item = null) {
		const currentId = item?.data?.contactId || ''
		const options = state.records.contact.map((contact) => `<option value="${esc(contact.id)}" ${String(contact.id) === String(currentId) ? 'selected' : ''}>${esc(contact.title)} · ${esc(contact.data?.categories || '')}</option>`).join('')
		root.insertAdjacentHTML('beforeend', `<div class="bp-modal"><form id="case-contact-form"><h2>Fallkontakt ${item ? 'bearbeiten' : 'zuordnen'}</h2><label>Nextcloud-Kontakt<select name="contactId" required><option value="">Kontakt wählen</option>${options}</select></label><label>Rolle im Fall<input name="role" value="${esc(item?.data?.role || '')}" placeholder="z. B. Tochter, Standesamt, Friedhof" required></label><div class="bp-modal-actions"><button type="button" class="bp-secondary" data-close-modal>Abbrechen</button><button class="bp-primary">Zuordnung speichern</button></div></form></div>`)
		const modal = [...root.querySelectorAll('.bp-modal')].pop(); modal.querySelector('[data-close-modal]').onclick = () => modal.remove()
		modal.querySelector('form').onsubmit = async (event) => { event.preventDefault(); const data = Object.fromEntries(new FormData(event.target)); const contact = state.records.contact.find((entry) => String(entry.id) === String(data.contactId)); if (!contact) return; const payload = { contactId: contact.id, addressBookKey: contact.data?.addressBookKey || '', role: data.role, source: 'case-assignment', contactSnapshot: { title: contact.title, email: contact.data?.email || '', phone: contact.data?.phone || '', address: contact.data?.address || '', country: contact.data?.country || '' } }; await api(item ? recordItemUrl(item.id) : recordUrl('case_contact'), { method: item ? 'PUT' : 'POST', body: new URLSearchParams({ title: contact.title, date: new Date().toISOString(), status: 'AKTIV', caseId: state.currentCase.id, data: JSON.stringify(payload) }) }); await loadRecordType('case_contact', state.currentCase.id); modal.remove(); render() }
	}

	function expandDeregistration(value, data) {
		const master = state.currentCase.masterData || {}
		return String(value || '').replace(/{{case.number}}/g, state.currentCase.caseNumber || '').replace(/{{case.first_name}}/g, state.currentCase.firstName || '').replace(/{{case.last_name}}/g, state.currentCase.lastName || '').replace(/{{case.date_of_birth}}/g, master.date_of_birth || '').replace(/{{case.date_of_death}}/g, state.currentCase.dateOfDeath || master.date_of_death || '').replace(/{{case.pension_number}}/g, data.pensionNumber || master.pension_insurance_number || '').replace(/{{advance.application}}/g, data.advanceApplication || 'NEIN')
	}

	function deliveryChannels(template, contact) {
		const available = []
		if (contact?.data?.email) available.push('EMAIL')
		if (contact?.data?.street && (contact?.data?.postalCode || contact?.data?.city)) available.push('POST')
		if (contact?.data?.url) available.push('PORTAL')
		if (contact?.data?.phone) available.push('TELEFON')
		return (template?.deliveryChannels || []).filter((channel) => available.includes(channel))
	}

	function showTextPreview(title, subject, body, missing = []) {
		root.insertAdjacentHTML('beforeend', `<div class="bp-modal"><section class="bp-wide-modal"><div class="bp-panel-head"><div><h2>Vorschau · ${esc(title)}</h2><p class="bp-muted">Arbeitsvorschau ohne Versand.</p></div><button class="bp-secondary" data-close-preview>Schließen</button></div>${missing.length ? `<div class="bp-error"><b>Fehlende Pflichtangaben:</b> ${missing.map(esc).join(', ')}</div>` : ''}<h3>Betreff</h3><div class="bp-preview-box">${esc(subject)}</div><h3>Text</h3><pre class="bp-preview-box">${esc(body)}</pre></section></div>`); const modal = [...root.querySelectorAll('.bp-modal')].pop(); modal.querySelector('[data-close-preview]').onclick = () => modal.remove()
	}

	function showLetterPreview(preview) {
		root.insertAdjacentHTML('beforeend', `<div class="bp-modal"><section class="bp-wide-modal"><div class="bp-panel-head"><div><p class="bp-eyebrow">Briefvorschau ohne Versand · ${esc(preview.letter.templateName || preview.documentTemplateKey || 'Briefvorlage')}</p><h2>${esc(preview.template.name)}</h2></div><button class="bp-secondary" data-close-preview>Schließen</button></div>${preview.missingFields.length?`<div class="bp-error">Fehlende Pflichtangaben: ${preview.missingFields.map(esc).join(', ')}</div>`:''}<article class="bp-letter-preview"><small class="bp-letter-sender">${esc(preview.letter.sender)}</small><pre class="bp-letter-recipient">${esc(preview.letter.recipient)}</pre><p class="bp-letter-date">${esc(preview.letter.date)}</p><h3>${esc(preview.subject)}</h3><pre>${esc(preview.body)}</pre><div class="bp-letter-attachments"><b>Anlagen</b>${(preview.attachments||[]).map((item)=>`<span>${esc(item.title)}</span>`).join('')||'<span>Keine Anlagen ausgewählt</span>'}</div></article>${preview.attachmentWarning?`<p class="bp-warning">${esc(preview.attachmentWarning)}</p>`:''}</section></div>`); const modal=[...root.querySelectorAll('.bp-modal')].pop();modal.querySelector('[data-close-preview]').onclick=()=>modal.remove()
	}

	function deregistrationPanel() {
		const records = (state.records.deregistration || []).filter((item) => item.caseId === state.currentCase.id)
		const next = { ENTWURF: ['VORBEREITET', 'Vorbereiten'], VORBEREITET: ['VERSENDET', 'Versand erfassen'], VERSENDET: ['BESTAETIGT', 'Bestätigen'], BESTAETIGT: ['ERLEDIGT', 'Erledigen'] }
		const rows = records.map((item) => { const action = next[item.status]; const editable=['ENTWURF','VORBEREITET'].includes(item.status); return `<article class="bp-record-row bp-deregistration-row"><span class="bp-record-check">↗</span><div class="bp-record-content"><b>${esc(item.title)}</b><small>${esc(item.data?.recipientName || '')} · ${esc(item.data?.deliveryChannel || 'kein Übertragungsweg')} · <span class="bp-status">${esc(item.status)}</span></small><small>${(item.data?.attachments||[]).length} Anlage(n)${item.data?.attachmentWarning?` · ${esc(item.data.attachmentWarning)}`:''}</small></div><div class="bp-row-actions bp-deregistration-actions">${editable?`<button class="bp-secondary edit-deregistration" data-id="${item.id}">Bearbeiten</button>`:''}<button class="bp-secondary preview-deregistration" data-id="${item.id}">Vorschau</button>${action ? `<button class="bp-primary transition-deregistration" data-id="${item.id}" data-status="${action[0]}">${action[1]}</button>` : ''}</div></article>` }).join('')
		return `<div class="bp-page-head"><div><p class="bp-eyebrow">Abmeldungen</p><h2>Abmeldungen und Mitteilungen</h2><p class="bp-muted">Kontaktwege, Pflichtfelder und Statuswechsel werden serverseitig geprüft. Der Versand erfolgt bewusst manuell.</p></div><button class="bp-primary" id="new-deregistration">Abmeldung vorbereiten</button></div><section class="bp-panel bp-record-panel"><div class="bp-record-list">${rows || '<p class="bp-empty">Noch keine Abmeldung vorbereitet.</p>'}</div></section>`
	}

	function showDeregistrationForm(item = null) {
		const data = item?.data || {}; const templates = state.deregistrationTemplates.filter((entry) => entry.active)
		const documents=state.caseFiles.files||[]; const selectedIds=new Set((data.attachments||[]).map((entry)=>Number(entry.fileId||entry.id))); if(!item){const death=documents.find((entry)=>/sterbeurk/i.test(`${entry.title} ${entry.documentType||''} ${entry.path||''}`));if(death)selectedIds.add(death.fileId)}
		const attachmentRow=(entry,checked=false)=>`<label class="bp-inline-check"><input type="checkbox" name="attachmentIds" value="${entry.fileId}" ${checked?'checked':''}> <span>${esc(entry.title)} <small>${esc(entry.documentType||'Sonstiges')} · ${esc(entry.status)} · ${esc(entry.subfolder||'Fallakte')}</small></span></label>`
		const attachments=documents.map((entry)=>attachmentRow(entry,selectedIds.has(entry.fileId))).join('')
		root.insertAdjacentHTML('beforeend', `<div class="bp-modal"><form id="deregistration-form" class="bp-wide-modal"><h2>Abmeldung ${item ? 'bearbeiten' : 'vorbereiten'}</h2><div class="bp-form-grid"><label>Vorgang<select name="templateKey" required>${templates.map((entry) => `<option value="${esc(entry.key)}" ${entry.key === data.templateKey ? 'selected' : ''}>${esc(entry.name)}</option>`).join('')}</select></label><label>Empfänger<select name="contactId"></select></label><label>Übertragungsweg<select name="deliveryChannel"></select><small id="channel-hint"></small></label><label>Status<select name="status">${['ENTWURF', 'VORBEREITET'].map((status) => `<option ${status === (item?.status || 'ENTWURF') ? 'selected' : ''}>${status}</option>`).join('')}</select></label></div><p class="bp-muted">Ein Entwurf kann unvollständig gespeichert werden und bleibt bis zum Versand bearbeitbar.</p><section id="pension-fields" class="bp-field-section bp-hidden"><h3>Rentenservice und Vorschussprüfung</h3><div class="bp-form-grid"><label>Postrentennummer<input name="pensionNumber" value="${esc(data.pensionNumber || state.currentCase.masterData?.pension_insurance_number || '')}"></label><label>Weitere Postrentennummer<input name="pensionNumber2" value="${esc(data.pensionNumber2 || state.currentCase.masterData?.pension_insurance_number_2 || '')}"></label><label>Vorschuss für Witwe/Witwer beantragen?<select name="advanceApplication"><option value="NEIN">Nein</option><option value="JA" ${data.advanceApplication === 'JA' ? 'selected' : ''}>Ja</option></select></label><div></div><label>Antragsteller/in<input name="advanceApplicantName" value="${esc(data.advanceApplicantName || '')}"></label><label>Geburtsdatum<input type="date" name="advanceApplicantBirthDate" value="${esc(data.advanceApplicantBirthDate || '')}"></label><label>Anschrift<input name="advanceApplicantAddress" value="${esc(data.advanceApplicantAddress || '')}"></label><label>Eigene Renten-/Versicherungsnummer<input name="advanceApplicantPensionNumber" value="${esc(data.advanceApplicantPensionNumber || '')}"></label></div></section><section class="bp-field-section"><div class="bp-panel-head"><div><h3>Anlagen aus der Fallakte</h3><p class="bp-muted">Eine vorhandene Sterbeurkunde wird standardmäßig ausgewählt. Direkt in Nextcloud abgelegte Dateien stehen ebenfalls zur Auswahl.</p></div><div><input id="deregistration-local-files" type="file" multiple hidden><button type="button" class="bp-secondary" id="add-local-attachments">Dateien vom Computer hinzufügen</button></div></div><div class="bp-attachment-picker">${attachments||'<p class="bp-warning bp-no-attachments">Im Fall ist noch keine Sterbeurkunde oder andere Anlage verfügbar.</p>'}</div><p class="bp-save-state" id="attachment-upload-state"></p><p class="bp-warning" id="attachment-warning"></p></section><label>Betreff<input name="subject" value="${esc(data.subject || '')}"></label><label>Text<textarea name="body" rows="10">${esc(data.body || '')}</textarea></label><p class="bp-save-state" id="deregistration-state"></p><div class="bp-modal-actions"><button type="button" class="bp-secondary" data-close-modal>Abbrechen</button><button type="button" class="bp-secondary" id="preview-deregistration-form">Vorschau</button><button class="bp-primary">Entwurf speichern</button></div></form></div>`)
		const modal = [...root.querySelectorAll('.bp-modal')].pop(); const form = modal.querySelector('form'); const templateSelect = form.elements.templateKey; const contactSelect = form.elements.contactId; const channelSelect = form.elements.deliveryChannel
		const refresh = (preserveText = false) => { const selectedContactId = contactSelect.value || String(data.contactId || ''); const selectedChannel = channelSelect.value || data.deliveryChannel || ''; const template = templates.find((entry) => entry.key === templateSelect.value) || templates[0]; const contacts = contactsByCategory(template?.contactCategory || ''); contactSelect.innerHTML = '<option value="">Empfänger wählen</option>' + contacts.map((contact) => `<option value="${esc(contact.id)}">${esc(contact.title)}</option>`).join(''); contactSelect.value = contacts.some((entry) => String(entry.id) === selectedContactId) ? selectedContactId : ''; const contact = contacts.find((entry) => String(entry.id) === String(contactSelect.value)); const channels = deliveryChannels(template, contact); const remembered = localStorage.getItem(`bestatter:dereg-channel:${template?.key}`) || template?.defaultChannel || ''; channelSelect.innerHTML = '<option value="">Übertragungsweg wählen</option>' + channels.map((channel) => `<option value="${channel}">${channel}</option>`).join(''); channelSelect.value = channels.includes(selectedChannel) ? selectedChannel : (channels.includes(remembered) ? remembered : ''); form.querySelector('#channel-hint').textContent = channels.length ? '' : 'Am Kontakt ist kein zulässiger Übertragungsweg gepflegt.'; form.querySelector('#pension-fields').classList.toggle('bp-hidden', template?.formType !== 'PENSION_SERVICE'); if (!preserveText) { form.elements.subject.value = expandDeregistration(template?.subjectTemplate, data); form.elements.body.value = expandDeregistration(template?.bodyTemplate, data) } }
		const missing = () => { const template = templates.find((entry) => entry.key === templateSelect.value); const values = Object.fromEntries(new FormData(form)); const result = []; if (!values.contactId) result.push('Empfänger'); if (!values.deliveryChannel) result.push('Übertragungsweg'); if (template?.formType === 'PENSION_SERVICE') { if (!values.pensionNumber) result.push('Postrentennummer'); if (values.advanceApplication === 'JA') { if (!values.advanceApplicantName) result.push('Name Antragsteller/in'); if (!values.advanceApplicantBirthDate) result.push('Geburtsdatum Antragsteller/in'); if (!values.advanceApplicantAddress) result.push('Anschrift Antragsteller/in') } } return result }
		const valuesWithAttachments=()=>({...data,...Object.fromEntries(new FormData(form)),attachments:[...form.querySelectorAll('[name="attachmentIds"]:checked')].map((input)=>Number(input.value))})
		form.querySelector('#add-local-attachments').onclick=()=>form.querySelector('#deregistration-local-files').click()
		form.querySelector('#deregistration-local-files').onchange=async(event)=>{const files=[...event.target.files];if(!files.length)return;const status=form.querySelector('#attachment-upload-state');status.textContent=`${files.length} Datei(en) werden in „03 Behörden“ abgelegt …`;status.dataset.state='saving';try{for(const file of files){const result=await uploadCaseFile(file,'03 Behörden',/sterbeurk/i.test(file.name)?'Urkunde':'Nachweis');state.caseFiles.files.push(result.file);form.querySelector('.bp-no-attachments')?.remove();form.querySelector('.bp-attachment-picker').insertAdjacentHTML('beforeend',attachmentRow(result.file,true))}status.textContent='Anlagen wurden hochgeladen und ausgewählt.';status.dataset.state='saved';event.target.value=''}catch(error){status.textContent=error.message;status.dataset.state='error'}}
		templateSelect.onchange = () => refresh(false); contactSelect.onchange = () => refresh(true); modal.querySelector('[data-close-modal]').onclick = () => modal.remove(); modal.querySelector('#preview-deregistration-form').onclick = async () => { const values=valuesWithAttachments(); const preview = await api(`${deregistrationUrl(state.currentCase.id)}/preview`, { method: 'POST', body: new URLSearchParams({ deregistration: JSON.stringify(values) }) }); form.elements.subject.value = preview.subject; form.elements.body.value = preview.body; form.querySelector('#attachment-warning').textContent=preview.attachmentWarning||''; if(preview.previewMode==='LETTER')showLetterPreview(preview);else showTextPreview(preview.template.name, preview.subject, preview.body, preview.missingFields) }
		form.onsubmit = async (event) => { event.preventDefault(); const values=valuesWithAttachments(); const missingFields = missing(); const status = form.querySelector('#deregistration-state'); if (values.status !== 'ENTWURF' && missingFields.length) { status.textContent = `Bitte ergänzen: ${missingFields.join(', ')}`; status.dataset.state = 'error'; return } const template = templates.find((entry) => entry.key === values.templateKey); const contact = state.records.contact.find((entry) => String(entry.id) === String(values.contactId)); const payload = { ...values, formType: template.formType, recipientName: contact?.title || '', recipient: contact?.data || {}, contactCategory: template.contactCategory }; if (values.deliveryChannel) localStorage.setItem(`bestatter:dereg-channel:${template.key}`, values.deliveryChannel); try { await api(item ? `${deregistrationUrl(state.currentCase.id)}/${item.id}` : deregistrationUrl(state.currentCase.id), { method: item ? 'PUT' : 'POST', body: new URLSearchParams({ deregistration: JSON.stringify(payload) }) }); state.records.deregistration = await api(deregistrationUrl(state.currentCase.id)); modal.remove(); render() } catch (error) { status.textContent = error.message; status.dataset.state = 'error' } }
		refresh(Boolean(item))
	}

	function showDeregistrationTransitionForm(item, targetStatus) {
		if (targetStatus !== 'VERSENDET') {
			return api(`${apiBase}/deregistrations/${item.id}/transition`, { method: 'POST', body: new URLSearchParams({ status: targetStatus, data: '{}' }) }).then(async () => { state.records.deregistration = await api(deregistrationUrl(state.currentCase.id)); render() })
		}
		const localDate = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16)
		root.insertAdjacentHTML('beforeend', `<div class="bp-modal"><form class="bp-wide-modal" id="deregistration-transition-form"><div class="bp-panel-head"><div><p class="bp-eyebrow">Manueller Versandnachweis</p><h2>${esc(item.title)}</h2><p class="bp-muted">Hier wird ausschließlich ein bereits außerhalb der Anwendung erfolgter Versand dokumentiert. Es wird keine E-Mail versendet.</p></div><button type="button" class="bp-secondary" data-close-modal>Schließen</button></div><div class="bp-form-grid"><label><span>Versandzeitpunkt</span><input name="sentAt" type="datetime-local" value="${localDate}" required></label><label><span>Übertragungsweg</span><input value="${esc(item.data?.deliveryChannel || 'nicht festgelegt')}" readonly></label><label class="bp-span-2"><span>Versandnachweis / Notiz</span><textarea name="evidenceNote" rows="4" placeholder="z. B. Einwurf-Einschreiben, Belegnummer oder persönliche Übergabe" required></textarea></label><label class="bp-span-2"><span>Pfad zum Beleg (optional)</span><input name="evidenceDocumentPath" placeholder="Bestatter/Fälle/…/Versandnachweis.pdf"></label></div><p class="bp-save-state" aria-live="polite"></p><div class="bp-modal-actions"><button type="button" class="bp-secondary" data-close-modal>Abbrechen</button><button class="bp-primary">Versandnachweis speichern (kein Versand)</button></div></form></div>`)
		const modal = [...root.querySelectorAll('.bp-modal')].pop(); const form = modal.querySelector('form'); modal.querySelectorAll('[data-close-modal]').forEach((button) => button.onclick = () => modal.remove())
		form.onsubmit = async (event) => { event.preventDefault(); const status = form.querySelector('.bp-save-state'); const values = Object.fromEntries(new FormData(form)); if (!values.evidenceNote.trim()) { status.textContent = 'Bitte einen Versandnachweis oder eine Notiz eintragen.'; status.dataset.state = 'error'; return } values.sentAt = new Date(values.sentAt).toISOString(); try { await api(`${apiBase}/deregistrations/${item.id}/transition`, { method: 'POST', body: new URLSearchParams({ status: targetStatus, data: JSON.stringify(values) }) }); state.records.deregistration = await api(deregistrationUrl(state.currentCase.id)); modal.remove(); render() } catch (error) { status.textContent = error.message; status.dataset.state = 'error' } }
	}

	function documentPanel() {
		const templates = state.documentTemplates.filter((entry) => entry.active && String(entry.fileName || '').toLowerCase().endsWith('.docx'))
		const cards = templates.map((entry) => `<article class="bp-document-card"><div><p class="bp-eyebrow">${esc(entry.category)}</p><h3>${esc(entry.name)}</h3><p>${esc(entry.description || '')}</p><small>${esc(entry.outputSubfolder || '')} · ${entry.supportsPdf ? 'DOCX + PDF' : 'DOCX'}</small></div><button class="bp-primary create-document" data-template-key="${esc(entry.key)}">Vorschau / erzeugen</button></article>`).join('')
		const outputs = (state.records.document || []).filter((item) => item.caseId === state.currentCase.id).map(documentOutputRow).join('')
		const folders=(state.caseFiles.folders||[]).map((value)=>`<option>${esc(value)}</option>`).join(''); const types=(state.caseFiles.documentTypes||[]).map((value)=>`<option>${esc(value)}</option>`).join('')
		const files=(state.caseFiles.files||[]).map((file)=>`<article class="bp-file-row"><div class="bp-file-icon">${esc((file.extension||'DATEI').toUpperCase())}</div><div class="bp-record-content"><b>${esc(file.title)}</b><small>${esc(file.documentType)} · ${esc(file.status)} · ${esc(file.source==='NEXTCLOUD'?'direkt in Nextcloud abgelegt':'über die Bestatter-App/Erzeugung')} · ${esc(file.relativePath)}</small></div><div class="bp-row-actions"><button class="bp-secondary preview-nextcloud-file" data-file-id="${file.fileId}" aria-label="${esc(file.title)}">Vorschau</button><button class="bp-secondary open-nextcloud-file" data-file-id="${file.fileId}">${file.readOnly?'Öffnen':'In Nextcloud öffnen'}</button></div></article>`).join('')
		return `<div class="bp-page-head"><div><p class="bp-eyebrow">Dokumente</p><h2>Dokumente zum Fall</h2><p class="bp-muted">Vorlagen, erzeugte Ausgaben und die tatsächlichen Dateien der Nextcloud-Fallakte werden gemeinsam berücksichtigt.</p></div></div><section class="bp-field-section"><div class="bp-panel-head"><div><h3>Dokumente hochladen</h3><p class="bp-muted">Mehrfachauswahl bis 50 MB je Datei. Gleichnamige Dateien werden nicht überschrieben.</p></div><button class="bp-primary" id="upload-case-documents">Dateien auswählen</button></div><input id="case-document-files" type="file" multiple hidden><div class="bp-form-grid"><label>Ablage im Fall<select id="case-upload-folder">${folders}</select></label><label>Dokumentenart<select id="case-upload-type">${types}</select></label></div><p class="bp-save-state" id="case-upload-state"></p></section><section class="bp-field-section"><div class="bp-panel-head"><div><h3>Vorlagen auswählen</h3><p class="bp-muted">Erzeugt einen aktuellen Entwurf oder eine finale, nicht bearbeitbare PDF-Ausgabe.</p></div></div><div class="bp-document-grid">${cards}</div></section><section class="bp-field-section"><div class="bp-panel-head"><div><h3>Erstellte Ausgaben</h3><p class="bp-muted">Fachlich erzeugte Entwürfe und finale Ausgaben mit Status und Metadaten.</p></div></div><div class="bp-record-list">${outputs || '<p class="bp-empty">Noch kein Dokument erzeugt.</p>'}</div></section><section class="bp-field-section"><div class="bp-panel-head"><div><h3>Dateien in der Nextcloud-Fallakte</h3><p class="bp-muted">Enthält auch Dateien, die außerhalb der Bestatter-App direkt in den Fallordner hochgeladen wurden.</p></div><span class="bp-status">${files?state.caseFiles.files.length:0} Datei(en)</span></div><div class="bp-file-list">${files||'<p class="bp-empty">Der Fallordner enthält noch keine Dateien.</p>'}</div></section>`
	}

	function showFilePreview(fileId, title = 'Dokumentvorschau') {
		const numericFileId = Number(fileId)
		const src = OC.generateUrl(`/core/preview?fileId=${numericFileId}&x=1800&y=2400&a=1`)
		root.insertAdjacentHTML('beforeend', `<div class="bp-modal"><section class="bp-wide-modal bp-file-preview" role="dialog" aria-modal="true" aria-labelledby="bp-file-preview-title"><div class="bp-panel-head bp-file-preview-head"><div><p class="bp-eyebrow">Schreibgeschützte Vorschau</p><h2 id="bp-file-preview-title">${esc(title)}</h2></div><div class="bp-row-actions"><button type="button" class="bp-secondary" data-open-preview-file>Datei öffnen</button><button type="button" class="bp-secondary" data-close-preview>Schließen</button></div></div><div class="bp-file-preview-toolbar" role="toolbar" aria-label="Vorschaugröße"><button type="button" class="bp-secondary" data-preview-zoom-out aria-label="Vorschau verkleinern">−</button><output data-preview-zoom aria-live="polite">Seitenbreite</output><button type="button" class="bp-secondary" data-preview-zoom-in aria-label="Vorschau vergrößern">+</button><button type="button" class="bp-secondary" data-preview-fit-page>Ganze Seite</button><button type="button" class="bp-secondary" data-preview-fit-width>Seitenbreite</button></div><div class="bp-file-preview-viewport" tabindex="0" aria-label="Dokumentseite – zum Lesen scrollen"><img src="${esc(src)}" alt="${esc(title)}" data-preview-image><div class="bp-empty bp-hidden" data-preview-fallback><b>Für diese Datei konnte Nextcloud kein Vorschaubild erzeugen.</b><br>Das vollständige Dokument kann über „Datei öffnen“ angezeigt werden.</div></div><p class="bp-muted bp-file-preview-note">Die Vorschau ist schreibgeschützt. Bei mehrseitigen Dateien zeigt die Nextcloud-Vorschau die erste Seite; über „Datei öffnen“ steht das vollständige Dokument zur Verfügung.</p></section></div>`)
		const modal = [...root.querySelectorAll('.bp-modal')].pop()
		const viewer = modal.querySelector('.bp-file-preview-viewport')
		const image = modal.querySelector('[data-preview-image]')
		const zoomOutput = modal.querySelector('[data-preview-zoom]')
		const fallback = modal.querySelector('[data-preview-fallback]')
		let zoom = 100
		const setPixelWidth = (width, label) => {
			image.style.width = `${Math.max(260, Math.round(width))}px`
			image.style.maxWidth = 'none'
			zoomOutput.textContent = label
		}
		const fitWidth = () => setPixelWidth(viewer.clientWidth - 48, 'Seitenbreite')
		const fitPage = () => {
			const ratio = image.naturalWidth && image.naturalHeight ? image.naturalWidth / image.naturalHeight : 0.707
			setPixelWidth(Math.min(viewer.clientWidth - 48, (viewer.clientHeight - 48) * ratio), 'Ganze Seite')
		}
		const applyZoom = () => setPixelWidth((image.naturalWidth || 1200) * zoom / 100, `${zoom} %`)
		modal.querySelector('[data-preview-zoom-out]').onclick = () => { zoom = Math.max(40, zoom - 20); applyZoom() }
		modal.querySelector('[data-preview-zoom-in]').onclick = () => { zoom = Math.min(240, zoom + 20); applyZoom() }
		modal.querySelector('[data-preview-fit-page]').onclick = fitPage
		modal.querySelector('[data-preview-fit-width]').onclick = fitWidth
		modal.querySelector('[data-open-preview-file]').onclick = () => window.open(OC.generateUrl(`/f/${numericFileId}`), '_blank', 'noopener')
		modal.querySelector('[data-close-preview]').onclick = () => modal.remove()
		image.onload = fitWidth
		image.onerror = () => {
			image.hidden = true
			fallback.classList.remove('bp-hidden')
			modal.querySelector('.bp-file-preview-toolbar').hidden = true
			zoomOutput.textContent = 'Keine Vorschau verfügbar'
		}
		if (image.complete) fitWidth()
		viewer.focus()
	}

	async function showOrderDocumentPreview() {
		const isQuote = String(state.currentCase?.masterData?.order_mode || 'A').toUpperCase() === 'KVA'
		const definitions = isQuote
			? [{ key: 'BESTATTUNGSAUFTRAG', title: 'Kostenvoranschlag' }]
			: [{ key: 'BESTATTUNGSAUFTRAG', title: 'Bestattungsauftrag' }, { key: 'BESTATTUNGSVOLLMACHT', title: 'Bestattungsvollmacht' }]
		const packageTitle = isQuote ? 'Kostenvoranschlag prüfen' : 'Auftrag und Vollmacht prüfen'
		const hasCurrentDraft = () => (state.records.document || []).some((record) => record.caseId === state.currentCase.id && record.status === 'ENTWURF' && definitions.some((entry) => entry.key === record.data?.templateKey))
		root.insertAdjacentHTML('beforeend', `<div class="bp-modal" role="dialog" aria-modal="true" aria-labelledby="bp-order-preview-title"><section class="bp-wide-modal bp-order-preview"><div class="bp-panel-head bp-file-preview-head"><div><p class="bp-eyebrow">Nebenwirkungsfreie PDF-Vorschau</p><h2 id="bp-order-preview-title">${esc(packageTitle)}</h2><p class="bp-muted">Die Vorschau legt keine Datei und keinen Dokumentdatensatz in der Fallakte an.</p></div><button type="button" class="bp-secondary" data-close-order-preview>Schließen</button></div><div class="bp-order-preview-tabs" role="tablist" aria-label="Dokumente des Pakets">${definitions.map((entry, index) => `<button type="button" role="tab" aria-selected="${index === 0 ? 'true' : 'false'}" data-order-preview-tab="${esc(entry.key)}" ${index === 0 ? '' : 'tabindex="-1"'}>${esc(entry.title)}</button>`).join('')}</div><div class="bp-order-preview-stage" aria-busy="true"><p class="bp-empty" data-order-preview-loading>PDF-Vorschau wird erzeugt …</p><iframe title="${esc(definitions[0].title)}" data-order-preview-frame hidden></iframe></div><p class="bp-save-state" data-order-preview-state aria-live="polite"></p><div class="bp-modal-actions"><button type="button" class="bp-secondary" data-open-order-preview disabled>PDF öffnen / drucken</button><button type="button" class="bp-primary" data-create-order-draft disabled>${hasCurrentDraft() ? 'Entwurfspaket aktualisieren' : 'Entwurfspaket erzeugen'}</button></div></section></div>`)
		const modal = [...root.querySelectorAll('.bp-modal')].pop()
		const frame = modal.querySelector('[data-order-preview-frame]')
		const stage = modal.querySelector('.bp-order-preview-stage')
		const status = modal.querySelector('[data-order-preview-state]')
		const openButton = modal.querySelector('[data-open-order-preview]')
		const createButton = modal.querySelector('[data-create-order-draft]')
		const tabs = [...modal.querySelectorAll('[data-order-preview-tab]')]
		const previews = new Map()
		let activeKey = definitions[0].key
		let closed = false
		const close = () => {
			closed = true
			for (const preview of previews.values()) URL.revokeObjectURL(preview.url)
			modal.remove()
		}
		const show = (key) => {
			const preview = previews.get(key)
			if (!preview) return
			activeKey = key
			frame.src = preview.url
			frame.title = preview.title
			frame.hidden = false
			tabs.forEach((tab) => {
				const selected = tab.dataset.orderPreviewTab === key
				tab.setAttribute('aria-selected', String(selected))
				tab.tabIndex = selected ? 0 : -1
			})
		}
		modal.querySelector('[data-close-order-preview]').onclick = close
		tabs.forEach((tab) => tab.onclick = () => show(tab.dataset.orderPreviewTab))
		openButton.onclick = () => {
			const preview = previews.get(activeKey)
			if (preview) window.open(preview.url, '_blank', 'noopener')
		}
		createButton.onclick = async () => {
			if (hasCurrentDraft() && !await confirmAction('Der vorhandene Arbeitsentwurf wird erst nach vollständig erfolgreicher Erzeugung des neuen Pakets ersetzt. Fortfahren?', { title: 'Entwurfspaket aktualisieren', confirmLabel: 'Entwurf aktualisieren' })) return
			createButton.disabled = true
			openButton.disabled = true
			status.textContent = 'Entwurfspaket wird vollständig erzeugt und gespeichert …'
			status.dataset.state = 'saving'
			try {
				const generated = await api(`${apiBase}/cases/${state.currentCase.id}/order-documents`, { method: 'POST', feedback: false })
				await Promise.all([loadRecordType('document', state.currentCase.id), loadCaseFiles(state.currentCase.id)])
				status.textContent = `${(generated.files || []).length} Dokument(e) wurden als gemeinsames Entwurfspaket gespeichert.`
				status.dataset.state = 'saved'
				createButton.textContent = 'Entwurfspaket aktualisieren'
				if ((generated.cleanupWarnings || []).length) notifyWarning('Das neue Entwurfspaket wurde gespeichert; ältere Entwurfsdateien konnten teilweise nicht bereinigt werden.')
				else notifySuccess(isQuote ? 'Der KVA-Entwurf wurde atomar erzeugt.' : 'Auftrag und Vollmacht wurden atomar als Entwurfspaket erzeugt.')
			} catch (error) {
				status.textContent = error.message
				status.dataset.state = 'error'
			} finally {
				if (!closed) { createButton.disabled = false; openButton.disabled = false }
			}
		}
		try {
			for (const definition of definitions) {
				const response = await fetch(`${apiBase}/cases/${state.currentCase.id}/order-documents/${encodeURIComponent(definition.key)}/preview.pdf`, { credentials: 'same-origin', headers: { requesttoken: OC.requestToken } })
				if (!response.ok) {
					const text = await response.text()
					let message = ''
					try { message = JSON.parse(text)?.message || '' } catch (_) { message = text.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim() }
					throw new Error(message || `PDF-Vorschau fehlgeschlagen (HTTP ${response.status}).`)
				}
				const blob = await response.blob()
				if (!String(blob.type || '').includes('pdf')) throw new Error('Der Server hat keine PDF-Vorschau geliefert.')
				previews.set(definition.key, { ...definition, url: URL.createObjectURL(blob) })
			}
			if (closed) return
			modal.querySelector('[data-order-preview-loading]')?.remove()
			stage.setAttribute('aria-busy', 'false')
			show(activeKey)
			openButton.disabled = false
			createButton.disabled = false
			status.textContent = 'Vorschau vollständig. Es wurde noch kein Dokument in der Fallakte angelegt.'
			status.dataset.state = 'saved'
		} catch (error) {
			if (closed) return
			stage.setAttribute('aria-busy', 'false')
			stage.innerHTML = `<div class="bp-error"><b>Die PDF-Vorschau konnte nicht erzeugt werden.</b><br>${esc(error.message)}</div>`
			status.textContent = 'Es wurde kein Dokument angelegt.'
			status.dataset.state = 'error'
		}
	}

	async function showDocumentDialog(key, scheduleId = 0) {
		const query = scheduleId > 0 ? `?scheduleId=${encodeURIComponent(scheduleId)}` : ''
		const preview = await api(`${documentUrl(state.currentCase.id, key)}/preview${query}`)
		const event = preview.summary.funeralEvent
		const eventTime = event?.date
			? `${event.date}${event.time ? ` · ${event.time}${event.endTime ? `–${event.endTime}` : ''} Uhr` : ''}`
			: 'Noch keine bestätigte Trauerfeier ausgewählt'
		const contextRows = preview.requiresFuneralEvent
			? `<div><dt>Trauerfeier</dt><dd>${esc(eventTime)}</dd></div>${event?.location ? `<div><dt>Ort</dt><dd>${esc(event.location)}</dd></div>` : ''}`
			: `<div><dt>Auftragssumme</dt><dd>${esc(preview.summary.grossTotal)}</dd></div>`
		const uniqueSchedules = [...new Map((preview.scheduleOptions || []).map((item) => [Number(item.id), item])).values()]
		const scheduleOptions = uniqueSchedules.map((item) => `<option value="${item.id}" ${Number(item.id) === Number(preview.selectedScheduleId) ? 'selected' : ''}>${esc(`${item.title} · ${item.displayDate} · ${item.displayTime} Uhr${item.location ? ` · ${item.location}` : ''}`)}</option>`).join('')
		const scheduleSelection = preview.requiresFuneralEvent && scheduleOptions
			? `<label><span>Verknüpfte Trauerfeier</span><select name="scheduleId" required>${uniqueSchedules.length > 1 && !preview.selectedScheduleId ? '<option value="">Bitte auswählen</option>' : ''}${scheduleOptions}</select><small>Datum, Uhrzeit und Ort werden aus dem bestätigten Termin in das Dokument übernommen.</small></label>`
			: ''
		root.insertAdjacentHTML('beforeend', `<div class="bp-modal"><form class="bp-wide-modal" id="document-generate-form"><div class="bp-panel-head"><div><p class="bp-eyebrow">Dokumentvorschau</p><h2>${esc(preview.title)}</h2></div><button type="button" class="bp-secondary" data-close-modal>Schließen</button></div>${scheduleSelection}${preview.missingFields.length ? `<div class="bp-error">Fehlend: ${preview.missingFields.map(esc).join(', ')}</div>` : '<div class="bp-success">Alle Pflichtangaben vorhanden.</div>'}<div class="bp-paper-preview"><div class="bp-paper-head"><b>${esc(preview.title)}</b><span>Fall ${esc(preview.summary.caseNumber)}</span></div><h3>${esc(preview.summary.deceased)}</h3><p>${esc(preview.summary.death)}</p><dl><div><dt>Dokumentenart</dt><dd>${esc(preview.documentType || 'Allgemein')}</dd></div>${contextRows}<div><dt>Ablage</dt><dd>${esc(preview.outputSubfolder)} / ${esc(preview.fileName)}</dd></div></dl><p class="bp-muted">Die bildgenaue Seitenvorschau steht nach der Erzeugung über das Vorschaubild der Ausgabe bereit.</p></div><label class="bp-inline-check"><input type="checkbox" name="createPdf" ${preview.supportsPdf ? 'checked' : 'disabled'}> PDF zusätzlich erzeugen</label>${preview.pdfMessage ? `<p class="bp-muted">${esc(preview.pdfMessage)}</p>` : ''}<label>Status<select name="documentStatus"><option value="ENTWURF">Entwurf – in Nextcloud Office bearbeitbar</option><option value="FINAL" ${preview.ready ? '' : 'disabled'}>Final – nur als PDF, nicht bearbeitbar</option><option value="UNTERSCHRIEBEN" ${preview.ready ? '' : 'disabled'}>Unterschrieben – nur als PDF</option></select></label>${preview.ready ? '' : '<label class="bp-inline-check"><input type="checkbox" name="allowIncomplete"> Unvollständigen Entwurf erzeugen</label>'}<p class="bp-save-state" aria-live="polite"></p><div class="bp-modal-actions"><button class="bp-primary">Aktuelle Ausgabe erzeugen</button></div></form></div>`)
		const modal = [...root.querySelectorAll('.bp-modal')].pop()
		const form = modal.querySelector('form')
		modal.querySelector('[data-close-modal]').onclick = () => modal.remove()
		form.elements.scheduleId?.addEventListener('change', () => {
			const selectedId = Number(form.elements.scheduleId.value || 0)
			modal.remove()
			showDocumentDialog(key, selectedId)
		})
		form.elements.documentStatus.onchange = () => {
			if (['FINAL', 'UNTERSCHRIEBEN'].includes(form.elements.documentStatus.value) && form.elements.createPdf) {
				form.elements.createPdf.checked = true
				form.elements.createPdf.disabled = true
			} else if (form.elements.createPdf) form.elements.createPdf.disabled = !preview.supportsPdf
		}
		form.onsubmit = async (event) => {
			event.preventDefault()
			const status = form.querySelector('.bp-save-state')
			try {
				await api(`${documentUrl(state.currentCase.id, key)}/generate`, { method: 'POST', body: new URLSearchParams({
					documentStatus: form.elements.documentStatus.value,
					createPdf: form.elements.createPdf?.checked ? 'true' : 'false',
					allowIncomplete: form.elements.allowIncomplete?.checked ? 'true' : 'false',
					scheduleId: String(form.elements.scheduleId?.value || preview.selectedScheduleId || 0),
				}) })
				await Promise.all([loadRecordType('document', state.currentCase.id), loadCaseFiles(state.currentCase.id)])
				modal.remove()
				render()
			} catch (error) {
				status.textContent = error.message
				status.dataset.state = 'error'
			}
		}
	}

	function caseDetail() {
		const item = state.currentCase
		const holdSetAt = item.retentionHoldSetAt ? new Date(item.retentionHoldSetAt).toLocaleString('de-DE') : 'nicht dokumentiert'
		const holdReviewAt = item.retentionHoldReviewAt ? new Date(`${item.retentionHoldReviewAt}T00:00:00`).toLocaleDateString('de-DE') : 'kein Überprüfungstermin'
		const holdTitle = item.retentionHold ? `Grund: ${item.retentionHoldReason || 'nicht dokumentiert'} · Verantwortlich: ${item.retentionHoldResponsible || 'nicht dokumentiert'} · Gesetzt: ${holdSetAt} · Prüfung: ${holdReviewAt}` : ''
		const data = { ...(item.masterData || {}), first_name: item.firstName, last_name: item.lastName, date_of_death: item.dateOfDeath, funeral_type: item.funeralType, branch: item.branch, responsible_employee: item.responsibleEmployee, status: item.status }
		const tabs = [['overview', 'Übersicht'], ['master', 'Stammdaten'], ['order', 'Auftrag / KVA'], ['side-orders', 'Nebenaufträge'], ['contact', 'Kontakte'], ['task', 'Aufgaben'], ['schedule', 'Termine / Fristen'], ['deregistration', 'Abmeldungen'], ['document', 'Dokumente'], ['services', 'Leistungen'], ['finances', 'Finanzen'], ['history', 'Fall-Verlauf']]
		let body = caseOverview()
		if (state.caseTab === 'master') body = masterDataForm(data)
		else if (state.caseTab === 'order') body = orderPanel(data)
		else if (state.caseTab === 'side-orders') body = sideOrdersPanel()
		else if (state.caseTab === 'services') body = serviceSelectionPanel()
		else if (state.caseTab === 'task') body = `${checklistPanel()}${recordsView('task', item.id)}`
		else if (state.caseTab === 'contact') body = caseContactsPanel()
		else if (state.caseTab === 'deregistration') body = deregistrationPanel()
		else if (state.caseTab === 'document') body = documentPanel()
		else if (state.caseTab === 'finances') body = financesPanel()
		else if (state.caseTab === 'schedule') body = recordsView(state.caseTab, item.id)
		else if (state.caseTab === 'history') body = recordsView('activity', item.id)
		else if (!['overview', 'master', 'task', 'history'].includes(state.caseTab)) body = `<section class="bp-panel bp-placeholder"><h3>${esc(tabs.find((tab) => tab[0] === state.caseTab)?.[1] || state.caseTab)}</h3><p>Dieser fallbezogene Arbeitsbereich ist vorbereitet.</p></section>`
		return `<div class="bp-page-head"><div><p class="bp-eyebrow">Fallakte ${esc(item.caseNumber)}</p><h2>${esc(item.lastName)}, ${esc(item.firstName)}</h2>${item.retentionHold?`<span class="bp-status" title="${esc(holdTitle)}" aria-label="Aufbewahrung gesperrt. ${esc(holdTitle)}">Aufbewahrung gesperrt</span><small class="bp-hold-summary">Verantwortlich: ${esc(item.retentionHoldResponsible || '–')} · Prüfung: ${esc(holdReviewAt)}</small>`:''}</div><div class="bp-head-actions"><button class="bp-secondary" id="export-case" title="Alle strukturierten Daten dieses Falls als Exportdatei herunterladen.">Falldaten exportieren</button>${state.team.isBestatterAdmin?`<button class="bp-secondary" id="toggle-retention-hold" title="${item.retentionHold?'Die Löschsperre nach Angabe eines Grundes aufheben.':'Den Fall vor Löschung und Anonymisierung schützen.'}">${item.retentionHold?'Legal Hold aufheben':'Legal Hold setzen'}</button>`:''}<button class="bp-secondary" data-view="cases" title="Zur Fallübersicht zurückkehren.">Zurück zu Fällen</button>${state.team.isBestatterAdmin ? '<button class="bp-danger" id="delete-case" title="Diesen Fall nach Sicherheitsabfrage endgültig löschen.">Fall löschen</button>' : ''}</div></div><div class="bp-tabs bp-case-tabs">${tabs.map((tab) => `<button class="${state.caseTab === tab[0] ? 'active' : ''}" data-case-tab="${tab[0]}" title="Arbeitsbereich ${esc(tab[1])} öffnen.">${tab[1]}</button>`).join('')}</div>${body}`
	}

	function showRetentionHoldDialog() {
		const hold = !state.currentCase.retentionHold
		const members = (state.team.members || []).map((member) => `<option value="${esc(member.uid)}" ${member.uid === state.team.currentUid ? 'selected' : ''}>${esc(member.displayName)} (${esc(member.uid)})</option>`).join('')
		const currentDetails = state.currentCase.retentionHold ? `<aside class="bp-compliance-note"><b>Aktiver Legal Hold:</b> ${esc(state.currentCase.retentionHoldReason || 'Kein Grund im Altbestand dokumentiert.')}<br>Verantwortlich: ${esc(state.currentCase.retentionHoldResponsible || '–')} · Überprüfung: ${esc(state.currentCase.retentionHoldReviewAt || 'nicht terminiert')}</aside>` : ''
		root.insertAdjacentHTML('beforeend', `<div class="bp-modal" role="dialog" aria-modal="true" aria-labelledby="retention-hold-title"><form id="retention-hold-form" class="bp-modal-card"><div class="bp-panel-head"><div><p class="bp-eyebrow">Aufbewahrung und Löschung</p><h2 id="retention-hold-title">Legal Hold ${hold ? 'setzen' : 'aufheben'}</h2><p class="bp-muted">${hold ? 'Die Löschsperre verhindert eine Löschung oder Anonymisierung des Falls.' : 'Das Aufheben gibt den Fall wieder für die geltende Aufbewahrungsrichtlinie frei.'}</p></div></div>${currentDetails}<label><span>${hold ? 'Grund des Legal Holds' : 'Grund für das Aufheben'}</span><textarea name="reason" minlength="5" required aria-describedby="retention-reason-help"></textarea><small id="retention-reason-help">Mindestens fünf Zeichen; die Angabe wird unveränderlich protokolliert.</small></label>${hold ? `<label><span>Verantwortliche Person</span><select name="responsibleUid" required><option value="">Bitte auswählen</option>${members}</select><small>Nur Mitglieder der konfigurierten Bestatter-Gruppe sind zulässig.</small></label><label><span>Setzdatum</span><input value="${esc(new Date().toLocaleString('de-DE'))}" readonly><small>Der Server protokolliert den verbindlichen Zeitpunkt.</small></label><label><span>Überprüfung am (optional)</span><input type="date" name="reviewAt" min="${new Date().toISOString().slice(0, 10)}"><small>Erinnert an die fachliche Neubewertung; der Legal Hold endet nicht automatisch.</small></label>` : ''}<p class="bp-save-state" aria-live="polite"></p><div class="bp-modal-actions"><button type="button" class="bp-secondary" data-close-modal title="Dialog ohne Änderung schließen.">Abbrechen</button><button class="${hold ? 'bp-primary' : 'bp-danger'}" title="${hold ? 'Löschsperre mit diesen Angaben aktivieren.' : 'Löschsperre nach Protokollierung aufheben.'}">${hold ? 'Legal Hold setzen' : 'Legal Hold aufheben'}</button></div></form></div>`)
		const modal = [...root.querySelectorAll('.bp-modal')].pop()
		const form = modal.querySelector('form')
		modal.querySelector('[data-close-modal]').onclick = () => modal.remove()
		form.onsubmit = async (event) => {
			event.preventDefault()
			const status = form.querySelector('.bp-save-state')
			status.textContent = hold ? 'Legal Hold wird gesetzt …' : 'Legal Hold wird aufgehoben …'
			status.dataset.state = 'saving'
			try {
				await api(`${apiBase}/cases/${state.currentCase.id}/retention`, { method: 'PUT', body: new URLSearchParams({ hold: hold ? '1' : '0', reason: form.elements.reason.value, responsibleUid: form.elements.responsibleUid?.value || '', reviewAt: form.elements.reviewAt?.value || '' }) })
				state.currentCase = await api(`${ctx.urls.cases}/${state.currentCase.id}`)
				modal.remove()
				notifySuccess(hold ? 'Legal Hold wurde gesetzt und protokolliert.' : 'Legal Hold wurde aufgehoben und protokolliert.')
				render()
			} catch (error) {
				status.textContent = error.message
				status.dataset.state = 'error'
			}
		}
		form.elements.reason.focus()
	}


	return { caseContactsPanel, showCaseContactForm, expandDeregistration, deliveryChannels, showTextPreview, showLetterPreview, deregistrationPanel, showDeregistrationForm, showDeregistrationTransitionForm, documentPanel, showFilePreview, showOrderDocumentPreview, showDocumentDialog, caseDetail, showRetentionHoldDialog }
}
