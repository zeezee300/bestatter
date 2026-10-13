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
	// 0.71.0: Aufnahme bleibt im Speicher, bis die Transkription erfolgreich ist (erneut senden / herunterladen).
	let pendingAudio = null
	let transcriptionBusy = false
	let resumeChecked = false
	const storageKey = () => `bestatter-assistant-capture:${currentUser() || 'anonym'}`
	globalThis.addEventListener?.('beforeunload', (event) => {
		if (!(recorder?.state === 'recording' || transcriptionBusy || pendingAudio)) return
		event.preventDefault(); event.returnValue = ''
	})

	const capture = () => state.assistantCapture
	const fieldLabels = {
		salutation: 'Anrede', title: 'Titel', first_name: 'Vorname', last_name: 'Nachname', birth_name: 'Geburtsname', date_of_birth: 'Geburtsdatum', date_of_death: 'Sterbedatum',
		place_of_death: 'Sterbeort / Einrichtung', birth_place: 'Geburtsort', birth_registry_office: 'Geburtsstandesamt', profession: 'Beruf', pension_insurance_number: 'Postrentennummer', last_residence_city: 'Letzter Wohnort',
		last_residence: 'Straße / Hausnummer', last_residence_postal_code: 'PLZ', civil_status: 'Familienstand', spouse_first_name: 'Vorname Ehepartner/in', spouse_last_name: 'Nachname Ehepartner/in', spouse_date_of_birth: 'Geburtsdatum Ehepartner/in', spouse_birth_place: 'Geburtsort Ehepartner/in', spouse_residence: 'Wohnort Ehepartner/in', spouse_date_of_death: 'Todesdatum Ehepartner/in (falls vorverstorben)', spouse_death_place: 'Sterbeort Ehepartner/in',
		marriage_date: 'Datum der Eheschließung', marriage_place: 'Ort der Eheschließung', partnership_date: 'Datum der Begründung der Lebenspartnerschaft', divorce_date: 'Datum der Scheidung / Aufhebung', religion: 'Religion', cemetery_contact: 'Friedhof',
		burial_variant_code: 'Bestattungsart / Variante', order_client_relation: 'Beziehung Auftraggeber/in', order_client_first_name: 'Vorname Auftraggeber/in', order_client_name: 'Nachname Auftraggeber/in', order_client_mobile: 'Mobilfunknummer Auftraggeber/in', certificate_free_count: 'Sterbeurkunden gebührenfrei', certificate_paid_count: 'Sterbeurkunden gebührenpflichtig', branch: 'Niederlassung', responsible_employee: 'Zuständiger Mitarbeiter', notes: 'Gesprächsnotiz',
	}
	const variantTree = () => state.customizing.find((list) => list.key === 'BURIAL_VARIANT')?.tree || []
	function variantPath(code, level = variantTree(), ancestors = []) {
		for (const item of level) {
			const path = [...ancestors, item]
			if (item.value === code) return path
			const nested = variantPath(code, item.children || [], path)
			if (nested.length) return nested
		}
		return []
	}
	function burialChoice(data) {
		const code = String(data.burial_variant_code || '')
		const path = variantPath(code)
		let level = variantTree()
		const fields = []
		for (let depth = 0; level.length; depth++) {
			const selected = path[depth]?.value || ''
			fields.push(`<label><span>${depth ? `Unterauswahl ${depth}` : 'Bestattungsart'}</span><select data-capture-burial-level="${depth}"><option value="">${depth ? 'Noch offen' : 'Bitte auswählen'}</option>${level.map((item) => `<option value="${esc(item.value)}" ${item.value === selected ? 'selected' : ''}>${esc(item.label)}</option>`).join('')}</select></label>`)
			level = path[depth]?.children || []
		}
		return `<input type="hidden" name="burial_variant_code" value="${esc(code)}">${fields.join('')}`
	}
	function suggestedVariant(value) {
		const normalized = String(value || '').toLocaleLowerCase('de').normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]/g, '')
		return variantTree().find((item) => [item.value, item.label].some((candidate) => String(candidate).toLocaleLowerCase('de').normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]/g, '') === normalized))?.value || ''
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

	/** 0.71.0: Gesprächsaufnahme und Bogen-Einlesen nur anbieten, wenn Spracherkennung bzw. Texterkennung wirklich bereitstehen. */
	function speechState(config) {
		if (config.speech) return config.speech
		const available = Boolean(config.speechInputEnabled !== false && config.providers?.speechToText && config.speechProviderApproved)
		return { available, reason: available ? '' : 'Spracherkennung nicht eingerichtet oder nicht freigegeben.' }
	}
	function ocrState(config) { return config.ocr || { available: false, reason: 'Keine Texterkennung eingerichtet.', accept: '' } }
	const isAdmin = () => Boolean(state.team?.isBestatterAdmin)
	function unavailableHint(title, reason) {
		// Nur Administratoren sehen, warum eine Option fehlt; alle anderen sehen eine aufgeräumte Erfassung.
		return isAdmin() ? `<p class="bp-muted bp-capture-unavailable"><b>${esc(title)} nicht verfügbar:</b> ${esc(reason)} <small>(Hinweis nur für Administratoren)</small></p>` : ''
	}
	function allSuggestions() {
		return [...(capture().suggestions || []).map((item) => ({ ...item, sourceLabel: 'Gespräch' })), ...(capture().ocrSuggestions || []).map((item) => ({ ...item, sourceLabel: 'Scan' }))]
	}
	/**
	 * 0.71.1: Widersprüchliche Angaben – vom Server erkannte Alternativen im Text sowie abweichende Werte aus
	 * Gespräch und Scan für dasselbe Feld. Jede Variante ist auswählbar; alternativ „selbst eintragen“.
	 */
	const normalizeValue = (value) => String(value ?? '').trim().toLocaleLowerCase('de').replace(/\s+/g, ' ')
	function allConflicts() {
		const map = new Map()
		const add = (field, label, value, origin) => {
			if (!field || String(value ?? '').trim() === '') return
			const entry = map.get(field) || { field, label: label || fieldLabels[field] || field, values: [] }
			if (!entry.values.some((item) => normalizeValue(item.value) === normalizeValue(value))) entry.values.push({ value: String(value).trim(), origin })
			map.set(field, entry)
		}
		for (const conflict of capture().conflicts || []) for (const value of conflict.values || []) add(conflict.field, conflict.label, value, 'Gespräch')
		for (const item of allSuggestions()) add(item.field, item.label, item.value, item.sourceLabel)
		return [...map.values()].filter((entry) => entry.values.length > 1)
	}
	const conflictChoice = (field) => (capture().conflictChoices || {})[field]
	const unresolvedConflicts = () => allConflicts().filter((entry) => conflictChoice(entry.field) === undefined)
	function applyConflictChoice(field, value) {
		capture().conflictChoices = { ...(capture().conflictChoices || {}), [field]: value }
		if (value === '') return // „selbst eintragen“: Feld bleibt, wie es ist
		if (field === 'funeral_type') {
			const code = suggestedVariant(value)
			if (code) capture().data.burial_variant_code = code
		} else capture().data[field] = value
	}
	function conflictSection() {
		const conflicts = allConflicts()
		if (!conflicts.length) return ''
		return `<section class="bp-capture-warnings bp-capture-conflicts"><h4>Widersprüchliche Angaben – bitte eine Variante wählen</h4>${conflicts.map((entry) => {
			const chosen = conflictChoice(entry.field)
			const name = `conflict-${entry.field}`
			return `<fieldset data-conflict-field="${esc(entry.field)}"><legend>${esc(entry.label)}</legend>${entry.values.map((item, index) => `<label class="bp-inline-check"><input type="radio" name="${esc(name)}" data-conflict-value="${index}" ${chosen !== undefined && normalizeValue(chosen) === normalizeValue(item.value) ? 'checked' : ''}> ${esc(/^\d{4}-\d{2}-\d{2}$/.test(item.value) ? new Date(`${item.value}T00:00:00`).toLocaleDateString('de-DE') : item.value)} <small>(${esc(item.origin)})</small></label>`).join('')}<label class="bp-inline-check"><input type="radio" name="${esc(name)}" data-conflict-value="own" ${chosen === '' ? 'checked' : ''}> Selbst eintragen <small>(Feld unten ausfüllen)</small></label></fieldset>`
		}).join('')}</section>`
	}

	function captureConversation(config) {
		const speech = speechState(config)
		const ocr = ocrState(config)
		const speechAvailable = speech.available
		const recording = Boolean(capture().recording)
		const transcription = capture().transcriptionProgress || {}
		const progressValue = Number.isFinite(Number(transcription.progress)) ? ` value="${Math.max(0, Math.min(100, Number(transcription.progress)))}"` : ''
		const delayed = transcription.status === 'delayed' && Number(capture().transcriptionTaskId || 0) > 0
		const scan = capture().scanImport || {}
		const scanProgress = ['OCR_QUEUED', 'OCR_PROCESSING'].includes(scan.status)
		const showScan = ocr.available || Boolean(scan.id)
		const engineLabel = ocr.engine === 'NEXTCLOUD' ? 'die Nextcloud-Texterkennung' : 'Paperless'
		const speechBlock = speechAvailable || recording || transcriptionBusy || pendingAudio ? `<div class="bp-speech-actions">
				<button type="button" class="bp-secondary ${recording ? 'recording' : ''}" id="capture-record" aria-pressed="${recording ? 'true' : 'false'}" ${speechAvailable || recording ? '' : 'disabled'}><span class="bp-record-control-icon" aria-hidden="true">${recording ? '■' : '●'}</span><span>${recording ? 'Aufnahme beenden' : 'Gespräch aufnehmen'}</span></button>
				<button type="button" class="bp-secondary" id="capture-audio-select" ${speechAvailable && !recording && !transcriptionBusy ? '' : 'disabled'}>Audiodatei auswählen</button>
				<input type="file" id="capture-audio-file" accept="audio/*,video/webm" hidden>
				${pendingAudio && !transcriptionBusy && !recording ? '<button type="button" class="bp-secondary" id="capture-audio-resend">Aufnahme erneut senden</button><button type="button" class="bp-secondary" id="capture-audio-download">Aufnahme speichern</button>' : ''}
				<span id="capture-speech-state" role="status" aria-live="polite">${recording ? '<b class="bp-recording-indicator">Aufnahme läuft</b> · Zum Stoppen „Aufnahme beenden“ wählen.' : (pendingAudio && !transcriptionBusy ? 'Die Aufnahme ist noch nicht transkribiert und nur in diesem Browserfenster vorhanden. Bitte erneut senden oder speichern.' : 'Bereit zur Aufnahme. Das Audio wird nach der Transkription gelöscht.')}</span>
			</div>
			<div id="capture-speech-progress" class="bp-speech-progress" data-state="${esc(transcription.status || 'idle')}" ${transcription.visible ? '' : 'hidden'}>
				<progress id="capture-speech-progress-bar" max="100"${progressValue} aria-describedby="capture-speech-progress-label"></progress>
				<span id="capture-speech-progress-label" role="status" aria-live="polite">${esc(transcription.label || '')}</span>
				${delayed ? '<button type="button" class="bp-secondary" id="capture-transcription-retry">Status erneut prüfen</button>' : ''}
			</div>` : unavailableHint('Gesprächsaufnahme', speech.reason)
		const scanBlock = showScan ? `<section class="bp-capture-scan" aria-labelledby="capture-scan-heading"><div><h4 id="capture-scan-heading">Alternativ: Auftragserfassungsbogen einlesen</h4><p>${ocr.acceptsImages ? 'PDF oder Foto' : 'PDF'} hochladen, durch ${esc(engineLabel)} erkennen lassen und jeden Feldvorschlag mit dem Original vergleichen. Der Bogen liegt bis zur Zuordnung nur in Ihrem persönlichen Bereich.</p></div>
				<div class="bp-row-actions">${ocr.available ? `<button type="button" class="bp-secondary" id="capture-scan-select" ${scanProgress ? 'disabled' : ''}>${ocr.acceptsImages ? 'Erfassungsbogen (PDF/Foto) auswählen' : 'PDF-Erfassungsbogen auswählen'}</button><input type="file" id="capture-scan-file" accept="${esc(ocr.accept || 'application/pdf,.pdf')}" hidden>` : ''}${scan.id && ['OCR_QUEUED','OCR_PROCESSING','OCR_DELAYED'].includes(scan.status) ? '<button type="button" class="bp-secondary" id="capture-scan-check">Status prüfen</button>' : ''}${scan.id && ocr.available && ['OCR_FAILED','REVIEW_REQUIRED','MANUAL_REVIEW'].includes(scan.status) ? '<button type="button" class="bp-secondary" id="capture-scan-retry">Texterkennung erneut versuchen</button>' : ''}${scan.id ? '<button type="button" class="bp-secondary" id="capture-scan-discard">Bogen verwerfen</button>' : ''}</div>
				${scan.id || scan.status === 'UPLOADING' ? scanStateHtml(scan, scanProgress) : ''}
				${!ocr.available && scan.id ? `<small>${esc(ocr.reason)} Der gespeicherte Bogen kann im nächsten Schritt manuell übertragen werden.</small>` : ''}
			</section>` : unavailableHint('Auftragserfassungsbogen einlesen', ocr.reason)
		const hasScan = Boolean(scan.id)
		return `<section class="bp-panel bp-capture-panel"><div class="bp-panel-head"><div><p class="bp-eyebrow">Schritt 1</p><h3>${speechAvailable ? 'Gespräch aufnehmen oder Notiz erfassen' : 'Notiz zum Sterbefall erfassen'}</h3></div>${speechAvailable ? '<span class="bp-status">Spracherkennung verfügbar</span>' : ''}</div>
			<label class="bp-capture-text"><span>Gesprächstext</span><textarea id="capture-transcript" rows="10" placeholder="Beispiel: Sterbefall Maria Muster, geboren am 12. März 1942, verstorben heute im Klinikum Bremen. Gewünscht ist eine Feuerbestattung …">${esc(capture().transcript)}</textarea></label>
			${speechBlock}
			${scanBlock}
			<div class="bp-capture-actions"><span></span><button type="button" class="bp-primary" id="capture-analyze">${hasScan ? 'Weiter zu den Angaben' : 'Text auswerten und weiter'}</button></div>
		</section>`
	}

	function captureFields(data) {
		const suggestions = allSuggestions()
		const questions = capture().questions || []
		const suggestionHtml = suggestions.length ? `<section class="bp-capture-suggestions"><h4>Erkannte Angaben</h4>${suggestions.map((item, index) => `<label><input type="checkbox" data-suggestion="${index}" checked><span><b>${esc(item.label)}</b> ${esc(item.value)}<small>${(capture().ocrSuggestions || []).length ? `${esc(item.sourceLabel)} · ` : ''}${Math.round(Number(item.confidence || 0) * 100)} % · „${esc(item.source)}“</small></span></label>`).join('')}<button type="button" class="bp-secondary" id="capture-apply-suggestions">Ausgewählte Vorschläge übernehmen</button></section>` : '<p class="bp-warning">Es wurden keine eindeutigen Angaben erkannt. Die Felder können manuell ausgefüllt werden.</p>'
		const branchOptions = (state.branches || []).filter((item) => item.active).map((item) => `<option value="${esc(item.key)}" ${data.branch === item.key ? 'selected' : ''}>${esc(item.name)}</option>`).join('')
		const memberOptions = (state.team.members || []).map((item) => `<option value="${esc(item.uid)}" ${data.responsible_employee === item.uid ? 'selected' : ''}>${esc(item.displayName)} (${esc(item.uid)})</option>`).join('')
		const scan = capture().scanImport || {}
		const scanPreview = scan.id ? `<aside class="bp-capture-original"><h4>Originalscan</h4><img src="${esc(OC.generateUrl(`/core/preview?fileId=${scan.fileId}&x=1100&y=1500&a=1`))}" alt="Vorschau des hochgeladenen Auftragserfassungsbogens">${capture().ocrText ? `<details><summary>Erkannter Text</summary><pre class="bp-ocr-text">${esc(capture().ocrText)}</pre></details>` : ''}<button type="button" class="bp-secondary" id="capture-scan-open">Original groß öffnen</button><small>Die OCR-Markierungen sind Vorschläge. Maßgeblich ist der sichtbare Originalscan.</small></aside>` : ''
		return `<section class="bp-panel bp-capture-panel"><div class="bp-panel-head"><div><p class="bp-eyebrow">Schritt 2</p><h3>Erkannte Angaben prüfen und ergänzen</h3></div></div><div class="${scan.id ? 'bp-capture-split' : ''}">${scanPreview}<div class="bp-capture-fields-side">${suggestionHtml}${conflictSection()}${questions.length ? `<section class="bp-capture-questions"><h4>Gezielte Rückfragen</h4><ul>${questions.map((item) => `<li><a href="#" data-focus-capture-field="${esc(item.field)}">${esc(item.question)}</a></li>`).join('')}</ul></section>` : ''}${followUpSection()}
			<form id="capture-fields" class="bp-capture-groups">
				${captureGroup('Verstorbene Person', `${captureField('salutation')}${captureField('title')}${captureField('first_name', 'text', true)}${captureField('last_name', 'text', true)}${captureField('birth_name')}${captureField('profession')}${captureField('religion')}`)}
				${captureGroup('Geburt', `${captureField('date_of_birth', 'date')}${captureField('birth_place')}${captureField('birth_registry_office')}${captureField('pension_insurance_number')}`)}
				${captureGroup('Sterbefall', `${captureField('date_of_death', 'date')}${captureField('place_of_death')}`)}
				${captureGroup('Letzter Wohnsitz', `${captureField('last_residence')}${captureField('last_residence_postal_code')}${captureField('last_residence_city')}`)}
				${captureGroup('Familienstand und Ehepartner/in', `${captureField('civil_status')}${captureField('spouse_first_name')}${captureField('spouse_last_name')}${captureField('spouse_date_of_birth', 'date')}${captureField('spouse_birth_place')}${captureField('spouse_residence')}${captureField('spouse_date_of_death', 'date')}${captureField('spouse_death_place')}${captureField('marriage_date', 'date')}${captureField('marriage_place')}${captureField('partnership_date', 'date')}${captureField('divorce_date', 'date')}`, true)}
				${captureGroup('Auftraggeber/in', `${captureField('order_client_relation')}${captureField('order_client_first_name')}${captureField('order_client_name')}${captureField('order_client_mobile', 'tel')}`)}
				${captureGroup('Bestattung und Urkunden', `${burialChoice(data)}${captureField('cemetery_contact')}${captureField('certificate_free_count', 'number')}${captureField('certificate_paid_count', 'number')}`)}
				${captureGroup('Zuständigkeit', `<label><span>Niederlassung</span><select name="branch"><option value=""></option>${branchOptions}</select></label><label><span>Zuständiger Mitarbeiter</span><select name="responsible_employee"><option value=""></option>${memberOptions}</select></label>`)}
				${captureGroup('Gesprächsnotiz', `<label class="bp-span-2"><span>Interne Gesprächsnotiz</span><textarea name="notes" rows="4">${esc(data.notes || capture().transcript)}</textarea></label>`, true)}
			</form>
			${(capture().warnings || []).length ? `<ul class="bp-capture-warnings">${capture().warnings.map((warning) => `<li>${esc(warning)}</li>`).join('')}</ul>` : ''}</div></div>
			<div class="bp-capture-actions"><button type="button" class="bp-secondary" data-capture-step="1">Zurück</button><button type="button" class="bp-primary" id="capture-review">Zur Prüfung</button></div>
		</section>`
	}

	/** 0.85.0: Folgeschritte aus dem Gespräch, die nach dem Anlegen als Aufgabe entstehen (abwählbar). */
	function followUpSection() {
		const items = capture().followUps || []
		if (!items.length) return ''
		return `<section class="bp-capture-followups"><h4>Nach dem Anlegen</h4>${items.map((item) => `<label class="bp-inline-check"><input type="checkbox" data-follow-up="${esc(item.key)}" ${capture().followUpSelected?.[item.key] !== false ? 'checked' : ''}> <span>Aufgabe anlegen: <b>${esc(item.label)}</b><small>Aus dem Gespräch: „${esc(item.source)}“. ${esc(followUpNote(item.key))}</small></span></label>`).join('')}</section>`
	}

	function followUpNote(key) {
		return key === 'SIGNED_ORDER_UPLOAD' ? 'Als unterschrieben gilt der Auftrag erst, wenn der Scan in der Fallakte hochgeladen und geprüft ist.' : ''
	}

	const selectedFollowUps = () => (capture().followUps || []).filter((item) => capture().followUpSelected?.[item.key] !== false)

	/** 0.84.0: Angaben in Blöcken wie bei „Neuer Fall“, aber auf einer Seite. Breite Blöcke nehmen die ganze Zeile ein. */
	function captureGroup(title, fields, wide = false) {
		return `<fieldset class="bp-capture-group${wide ? ' bp-capture-group-wide' : ''}"><legend>${esc(title)}</legend><div class="bp-form-grid">${fields}</div></fieldset>`
	}

	function captureField(name, type = 'text', required = false) {
		// Aus Gespräch oder Bogen übernommene Werte hervorheben, damit sie gezielt geprüft werden.
		const value = String(capture().data[name] || '')
		const recognized = value !== '' && allSuggestions().some((item) => item.field === name && normalizeValue(item.value) === normalizeValue(value))
		return `<label${recognized ? ' class="bp-capture-recognized" title="Aus Gespräch bzw. Bogen übernommen – bitte prüfen"' : ''}><span>${esc(fieldLabels[name] || name)}${required ? ' *' : ''}</span><input name="${name}" type="${type}" value="${esc(value)}" ${required ? 'required' : ''}></label>`
	}

	/** 0.84.1: Prüfansicht in denselben Blöcken wie Schritt 2; leere Blöcke entfallen. */
	const reviewGroups = [
		['Verstorbene Person', ['salutation', 'title', 'first_name', 'last_name', 'birth_name', 'profession', 'religion']],
		['Geburt', ['date_of_birth', 'birth_place', 'birth_registry_office', 'pension_insurance_number']],
		['Sterbefall', ['date_of_death', 'place_of_death']],
		['Letzter Wohnsitz', ['last_residence', 'last_residence_postal_code', 'last_residence_city']],
		['Familienstand und Ehepartner/in', ['civil_status', 'spouse_first_name', 'spouse_last_name', 'spouse_date_of_birth', 'spouse_birth_place', 'spouse_residence', 'spouse_date_of_death', 'spouse_death_place', 'marriage_date', 'marriage_place', 'partnership_date', 'divorce_date']],
		['Auftraggeber/in', ['order_client_relation', 'order_client_first_name', 'order_client_name', 'order_client_mobile']],
		['Bestattung und Urkunden', ['burial_variant_code', 'cemetery_contact', 'certificate_free_count', 'certificate_paid_count']],
		['Zuständigkeit', ['branch', 'responsible_employee']],
	]

	function captureReview(data) {
		const display = (key, value) => key === 'burial_variant_code' ? variantPath(value).map((item) => item.label).join(' → ') || value : key === 'branch' ? (state.branches || []).find((item) => item.key === value)?.name || value : key === 'responsible_employee' ? (state.team.members || []).find((item) => item.uid === value)?.displayName || value : /^\d{4}-\d{2}-\d{2}$/.test(String(value)) ? new Date(`${value}T00:00:00`).toLocaleDateString('de-DE') : value
		const filled = (key) => key !== 'funeral_type' && String(data[key] ?? '').trim() !== ''
		// Im Block „Auftraggeber/in“ bzw. „Familienstand und Ehepartner/in“ genügt die kurze Bezeichnung (Vorname statt Vorname Auftraggeber/in).
		const row = (key) => `<div><dt>${esc(String(fieldLabels[key] || key).replace(/ (Auftraggeber\/in|Ehepartner\/in)( \(falls vorverstorben\))?$/, '$2'))}</dt><dd>${esc(display(key, data[key]))}</dd></div>`
		const known = new Set(['notes', ...reviewGroups.flatMap(([, keys]) => keys)])
		const groups = [...reviewGroups, ['Weitere Angaben', Object.keys(data).filter((key) => !known.has(key))]]
			.map(([title, keys]) => [title, keys.filter(filled)]).filter(([, keys]) => keys.length)
		const missing = ['first_name', 'last_name', 'date_of_death', 'burial_variant_code', 'branch', 'responsible_employee'].filter((key) => !filled(key)).map((key) => fieldLabels[key] || key)
		// 0.85.0: Unbeantwortete Rückfragen gehören ebenfalls zu „Noch offen“; vorbelegte Werte mit Prüfhinweis (z. B. Aufteilung der Sterbeurkunden) zu „Bitte prüfen“.
		const questions = capture().questions || []
		for (const question of questions) if (question.kind !== 'CHECK' && question.field && !filled(question.field) && !missing.includes(question.label) && !missing.includes(fieldLabels[question.field])) missing.push(question.label || fieldLabels[question.field] || question.field)
		const checks = questions.filter((question) => question.kind === 'CHECK' && filled(question.field))
		const followUps = selectedFollowUps()
		const cards = groups.map(([title, keys]) => `<section class="bp-capture-review-group"><h4>${esc(title)}</h4><dl>${keys.map(row).join('')}</dl></section>`).join('')
		const note = filled('notes') ? `<section class="bp-capture-review-group bp-capture-review-note"><h4>Gesprächsnotiz</h4><p>${esc(data.notes)}</p></section>` : ''
		return `<section class="bp-panel bp-capture-panel"><div class="bp-panel-head"><div><p class="bp-eyebrow">Schritt 3</p><h3>Entwurf verbindlich anlegen</h3></div><span class="bp-status">Noch nicht gespeichert</span></div>
			<p>Bitte kontrolliere besonders Namen, Datumsangaben, Bestattungsart, Niederlassung und Zuständigkeit. Der Assistent legt erst nach dem folgenden Klick einen Fall an.</p>
			${(capture().duplicates || []).length ? `<div class="bp-warning" role="alert"><b>Möglicher Doppelfall:</b> ${capture().duplicates.map((item) => esc(`${item.caseNumber} · ${item.firstName} ${item.lastName}${item.dateOfDeath ? ` · † ${new Date(`${item.dateOfDeath}T00:00:00`).toLocaleDateString('de-DE')}` : ''}`)).join('; ')}. Bitte prüfen, ob der Sterbefall schon angelegt ist.</div>` : ''}
			${missing.length ? `<p class="bp-capture-review-missing"><b>Noch offen:</b> ${missing.map((label) => esc(label)).join(', ')} – über „Zurück“ ergänzen oder später in der Fallakte.</p>` : ''}
			${checks.length ? `<div class="bp-capture-review-missing bp-capture-review-checks"><b>Bitte prüfen:</b><ul>${checks.map((question) => `<li>${esc(question.question)}</li>`).join('')}</ul></div>` : ''}
			${followUps.length ? `<div class="bp-capture-review-followups"><b>Nach dem Anlegen:</b><ul>${followUps.map((item) => `<li>Aufgabe <b>${esc(item.label)}</b> – ${esc(followUpNote(item.key))}</li>`).join('')}</ul></div>` : ''}
			<div class="bp-capture-review">${cards || note ? cards + note : '<p>Keine Angaben vorhanden.</p>'}</div>
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
		if (intent === 'EXECUTE_PROCESS_ACTION') return 'Dokumentpaket geprüft erzeugen'
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
		return `<button type="button" class="bp-assistant-toggle" id="assistant-sidebar-toggle" aria-controls="assistant-sidebar" aria-expanded="${side.open ? 'true' : 'false'}"><span aria-hidden="true">✦</span><span class="bp-assistant-toggle-label">Assistent</span></button>
		<aside id="assistant-sidebar" class="bp-assistant-sidebar ${side.open ? 'open' : ''}" aria-hidden="${side.open ? 'false' : 'true'}" aria-label="Bestatter-Assistent">
			<header><div><p class="bp-eyebrow">Fallbezogene Hilfe</p><h2>Bestatter-Assistent</h2></div><button type="button" class="bp-icon-button" id="assistant-sidebar-close" aria-label="Assistent schließen">×</button></header>
			<p class="bp-muted">Funktioniert auch ohne KI-Anbieter. Schreibende Vorschläge werden niemals ohne Ihre ausdrückliche Bestätigung ausgeführt.</p>
			<div class="bp-assistant-quick" aria-label="Schnellfragen"><button type="button" data-assistant-question="Was ist heute fällig?">Mein Arbeitstag</button><button type="button" data-assistant-question="Suche Fall ">Fall suchen</button><button type="button" data-assistant-question="Welche Angaben fehlen in diesem Fall?">Fehlende Angaben</button><button type="button" data-assistant-question="Öffne die Stammdaten dieses Falls">Stammdaten</button><button type="button" data-assistant-question="Öffne die Leistungen dieses Falls">Leistungen</button><button type="button" data-assistant-question="Bereite eine Abmeldung vor">Abmeldung</button><button type="button" data-assistant-question="Ist der Fall für die Schlussrechnung vollständig?">Rechnungsbereitschaft</button><button type="button" data-assistant-question="Welche Dokumentausgaben kann ich für diesen Fall erzeugen?">Dokument ausgeben</button><button type="button" data-assistant-question="Welche Dokumente sind für diesen Fall eingegangen?">Eingegangene Dokumente</button><button type="button" data-assistant-question="Was liegt im Belegeingang?">Belegeingang</button>${isAdmin() ? '<button type="button" data-assistant-question="Ist Paperless richtig eingerichtet?">Paperless prüfen</button>' : ''}</div>
			<form id="assistant-sidebar-command"><label class="bp-assistant-case-field"><span>Fallbezug</span><select name="caseId" title="${esc(selectedCaseLabel)}"><option value="0">Kein Fall ausgewählt</option>${caseOptions}</select><small aria-live="polite">${esc(selectedCaseLabel)}</small></label><label><span>Frage oder Arbeitsauftrag</span><textarea name="input" rows="4" placeholder="Zum Beispiel: Was ist heute fällig?">${esc(side.command)}</textarea><small>Enter startet die Prüfung · Umschalt+Enter fügt eine neue Zeile ein.</small></label><button class="bp-primary">Prüfen</button></form>
			<div class="bp-assistant-response" role="status" aria-live="polite">${assistantPreview(preview)}</div>
			<footer><small>Der Assistent arbeitet mit geprüften fachlichen Regeln; es werden keine Falldaten an eine Text-KI übermittelt.</small></footer>
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
		document.getElementById('capture-reset')?.addEventListener('click', resetCapture)
		document.getElementById('capture-transcript')?.addEventListener('input', (event) => { capture().transcript = event.target.value; persist() })
		document.getElementById('capture-analyze')?.addEventListener('click', analyzeTranscript)
		document.getElementById('capture-apply-suggestions')?.addEventListener('click', () => { persistFields(); applySuggestions(); render() })
		document.querySelectorAll('[data-capture-burial-level]').forEach((select) => select.addEventListener('change', () => {
			persistFields()
			capture().data.burial_variant_code = select.value || (Number(select.dataset.captureBurialLevel) ? variantPath(capture().data.burial_variant_code)[Number(select.dataset.captureBurialLevel) - 1]?.value || '' : '')
			persist(); render()
		}))
		document.querySelectorAll('[data-conflict-field] input[type="radio"]').forEach((input) => input.addEventListener('change', () => {
			persistFields()
			const field = input.closest('[data-conflict-field]').dataset.conflictField
			const entry = allConflicts().find((item) => item.field === field)
			const value = input.dataset.conflictValue === 'own' ? '' : entry?.values[Number(input.dataset.conflictValue)]?.value
			if (value === undefined) return
			applyConflictChoice(field, value); persist(); render()
			if (value === '') requestAnimationFrame(() => document.querySelector(`#capture-fields [name="${CSS.escape(field === 'funeral_type' ? 'burial_variant_code' : field)}"]`)?.focus())
		}))
		document.querySelectorAll('[data-follow-up]').forEach((input) => input.addEventListener('change', () => { capture().followUpSelected = { ...(capture().followUpSelected || {}), [input.dataset.followUp]: input.checked }; persist() }))
		document.querySelectorAll('[data-focus-capture-field]').forEach((link) => link.addEventListener('click', (event) => { event.preventDefault(); document.querySelector(`#capture-fields [name="${CSS.escape(link.dataset.focusCaptureField)}"]`)?.focus() }))
		document.getElementById('capture-review')?.addEventListener('click', async () => {
			persistFields()
			const open = unresolvedConflicts()
			if (open.length) { notifyWarning(`Bitte bei widersprüchlichen Angaben eine Variante wählen oder „Selbst eintragen“: ${open.map((entry) => entry.label).join(', ')}.`); document.querySelector('.bp-capture-conflicts')?.scrollIntoView({ block: 'center' }); return }
			// 0.71.0: Angehakte Vorschläge gelten auch ohne „übernehmen“ – aber nur für noch leere Felder.
			applySuggestions(false, true)
			render()
			const refreshed = document.getElementById('capture-fields')
			if (refreshed && !refreshed.reportValidity()) return
			capture().duplicates = await findDuplicates(capture().data)
			capture().step = 3; capture().maxStep = 3; persist(); render()
		})
		document.getElementById('capture-create-case')?.addEventListener('click', createCase)
		document.getElementById('capture-record')?.addEventListener('click', toggleRecording)
		document.getElementById('capture-audio-select')?.addEventListener('click', () => document.getElementById('capture-audio-file')?.click())
		document.getElementById('capture-audio-file')?.addEventListener('change', (event) => { if (event.target.files?.[0]) transcribe(event.target.files[0]) })
		document.getElementById('capture-scan-select')?.addEventListener('click', () => document.getElementById('capture-scan-file')?.click())
		document.getElementById('capture-scan-file')?.addEventListener('change', (event) => { if (event.target.files?.[0]) uploadCaptureImport(event.target.files[0]) })
		document.getElementById('capture-scan-retry')?.addEventListener('click', retryCaptureImport)
		document.getElementById('capture-scan-discard')?.addEventListener('click', async () => { if (await ctx.confirmAction('Den hochgeladenen Erfassungsbogen verwerfen? Der Scan wird gelöscht; bereits eingetragene Angaben bleiben erhalten.', { title: 'Erfassungsbogen verwerfen', confirmLabel: 'Verwerfen' })) { await discardScan(); render() } })
		document.getElementById('capture-audio-resend')?.addEventListener('click', () => { if (pendingAudio) transcribe(pendingAudio) })
		document.getElementById('capture-audio-download')?.addEventListener('click', downloadPendingAudio)
		document.getElementById('capture-scan-check')?.addEventListener('click', () => pollCaptureImport(Number(capture().scanImport?.id || 0)).catch((error) => notifyError(error.message)))
		document.getElementById('capture-scan-open')?.addEventListener('click', () => window.open(OC.generateUrl(`/f/${capture().scanImport?.fileId}`), '_blank', 'noopener'))
		document.getElementById('capture-transcription-retry')?.addEventListener('click', () => pollTranscription(Number(capture().transcriptionTaskId), 40).catch(handleTranscriptionError))
		document.getElementById('assistant-command')?.addEventListener('submit', previewIntent)
		document.getElementById('assistant-execute')?.addEventListener('click', executeIntent)
		resumePending()
	}

	/** 0.71.0: Nach Neuladen laufende Transkription bzw. Texterkennung weiter abfragen (vorher blieb sie liegen). */
	function resumePending() {
		if (resumeChecked) return
		resumeChecked = true
		const taskId = Number(capture().transcriptionTaskId || 0)
		if (taskId > 0 && !transcriptionBusy) { transcriptionBusy = true; pollTranscription(taskId, 40).catch(handleTranscriptionError).finally(() => { transcriptionBusy = false; render() }) }
		const scan = capture().scanImport
		if (scan?.id && ['OCR_QUEUED', 'OCR_PROCESSING', 'OCR_DELAYED'].includes(scan.status)) pollCaptureImport(Number(scan.id)).catch(() => {})
	}

	async function resetCapture() {
		const c = capture()
		const hasContent = Boolean(String(c.transcript || '').trim() || c.scanImport?.id || Object.entries(c.data || {}).some(([key, value]) => !['branch', 'responsible_employee'].includes(key) && String(value || '').trim()))
		if (hasContent && !await ctx.confirmAction('Den Entwurf verwerfen? Gesprächstext, Angaben und ein hochgeladener Erfassungsbogen werden gelöscht.', { title: 'Entwurf verwerfen', confirmLabel: 'Verwerfen' })) return
		await discardScan()
		pendingAudio = null
		state.assistantCapture = emptyCapture()
		try { localStorage.removeItem(storageKey()) } catch (_) {}
		render()
	}

	async function discardScan() {
		const id = Number(capture().scanImport?.id || 0)
		if (id) { try { await api(`${ctx.apiBase}/assistant/capture-imports/${id}/discard`, { method: 'POST', feedback: false }) } catch (error) { notifyWarning(error.message) } }
		capture().scanImport = null; capture().ocrSuggestions = []; capture().ocrText = ''
		persist()
	}

	function downloadPendingAudio() {
		if (!pendingAudio) return
		const url = URL.createObjectURL(pendingAudio)
		const link = document.createElement('a'); link.href = url; link.download = pendingAudio.name || 'aufnahme.webm'; document.body.append(link); link.click(); link.remove()
		setTimeout(() => URL.revokeObjectURL(url), 5000)
	}

	/** Mögliche Doppelfälle: gleicher Name, bei bekanntem Sterbedatum auch gleiches Datum. */
	async function findDuplicates(data) {
		const last = String(data.last_name || '').trim(); const first = String(data.first_name || '').trim()
		if (!last) return []
		try {
			const result = await api(`${ctx.apiBase}/cases/search?${new URLSearchParams({ query: last, status: 'ALL', branch: 'ALL', responsible: 'ALL', limit: '25', offset: '0' })}`, { feedback: false })
			const norm = (value) => String(value || '').toLocaleLowerCase('de').normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim()
			return (result.items || []).filter((item) => norm(item.lastName) === norm(last) && (!first || norm(item.firstName) === norm(first)) && (!data.date_of_death || !item.dateOfDeath || item.dateOfDeath === data.date_of_death)).slice(0, 5)
		} catch (_) { return [] }
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
				if (result.navigation.customizingTab) state.customizingTab = result.navigation.customizingTab
				// 0.81.0: Daten des Zielbereichs laden wie beim Klick in der Navigation
				if (state.view === 'inbox' && ctx.loadPaperlessInbox) await ctx.loadPaperlessInbox()
				if (state.view === 'administration' && state.administrationTab === 'system' && ctx.loadSystemCheck) await ctx.loadSystemCheck()
				if (state.view === 'administration' && state.administrationTab === 'paperless' && ctx.loadPaperlessConfiguration) await ctx.loadPaperlessConfiguration()
				if (state.view === 'customizing' && state.customizingTab === 'document-kinds' && ctx.loadDocumentKinds) await ctx.loadDocumentKinds()
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
		if (!capture().transcript && capture().scanImport?.id) {
			// Nur Scan: direkt zu den Angaben (Vorschläge aus der Texterkennung bzw. manuelle Übertragung)
			capture().suggestions = []; capture().questions = []; capture().conflicts = []; capture().followUps = []; capture().followUpSelected = {}
			capture().step = 2; capture().maxStep = Math.max(capture().maxStep, 2); persist(); render(); return
		}
		try {
			const result = await api(`${ctx.apiBase}/assistant/analyze`, { method: 'POST', feedback: false, loadingLabel: 'Gesprächstext wird ausgewertet …', body: new URLSearchParams({ text: capture().transcript, context: 'CASE_CAPTURE' }) })
			capture().suggestions = result.suggestions || []; capture().warnings = result.warnings || []; capture().questions = result.questions || []; capture().conflicts = result.conflicts || []; capture().conflictChoices = {}
			// 0.85.0: Folgeschritte (z. B. Aufgabe zum unterschriebenen Auftrag) sind vorausgewählt, aber abwählbar.
			capture().followUps = result.followUps || []; capture().followUpSelected = Object.fromEntries(capture().followUps.map((item) => [item.key, true]))
			capture().step = 2; capture().maxStep = Math.max(capture().maxStep, 2); persist(); render()
		} catch (error) { notifyError(error.message) }
	}

	async function uploadCaptureImport(file) {
		const ocr = ocrState(state.assistantConfiguration || {})
		const image = /^image\/(jpeg|png)$/.test(file.type) || /\.(jpe?g|png)$/i.test(file.name)
		const pdf = /\.pdf$/i.test(file.name) || file.type === 'application/pdf'
		if (!pdf && !(image && ocr.acceptsImages)) { notifyError(ocr.acceptsImages ? 'Bitte den Erfassungsbogen als PDF oder Foto (JPG/PNG) auswählen.' : 'Bitte den Erfassungsbogen als PDF auswählen.'); return }
		const body=new FormData();body.append('document',file);body.append('templateKey','AUFTRAGSERFASSUNG_STANDARD');body.append('templateVersion','1')
		try{
			capture().scanImport={status:'UPLOADING',originalName:file.name};persist();render()
			const item=await api(`${ctx.apiBase}/assistant/capture-imports`,{method:'POST',body,feedback:false,loadingLabel:'Erfassungsbogen wird sicher gespeichert …'})
			capture().scanImport=item;persist();render()
			if(item.resumed)notifyWarning('Dieser Erfassungsbogen ist bereits in Bearbeitung – der vorhandene Vorgang wird fortgesetzt.')
			if(item.status==='EXTRACTION_READY')applyOcrResult(item)
			else if(['OCR_QUEUED','OCR_PROCESSING'].includes(item.status))await pollCaptureImport(item.id)
			else if(['OCR_FAILED','REVIEW_REQUIRED','MANUAL_REVIEW'].includes(item.status))notifyWarning(item.errorMessage||'Die Texterkennung konnte nicht abgeschlossen werden. Der Scan ist gespeichert; bitte die Angaben manuell übertragen.')
		}catch(error){capture().scanImport=null;persist();render();notifyError(error.message)}
	}

	/** 0.71.0: OCR-Ergebnis getrennt vom Gesprächstext halten (vorher überschrieben) und den Schritt nur vorwärts setzen. */
	function applyOcrResult(item){
		capture().ocrText=item.ocrText||'';capture().ocrSuggestions=item.suggestions||[]
		const hint='OCR-Angaben sind ungeprüfte Vorschläge. Bitte mit dem Originalscan vergleichen.'
		if(!(capture().warnings||[]).includes(hint))capture().warnings=[...(capture().warnings||[]),hint]
		if(capture().step===1)capture().step=2
		capture().maxStep=Math.max(capture().maxStep,2);persist()
		// 0.79.0: Ohne lesbare Angaben deutlich sagen, warum – statt „abgeschlossen“ ohne Ergebnis.
		if(!(item.suggestions||[]).length){
			const empty='Der Bogen wurde gelesen, aber keine Angabe war sicher erkennbar. Handschriftliche Einträge kann die Texterkennung meist nicht lesen – bitte die Angaben anhand des Scans übertragen.'
			if(!(capture().warnings||[]).includes(empty))capture().warnings=[...(capture().warnings||[]),empty]
			notifyWarning(empty)
		}else notifySuccess(`Texterkennung abgeschlossen: ${(item.suggestions||[]).length} Angabe(n) erkannt. Bitte alle Vorschläge mit dem Original vergleichen.`)
		if(item.errorMessage)notifyWarning(item.errorMessage)
		render()
	}

	/**
	 * 0.79.0 – Status abfragen, bis die Erkennung fertig ist: anfangs alle 2 s, nach einer Minute alle 5 s, nach fünf Minuten
	 * alle 15 s, höchstens 15 Minuten (vorher 80 × 2 s = 2½ Minuten). Ohne Ladeüberlagerung; nur die Statuszeile wird aktualisiert.
	 */
	let activeScanPoll=0
	async function pollCaptureImport(id,maxSeconds=900){
		if(!id||activeScanPoll===id)return
		activeScanPoll=id
		try{await pollCaptureImportLoop(id,maxSeconds)}finally{if(activeScanPoll===id)activeScanPoll=0}
	}
	async function pollCaptureImportLoop(id,maxSeconds){
		const started=Date.now()
		while((Date.now()-started)/1000<maxSeconds){
			const previous=capture().scanImport||{}
			let item
			try{item=await api(`${ctx.apiBase}/assistant/capture-imports/${id}`,{feedback:false,loadingLabel:false})}
			catch(error){await new Promise((resolve)=>setTimeout(resolve,5000));continue}
			if(!capture().scanImport||Number(capture().scanImport.id)!==Number(id))return
			capture().scanImport=item;persist()
			if(item.status!==previous.status||item.stage!==previous.stage||item.progress!==previous.progress||item.errorMessage!==previous.errorMessage)render()
			else{const detail=document.getElementById('capture-scan-detail');if(detail)detail.textContent=scanDetailText(item)}
			if(item.status==='EXTRACTION_READY'){applyOcrResult(item);return}
			if(['OCR_FAILED','REVIEW_REQUIRED','MANUAL_REVIEW'].includes(item.status)){notifyWarning(item.errorMessage||'OCR konnte nicht abgeschlossen werden. Die manuelle Erfassung bleibt verfügbar.');return}
			const elapsed=(Date.now()-started)/1000
			await new Promise((resolve)=>setTimeout(resolve,elapsed<60?2000:(elapsed<300?5000:15000)))
		}
		if(capture().scanImport){capture().scanImport.status='OCR_DELAYED';persist();render()}notifyWarning('Die Texterkennung dauert ungewöhnlich lange (über 15 Minuten). Mit „Status prüfen“ lässt sie sich später erneut abfragen.')
	}

	async function retryCaptureImport(){const id=Number(capture().scanImport?.id||0);if(!id)return;try{capture().scanImport=await api(`${ctx.apiBase}/assistant/capture-imports/${id}/retry`,{method:'POST',feedback:false});persist();render();await pollCaptureImport(id)}catch(error){notifyError(error.message)}}

	/**
	 * 0.79.0 – Fortschritt der Texterkennung: Stufen (gespeichert → übergeben → erkannt → ausgewertet), aktuelle Tätigkeit,
	 * vergangene Zeit, nächster Versuch. Vorher nur ein Balken ohne Angabe, was passiert.
	 * 0.79.1 – Schrittnamen für alle Texterkennungen gleich; welches System arbeitet, steht in der Tätigkeitszeile und
	 * unter den Schritten (Texterkennung: Paperless-ngx/Nextcloud). Bis 0.79.0 hatte jedes System eigene Schrittnamen.
	 */
	const SCAN_STAGE_ORDER=['STORED','SENDING','RECOGNIZING','DONE']
	const SCAN_STAGE_LABELS={STORED:'Gespeichert',SENDING:'An Texterkennung übergeben',RECOGNIZING:'Text wird erkannt',DONE:'Angaben ausgewertet'}
	function scanEngineLabel(scan){return scan.ocrEngineLabel||({PAPERLESS:'Paperless-ngx',NEXTCLOUD:'Nextcloud'})[scan.ocrEngine]||''}
	function scanStages(scan){
		const stage=scan.stage==='QUEUED'||scan.stage==='WAITING'?'SENDING':scan.stage
		const current=scan.status==='UPLOADING'?'STORED':(stage||'SENDING')
		const index=Math.max(0,SCAN_STAGE_ORDER.indexOf(current==='FAILED'?'RECOGNIZING':current))
		return SCAN_STAGE_ORDER.map((key,position)=>({key,label:SCAN_STAGE_LABELS[key],state:scan.stage==='DONE'||position<index?'done':(position===index?(scan.stage==='FAILED'?'failed':'active'):'open')}))
	}
	function elapsedLabel(seconds){const value=Math.max(0,Math.round(Number(seconds)||0));return `${Math.floor(value/60)}:${String(value%60).padStart(2,'0')}`}
	function scanDetailText(scan){
		const parts=[scan.stageLabel||scanStatusLabel(scan.status)]
		if(['OCR_QUEUED','OCR_PROCESSING','OCR_DELAYED'].includes(scan.status)&&scan.elapsedSeconds!=null)parts.push(`seit ${elapsedLabel(scan.elapsedSeconds)} Min.`)
		if(scan.nextAttemptAt)parts.push(`nächster Versuch ${new Date(scan.nextAttemptAt).toLocaleTimeString('de-DE',{hour:'2-digit',minute:'2-digit'})} Uhr`)
		if(scan.status==='OCR_DELAYED')parts.push('Die Abfrage wurde angehalten – mit „Status prüfen“ fortsetzen.')
		return parts.join(' · ')
	}
	function scanStateHtml(scan,busy){
		const progress=scan.status==='EXTRACTION_READY'?100:(Number.isFinite(Number(scan.progress))&&scan.progress!==null?Math.round(Number(scan.progress)*100):null)
		const stages=scanStages(scan).map((stage)=>`<li class="is-${stage.state}">${esc(stage.label)}</li>`).join('')
		// Ein Balken nur bei bekanntem Fortschritt; sonst zeigen die Schritte, was passiert (ein leerer Balken wirkte wie Stillstand).
		return `<div class="bp-scan-state ${progress===null?'is-stepwise':''}" data-state="${esc(scan.status)}">${progress!==null?`<progress max="100" value="${progress}"></progress>`:''}<span id="capture-scan-status" role="status" aria-live="polite"><b>${esc(scan.originalName||'Erfassungsbogen')}</b> · <span id="capture-scan-detail">${esc(scanDetailText(scan))}</span>${scan.errorMessage?` · ${esc(scan.errorMessage)}`:''}</span><ol class="bp-scan-stages" aria-label="Verarbeitungsschritte">${stages}</ol>${scanEngineLabel(scan)?`<small class="bp-scan-engine">Texterkennung: ${esc(scanEngineLabel(scan))}</small>`:''}</div>`
	}

	function scanStatusLabel(status){return ({UPLOADING:'wird hochgeladen',UPLOADED:'sicher gespeichert',OCR_QUEUED:'an Texterkennung übergeben',OCR_PROCESSING:'Text wird erkannt',EXTRACTION_READY:'OCR abgeschlossen – Prüfung erforderlich',REVIEW_REQUIRED:'manuelle Prüfung erforderlich',MANUAL_REVIEW:'manuelle Erfassung',OCR_FAILED:'Texterkennung fehlgeschlagen',OCR_DELAYED:'Texterkennung verzögert',COMPLETED:'dem Fall zugeordnet',DISCARDED:'verworfen'})[status]||String(status||'bereit')}

	function applySuggestions(all = false, onlyEmpty = false) {
		const list = allSuggestions()
		const boxes = [...document.querySelectorAll('[data-suggestion]')]
		const selected = all ? list.map((_, index) => index) : boxes.filter((input) => input.checked).map((input) => Number(input.dataset.suggestion))
		const conflictFields = new Set(allConflicts().map((entry) => entry.field))
		for (const index of selected) {
			const item = list[index]
			if (!item) continue
			// Widersprüchliche Felder nur über die ausdrückliche Auswahl füllen.
			if (conflictFields.has(item.field)) continue
			if (onlyEmpty && String(item.field === 'funeral_type' ? capture().data.burial_variant_code || '' : capture().data[item.field] || '').trim()) continue
			if (item.field === 'funeral_type') {
				const code = suggestedVariant(item.value)
				if (code) capture().data.burial_variant_code = code
				else if (!capture().warnings.includes('Bestattungsart bitte anhand der Variantenliste selbst auswählen.')) capture().warnings.push('Bestattungsart bitte anhand der Variantenliste selbst auswählen.')
			} else capture().data[item.field] = item.value
		}
		if (!capture().data.notes) capture().data.notes = capture().transcript
		persist()
	}

	function persistFields() {
		const form = document.getElementById('capture-fields')
		if (form) capture().data = { ...capture().data, ...Object.fromEntries(new FormData(form)) }
		persist()
	}

	async function createCase(event) {
		const button = event?.currentTarget instanceof HTMLElement ? event.currentTarget : null
		if (button?.disabled) return
		if ((capture().duplicates || []).length && !await ctx.confirmAction('Es gibt bereits einen Fall mit diesem Namen. Trotzdem einen neuen Fall anlegen?', { title: 'Möglicher Doppelfall', confirmLabel: 'Neuen Fall anlegen' })) return
		if (button) { button.disabled = true; button.textContent = 'Fall wird angelegt …' }
		try {
			const scanImport = capture().scanImport ? { ...capture().scanImport } : null
			const data = { ...capture().data, status: 'NEU' }
			delete data.funeral_type // Ausschließlich der Variantenbaum bestimmt die Bestattungsart.
			const corrections = allSuggestions().filter((item) => String(data[item.field] || '').trim() && String(data[item.field]).trim() !== String(item.value || '').trim()).map((item) => ({ field: item.field, source: item.source, observedValue: item.value, confirmedValue: data[item.field] }))
			if (corrections.length) await api(`${ctx.apiBase}/assistant/corrections`, { method: 'POST', feedback: false, loadingLabel: 'Bestätigte Korrekturen werden für die fachliche Prüfung vorgemerkt …', body: new URLSearchParams({ corrections: JSON.stringify(corrections) }) })
			const result = await api(ctx.urls.cases, { method: 'POST', feedback: false, loadingLabel: 'Geprüfter Fall wird angelegt …', body: new URLSearchParams({ masterData: JSON.stringify(data), firstName: data.first_name, lastName: data.last_name, creationToken: capture().creationToken }) })
			if(scanImport?.id){const ocrFields=new Set((capture().ocrSuggestions||[]).map((item)=>item.field));const confirmedFields=Object.entries(data).filter(([key,value])=>ocrFields.has(key)&&String(value||'').trim()!=='').map(([key])=>key);await api(`${ctx.apiBase}/assistant/capture-imports/${scanImport.id}/complete`,{method:'POST',feedback:false,loadingLabel:'Originalscan wird dem Fall zugeordnet …',body:new URLSearchParams({caseId:String(result.id),confirmedFields:JSON.stringify(confirmedFields)})})}
			// 0.85.0: Folgeaufgaben aus dem Gespräch; ein Fehler verhindert die Fallanlage nicht.
			const followUpWarnings = await createFollowUpTasks(result.id, data, selectedFollowUps())
			state.currentCase = await api(`${ctx.urls.cases}/${result.id}`)
			state.cases = await api(ctx.urls.cases)
			pendingAudio = null
			state.assistantCapture = emptyCapture(); try { localStorage.removeItem(storageKey()) } catch (_) {}
			state.view = 'case-detail'; state.caseTab = 'overview'
			notifySuccess(`Fall ${state.currentCase.caseNumber} wurde angelegt.`)
			const warnings = Array.isArray(result.validationWarnings) ? result.validationWarnings : []
			if (warnings.length) notifyWarning(`Bitte prüfen: ${warnings.join(' ')}`)
			if (followUpWarnings.length) notifyWarning(followUpWarnings.join(' '))
			render()
		} catch (error) { notifyError(error.message); if (button) { button.disabled = false; button.textContent = 'Geprüften Fall anlegen' } }
	}

	async function createFollowUpTasks(caseId, data, followUps) {
		const warnings = []
		const today = new Date(); const pad = (value) => String(value).padStart(2, '0')
		const due = `${today.getFullYear()}-${pad(today.getMonth() + 1)}-${pad(today.getDate())}T17:00`
		for (const item of followUps) {
			const description = item.key === 'SIGNED_ORDER_UPLOAD'
				? `Laut Gespräch liegt der unterschriebene Auftrag vor („${item.source}“). Scan in der Fallakte unter Dokumente → „Handschriftlich unterschriebener Vertrag“ hochladen und die Sichtprüfung bestätigen. Erst dann gilt der Auftrag als unterschrieben. Danach diese Aufgabe erledigen.`
				: item.label
			try {
				await api(`${ctx.apiBase}/records/task`, { method: 'POST', feedback: false, loadingLabel: 'Folgeaufgabe wird angelegt …', body: new URLSearchParams({ title: item.label, date: due, status: 'OFFEN', caseId: String(caseId), data: JSON.stringify({ description, priority: 'HOCH', assigneeUid: data.responsible_employee || currentUser(), captureFollowUp: item.key }) }) })
			} catch (error) { warnings.push(`Die Aufgabe „${item.label}“ konnte nicht angelegt werden (${error.message}). Bitte von Hand anlegen.`) }
		}
		return warnings
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
				pendingAudio = file
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
				? `Das Mikrofon wurde nicht freigegeben. Bitte die Websiteberechtigung für ${globalThis.location?.host || 'diese Seite'} prüfen und die Seite neu laden. Alternativ kann eine Audiodatei ausgewählt werden.`
				: `Das Mikrofon konnte nicht verwendet werden: ${error.message}`
			notifyError(message)
		}
	}

	async function transcribe(file) {
		const limit = Number(state.assistantConfiguration?.limits?.audioBytes || 0)
		pendingAudio = file
		if (limit && file.size > limit) { handleTranscriptionError(new Error(`Die Aufnahme ist mit ${(file.size / 1048576).toFixed(1)} MB größer als erlaubt (${Math.round(limit / 1048576)} MB). Bitte speichern und kürzer aufnehmen.`)); return }
		const status = document.getElementById('capture-speech-state'); if (status) status.textContent = 'Audio wird hochgeladen …'
		setTranscriptionProgress(null, `„${file.name}“ wird hochgeladen …`, 'uploading')
		const body = new FormData(); body.append('audio', file)
		transcriptionBusy = true; render()
		try {
			const task = await api(`${ctx.apiBase}/assistant/transcriptions`, { method: 'POST', body, feedback: false, loadingLabel: 'Transkription wird gestartet …' })
			capture().transcriptionTaskId = Number(task.taskId || 0); persist()
			setTranscriptionProgress(10, 'Upload abgeschlossen · Transkription wurde eingeplant.', 'scheduled')
			await pollTranscription(task.taskId)
		} catch (error) { handleTranscriptionError(error, status) }
		finally { transcriptionBusy = false; render() }
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
				pendingAudio = null
				persist(); notifySuccess('Die Aufnahme wurde transkribiert.'); render(); return
			}
			if (['FAILED', 'CANCELLED'].includes(result.status)) { capture().transcriptionTaskId = null; persist(); throw new Error(result.message || 'Die Transkription ist fehlgeschlagen.') }
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
		const message = (error?.message || 'Die Transkription ist fehlgeschlagen.') + (pendingAudio ? ' Die Aufnahme ist noch vorhanden und kann erneut gesendet oder gespeichert werden.' : '')
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
		try { localStorage.setItem(storageKey(), JSON.stringify(capture())) } catch (_) { notifyWarning('Der lokale Entwurf konnte im Browser nicht zwischengespeichert werden.') }
	}

	function emptyCapture() {
		return { step: 1, maxStep: 1, transcript: '', suggestions: [], ocrSuggestions: [], ocrText: '', duplicates: [], conflictChoices: {}, warnings: [], questions: [], conflicts: [], followUps: [], followUpSelected: {}, scanImport: null, recording: false, recordingStartedAt: null, transcriptionTaskId: null, transcriptionProgress: { visible: false, progress: null, label: '', status: 'idle' }, data: { salutation: '', title: '', first_name: '', last_name: '', birth_name: '', date_of_birth: '', date_of_death: '', burial_variant_code: '', place_of_death: '', birth_place: '', birth_registry_office: '', profession: '', pension_insurance_number: '', civil_status: '', spouse_first_name: '', spouse_last_name: '', spouse_date_of_birth: '', spouse_birth_place: '', spouse_residence: '', spouse_date_of_death: '', spouse_death_place: '', marriage_date: '', marriage_place: '', partnership_date: '', divorce_date: '', religion: '', last_residence: '', last_residence_postal_code: '', last_residence_city: '', cemetery_contact: '', order_client_relation: '', order_client_first_name: '', order_client_name: '', order_client_mobile: '', certificate_free_count: '', certificate_paid_count: '', branch: defaultBranch(), responsible_employee: currentUser(), notes: '' }, command: '', assistantCaseId: 0, intentPreview: null, creationToken: ctx.createToken() }
	}

	function defaultBranch() { return state.branches.find((item) => item.active && (item.memberUids || []).includes(currentUser()))?.key || state.branches.find((item) => item.active)?.key || '' }
	function currentUser() { return state.team.currentUid || (OC.getCurrentUser && OC.getCurrentUser().uid) || '' }

	function initializeAssistantCapture() {
		if (!state.assistantSidebar) state.assistantSidebar = { open: false, command: '', caseId: Number(state.currentCase?.id || 0), preview: null }
		if (state.assistantCapture) return
		try {
			// 0.71.0: Entwurf je Benutzer; der frühere gemeinsame Schlüssel wird nicht übernommen (unbekannter Eigentümer).
			try { localStorage.removeItem('bestatter-assistant-capture') } catch (_) {}
			const saved = JSON.parse(localStorage.getItem(storageKey()) || 'null')
			state.assistantCapture = saved && typeof saved === 'object' && saved.creationToken ? { ...saved, recording: false, recordingStartedAt: null } : emptyCapture()
		} catch (_) { state.assistantCapture = emptyCapture() }
	}

	return { guidedCaptureView, assistantSidebarView, bindAssistant, initializeAssistantCapture, emptyCapture }
}
