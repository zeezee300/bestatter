export function createAssistantModule(ctx) {
	const { state } = ctx
	const esc = (...args) => ctx.esc(...args)
	const api = (...args) => ctx.api(...args)
	const render = (...args) => ctx.render(...args)
	const notifyError = (...args) => ctx.notifyError(...args)
	const notifySuccess = (...args) => ctx.notifySuccess(...args)
	const notifyWarning = (...args) => ctx.notifyWarning(...args)
	let recorder = null
	let recorderStream = null
	let chunks = []

	const capture = () => state.assistantCapture
	const fieldLabels = {
		salutation: 'Anrede', title: 'Titel', first_name: 'Vorname', last_name: 'Nachname', birth_name: 'Geburtsname', date_of_birth: 'Geburtsdatum', date_of_death: 'Sterbedatum',
		place_of_death: 'Sterbeort / Einrichtung', birth_place: 'Geburtsort', birth_registry_office: 'Geburtsstandesamt', profession: 'Beruf', pension_insurance_number: 'Postrentennummer', last_residence_city: 'Letzter Wohnort',
		last_residence: 'Straße / Hausnummer', last_residence_postal_code: 'PLZ', civil_status: 'Familienstand', spouse_first_name: 'Vorname Ehepartner/in', spouse_last_name: 'Nachname Ehepartner/in', spouse_date_of_birth: 'Geburtsdatum Ehepartner/in', spouse_birth_place: 'Geburtsort Ehepartner/in', spouse_residence: 'Wohnort Ehepartner/in', spouse_date_of_death: 'Todesdatum Ehepartner/in (falls vorverstorben)', spouse_death_place: 'Sterbeort Ehepartner/in',
		marriage_date: 'Datum der Eheschließung', marriage_place: 'Ort der Eheschließung', partnership_date: 'Datum der Begründung der Lebenspartnerschaft', divorce_date: 'Datum der Scheidung / Aufhebung', religion: 'Religion', cemetery_contact: 'Friedhof',
		funeral_type: 'Bestattungsart', order_client_relation: 'Beziehung Auftraggeber/in', order_client_first_name: 'Vorname Auftraggeber/in', order_client_name: 'Nachname Auftraggeber/in', order_client_mobile: 'Mobilfunknummer Auftraggeber/in', certificate_free_count: 'Sterbeurkunden gebührenfrei', certificate_paid_count: 'Sterbeurkunden gebührenpflichtig', branch: 'Niederlassung', responsible_employee: 'Zuständiger Mitarbeiter', notes: 'Gesprächsnotiz',
	}

	function guidedCaptureView() {
		const data = capture().data
		const config = state.assistantConfiguration || {}
		const step = capture().step
		const steps = [['1', 'Gespräch'], ['2', 'Angaben'], ['3', 'Prüfen']]
		return `<div class="bp-page-head"><div><p class="bp-eyebrow">Aufnahmeassistent</p><h2>Sterbefall schnell erfassen</h2><p>Alle Vorschläge bleiben unverbindlich, bis sie im letzten Schritt ausdrücklich gespeichert werden.</p></div><button type="button" class="bp-secondary" id="capture-reset">Entwurf verwerfen</button></div>
			<nav class="bp-capture-steps" aria-label="Erfassungsschritte">${steps.map(([number, label], index) => `<button type="button" data-capture-step="${number}" class="${Number(step) === Number(number) ? 'active' : ''}" ${index + 1 > capture().maxStep ? 'disabled' : ''}><b>${number}</b><span>${label}</span></button>`).join('')}</nav>
			${step === 1 ? captureConversation(config) : step === 2 ? captureFields(data) : captureReview(data)}`
	}

	function captureConversation(config) {
		const speechAvailable = Boolean(config.providers?.speechToText && config.speechProviderApproved)
		const recording = Boolean(capture().recording)
		const speechStatus = !config.providers?.speechToText ? 'Spracherkennung noch nicht eingerichtet' : (!config.speechProviderApproved ? 'Provider wartet auf administrative Freigabe' : 'Spracherkennung verfügbar')
		const transcription = capture().transcriptionProgress || {}
		const progressValue = Number.isFinite(Number(transcription.progress)) ? ` value="${Math.max(0, Math.min(100, Number(transcription.progress)))}"` : ''
		const delayed = transcription.status === 'delayed' && Number(capture().transcriptionTaskId || 0) > 0
		const scan = capture().scanImport || {}
		const scanProgress = ['OCR_QUEUED', 'OCR_PROCESSING'].includes(scan.status)
		return `<section class="bp-panel bp-capture-panel"><div class="bp-panel-head"><div><p class="bp-eyebrow">Schritt 1</p><h3>Gespräch oder Notiz erfassen</h3></div><span class="bp-status ${speechAvailable ? '' : 'bp-muted'}">${speechStatus}</span></div>
			<label class="bp-capture-text"><span>Gesprächstext</span><textarea id="capture-transcript" rows="10" placeholder="Beispiel: Sterbefall Maria Muster, geboren am 12. März 1942, verstorben heute im Klinikum Bremen. Gewünscht ist eine Feuerbestattung …">${esc(capture().transcript)}</textarea></label>
			<div class="bp-speech-actions">
				<button type="button" class="bp-secondary ${recording ? 'recording' : ''}" id="capture-record" aria-pressed="${recording ? 'true' : 'false'}" title="${recording ? '■ Aufnahme beenden' : 'Audioaufnahme starten'}" ${speechAvailable ? '' : 'disabled'}><span class="bp-record-control-icon" aria-hidden="true">${recording ? '■' : '●'}</span><span>${recording ? 'Aufnahme jetzt stoppen' : 'Audioaufnahme starten'}</span></button>
				<button type="button" class="bp-secondary" id="capture-audio-select" ${speechAvailable && !recording ? '' : 'disabled'}>Audiodatei auswählen</button>
				<input type="file" id="capture-audio-file" accept="audio/*,video/webm" hidden>
				<span id="capture-speech-state" role="status" aria-live="assertive">${recording ? '<b class="bp-recording-indicator">Aufnahme läuft</b> · Zum Stoppen erneut „Aufnahme beenden“ wählen.' : (speechAvailable ? 'Bereit zur Aufnahme. Audio wird nach der Transkription automatisch gelöscht.' : `${speechStatus}. Texteingabe und Schnellerfassung sind vollständig nutzbar.`)}</span>
			</div>
			<div id="capture-speech-progress" class="bp-speech-progress" data-state="${esc(transcription.status || 'idle')}" ${transcription.visible ? '' : 'hidden'}>
				<progress id="capture-speech-progress-bar" max="100"${progressValue} aria-describedby="capture-speech-progress-label"></progress>
				<span id="capture-speech-progress-label" role="status" aria-live="polite">${esc(transcription.label || '')}</span>
				${delayed ? '<button type="button" class="bp-secondary" id="capture-transcription-retry">Status erneut prüfen</button>' : ''}
			</div>
			<section class="bp-capture-scan" aria-labelledby="capture-scan-heading"><div><h4 id="capture-scan-heading">Alternativ: Auftragserfassungsbogen einlesen</h4><p>PDF sicher in Nextcloud zwischenspeichern, optional durch Paperless erkennen lassen und jeden Feldvorschlag selbst bestätigen.</p></div>
				<div class="bp-row-actions"><button type="button" class="bp-secondary" id="capture-scan-select" ${scanProgress ? 'disabled' : ''}>PDF-Erfassungsbogen auswählen</button><input type="file" id="capture-scan-file" accept="application/pdf,.pdf" hidden>${scan.id && ['OCR_QUEUED','OCR_PROCESSING','OCR_DELAYED'].includes(scan.status) ? '<button type="button" class="bp-secondary" id="capture-scan-check">Status prüfen</button>' : ''}${scan.id && ['OCR_FAILED','REVIEW_REQUIRED','MANUAL_REVIEW'].includes(scan.status) ? '<button type="button" class="bp-secondary" id="capture-scan-retry">OCR erneut versuchen</button>' : ''}</div>
				${scan.id ? `<div class="bp-scan-state" data-state="${esc(scan.status)}"><progress max="100"${scanProgress ? '' : ` value="${scan.status === 'EXTRACTION_READY' ? 100 : 0}"`}></progress><span role="status" aria-live="polite"><b>${esc(scan.originalName || 'Erfassungsbogen')}</b> · ${esc(scanStatusLabel(scan.status))}${scan.errorMessage ? ` · ${esc(scan.errorMessage)}` : ''}</span></div>` : ''}
				<small>Ohne Paperless bleibt der Scan sicher erhalten; die manuelle und sprachgestützte Erfassung funktioniert uneingeschränkt.</small>
			</section>
			<div class="bp-capture-actions"><span></span><button type="button" class="bp-primary" id="capture-analyze">Text auswerten und weiter</button></div>
		</section>`
	}

	function captureFields(data) {
		const suggestions = capture().suggestions || []
		const questions = capture().questions || []
		const conflicts = capture().conflicts || []
		const suggestionHtml = suggestions.length ? `<section class="bp-capture-suggestions"><h4>Erkannte Angaben</h4>${suggestions.map((item, index) => `<label><input type="checkbox" data-suggestion="${index}" checked><span><b>${esc(item.label)}</b> ${esc(item.value)}<small>${Math.round(Number(item.confidence || 0) * 100)} % · „${esc(item.source)}“</small></span></label>`).join('')}<button type="button" class="bp-secondary" id="capture-apply-suggestions">Ausgewählte Vorschläge übernehmen</button></section>` : '<p class="bp-warning">Es wurden keine eindeutigen Angaben erkannt. Die Felder können manuell ausgefüllt werden.</p>'
		const branchOptions = (state.branches || []).filter((item) => item.active).map((item) => `<option value="${esc(item.key)}" ${data.branch === item.key ? 'selected' : ''}>${esc(item.name)}</option>`).join('')
		const memberOptions = (state.team.members || []).map((item) => `<option value="${esc(item.uid)}" ${data.responsible_employee === item.uid ? 'selected' : ''}>${esc(item.displayName)} (${esc(item.uid)})</option>`).join('')
		const scan = capture().scanImport || {}
		const scanPreview = scan.id ? `<aside class="bp-capture-original"><h4>Originalscan</h4><img src="${esc(OC.generateUrl(`/core/preview?fileId=${scan.fileId}&x=1100&y=1500&a=1`))}" alt="Vorschau des hochgeladenen Auftragserfassungsbogens"><button type="button" class="bp-secondary" id="capture-scan-open">Original groß öffnen</button><small>Die OCR-Markierungen sind Vorschläge. Maßgeblich ist der sichtbare Originalscan.</small></aside>` : ''
		return `<section class="bp-panel bp-capture-panel"><div class="bp-panel-head"><div><p class="bp-eyebrow">Schritt 2</p><h3>Erkannte Angaben prüfen und ergänzen</h3></div></div><div class="${scan.id ? 'bp-capture-split' : ''}">${scanPreview}<div class="bp-capture-fields-side">${suggestionHtml}${conflicts.length ? `<section class="bp-capture-warnings"><h4>Widersprüchliche Angaben</h4>${conflicts.map((item) => `<p><b>${esc(item.question)}</b> ${item.values.map((value) => esc(value)).join(' / ')}</p>`).join('')}</section>` : ''}${questions.length ? `<section class="bp-capture-questions"><h4>Gezielte Rückfragen</h4><ul>${questions.map((item) => `<li><a href="#" data-focus-capture-field="${esc(item.field)}">${esc(item.question)}</a></li>`).join('')}</ul></section>` : ''}
			<form id="capture-fields" class="bp-form-grid">
				${captureField('salutation')}${captureField('title')}${captureField('first_name', 'text', true)}${captureField('last_name', 'text', true)}${captureField('birth_name')}
				${captureField('date_of_birth', 'date')}${captureField('date_of_death', 'date')}
				${captureField('birth_place')}${captureField('birth_registry_office')}${captureField('profession')}${captureField('pension_insurance_number')}${captureField('place_of_death')}${captureField('civil_status')}${captureField('spouse_first_name')}${captureField('spouse_last_name')}${captureField('spouse_date_of_birth', 'date')}${captureField('spouse_birth_place')}${captureField('spouse_residence')}${captureField('spouse_date_of_death', 'date')}${captureField('spouse_death_place')}${captureField('marriage_date', 'date')}${captureField('marriage_place')}${captureField('partnership_date', 'date')}${captureField('divorce_date', 'date')}${captureField('religion')}
				${captureField('last_residence')}${captureField('last_residence_postal_code')}${captureField('last_residence_city')}${captureField('cemetery_contact')}
				${captureField('order_client_relation')}${captureField('order_client_first_name')}${captureField('order_client_name')}${captureField('order_client_mobile', 'tel')}${captureField('certificate_free_count', 'number')}${captureField('certificate_paid_count', 'number')}
				<label><span>Bestattungsart</span><select name="funeral_type"><option value=""></option><option value="FEUERBESTATTUNG" ${data.funeral_type === 'FEUERBESTATTUNG' ? 'selected' : ''}>Feuerbestattung</option><option value="ERDBESTATTUNG" ${data.funeral_type === 'ERDBESTATTUNG' ? 'selected' : ''}>Erdbestattung</option><option value="UEBERFUEHRUNG" ${data.funeral_type === 'UEBERFUEHRUNG' ? 'selected' : ''}>Überführung</option></select></label>
				<label><span>Niederlassung</span><select name="branch"><option value=""></option>${branchOptions}</select></label>
				<label><span>Zuständiger Mitarbeiter</span><select name="responsible_employee"><option value=""></option>${memberOptions}</select></label>
				<label class="bp-span-2"><span>Interne Gesprächsnotiz</span><textarea name="notes" rows="4">${esc(data.notes || capture().transcript)}</textarea></label>
			</form>
			${(capture().warnings || []).length ? `<ul class="bp-capture-warnings">${capture().warnings.map((warning) => `<li>${esc(warning)}</li>`).join('')}</ul>` : ''}</div></div>
			<div class="bp-capture-actions"><button type="button" class="bp-secondary" data-capture-step="1">Zurück</button><button type="button" class="bp-primary" id="capture-review">Zur Prüfung</button></div>
		</section>`
	}

	function captureField(name, type = 'text', required = false) {
		return `<label><span>${esc(fieldLabels[name] || name)}${required ? ' *' : ''}</span><input name="${name}" type="${type}" value="${esc(capture().data[name] || '')}" ${required ? 'required' : ''}></label>`
	}

	function captureReview(data) {
		const rows = Object.entries(data).filter(([, value]) => String(value || '').trim() !== '').map(([key, value]) => `<div><dt>${esc(fieldLabels[key] || key)}</dt><dd>${esc(value)}</dd></div>`).join('')
		return `<section class="bp-panel bp-capture-panel"><div class="bp-panel-head"><div><p class="bp-eyebrow">Schritt 3</p><h3>Entwurf verbindlich anlegen</h3></div><span class="bp-status">Noch nicht gespeichert</span></div>
			<p>Bitte kontrolliere besonders Namen, Datumsangaben, Bestattungsart, Niederlassung und Zuständigkeit. Der Assistent legt erst nach dem folgenden Klick einen Fall an.</p>
			<dl class="bp-capture-review">${rows || '<div><dd>Keine Angaben vorhanden.</dd></div>'}</dl>
			<div class="bp-capture-actions"><button type="button" class="bp-secondary" data-capture-step="2">Zurück</button><button type="button" class="bp-primary" id="capture-create-case" ${data.first_name && data.last_name ? '' : 'disabled'}>Geprüften Fall anlegen</button></div>
		</section>`
	}

	function assistantDocumentCards(documents = []) {
		if (!documents.length) return ''
		return `<div class="bp-assistant-documents"><h3>Dokumentausgaben</h3>${documents.map((document) => `<article><div><b>${esc(document.title || 'Dokument')}</b><small>${esc(document.path || '05 Trauerdruck')}</small>${document.pdfWarning ? `<small class="bp-warning">${esc(document.pdfWarning)}</small>` : ''}</div><div class="bp-row-actions">${document.previewFileId ? `<button type="button" class="bp-secondary assistant-preview-file" data-file-id="${Number(document.previewFileId)}">Vorschau</button>` : ''}${document.docxFileId ? `<button type="button" class="bp-secondary assistant-open-file" data-file-id="${Number(document.docxFileId)}">DOCX öffnen</button>` : ''}${document.pdfFileId ? `<button type="button" class="bp-primary assistant-print-file" data-file-id="${Number(document.pdfFileId)}">PDF öffnen / drucken</button>` : ''}</div></article>`).join('')}<small>Der Browser-Druckdialog bleibt die abschließende Druckfreigabe.</small></div>`
	}

	function assistantChoices(choices = []) {
		if (!choices.length) return ''
		return `<div class="bp-assistant-choices"><h3>Auswahl</h3>${choices.map((choice) => {
			const data = choice.data || {}
			if (choice.kind === 'document') return `<article><div><b>${esc(choice.label)}</b><span>${esc(choice.description || '')}</span></div>${assistantDocumentCards(choice.documents || [])}${choice.confirmationToken ? `<button type="button" class="bp-primary assistant-intent-choice" data-confirmation-token="${esc(choice.confirmationToken)}">${esc(choice.actionLabel || 'Geprüft erzeugen')}</button>` : `<button type="button" class="bp-secondary" disabled>${esc(choice.actionLabel || 'Nicht verfügbar')}</button>`}</article>`
			if (choice.kind === 'navigation') return `<article><div><b>${esc(choice.label)}</b><span>${esc(choice.description || '')}</span></div><button type="button" class="bp-primary assistant-intent-choice" data-confirmation-token="${esc(choice.confirmationToken || '')}">Öffnen</button></article>`
			const duplicateText = data.duplicates?.length ? `Mögliche Dublette: ${data.duplicates.map((item) => item.title).join(', ')}` : ''
			return `<article><div><b>${esc(choice.label)}</b><span>${esc(choice.description || '')}</span><small>Quelle: <a href="${esc(data.sourceUrl || '#')}" target="_blank" rel="noopener noreferrer">${esc(data.sourceName || 'Webrecherche')}</a> · abgerufen ${esc(data.retrievedAt || '')}</small>${data.needsOfficialVerification ? '<small class="bp-warning">Bitte Anschrift anhand der offiziellen Behördenwebsite prüfen.</small>' : ''}${duplicateText ? `<small class="bp-error">${esc(duplicateText)}</small>` : ''}</div><button type="button" class="bp-primary assistant-intent-choice assistant-contact-choice" data-confirmation-token="${esc(choice.confirmationToken || '')}" ${duplicateText ? 'disabled' : ''}>Geprüft ins Adressbuch übernehmen</button></article>`
		}).join('')}</div>`
	}

	function assistantPreview(preview) {
		if (!preview) return '<p class="bp-empty">Stellen Sie eine Frage oder wählen Sie eine Schnellprüfung.</p>'
		const answer = preview.answer
		return `<article class="bp-intent-preview"><b>${esc(answer?.title || preview.label)}</b><span>${esc(answer?.text || preview.message)}</span>${answer?.items?.length ? `<ul>${answer.items.map((item) => `<li>${esc(item)}</li>`).join('')}</ul>` : ''}${preview.requiredInput ? `<p class="bp-assistant-question"><b>Rückfrage:</b> ${esc(preview.requiredInput.question)}</p>` : ''}${assistantChoices(preview.choices)}${assistantDocumentCards(preview.documents)}${preview.writeOperation && !preview.choices?.length && !preview.documents?.length ? `<details><summary>Erkannte Daten prüfen</summary><pre>${esc(JSON.stringify(preview.data, null, 2))}</pre></details><strong>Keine Ausführung ohne gesonderte Bestätigung.</strong>` : ''}${preview.executable ? `<button type="button" class="bp-primary" id="assistant-sidebar-execute">${esc(assistantActionLabel(preview.intent))}</button>` : ''}</article>`
	}

	function assistantActionLabel(intent) {
		if (intent === 'UPDATE_CASE_MASTER_DATA') return 'Geprüfte Falldaten übernehmen'
		if (intent === 'CREATE_TASK_BATCH') return 'Geprüfte Aufgaben anlegen'
		if (intent === 'EXECUTE_WORKFLOW_ACTION') return 'Dokumentpaket geprüft erzeugen'
		return 'Geprüften Entwurf jetzt anlegen'
	}

	function assistantSidebarView() {
		if (!state.assistantConfiguration?.assistantEnabled) return ''
		const side = state.assistantSidebar || { open: false, command: '', caseId: 0, preview: null }
		const selectedCase = Number(side.caseId || state.currentCase?.id || 0)
		const preview = side.preview
		const caseOptions = (state.cases || []).map((item) => `<option value="${item.id}" ${selectedCase === Number(item.id) ? 'selected' : ''}>${esc(item.caseNumber)} · ${esc(item.firstName)} ${esc(item.lastName)}</option>`).join('')
		const selectedCaseData = (state.cases || []).find((item) => Number(item.id) === selectedCase) || (Number(state.currentCase?.id) === selectedCase ? state.currentCase : null)
		const selectedCaseLabel = selectedCaseData ? `${selectedCaseData.caseNumber} · ${selectedCaseData.firstName} ${selectedCaseData.lastName}` : 'Kein Fall ausgewählt'
		return `<button type="button" class="bp-assistant-toggle" id="assistant-sidebar-toggle" aria-controls="assistant-sidebar" aria-expanded="${side.open ? 'true' : 'false'}"><span aria-hidden="true">✦</span><span>Assistent</span></button>
		<aside id="assistant-sidebar" class="bp-assistant-sidebar ${side.open ? 'open' : ''}" aria-hidden="${side.open ? 'false' : 'true'}" aria-label="Bestatter-Assistent">
			<header><div><p class="bp-eyebrow">Fallbezogene Hilfe</p><h2>Bestatter-Assistent</h2></div><button type="button" class="bp-icon-button" id="assistant-sidebar-close" aria-label="Assistent schließen">×</button></header>
			<p class="bp-muted">Funktioniert auch ohne KI-Anbieter. Schreibende Vorschläge werden niemals ohne Ihre ausdrückliche Bestätigung ausgeführt.</p>
			<div class="bp-assistant-quick" aria-label="Schnellfragen"><button type="button" data-assistant-question="Was ist heute fällig?">Mein Arbeitstag</button><button type="button" data-assistant-question="Suche Fall ">Fall suchen</button><button type="button" data-assistant-question="Welche Angaben fehlen in diesem Fall?">Fehlende Angaben</button><button type="button" data-assistant-question="Öffne die Stammdaten dieses Falls">Stammdaten</button><button type="button" data-assistant-question="Öffne die Leistungen dieses Falls">Leistungen</button><button type="button" data-assistant-question="Bereite eine Abmeldung vor">Abmeldung</button><button type="button" data-assistant-question="Ist der Fall für die Schlussrechnung vollständig?">Rechnungsbereitschaft</button><button type="button" data-assistant-question="Welche Dokumentausgaben kann ich für diesen Fall erzeugen?">Dokument ausgeben</button></div>
			<form id="assistant-sidebar-command"><label class="bp-assistant-case-field"><span>Fallbezug</span><select name="caseId" title="${esc(selectedCaseLabel)}"><option value="0">Kein Fall ausgewählt</option>${caseOptions}</select><small aria-live="polite">${esc(selectedCaseLabel)}</small></label><label><span>Frage oder Arbeitsauftrag</span><textarea name="input" rows="4" placeholder="Zum Beispiel: Was ist heute fällig?">${esc(side.command)}</textarea><small>Enter startet die Prüfung · Umschalt+Enter fügt eine neue Zeile ein.</small></label><button class="bp-primary">Prüfen</button></form>
			<div class="bp-assistant-response" role="status" aria-live="polite">${assistantPreview(preview)}</div>
			<footer><small>${state.assistantConfiguration.providers?.textToText ? 'Text-KI verfügbar; fachliche Regeln bleiben führend.' : 'Regelbasierter, lokaler Fallback aktiv.'}</small></footer>
		</aside>`
	}

	function bindAssistant() {
		document.getElementById('assistant-sidebar-toggle')?.addEventListener('click', () => { state.assistantSidebar.open = true; render(); requestAnimationFrame(() => document.querySelector('#assistant-sidebar textarea')?.focus()) })
		document.getElementById('assistant-sidebar-close')?.addEventListener('click', () => { state.assistantSidebar.open = false; render(); requestAnimationFrame(() => document.getElementById('assistant-sidebar-toggle')?.focus()) })
		document.getElementById('assistant-sidebar')?.addEventListener('keydown', (event) => {
			if (event.key === 'Escape') { event.preventDefault(); state.assistantSidebar.open = false; render(); requestAnimationFrame(() => document.getElementById('assistant-sidebar-toggle')?.focus()); return }
			if (event.key !== 'Tab') return
			const focusable = [...event.currentTarget.querySelectorAll('button:not([disabled]),select:not([disabled]),textarea:not([disabled]),input:not([disabled]),a[href]')]
			if (!focusable.length) return
			const first = focusable[0]; const last = focusable.at(-1)
			if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus() }
			else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus() }
		})
		document.getElementById('assistant-sidebar-command')?.addEventListener('submit', previewSidebarIntent)
		const commandForm = document.getElementById('assistant-sidebar-command')
		const commandInput = commandForm?.querySelector('textarea[name="input"]')
		commandForm?.querySelector('select[name="caseId"]')?.addEventListener('change', (event) => {
			state.assistantSidebar.caseId = Number(event.target.value || 0)
			globalThis.localStorage?.setItem('bestatter-assistant-case-id', String(state.assistantSidebar.caseId))
			const selected = event.target.selectedOptions?.[0]?.textContent?.trim() || 'Kein Fall ausgewählt'
			event.target.title = selected
			event.target.closest('label')?.querySelector('small')?.replaceChildren(selected)
		})
		commandInput?.addEventListener('input', (event) => { state.assistantSidebar.command = event.target.value })
		commandInput?.addEventListener('keydown', (event) => {
			if (event.key !== 'Enter' || event.shiftKey || event.isComposing) return
			event.preventDefault()
			void previewSidebarIntent({ preventDefault() {}, currentTarget: commandForm })
		})
		document.querySelectorAll('[data-assistant-question]').forEach((button) => button.addEventListener('click', async () => {
			state.assistantSidebar.command = button.dataset.assistantQuestion
			await requestSidebarPreview()
		}))
		document.getElementById('assistant-sidebar-execute')?.addEventListener('click', executeSidebarIntent)
		document.querySelectorAll('.assistant-intent-choice').forEach((button) => button.addEventListener('click', () => executeSidebarIntent(button.dataset.confirmationToken)))
		document.querySelectorAll('.assistant-open-file,.assistant-print-file').forEach((button) => button.addEventListener('click', () => window.open(OC.generateUrl(`/f/${button.dataset.fileId}`), '_blank', 'noopener')))
		document.querySelectorAll('.assistant-preview-file').forEach((button) => button.addEventListener('click', () => window.open(OC.generateUrl(`/core/preview?fileId=${button.dataset.fileId}&x=1200&y=1600&a=1`), '_blank', 'noopener')))
		if (state.view !== 'capture') return
		document.querySelectorAll('[data-capture-step]').forEach((button) => button.addEventListener('click', () => {
			persistFields()
			state.assistantCapture.step = Number(button.dataset.captureStep)
			render()
		}))
		document.getElementById('capture-reset')?.addEventListener('click', () => {
			state.assistantCapture = emptyCapture()
			localStorage.removeItem('bestatter-assistant-capture')
			render()
		})
		document.getElementById('capture-transcript')?.addEventListener('input', (event) => { capture().transcript = event.target.value; persist() })
		document.getElementById('capture-analyze')?.addEventListener('click', analyzeTranscript)
		document.getElementById('capture-apply-suggestions')?.addEventListener('click', () => { applySuggestions(); render() })
		document.querySelectorAll('[data-focus-capture-field]').forEach((link) => link.addEventListener('click', (event) => { event.preventDefault(); document.querySelector(`#capture-fields [name="${CSS.escape(link.dataset.focusCaptureField)}"]`)?.focus() }))
		document.getElementById('capture-review')?.addEventListener('click', () => {
			const form = document.getElementById('capture-fields')
			if (!form.reportValidity()) return
			persistFields(); capture().step = 3; capture().maxStep = 3; persist(); render()
		})
		document.getElementById('capture-create-case')?.addEventListener('click', createCase)
		document.getElementById('capture-record')?.addEventListener('click', toggleRecording)
		document.getElementById('capture-audio-select')?.addEventListener('click', () => document.getElementById('capture-audio-file')?.click())
		document.getElementById('capture-audio-file')?.addEventListener('change', (event) => { if (event.target.files?.[0]) transcribe(event.target.files[0]) })
		document.getElementById('capture-scan-select')?.addEventListener('click', () => document.getElementById('capture-scan-file')?.click())
		document.getElementById('capture-scan-file')?.addEventListener('change', (event) => { if (event.target.files?.[0]) uploadCaptureImport(event.target.files[0]) })
		document.getElementById('capture-scan-retry')?.addEventListener('click', retryCaptureImport)
		document.getElementById('capture-scan-check')?.addEventListener('click', () => pollCaptureImport(Number(capture().scanImport?.id || 0), 40).catch((error) => notifyError(error.message)))
		document.getElementById('capture-scan-open')?.addEventListener('click', () => window.open(OC.generateUrl(`/f/${capture().scanImport?.fileId}`), '_blank', 'noopener'))
		document.getElementById('capture-transcription-retry')?.addEventListener('click', () => pollTranscription(Number(capture().transcriptionTaskId), 40).catch(handleTranscriptionError))
		document.getElementById('assistant-command')?.addEventListener('submit', previewIntent)
		document.getElementById('assistant-execute')?.addEventListener('click', executeIntent)
	}

	async function previewSidebarIntent(event) {
		event.preventDefault()
		const values = new FormData(event.currentTarget)
		state.assistantSidebar.command = String(values.get('input') || '').trim()
		state.assistantSidebar.caseId = Number(values.get('caseId') || state.currentCase?.id || 0)
		await requestSidebarPreview()
	}

	async function requestSidebarPreview() {
		try {
			state.assistantSidebar.preview = await api(`${ctx.apiBase}/assistant/intents/preview`, { method: 'POST', feedback: false, loadingLabel: 'Der Assistent prüft die Anfrage …', body: new URLSearchParams({ input: state.assistantSidebar.command, caseId: String(state.assistantSidebar.caseId || state.currentCase?.id || 0) }) })
			render()
		} catch (error) { notifyError(error.message) }
	}

	async function executeSidebarIntent(selectedToken = '') {
		const preview = state.assistantSidebar.preview
		const confirmationToken = typeof selectedToken === 'string' && selectedToken ? selectedToken : preview?.confirmationToken
		if (!confirmationToken) return
		if (preview?.writeOperation && !await ctx.confirmAction(`${preview.label} wirklich ausführen?`, { title: 'Assistentenaktion bestätigen', confirmLabel: 'Geprüft ausführen' })) return
		try {
			const result = await api(`${ctx.apiBase}/assistant/intents/execute`, { method: 'POST', feedback: false, loadingLabel: 'Bestätigte Aktion wird ausgeführt …', body: new URLSearchParams({ confirmationToken }) })
			if (result.navigation) {
				if (result.navigation.caseId) {
					state.currentCase = await api(`${ctx.urls.cases}/${result.navigation.caseId}`, { feedback: false })
					state.assistantSidebar.caseId = Number(result.navigation.caseId)
				}
				state.view = result.navigation.view || state.view
				if (result.navigation.caseTab) state.caseTab = result.navigation.caseTab
				if (result.navigation.administrationTab) state.administrationTab = result.navigation.administrationTab
				state.assistantSidebar.open = false; state.assistantSidebar.command = ''; state.assistantSidebar.preview = null
			} else if (result.case) {
				state.currentCase = result.case
				const index = state.cases.findIndex((item) => Number(item.id) === Number(result.case.id))
				if (index >= 0) state.cases[index] = result.case
				state.assistantSidebar.command = ''; state.assistantSidebar.preview = null
			} else if (result.documents?.length) {
				state.assistantSidebar.preview = { intent: result.intent, label: 'Dokumentpaket erzeugt', writeOperation: false, executable: false, answer: { title: 'Dokumentpaket wurde erzeugt', text: 'Die Ausgaben wurden im Fallordner gespeichert und können jetzt geöffnet oder gedruckt werden.', items: [] }, documents: result.documents }
			} else {
				state.assistantSidebar.command = ''; state.assistantSidebar.preview = null
			}
			notifySuccess(result.navigation ? 'Der gewünschte Arbeitsbereich wurde geöffnet.' : (result.contact ? `${result.contact.title} wurde im Nextcloud-Adressbuch gespeichert.` : `${result.caseNumber}: ${preview.label} wurde ausgeführt.`))
			render()
		} catch (error) { notifyError(error.message) }
	}

	async function analyzeTranscript() {
		const textarea = document.getElementById('capture-transcript')
		capture().transcript = textarea.value.trim()
		try {
			const result = await api(`${ctx.apiBase}/assistant/analyze`, { method: 'POST', feedback: false, loadingLabel: 'Gesprächstext wird ausgewertet …', body: new URLSearchParams({ text: capture().transcript, context: 'CASE_CAPTURE' }) })
			capture().suggestions = result.suggestions || []; capture().warnings = result.warnings || []; capture().questions = result.questions || []; capture().conflicts = result.conflicts || []
			capture().step = 2; capture().maxStep = Math.max(capture().maxStep, 2); persist(); render()
		} catch (error) { notifyError(error.message) }
	}

	async function uploadCaptureImport(file) {
		if (!/\.pdf$/i.test(file.name) && file.type !== 'application/pdf') { notifyError('Bitte einen Auftragserfassungsbogen als PDF auswählen.'); return }
		const body=new FormData();body.append('document',file);body.append('templateKey','AUFTRAGSERFASSUNG_STANDARD');body.append('templateVersion','1')
		try{
			capture().scanImport={status:'UPLOADING',originalName:file.name};persist();render()
			const item=await api(`${ctx.apiBase}/assistant/capture-imports`,{method:'POST',body,feedback:false,loadingLabel:'Erfassungsbogen wird sicher gespeichert …'})
			capture().scanImport=item;persist();render()
			if(['OCR_QUEUED','OCR_PROCESSING'].includes(item.status))await pollCaptureImport(item.id)
			else if(item.status==='MANUAL_REVIEW')notifyWarning('Paperless ist nicht aktiv. Der Scan ist sicher gespeichert; bitte die Angaben manuell übertragen.')
		}catch(error){capture().scanImport=null;persist();render();notifyError(error.message)}
	}

	async function pollCaptureImport(id,maxAttempts=80){
		for(let attempt=0;attempt<maxAttempts;attempt++){
			const item=await api(`${ctx.apiBase}/assistant/capture-imports/${id}`,{feedback:false,loadingLabel:'OCR-Ergebnis wird abgerufen …'});capture().scanImport=item;persist();render()
			if(item.status==='EXTRACTION_READY'){
				capture().transcript=item.ocrText||'';capture().suggestions=item.suggestions||[];capture().warnings=['OCR-Angaben sind ungeprüfte Vorschläge. Bitte mit dem Originalscan vergleichen.'];capture().questions=[];capture().conflicts=[];capture().step=2;capture().maxStep=2;persist();notifySuccess('OCR abgeschlossen. Bitte alle erkannten Angaben mit dem Original vergleichen.');render();return
			}
			if(['OCR_FAILED','REVIEW_REQUIRED','MANUAL_REVIEW'].includes(item.status)){notifyWarning(item.errorMessage||'OCR konnte nicht abgeschlossen werden. Die manuelle Erfassung bleibt verfügbar.');return}
			await new Promise((resolve)=>setTimeout(resolve,2000))
		}
		capture().scanImport.status='OCR_DELAYED';persist();render();notifyWarning('Die OCR-Verarbeitung dauert ungewöhnlich lange. Sie kann später erneut geprüft werden.')
	}

	async function retryCaptureImport(){const id=Number(capture().scanImport?.id||0);if(!id)return;try{capture().scanImport=await api(`${ctx.apiBase}/assistant/capture-imports/${id}/retry`,{method:'POST',feedback:false});persist();render();await pollCaptureImport(id)}catch(error){notifyError(error.message)}}

	function scanStatusLabel(status){return ({UPLOADING:'wird hochgeladen',UPLOADED:'sicher gespeichert',OCR_QUEUED:'OCR eingeplant',OCR_PROCESSING:'OCR läuft',EXTRACTION_READY:'OCR abgeschlossen – Prüfung erforderlich',REVIEW_REQUIRED:'manuelle Prüfung erforderlich',MANUAL_REVIEW:'manuelle Erfassung',OCR_FAILED:'OCR fehlgeschlagen',OCR_DELAYED:'OCR verzögert',COMPLETED:'dem Fall zugeordnet'})[status]||String(status||'bereit')}

	function applySuggestions(all = false) {
		const selected = all ? capture().suggestions.map((_, index) => index) : [...document.querySelectorAll('[data-suggestion]:checked')].map((input) => Number(input.dataset.suggestion))
		for (const index of selected) {
			const item = capture().suggestions[index]
			if (item) capture().data[item.field] = item.value
		}
		if (!capture().data.notes) capture().data.notes = capture().transcript
		persist()
	}

	function persistFields() {
		const form = document.getElementById('capture-fields')
		if (form) capture().data = { ...capture().data, ...Object.fromEntries(new FormData(form)) }
		persist()
	}

	async function createCase() {
		try {
			const scanImport = capture().scanImport ? { ...capture().scanImport } : null
			const data = { ...capture().data, status: 'NEU' }
			const corrections = (capture().suggestions || []).filter((item) => String(data[item.field] || '').trim() && String(data[item.field]).trim() !== String(item.value || '').trim()).map((item) => ({ field: item.field, source: item.source, observedValue: item.value, confirmedValue: data[item.field] }))
			if (corrections.length) await api(`${ctx.apiBase}/assistant/corrections`, { method: 'POST', feedback: false, loadingLabel: 'Bestätigte Korrekturen werden für die fachliche Prüfung vorgemerkt …', body: new URLSearchParams({ corrections: JSON.stringify(corrections) }) })
			const result = await api(ctx.urls.cases, { method: 'POST', feedback: false, loadingLabel: 'Geprüfter Fall wird angelegt …', body: new URLSearchParams({ masterData: JSON.stringify(data), firstName: data.first_name, lastName: data.last_name, creationToken: capture().creationToken }) })
			if(scanImport?.id){const confirmedFields=Object.entries(data).filter(([,value])=>String(value||'').trim()!=='').map(([key])=>key);await api(`${ctx.apiBase}/assistant/capture-imports/${scanImport.id}/complete`,{method:'POST',feedback:false,loadingLabel:'Originalscan wird dem Fall zugeordnet …',body:new URLSearchParams({caseId:String(result.id),confirmedFields:JSON.stringify(confirmedFields)})})}
			state.currentCase = await api(`${ctx.urls.cases}/${result.id}`)
			state.cases = await api(ctx.urls.cases)
			state.assistantCapture = emptyCapture(); localStorage.removeItem('bestatter-assistant-capture')
			state.view = 'case-detail'; state.caseTab = 'overview'
			notifySuccess(`Fall ${state.currentCase.caseNumber} wurde angelegt.`); render()
		} catch (error) { notifyError(error.message) }
	}

	async function toggleRecording(event) {
		const button = event.currentTarget instanceof HTMLElement ? event.currentTarget : document.getElementById('capture-record')
		if (recorder?.state === 'recording') {
			capture().recording = false
			capture().recordingStartedAt = null
			setTranscriptionProgress(null, 'Aufnahme wird abgeschlossen und zum Hochladen vorbereitet …', 'preparing')
			recorder.stop(); persist(); render(); return
		}
		if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) { notifyError('Dieser Browser unterstützt keine Audioaufnahme. Bitte eine Audiodatei auswählen.'); return }
		if (!window.isSecureContext) { notifyError('Die Mikrofonaufnahme ist nur über eine sichere HTTPS-Verbindung möglich. Die Audiodatei-Auswahl bleibt verfügbar.'); return }
		try {
			if (navigator.permissions?.query) {
				const permission = await navigator.permissions.query({ name: 'microphone' }).catch(() => null)
				if (permission?.state === 'denied') throw new DOMException('Die Mikrofonberechtigung ist im Browser oder durch eine Richtlinie gesperrt.', 'NotAllowedError')
			}
			recorderStream = await navigator.mediaDevices.getUserMedia({ audio: true })
			const preferred = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg'].find((type) => MediaRecorder.isTypeSupported?.(type))
			recorder = new MediaRecorder(recorderStream, preferred ? { mimeType: preferred } : undefined); chunks = []
			recorder.ondataavailable = (item) => { if (item.data.size) chunks.push(item.data) }
			recorder.onstop = async () => {
				const type = recorder.mimeType || 'audio/webm'; const file = new File(chunks, `aufnahme-${Date.now()}.${type.includes('ogg') ? 'ogg' : 'webm'}`, { type })
				recorderStream?.getTracks().forEach((track) => track.stop()); recorderStream = null; recorder = null
				await transcribe(file)
			}
			recorder.start(500)
			capture().recording = true
			capture().recordingStartedAt = new Date().toISOString()
			persist(); render()
		} catch (error) {
			recorderStream?.getTracks().forEach((track) => track.stop()); recorderStream = null; recorder = null
			capture().recording = false; capture().recordingStartedAt = null; persist()
			const message = error?.name === 'NotAllowedError'
				? 'Das Mikrofon wurde nicht freigegeben. Bitte die Websiteberechtigung für test.luedeke-bremen.de prüfen und die Seite neu laden. Alternativ kann eine Audiodatei ausgewählt werden.'
				: `Das Mikrofon konnte nicht verwendet werden: ${error.message}`
			notifyError(message)
		}
	}

	async function transcribe(file) {
		const status = document.getElementById('capture-speech-state'); if (status) status.textContent = 'Audio wird hochgeladen …'
		setTranscriptionProgress(null, `„${file.name}“ wird hochgeladen …`, 'uploading')
		const body = new FormData(); body.append('audio', file)
		try {
			const task = await api(`${ctx.apiBase}/assistant/transcriptions`, { method: 'POST', body, feedback: false, loadingLabel: 'Transkription wird gestartet …' })
			capture().transcriptionTaskId = Number(task.taskId || 0)
			setTranscriptionProgress(10, 'Upload abgeschlossen · Transkription wurde eingeplant.', 'scheduled')
			await pollTranscription(task.taskId)
		} catch (error) { handleTranscriptionError(error, status) }
	}

	async function pollTranscription(taskId, maxAttempts = 80) {
		if (!Number(taskId)) throw new Error('Die Transkriptionskennung fehlt. Bitte die Aufnahme erneut starten.')
		capture().transcriptionTaskId = Number(taskId)
		for (let attempt = 0; attempt < maxAttempts; attempt++) {
			const result = await api(`${ctx.apiBase}/assistant/transcriptions/${taskId}`, { feedback: false, loadingLabel: 'Sprache wird erkannt …' })
			if (result.status === 'SUCCESSFUL') {
				setTranscriptionProgress(100, 'Transkription abgeschlossen.', 'successful')
				capture().transcript = [capture().transcript, result.transcript].filter(Boolean).join(capture().transcript ? '\n' : '')
				capture().transcriptionTaskId = null
				persist(); notifySuccess('Die Aufnahme wurde transkribiert.'); render(); return
			}
			if (['FAILED', 'CANCELLED'].includes(result.status)) throw new Error(result.message || 'Die Transkription ist fehlgeschlagen.')
			const providerProgress = Number(result.progress)
			const progress = Number.isFinite(providerProgress) && providerProgress > 0 ? Math.max(15, Math.min(95, providerProgress)) : Math.min(90, 15 + (attempt * 2))
			setTranscriptionProgress(progress, result.status === 'RUNNING' ? `Sprache wird erkannt … ${Math.round(progress)} %` : 'Transkription wartet auf Verarbeitung …', String(result.status || 'SCHEDULED').toLowerCase())
			await new Promise((resolve) => setTimeout(resolve, 1500))
		}
		setTranscriptionProgress(null, 'Die Transkription reagiert ungewöhnlich lange nicht. Der Speech-to-Text-Dienst ist möglicherweise ausgelastet oder nicht erreichbar. Sie können den Status erneut prüfen; die Texteingabe bleibt nutzbar.', 'delayed')
		persist(); render()
		notifyWarning('Spracherkennung verzögert: Bitte Status erneut prüfen. Bei wiederholtem Auftreten die KI-Systemprüfung und den Container nc_app_stt_whisper2 kontrollieren.')
	}

	function handleTranscriptionError(error, status = document.getElementById('capture-speech-state')) {
		const message = error?.message || 'Die Transkription ist fehlgeschlagen.'
		setTranscriptionProgress(null, message, 'failed')
		if (status) status.textContent = message
		persist(); notifyError(message); render()
	}

	function setTranscriptionProgress(progress, label, status) {
		capture().transcriptionProgress = { visible: true, progress: Number.isFinite(Number(progress)) ? Number(progress) : null, label, status }
		const box = document.getElementById('capture-speech-progress')
		const bar = document.getElementById('capture-speech-progress-bar')
		const progressLabel = document.getElementById('capture-speech-progress-label')
		if (box) { box.hidden = false; box.dataset.state = status }
		if (bar) {
			if (Number.isFinite(Number(progress))) bar.value = Math.max(0, Math.min(100, Number(progress)))
			else bar.removeAttribute('value')
		}
		if (progressLabel) progressLabel.textContent = label
	}

	async function previewIntent(event) {
		event.preventDefault(); const form = event.currentTarget; const formData = new FormData(form); capture().command = formData.get('input') || ''; capture().assistantCaseId = Number(formData.get('caseId') || 0)
		try {
			capture().intentPreview = await api(`${ctx.apiBase}/assistant/intents/preview`, { method: 'POST', feedback: false, body: new URLSearchParams({ input: capture().command, caseId: String(capture().assistantCaseId || state.currentCase?.id || 0) }) })
			persist(); render()
		} catch (error) { notifyError(error.message) }
	}

	async function executeIntent() {
		const preview = capture().intentPreview
		if (!preview?.executable || !preview.confirmationToken) return
		if (!await ctx.confirmAction(`${preview.label} wirklich im ausgewählten Fall anlegen?`, { title: 'Assistentenaktion bestätigen', confirmLabel: 'Geprüft anlegen' })) return
		try {
			const result = await api(`${ctx.apiBase}/assistant/intents/execute`, { method: 'POST', feedback: false, body: new URLSearchParams({ confirmationToken: preview.confirmationToken }) })
			notifySuccess(`${result.caseNumber}: ${preview.label} wurde angelegt.`)
			capture().command = ''; capture().intentPreview = null; persist(); render()
		} catch (error) { notifyError(error.message) }
	}

	function persist() {
		try { localStorage.setItem('bestatter-assistant-capture', JSON.stringify(capture())) } catch (_) { notifyWarning('Der lokale Entwurf konnte im Browser nicht zwischengespeichert werden.') }
	}

	function emptyCapture() {
		return { step: 1, maxStep: 1, transcript: '', suggestions: [], warnings: [], questions: [], conflicts: [], scanImport: null, recording: false, recordingStartedAt: null, transcriptionTaskId: null, transcriptionProgress: { visible: false, progress: null, label: '', status: 'idle' }, data: { salutation: '', title: '', first_name: '', last_name: '', birth_name: '', date_of_birth: '', date_of_death: '', funeral_type: '', place_of_death: '', birth_place: '', birth_registry_office: '', profession: '', pension_insurance_number: '', civil_status: '', spouse_first_name: '', spouse_last_name: '', spouse_date_of_birth: '', spouse_birth_place: '', spouse_residence: '', spouse_date_of_death: '', spouse_death_place: '', marriage_date: '', marriage_place: '', partnership_date: '', divorce_date: '', religion: '', last_residence: '', last_residence_postal_code: '', last_residence_city: '', cemetery_contact: '', order_client_relation: '', order_client_first_name: '', order_client_name: '', order_client_mobile: '', certificate_free_count: '', certificate_paid_count: '', branch: defaultBranch(), responsible_employee: currentUser(), notes: '' }, command: '', assistantCaseId: 0, intentPreview: null, creationToken: ctx.createToken() }
	}

	function defaultBranch() { return state.branches.find((item) => item.active && (item.memberUids || []).includes(currentUser()))?.key || state.branches.find((item) => item.active)?.key || '' }
	function currentUser() { return state.team.currentUid || (OC.getCurrentUser && OC.getCurrentUser().uid) || '' }

	function initializeAssistantCapture() {
		if (!state.assistantSidebar) state.assistantSidebar = { open: false, command: '', caseId: Number(state.currentCase?.id || 0), preview: null }
		if (state.assistantCapture) return
		try {
			const saved = JSON.parse(localStorage.getItem('bestatter-assistant-capture') || 'null')
			state.assistantCapture = saved && typeof saved === 'object' && saved.creationToken ? { ...saved, recording: false, recordingStartedAt: null } : emptyCapture()
		} catch (_) { state.assistantCapture = emptyCapture() }
	}

	return { guidedCaptureView, assistantSidebarView, bindAssistant, initializeAssistantCapture, emptyCapture }
}
