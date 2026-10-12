export function createCustomizingModule(ctx) {
	const { root, urls, apiBase, state } = ctx
	const api = (...args) => ctx.api(...args)
	const branchUrl = (...args) => ctx.branchUrl(...args)
	const deliveryChannels = (...args) => ctx.deliveryChannels(...args)
	const deregistrationTemplateUrl = (...args) => ctx.deregistrationTemplateUrl(...args)
	const documentTemplateUrl = (...args) => ctx.documentTemplateUrl(...args)
	const documentTemplateOptionsUrl = (...args) => ctx.documentTemplateOptionsUrl(...args)
	const esc = (...args) => ctx.esc(...args)
	const field = (...args) => ctx.field(...args)
	const invoiceSettingsUrl = (...args) => ctx.invoiceSettingsUrl(...args)
	const schedulingUrl = (...args) => ctx.schedulingUrl(...args)
	const render = (...args) => ctx.render(...args)
	const title = (...args) => ctx.title(...args)
	const confirmAction = (...args) => ctx.confirmAction(...args)
	const promptAction = (...args) => ctx.promptAction(...args)
	const notifySuccess = (...args) => ctx.notifySuccess(...args)
	const notifyError = (...args) => ctx.notifyError(...args)
	const download = (...args) => ctx.download(...args)

	async function showDocumentTemplatePreview(fileName, previewTitle = '') {
		const heading = previewTitle || fileName
		root.insertAdjacentHTML('beforeend', `<div class="bp-modal" role="dialog" aria-modal="true" aria-labelledby="bp-template-preview-title"><section class="bp-wide-modal bp-order-preview"><div class="bp-panel-head bp-file-preview-head"><div><p class="bp-eyebrow">Vorschau mit Beispieldaten</p><h2 id="bp-template-preview-title">${esc(heading)}</h2><p class="bp-muted">Name, Daten und Beträge sind frei erfunden und dienen ausschließlich der Layout-Kontrolle dieser Vorlage. Es wird keine Datei und kein Dokumentdatensatz in einer echten Fallakte angelegt.</p></div><button type="button" class="bp-secondary" data-close-template-preview>Schließen</button></div><div class="bp-order-preview-stage" aria-busy="true"><p class="bp-empty" data-template-preview-loading>PDF-Vorschau wird erzeugt …</p><iframe title="${esc(heading)}" data-template-preview-frame hidden></iframe></div><p class="bp-save-state" data-template-preview-state aria-live="polite"></p><div class="bp-modal-actions"><button type="button" class="bp-secondary" data-open-template-preview disabled>PDF öffnen / drucken</button></div></section></div>`)
		const modal = [...root.querySelectorAll('.bp-modal')].pop()
		const frame = modal.querySelector('[data-template-preview-frame]')
		const stage = modal.querySelector('.bp-order-preview-stage')
		const status = modal.querySelector('[data-template-preview-state]')
		const openButton = modal.querySelector('[data-open-template-preview]')
		let closed = false
		let objectUrl = null
		const close = () => { closed = true; if (objectUrl) URL.revokeObjectURL(objectUrl); modal.remove() }
		modal.querySelector('[data-close-template-preview]').onclick = close
		try {
			const response = await fetch(`${apiBase}/document-templates/preview.pdf?fileName=${encodeURIComponent(fileName)}`, { credentials: 'same-origin', headers: { requesttoken: OC.requestToken } })
			if (!response.ok) {
				const text = await response.text()
				let message = ''
				try { message = JSON.parse(text)?.message || '' } catch (_) { message = text.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim() }
				throw new Error(message || `PDF-Vorschau fehlgeschlagen (HTTP ${response.status}).`)
			}
			const blob = await response.blob()
			if (!String(blob.type || '').includes('pdf')) throw new Error('Der Server hat keine PDF-Vorschau geliefert.')
			if (closed) return
			objectUrl = URL.createObjectURL(blob)
			modal.querySelector('[data-template-preview-loading]')?.remove()
			stage.setAttribute('aria-busy', 'false')
			frame.src = objectUrl
			frame.hidden = false
			openButton.disabled = false
			openButton.onclick = () => window.open(objectUrl, '_blank', 'noopener')
			status.textContent = 'Vorschau mit Beispieldaten vollständig. Es wurde kein Dokument angelegt.'
			status.dataset.state = 'saved'
		} catch (error) {
			if (closed) return
			stage.setAttribute('aria-busy', 'false')
			stage.innerHTML = `<div class="bp-error"><b>Die PDF-Vorschau konnte nicht erzeugt werden.</b><br>${esc(error.message)}</div>`
			status.textContent = 'Es wurde kein Dokument angelegt.'
			status.dataset.state = 'error'
		}
	}

	function documentTemplatesView() {
		const files = state.documentTemplateOptions.files || []; const subfolders = state.documentTemplateOptions.subfolders || []
		const cards = state.documentTemplates.map((entry) => `<article class="bp-checklist-editor" data-document-template="${entry.id}"><div class="bp-form-grid"><label><span>Vorlagenschlüssel</span><input name="key" value="${esc(entry.key)}" pattern="[A-Za-z0-9_\\-]+" ${entry.id ? 'readonly' : 'required'}></label><label><span>Bezeichnung</span><input name="name" value="${esc(entry.name)}" required></label><label><span>Kategorie</span><input name="category" value="${esc(entry.category)}"></label><label><span>DOCX-Datei aus Bestatter/Vorlagen</span><select name="fileName" required><option value="">Bitte auswählen</option>${files.map((file)=>`<option value="${esc(file.fileName)}" ${file.fileName===entry.fileName?'selected':''}>${esc(file.label||file.fileName)}</option>`).join('')}</select></label><label><span>Ziel-Unterordner der Fallakte</span><select name="outputSubfolder" required><option value="">Bitte auswählen</option>${subfolders.map((folder)=>`<option value="${esc(folder)}" ${folder===entry.outputSubfolder?'selected':''}>${esc(folder)}</option>`).join('')}</select></label><label><span>Sortierung</span><input name="sortOrder" type="number" value="${Number(entry.sortOrder || 0)}"></label></div><label><span>Beschreibung</span><textarea name="description">${esc(entry.description)}</textarea></label><details><summary>Technische Pflichtfelder</summary><p class="bp-muted">Kommagetrennte gültige Stammdatenfelder. Unbekannte Schlüssel werden beim Speichern abgewiesen.</p><input name="requiredFields" value="${esc((entry.requiredFields || []).join(', '))}" list="document-required-fields"></details><div class="bp-inline-options"><label class="bp-inline-check"><input name="supportsPdf" type="checkbox" ${entry.supportsPdf ? 'checked' : ''}> PDF-Ausgabe</label><label class="bp-inline-check"><input name="active" type="checkbox" ${entry.active ? 'checked' : ''}> Aktiv</label></div><p class="bp-card-state" aria-live="polite"></p><div class="bp-row-actions"><button class="bp-secondary preview-document-template" type="button">Vorschau</button><button class="bp-secondary save-document-template" data-id="${entry.id}">Speichern</button>${entry.id ? `<button class="bp-secondary duplicate-document-template" type="button" data-id="${entry.id}">Duplizieren</button><button class="bp-danger delete-document-template" data-id="${entry.id}">Löschen</button>` : ''}</div></article>`).join('')
		return `<section class="bp-panel bp-config-admin"><datalist id="document-required-fields">${(state.documentTemplateOptions.requiredFields||[]).map((name)=>`<option value="${esc(name)}"></option>`).join('')}</datalist><div class="bp-panel-head"><div><h3>Dokumentenvorlagen</h3><p class="bp-muted">Vorlagen konfigurieren und im Nextcloud-Ordner Bestatter/Vorlagen bereitstellen. Vorhandene individuelle Dateien werden niemals überschrieben.</p></div><div class="bp-head-actions"><button type="button" class="bp-secondary" id="download-template-fields">Feldkatalog als CSV</button><button type="button" class="bp-secondary" id="download-logo-placeholder" title="Dieses Bild an der gewünschten Stelle in eine DOCX-Vorlage einfügen; beim Erzeugen ersetzt es die App durch das Logo der Niederlassung">Logo-Platzhalter herunterladen</button><button class="bp-secondary" id="provision-templates">Fehlende Standardvorlagen ergänzen</button><button class="bp-primary" id="new-document-template">+ Dokumentenvorlage</button></div></div><div class="bp-token-help"><h4>Verfügbare Vorlagenfelder</h4><p>Der CSV-Katalog enthält alle unterstützten Platzhalter, sprechende Klarnamen und die jeweilige Datenquelle. Er kann jederzeit erneut heruntergeladen werden.</p></div><div class="bp-admin-list">${cards || '<p class="bp-empty">Noch keine Dokumentenvorlagen vorhanden.</p>'}</div></section>`
	}

	// Befund (Review 2026-09-22, Kundenmeldung zum Prozess "Abmeldungen"), Punkte b) und c): "Die
	// Vorschau zeigt nur die Feldnamen an, nicht wie bei den anderen Vorschauen mit Musterdaten
	// gefüllt" und "Die Vorschau zeigt nicht das dazugehörige Formular mit an (falls eins ausgewählt
	// wurde)". Die Customizing-Vorschau eines Abmeldevorgangs übergab Betreff/Text bislang unverändert
	// mit rohen Platzhaltern ({{case.number}} usw.) an showTextPreview() und blendete ein eventuell
	// hinterlegtes Formular komplett aus. Die Platzhalter-Belegung verwendet denselben fiktiven
	// Musterfall "Maria Mustermann"/2026-0000 wie DocumentService::dummyPreviewData() für
	// Dokumentvorlagen-Vorschauen, damit alle Vorschauen im Customizing konsistent wirken. Ist ein
	// Dokumentvorlagen-Schlüssel hinterlegt, wird zusätzlich ein Öffnen der zugehörigen, ebenfalls mit
	// Musterdaten gefüllten Formularvorschau angeboten; verweist der Schlüssel auf keine vorhandene
	// aktive Vorlage, erscheint statt eines stillen Fehlschlags ein Plausibilitätshinweis.
	const DEREGISTRATION_PREVIEW_DUMMY_DATA = {
		'case.number': '2026-0000',
		'case.first_name': 'Maria',
		'case.last_name': 'Mustermann',
		'case.date_of_birth': '12.03.1948',
		'case.date_of_death': '08.09.2026',
		'case.pension_number': '65 120948 M 047',
		'advance.application': 'NEIN',
		'case.broadcast_contribution_number': '123456789',
		'case.contract_reference': 'K-2026-9911',
		'case.insurance_number': 'V-2026-4471',
	}

	function expandDeregistrationPreviewText(value) {
		return String(value || '').replace(/\{\{\s*([a-z0-9_.]+)\s*\}\}/gi, (match, token) => DEREGISTRATION_PREVIEW_DUMMY_DATA[token] ?? match)
	}

	function showDeregistrationTemplatePreview(values) {
		const name = values.name || 'Abmeldung'
		const subject = expandDeregistrationPreviewText(values.subjectTemplate)
		const body = expandDeregistrationPreviewText(values.bodyTemplate)
		const documentTemplateKey = values.documentTemplateKey || ''
		const matchedTemplate = documentTemplateKey ? state.documentTemplates.find((template) => template.key === documentTemplateKey && template.active) : null
		const formSection = !documentTemplateKey ? '' : matchedTemplate
			? `<h3>Zugehöriges Formular</h3><p class="bp-muted">Bei dieser Übertragung wird zusätzlich die Dokumentvorlage „${esc(matchedTemplate.name)}" ausgefüllt.</p><div class="bp-row-actions"><button type="button" class="bp-secondary" data-open-form-preview>Formularvorschau mit Musterdaten anzeigen</button></div>`
			: `<h3>Zugehöriges Formular</h3><p class="bp-warning">Der hinterlegte Dokumentvorlagen-Schlüssel „${esc(documentTemplateKey)}" verweist auf keine vorhandene, aktive Dokumentvorlage. Bitte in den Dokumentvorlagen korrigieren oder hier entfernen.</p>`
		root.insertAdjacentHTML('beforeend', `<div class="bp-modal"><section class="bp-wide-modal"><div class="bp-panel-head"><div><h2>Vorschau · ${esc(name)}</h2><p class="bp-muted">Name, Daten und Beträge sind frei erfunden (Musterfall „Maria Mustermann", Fall 2026-0000) und dienen ausschließlich der Layout-Kontrolle. Es wird keine E-Mail versendet und kein Dokument angelegt.</p></div><button class="bp-secondary" data-close-preview>Schließen</button></div><h3>Betreff</h3><div class="bp-preview-box">${esc(subject)}</div><h3>Text</h3><pre class="bp-preview-box">${esc(body)}</pre>${formSection}</section></div>`)
		const modal = [...root.querySelectorAll('.bp-modal')].pop()
		modal.querySelector('[data-close-preview]').onclick = () => modal.remove()
		modal.querySelector('[data-open-form-preview]')?.addEventListener('click', () => showDocumentTemplatePreview(matchedTemplate.fileName, matchedTemplate.name))
	}

	// Befund (Review 2026-09-22, Kundenmeldung zum Prozess "Abmeldungen"): "Die Felder [sind] nicht
	// auf Auswahllisten selektierbar (z.B. Empfängertyp, Nextcloud-Kontaktgruppe, zulässige
	// Übertragungswege, Dokumentenvorlagenschlüssel)." Übertragungswege sind serverseitig
	// (ConfigurationService::save()) auf einen echten festen Wertebereich (EMAIL/POST/PORTAL/TELEFON)
	// geprüft und werden daher als Checkboxen angeboten. Nextcloud-Kontaktgruppe ist dagegen ein
	// offenes, frei vergebenes Vokabular (das freeform vCard-CATEGORIES-Feld jedes einzelnen
	// Kontakts) - hierfür wird (Befund #22, 0.65.13) ein Freitextfeld mit <datalist> aus den
	// tatsächlich vorkommenden Werten angeboten, ergänzt um einen Warnhinweis, falls eine gepflegte
	// Kontaktgruppe aktuell zu keinem Nextcloud-Kontakt passt - eine eigene Werteliste würde hier
	// von den echten Kontaktgruppen abdriften können. Empfängertyp ist dagegen ein rein
	// klassifizierendes Feld ohne Bezug zu externen Daten (siehe Kundenrückfrage 2026-09-22, ob
	// Empfängertyp zusätzlich ein fachliches Verhalten auslösen sollte: nein - was mit einer
	// Abmeldung technisch passiert (E-Mail-Versand vs. Druckausgabe) wird bereits unabhängig davon
	// über "Zulässige Übertragungswege" gesteuert, siehe Mailversand/Druckfunktion aus 0.65.18;
	// Empfängertyp dient nur der Einordnung, welche Art Institution die Abmeldung erhält) und ist
	// daher seit 0.65.19 eine eigene, unter "Wertelisten" frei pflegbare Werteliste
	// (DEREGISTRATION_RECIPIENT_TYPE). Der Dokumentenvorlagenschlüssel referenziert eine konkrete, in
	// der Dokumentenvorlagenverwaltung gepflegte Vorlage und wird daher als echtes <select> aus
	// state.documentTemplates angeboten.
	//
	// Kundenrückmeldung 2026-09-22: "Das muss nicht bei jedem Dokument stehen" - der Hinweis, wo der
	// Empfängertyp gepflegt wird, erschien bislang als <small>-Text auf JEDER der (aktuell 7)
	// Abmeldevorgang-Karten identisch wiederholt. Jetzt nur noch einmal als Abschnittshinweis direkt
	// unter der Überschrift "Abmeldungen" (statt pro Karte), inhaltlich unverändert.
	function deregistrationTemplatesView() {
		const recipientTypeList = state.customizing.find((entry) => entry.key === 'DEREGISTRATION_RECIPIENT_TYPE')
		const recipientTypeItems = recipientTypeList?.items || []
		const contactCategoryOptions = [...new Set((state.records.contact || []).flatMap((contact) => String(contact.data?.categories || '').split(/[,;]/).map((value) => value.trim()).filter(Boolean)))].sort()
		const suggestionLists = `<datalist id="dereg-contact-category-suggestions">${contactCategoryOptions.map((value) => `<option value="${esc(value)}">`).join('')}</datalist>`
		const channelLabels = { EMAIL: 'E-Mail', POST: 'Post', PORTAL: 'Online-Portal', TELEFON: 'Telefon' }
		const cards = state.deregistrationTemplates.map((entry) => {
			const channels = entry.deliveryChannels || []
			const channelChecks = Object.entries(channelLabels).map(([channel, label]) => `<label class="bp-inline-check"><input type="checkbox" name="deliveryChannels" value="${channel}" ${channels.includes(channel) ? 'checked' : ''}> ${label}</label>`).join('')
			const contactCategoryHint = entry.contactCategory && !contactCategoryOptions.includes(entry.contactCategory) ? `<small class="bp-warning">Aktuell kein Nextcloud-Kontakt mit dieser Kontaktgruppe vorhanden.</small>` : ''
			// Defensiv, wie bereits beim Auftragsstatus (0.65.11): ein vorhandener, aber in der
			// Werteliste nicht (mehr) geführter Wert bleibt als Option erhalten, statt beim Speichern
			// stillschweigend zu verschwinden (z. B. nach Umbenennen/Löschen des Werteliste-Eintrags).
			const currentRecipientType = entry.recipientType || ''
			const recipientTypeKnown = recipientTypeItems.some((item) => item.value === currentRecipientType)
			const recipientTypeOptionsHtml = [
				'<option value="">– wie Vorgangsschlüssel –</option>',
				...recipientTypeItems.map((item) => `<option value="${esc(item.value)}" ${item.value === currentRecipientType ? 'selected' : ''}>${esc(item.label)}</option>`),
				!recipientTypeKnown && currentRecipientType ? `<option value="${esc(currentRecipientType)}" selected>${esc(currentRecipientType)} (nicht mehr in der Werteliste)</option>` : '',
			].join('')
			return `<article class="bp-checklist-editor" data-dereg-template="${entry.id}"><div class="bp-form-grid"><label><span>Vorgangsschlüssel</span><input name="key" value="${esc(entry.key)}" pattern="[A-Za-z0-9_\\-]+" ${entry.id ? 'readonly' : 'required'}></label><label><span>Bezeichnung</span><input name="name" value="${esc(entry.name)}" required></label><label><span>Empfängertyp</span><select name="recipientType">${recipientTypeOptionsHtml}</select></label><label><span>Nextcloud-Kontaktgruppe</span><input name="contactCategory" value="${esc(entry.contactCategory)}" list="dereg-contact-category-suggestions" placeholder="z. B. Standesämter">${contactCategoryHint}</label><label class="bp-span-2"><span>Zulässige Übertragungswege</span><div class="bp-inline-options">${channelChecks}</div></label><label><span>Formulartyp</span><select name="formType"><option value="STANDARD" ${entry.formType === 'STANDARD' ? 'selected' : ''}>Standard</option><option value="PENSION_SERVICE" ${entry.formType === 'PENSION_SERVICE' ? 'selected' : ''}>Rentenservice</option></select></label><label><span>Wiedervorlage in Tagen</span><input name="followUpDays" type="number" min="0" value="${entry.followUpDays || 0}"></label><label><span>Dokumentvorlagen-Schlüssel</span><select name="documentTemplateKey"><option value="">– keine –</option>${state.documentTemplates.map((template) => `<option value="${esc(template.key)}" ${template.key === (entry.documentTemplateKey || '') ? 'selected' : ''}>${esc(template.name)}</option>`).join('')}</select></label></div><label><span>Betreff-Vorlage</span><input name="subjectTemplate" value="${esc(entry.subjectTemplate)}"></label><label><span>Text-Vorlage</span><textarea name="bodyTemplate" rows="6">${esc(entry.bodyTemplate)}</textarea></label><details><summary>Technische Felder und Status</summary><label><span>Pflichtfelder</span><input name="requiredFields" value="${esc((entry.requiredFields || []).join(', '))}"></label><label><span>Zulässige Status</span><input name="allowedStatuses" value="${esc((entry.allowedStatuses || []).join(', '))}"></label></details><label class="bp-inline-check"><input name="active" type="checkbox" ${entry.active ? 'checked' : ''}> Aktiv</label><p class="bp-card-state" aria-live="polite"></p><div class="bp-row-actions"><button class="bp-secondary preview-dereg-template" type="button">Vorschau</button><button class="bp-secondary save-dereg-template" data-id="${entry.id}">Speichern</button>${entry.id ? `<button class="bp-danger delete-dereg-template" data-id="${entry.id}">Löschen</button>` : ''}</div></article>`
		}).join('')
		return `<section class="bp-panel bp-config-admin">${suggestionLists}<div class="bp-panel-head"><div><h3>Abmeldungen</h3><p class="bp-muted">Empfängergruppen und bearbeitbare Entwürfe; ein automatischer Versand findet nicht statt. Der Empfängertyp ist unter Customizing → Wertelisten → „Empfängertyp (Abmeldungen)“ pflegbar (hinzufügen, umbenennen, sortieren).</p></div><button class="bp-primary" id="new-dereg-template">+ Abmeldevorgang</button></div><div class="bp-admin-list">${cards || '<p class="bp-empty">Noch keine Abmeldevorgänge vorhanden.</p>'}</div></section>`
	}

	function dragControls(kind, index, label) {
		return `<span class="bp-order-cell"><button type="button" class="bp-drag-handle" draggable="true" data-drag-kind="${kind}" aria-label="${esc(label)} verschieben" title="Ziehen oder Alt+Pfeiltaste verwenden">⋮⋮</button><span class="bp-position-number">${index + 1}</span></span>`
	}

	function valueRow(item, index, technicalValuesLocked = false, variantItems = null) {
		const isVariant = Array.isArray(variantItems)
		const parent = isVariant ? variantItems.find((entry) => Number(entry.id) === Number(item.parentItemId)) : null
		const variantFields = isVariant ? `<td><select data-value-parent aria-label="Übergeordnete Variante, Zeile ${index + 1}"><option value="">Oberste Ebene</option>${variantItems.filter((entry) => Number(entry.id) !== Number(item.id)).map((entry) => `<option value="${esc(entry.value)}" ${entry.value === parent?.value ? 'selected' : ''}>${esc(entry.label)} (${esc(entry.value)})</option>`).join('')}</select></td><td><select data-value-scope aria-label="Bestattungszuordnung, Zeile ${index + 1}"><option value="" ${!item.metadata?.funeralScope ? 'selected' : ''}>Fachlich offen</option><option value="CREMATION" ${item.metadata?.funeralScope === 'CREMATION' ? 'selected' : ''}>Feuer-/Urnenbestattung</option><option value="BURIAL" ${item.metadata?.funeralScope === 'BURIAL' ? 'selected' : ''}>Erdbestattung</option></select></td>` : ''
		return `<tr data-value-row="${item.id}" draggable="false"><td class="bp-value-position">${dragControls('value', index, item.label)}</td><td><input data-value-value value="${esc(item.value)}" aria-label="Technischer Wert, Zeile ${index + 1}" ${item.id ? 'readonly title="Technischer Wert – nach Anlage nicht änderbar, damit bestehende Fälle ihre Zuordnung behalten"' : 'placeholder="wird aus der Bezeichnung erzeugt"'}></td><td><input data-value-label value="${esc(item.label)}" aria-label="Bezeichnung, Zeile ${index + 1}"></td>${variantFields}<td><span class="bp-row-save-state" aria-live="polite"></span></td><td class="bp-value-actions"><button type="button" class="bp-secondary save-value">Speichern</button><button type="button" class="bp-danger delete-value" ${technicalValuesLocked && !isVariant ? 'disabled title="Verbindlicher Systemwert"' : ''}>Löschen</button></td></tr>`
	}

	function schedulingView() {
		const requirementFields=(item)=>`<details class="bp-schedule-requirements"><summary>Pflichtbedarf festlegen</summary><div class="bp-form-grid"><label><span>Mitarbeitende</span><input data-required-staff type="number" min="0" value="${Number(item.requiredStaff||0)}"></label><label><span>Fahrzeuge</span><input data-required-vehicles type="number" min="0" value="${Number(item.requiredVehicles||0)}"></label><label><span>Räume</span><input data-required-rooms type="number" min="0" value="${Number(item.requiredRooms||0)}"></label><label><span>Kapellen</span><input data-required-chapels type="number" min="0" value="${Number(item.requiredChapels||0)}"></label><label><span>Ausstattung</span><input data-required-equipment type="number" min="0" value="${Number(item.requiredEquipment||0)}"></label></div><fieldset class="bp-member-picker"><legend>Standardmäßig vorbelegen</legend>${(state.scheduling?.resources||[]).filter((resource)=>resource.resourceType!=='STAFF'&&resource.active).map((resource)=>`<label class="bp-inline-check"><input data-default-resource type="checkbox" value="${esc(resource.key)}" ${(item.defaultResourceKeys||[]).includes(resource.key)?'checked':''}> ${esc(resource.name)}</label>`).join('')||'<small>Noch keine betrieblichen Ressourcen vorhanden.</small>'}</fieldset></details>`
		const typeRow=(item,newRow=false)=>`<tr data-schedule-type="${Number(item.id||0)}"><td><input data-key value="${esc(item.key||'')}" ${newRow?'placeholder="automatisch aus Bezeichnung"':'readonly'}></td><td><input data-name value="${esc(item.name||'')}"></td><td><select data-kind><option value="EXTERNAL_APPOINTMENT" ${item.scheduleKind!=='INTERNAL_ACTIVITY'?'selected':''}>Externer Fixtermin</option><option value="INTERNAL_ACTIVITY" ${item.scheduleKind==='INTERNAL_ACTIVITY'?'selected':''}>Interne Tätigkeit</option></select></td><td><input data-duration type="number" min="15" step="15" value="${Number(item.durationMinutes||60)}"></td><td><input data-before type="number" min="0" step="5" value="${Number(item.bufferBeforeMinutes||0)}"></td><td><input data-after type="number" min="0" step="5" value="${Number(item.bufferAfterMinutes||0)}"></td><td>${requirementFields(item)}</td><td><label class="bp-inline-check"><input data-active type="checkbox" ${item.active!==false?'checked':''}> Aktiv</label></td><td><button type="button" class="bp-secondary save-schedule-type">Speichern</button></td></tr>`
		const typeRows=(state.scheduling?.types||[]).map((item)=>typeRow(item)).join('');
		const resourceRows=(state.scheduling?.resources||[]).map((item)=>`<tr data-schedule-resource="${item.id}"><td><input data-key value="${esc(item.key)}" readonly></td><td><input data-name value="${esc(item.name)}"></td><td><select data-type>${[['STAFF','Mitarbeiter'],['VEHICLE','Fahrzeug'],['ROOM','Raum'],['CHAPEL','Kapelle'],['EQUIPMENT','Ausstattung']].map(([value,label])=>`<option value="${value}" ${item.resourceType===value?'selected':''}>${label}</option>`).join('')}</select></td><td><input data-uid value="${esc(item.linkedUid||'')}" placeholder="optional"></td><td><label class="bp-inline-check"><input data-conflict type="checkbox" ${item.conflictRelevant?'checked':''}> Konflikt prüfen</label></td><td><label class="bp-inline-check"><input data-active type="checkbox" ${item.active?'checked':''}> Aktiv</label></td><td><button type="button" class="bp-secondary save-schedule-resource">Speichern</button></td></tr>`).join('');
		return `<section class="bp-panel bp-custom-table bp-scheduling-admin"><div class="bp-panel-head"><div><h3>Terminplanung und Disposition</h3><p class="bp-muted">Terminarten definieren Dauer, Puffer und den mindestens zu disponierenden Bedarf. Konkrete Standardressourcen können vorbelegt werden; der Server verhindert unvollständige oder kollidierende Dispositionen.</p></div></div><section class="bp-field-section"><div class="bp-panel-head"><h4>Terminarten</h4><button type="button" class="bp-secondary" id="new-schedule-type">+ Terminart</button></div><div class="bp-table-scroll"><table><thead><tr><th>Schlüssel</th><th>Bezeichnung</th><th>Einordnung</th><th>Dauer</th><th>Puffer davor</th><th>Puffer danach</th><th>Personal und Ressourcen</th><th>Status</th><th></th></tr></thead><tbody id="schedule-type-rows">${typeRows}</tbody></table></div></section><section class="bp-field-section"><div class="bp-panel-head"><h4>Ressourcen</h4><button type="button" class="bp-secondary" id="new-schedule-resource">+ Ressource</button></div><p class="bp-muted">Kapellen werden getrennt von allgemeinen Räumen geführt. Persönliche Arbeits- und Abwesenheitszeiten sollen künftig aus Nextcloud Calendar übernommen werden; betriebliche Ressourcen bleiben hier gepflegt.</p><div class="bp-table-scroll"><table><thead><tr><th>Schlüssel</th><th>Bezeichnung</th><th>Typ</th><th>Nextcloud-UID</th><th>Prüfung</th><th>Status</th><th></th></tr></thead><tbody id="schedule-resource-rows">${resourceRows}</tbody></table></div></section></section>`
	}

	function surchargeView() {
		const articles = (state.articles || []).filter((item) => item.active && item.itemType !== 'PACKAGE')
		const row = (item = {}, isNew = false) => `<tr data-surcharge-rule="${Number(item.id || 0)}"><td><input data-rule-key value="${esc(item.ruleKey || '')}" ${isNew ? 'placeholder="automatisch aus Bezeichnung" required' : 'readonly'}></td><td><input data-label value="${esc(item.label || '')}" required></td><td><select data-dimension>${[['PICKUP','Abholzeit'],['HEIGHT_CM','Größe (cm)'],['WEIGHT_KG','Gewicht (kg)'],['OTHER','Weitere']].map(([key,label])=>`<option value="${key}" ${key===item.dimension?'selected':''}>${label}</option>`).join('')}</select></td><td><input data-from type="number" min="0" step="1" value="${item.thresholdFrom ?? ''}" aria-label="Staffel von"></td><td><input data-to type="number" min="0" step="1" value="${item.thresholdTo ?? ''}" aria-label="Staffel bis"></td><td><select data-article><option value="">Noch keine Katalogleistung</option>${articles.map((article)=>`<option value="${Number(article.id)}" ${Number(article.id)===Number(item.articleId)?'selected':''}>${esc(article.articleNumber)} · ${esc(article.shortName)} · ${Number(article.salesPriceCents || 0) / 100} €</option>`).join('')}</select></td><td><label class="bp-inline-check"><input data-active type="checkbox" ${item.active?'checked':''}> Aktiv</label></td><td><input data-sort type="number" min="0" value="${Number(item.sortOrder || 0)}" aria-label="Sortierung"></td><td><button type="button" class="bp-secondary save-surcharge-rule">Speichern</button></td></tr>`
		return `<section class="bp-panel bp-custom-table bp-surcharge-admin"><div class="bp-panel-head"><div><h3>Zuschlagsstaffeln</h3><p class="bp-muted">Staffeln und feste Katalogleistungen pflegen. Die Abholzeit-, Größen- und Gewichtswerte können bereits im Fall erfasst werden. Die Leistungs- und Zuschlagsprüfung im Ablauf (Knopf im Schritt bzw. Zusatzablauf 1) schlägt zutreffende Staffeln vor; übernommen wird bewusst über den Leistungsdialog. Änderungen nach Beauftragung erfolgen nur über den Nachtragsprozess.</p></div><button type="button" class="bp-primary" id="new-surcharge-rule">+ Staffel</button></div><div class="bp-table-scroll"><table><thead><tr><th>Schlüssel</th><th>Bezeichnung</th><th>Merkmal</th><th>Von</th><th>Bis</th><th>Katalogleistung / Preis</th><th>Status</th><th>Sortierung</th><th>Aktion</th></tr></thead><tbody id="surcharge-rule-rows">${(state.surchargeRules || []).map((item)=>row(item)).join('')}</tbody></table></div><template id="new-surcharge-template">${row({},true)}</template></section>`
	}

	function burialRuleView() {
		const variants = state.customizing.find((item) => item.key === 'BURIAL_VARIANT')?.items || []
		const articles = (state.articles || []).filter((item) => item.active && item.itemType !== 'PACKAGE')
		const articleOptions = (selected) => `<option value="">– keine –</option>${articles.map((article) => `<option value="${Number(article.id)}" ${Number(selected)===Number(article.id)?'selected':''}>${esc(article.articleNumber)} · ${esc(article.shortName)}</option>`).join('')}`
		const row = (item = {}, isNew = false) => `<tr data-burial-rule="${Number(item.id || 0)}"><td><select data-variant><option value="">Variante wählen</option>${variants.map((variant)=>`<option value="${esc(variant.value)}" ${variant.value===item.variantCode?'selected':''}>${esc(variant.label)} (${esc(variant.value)})</option>`).join('')}</select></td><td><select data-type>${[['MANDATORY_ARTICLE_GROUP','Gruppe erforderlich'],['MANDATORY_ARTICLE_GROUP_WITH_DEFAULT','Gruppe mit Standardartikel'],['SUGGESTED_ARTICLE','Artikel vorschlagen'],['EXCLUDED_ARTICLE_GROUP','Gruppe ausgeschlossen']].map(([key,label])=>`<option value="${key}" ${key===(item.ruleType||'MANDATORY_ARTICLE_GROUP')?'selected':''}>${label}</option>`).join('')}</select></td><td><select data-group><option value="">– keine –</option>${(state.catalogGroups || []).map((group)=>`<option value="${esc(group.key)}" ${group.key===item.articleGroup?'selected':''}>${esc(group.key)} · ${esc(group.name)}</option>`).join('')}</select></td><td><select data-article>${articleOptions(item.articleId)}</select></td><td><select data-default>${articleOptions(item.defaultArticleId)}</select></td><td><input data-note value="${esc(item.note || '')}" placeholder="Konkreter interner Hinweis"></td><td><label class="bp-inline-check"><input data-active type="checkbox" ${item.active?'checked':''}> Aktiv</label></td><td><input data-sort type="number" min="0" value="${Number(item.sortOrder || 0)}" aria-label="Sortierung"></td><td><button type="button" class="bp-secondary save-burial-rule">Speichern</button></td></tr>`
		return `<section class="bp-panel bp-custom-table"><div class="bp-panel-head"><div><h3>Varianten- und Leistungsregeln</h3><p class="bp-muted">Pflege von Pflicht-Warengruppen, Standards und Vorschlägen je Bestattungsvariante. Regeln verweisen auf den Warengruppen-Schlüssel des Leistungskatalogs. Ungeklärte Varianten können nicht aktiviert werden.</p></div><button type="button" class="bp-primary" id="new-burial-rule">+ Regel</button></div><div class="bp-table-scroll"><table><thead><tr><th>Variante</th><th>Regel</th><th>Warengruppe</th><th>Artikel</th><th>Standardartikel</th><th>Interner Hinweis</th><th>Status</th><th>Sort.</th><th>Aktion</th></tr></thead><tbody id="burial-rule-rows">${(state.burialVariantRules || []).map((item)=>row(item)).join('')}</tbody></table></div><template id="new-burial-rule-template">${row({},true)}</template></section>`
	}

	/**
	 * 0.66.1: Technische Schlüssel sind nach Anlage schreibgeschützt; bei Neuanlage werden sie aus der
	 * Bezeichnung erzeugt, solange niemand den Schlüssel selbst eintippt.
	 */
	const KEY_FIELDS = '[data-key],[data-rule-key],input[name="key"],[data-value-value]'
	const NAME_FIELDS = '[data-name],[data-label],input[name="name"],[data-value-label]'
	const keyFromLabel = (value) => String(value || '').trim().toUpperCase().replace(/Ä/g, 'AE').replace(/Ö/g, 'OE').replace(/Ü/g, 'UE').replace(/ß/g, 'SS').replace(/[^A-Z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 60)
	function bindAutoKeys() {
		if (root.dataset.autoKeysBound === '1') return
		root.dataset.autoKeysBound = '1'
		root.addEventListener('input', (event) => {
			const target = event.target
			if (!(target instanceof HTMLInputElement)) return
			if (target.matches(KEY_FIELDS) && !target.readOnly) { target.dataset.manualKey = target.value.trim() ? '1' : ''; return }
			if (!target.matches(NAME_FIELDS)) return
			const container = target.closest('tr, article, form, fieldset')
			const keyInput = container?.querySelector(KEY_FIELDS)
			if (!keyInput || keyInput.readOnly || keyInput.dataset.manualKey === '1') return
			// Wertelisten folgen der Schreibweise der vorhandenen Einträge (z. B. „Frau“ statt „FRAU“).
			if (keyInput.matches('[data-value-value]')) {
				const existing = [...(container.closest('tbody')?.querySelectorAll('[data-value-value][readonly]') || [])].map((input) => input.value).filter(Boolean)
				if (existing.length && !existing.every((value) => /^[A-Z0-9_-]+$/.test(value))) { keyInput.value = target.value.trim().slice(0, 60); return }
			}
			keyInput.value = keyFromLabel(target.value)
		})
	}

	function customizing() {
		const tabs = [['processes', 'Abläufe'], ['labels', 'Marker'], ['document-kinds', 'Dokumentarten'], ['value-lists', 'Wertelisten'], ['burial-rules', 'Variantenregeln'], ['surcharges', 'Zuschlagsstaffeln'], ['scheduling', 'Terminplanung'], ['documents', 'Dokumentvorlagen'], ['deregistration', 'Abmeldungen']]
		let body = ''
		if (state.customizingTab === 'processes') body = ctx.processAdminView ? ctx.processAdminView() : ''
		else if (state.customizingTab === 'labels') body = ctx.labelsAdminView ? ctx.labelsAdminView() : ''
		else if (state.customizingTab === 'document-kinds') body = ctx.documentKindsAdminView ? ctx.documentKindsAdminView() : ''
		else if (state.customizingTab === 'value-lists') {
			const sortedLists = [...state.customizing].sort((a, b) => a.name.localeCompare(b.name, 'de', { sensitivity: 'base' }) || a.key.localeCompare(b.key))
			const activeKey = state.valueListKey && state.customizing.some((list) => list.key === state.valueListKey) ? state.valueListKey : (sortedLists[0]?.key || '')
			state.valueListKey = activeKey
			const activeList = state.customizing.find((list) => list.key === activeKey)
			const isVariant = activeKey === 'BURIAL_VARIANT'
			const rows = (activeList?.items || []).map((item, index) => valueRow(item, index, activeList?.technicalValuesLocked, isVariant ? activeList.items : null)).join('')
			body = `<section class="bp-panel bp-custom-table"><div class="bp-panel-head"><div><h3>Wertelisten</h3><p class="bp-muted">Wertelisten alphabetisch anzeigen; die fachliche Reihenfolge der Einträge bleibt separat pflegbar.</p></div><span class="bp-status">${state.customizing.length} Listen</span></div><div class="bp-value-list-workspace"><nav class="bp-value-list-nav" aria-label="Wertelisten">${sortedLists.map((list) => `<button type="button" class="${list.key === activeKey ? 'active' : ''}" data-value-list="${esc(list.key)}"><span>${esc(list.name)}</span><small>${Number(list.itemCount ?? list.items.length)} Einträge</small></button>`).join('')}</nav><div class="bp-value-list-editor"><div class="bp-panel-head"><div><p class="bp-eyebrow">${esc(activeList?.key || '')}</p><h3>${esc(activeList?.name || 'Keine Werteliste vorhanden')}</h3>${activeList?.technicalValuesLocked ? `<small>${isVariant ? 'Bestehende Schlüssel bleiben fest; neue Ober- und Untervarianten können hier angelegt werden.' : 'Technische Schlüssel sind verbindlich; Bezeichnungen und Reihenfolge bleiben pflegbar.'}</small>` : ''}</div><button type="button" class="bp-primary" id="add-value" ${activeList && (!activeList.technicalValuesLocked || isVariant) ? '' : 'disabled'}>+ Neue Zeile</button></div>${isVariant ? '<p class="bp-muted">Einträge ohne übergeordnete Variante sind Bestattungsarten; darunter liegen die Untervarianten. „Zuordnung“ steuert nur die Katalogfilterung. Baum- und Waldbestattung sind eigenständige Bestattungsarten.</p>' : ''}<div class="bp-table-scroll"><table class="bp-value-table"><thead><tr><th>Reihenfolge</th><th>Technischer Wert</th><th>Sprechende Bezeichnung</th>${isVariant ? '<th>Übergeordnete Variante</th><th>Zuordnung</th>' : ''}<th>Status</th><th>Aktionen</th></tr></thead><tbody id="value-list-rows">${rows || `<tr class="bp-value-empty"><td colspan="${isVariant ? 7 : 5}" class="bp-empty">Noch keine Einträge. Mit „Neue Zeile“ beginnen.</td></tr>`}</tbody></table></div><p class="bp-muted">${isVariant ? '„Fachlich offen“ verhindert aktive Leistungsregeln. Neue Untervarianten erscheinen nach dem Speichern in der Fallauswahl.' : ''} Ziehen: Griff festhalten und Zeile verschieben. Tastatur: Griff fokussieren und Alt+Pfeil auf/ab verwenden. Ungespeicherte neue Zeilen zuerst speichern.</p></div></div></section>`
		} else if (state.customizingTab === 'burial-rules') body = burialRuleView()
		else if (state.customizingTab === 'surcharges') body = surchargeView()
		else if (state.customizingTab === 'scheduling') body = schedulingView()
		else if (state.customizingTab === 'documents') body = documentTemplatesView()
		else if (state.customizingTab === 'deregistration') body = deregistrationTemplatesView()
		return `<div class="bp-page-head"><div><p class="bp-eyebrow">Administration</p><h2>Customizing</h2></div></div><div class="bp-subtabs">${tabs.map((tab) => `<button class="${state.customizingTab === tab[0] ? 'active' : ''}" data-customizing-tab="${tab[0]}">${tab[1]}</button>`).join('')}</div>${body}`
	}

	function bindCustomizing() {
		bindAutoKeys()
		bindScheduling()
		const bindBurialRule = (row) => row.querySelector('.save-burial-rule')?.addEventListener('click', async () => {
			const id = Number(row.dataset.burialRule || 0)
			const rule = { variantCode: row.querySelector('[data-variant]').value, ruleType: row.querySelector('[data-type]').value, articleGroup: row.querySelector('[data-group]').value.trim(), articleId: row.querySelector('[data-article]').value, defaultArticleId: row.querySelector('[data-default]').value, note: row.querySelector('[data-note]').value.trim(), active: row.querySelector('[data-active]').checked, sortOrder: row.querySelector('[data-sort]').value }
			const button = row.querySelector('.save-burial-rule'); button.disabled = true
			try { await api(`${apiBase}/customizing/burial-rules${id ? `/${id}` : ''}`, { method: id ? 'PUT' : 'POST', body: new URLSearchParams({rule: JSON.stringify(rule)}) }); state.burialVariantRules = await api(`${apiBase}/customizing/burial-rules`); render(); notifySuccess('Variantenregel gespeichert.') }
			catch (error) { notifyError(error.message); button.disabled = false }
		})
		root.querySelectorAll('[data-burial-rule]').forEach(bindBurialRule)
		document.getElementById('new-burial-rule')?.addEventListener('click', () => { const row = document.getElementById('new-burial-rule-template').content.firstElementChild.cloneNode(true); document.getElementById('burial-rule-rows').append(row); bindBurialRule(row); row.querySelector('[data-variant]').focus() })
		const bindSurchargeRow = (row) => row.querySelector('.save-surcharge-rule')?.addEventListener('click', async () => {
			const id = Number(row.dataset.surchargeRule || 0)
			const rule = { ruleKey: row.querySelector('[data-rule-key]').value.trim(), label: row.querySelector('[data-label]').value.trim(), dimension: row.querySelector('[data-dimension]').value, thresholdFrom: row.querySelector('[data-from]').value, thresholdTo: row.querySelector('[data-to]').value, articleId: row.querySelector('[data-article]').value, active: row.querySelector('[data-active]').checked, sortOrder: row.querySelector('[data-sort]').value }
			const button = row.querySelector('.save-surcharge-rule'); button.disabled = true
			try { await api(`${apiBase}/customizing/surcharges${id ? `/${id}` : ''}`, { method: id ? 'PUT' : 'POST', body: new URLSearchParams({rule: JSON.stringify(rule)}) }); state.surchargeRules = await api(`${apiBase}/customizing/surcharges`); render(); notifySuccess('Zuschlagsstaffel gespeichert.') }
			catch (error) { notifyError(error.message); button.disabled = false }
		})
		root.querySelectorAll('[data-surcharge-rule]').forEach(bindSurchargeRow)
		document.getElementById('new-surcharge-rule')?.addEventListener('click', () => { const tbody = document.getElementById('surcharge-rule-rows'); const template = document.getElementById('new-surcharge-template'); const row = template.content.firstElementChild.cloneNode(true); tbody.append(row); bindSurchargeRow(row); row.querySelector('[data-rule-key]').focus() })
		root.querySelectorAll('[data-value-list]').forEach((button) => button.addEventListener('click', () => { state.valueListKey = button.dataset.valueList; globalThis.localStorage?.setItem('bestatter:value-list-key', state.valueListKey); render() }))
		const updateLocalItem = (saved) => { const list = state.customizing.find((entry) => entry.key === state.valueListKey); if (!list) return; const existing = list.items.find((entry) => Number(entry.id) === Number(saved.id)); if (existing) Object.assign(existing, saved); else list.items.push(saved); list.itemCount = list.items.length }
		const refreshPositions = (tbody, rowSelector) => [...tbody.querySelectorAll(rowSelector)].forEach((row, index) => { const number=row.querySelector('.bp-position-number'); if(number) number.textContent=String(index+1) })
		const bindSortableRows = (tbody, rowSelector, kind, saveOrder) => {
			if (!tbody) return
			tbody.querySelectorAll(`[data-drag-kind="${kind}"]`).forEach((handle) => {
				if (handle.dataset.sortableBound === 'true') return
				handle.dataset.sortableBound = 'true'
				handle.addEventListener('dragstart', (event) => { const dragged=handle.closest(rowSelector); tbody._bpDraggedRow=dragged; dragged?.classList.add('is-dragging'); event.dataTransfer.effectAllowed='move'; event.dataTransfer.setData('text/plain', dragged?.dataset.valueRow || '') })
				handle.addEventListener('dragend', () => { tbody._bpDraggedRow?.classList.remove('is-dragging'); tbody.querySelectorAll('.is-drag-target').forEach((row)=>row.classList.remove('is-drag-target')); tbody._bpDraggedRow=null })
				handle.addEventListener('keydown', async (event) => { if(!event.altKey || !['ArrowUp','ArrowDown'].includes(event.key)) return; event.preventDefault(); const row=handle.closest(rowSelector); const sibling=event.key==='ArrowUp'?row.previousElementSibling:row.nextElementSibling; if(!sibling?.matches(rowSelector)) return; if(event.key==='ArrowUp') tbody.insertBefore(row,sibling); else tbody.insertBefore(sibling,row); refreshPositions(tbody,rowSelector); await saveOrder(); handle.focus() })
			})
			if (tbody.dataset.sortableBound !== kind) {
				tbody.dataset.sortableBound = kind
				tbody.addEventListener('dragover', (event) => { const dragged=tbody._bpDraggedRow; const target=event.target.closest(rowSelector); if(!dragged||!target||target===dragged) return; event.preventDefault(); target.classList.add('is-drag-target'); event.dataTransfer.dropEffect='move' })
				tbody.addEventListener('dragleave', (event) => event.target.closest(rowSelector)?.classList.remove('is-drag-target'))
				tbody.addEventListener('drop', async (event) => { const dragged=tbody._bpDraggedRow; const target=event.target.closest(rowSelector); if(!dragged||!target||target===dragged) return; event.preventDefault(); const after=event.clientY>target.getBoundingClientRect().top+target.getBoundingClientRect().height/2; tbody.insertBefore(dragged,after?target.nextSibling:target); target.classList.remove('is-drag-target'); refreshPositions(tbody,rowSelector); await saveOrder() })
			}
		}
		const bindValueRow = (row) => {
			const stateLabel = row.querySelector('.bp-row-save-state')
			const markDirty = () => { row.classList.add('is-dirty'); stateLabel.textContent = 'Ungespeichert'; stateLabel.dataset.state = 'dirty' }
			row.querySelectorAll('input,select').forEach((input) => input.addEventListener('input', markDirty))
			row.querySelector('[data-value-parent]')?.addEventListener('change', (event) => { const parent = state.customizing.find((list) => list.key === 'BURIAL_VARIANT')?.items.find((item) => item.value === event.target.value); if (parent?.metadata?.funeralScope) row.querySelector('[data-value-scope]').value = parent.metadata.funeralScope; markDirty() })
			row.querySelector('.save-value')?.addEventListener('click', async () => {
				const value = row.querySelector('[data-value-value]').value.trim(); const label = row.querySelector('[data-value-label]').value.trim()
				if (!value || !label) { stateLabel.textContent = 'Beide Felder ausfüllen'; stateLabel.dataset.state = 'error'; return }
				const id = Number(row.dataset.valueRow || 0); const button = row.querySelector('.save-value'); button.disabled = true; stateLabel.textContent = 'Speichert …'; stateLabel.dataset.state = 'saving'
				try { const params = { value, label }; if (state.valueListKey === 'BURIAL_VARIANT') { params.parentValue = row.querySelector('[data-value-parent]').value; params.funeralScope = row.querySelector('[data-value-scope]').value } const saved = await api(id ? `${urls.customizing}/item/${id}` : `${urls.customizing}/${encodeURIComponent(state.valueListKey)}`, { method: id ? 'PUT' : 'POST', feedback: false, body: new URLSearchParams(params) }); row.dataset.valueRow = saved.id; updateLocalItem(saved); row.classList.remove('is-dirty','is-new'); stateLabel.textContent = 'Gespeichert'; stateLabel.dataset.state = 'saved'; row.querySelector('.delete-value').disabled = false; if (state.valueListKey === 'BURIAL_VARIANT') { state.customizing = (await api(urls.customizing)).lists; render() } else bindSortableRows(document.getElementById('value-list-rows'),'[data-value-row]','value',saveValueOrder); notifySuccess(`„${saved.label}“ wurde gespeichert.`) } catch (error) { stateLabel.textContent = error.message; stateLabel.dataset.state = 'error' } finally { button.disabled = false }
			})
			row.querySelector('.delete-value')?.addEventListener('click', async () => {
				const id = Number(row.dataset.valueRow || 0); if (!id) { row.remove(); return }
				if (!await confirmAction('Diesen Wert wirklich aus der geöffneten Werteliste löschen?', { title: 'Listenwert löschen', confirmLabel: 'Löschen', danger: true })) return
				try { await api(`${urls.customizing}/item/${id}`, { method: 'DELETE', feedback: false }); const list=state.customizing.find((entry)=>entry.key===state.valueListKey); if(list){list.items=list.items.filter((entry)=>Number(entry.id)!==id);list.itemCount=list.items.length} if (state.valueListKey === 'BURIAL_VARIANT') { state.customizing = (await api(urls.customizing)).lists; render() } else { row.remove(); refreshPositions(document.getElementById('value-list-rows'),'[data-value-row]') } notifySuccess('Der Listenwert wurde gelöscht.') } catch(error) { stateLabel.textContent=error.message; stateLabel.dataset.state='error' }
			})
		}
		root.querySelectorAll('[data-value-row]').forEach(bindValueRow)
		const valueBody=document.getElementById('value-list-rows')
		const saveValueOrder=async()=>{ const rows=[...valueBody.querySelectorAll('[data-value-row]')]; const ids=rows.map((row)=>Number(row.dataset.valueRow||0)); if(ids.includes(0)){notifyError('Neue Zeilen bitte zuerst speichern; danach kann die Reihenfolge gespeichert werden.');return} const original=[...(state.customizing.find((entry)=>entry.key===state.valueListKey)?.items||[])]; try{const saved=await api(`${urls.customizing}/${encodeURIComponent(state.valueListKey)}/reorder`,{method:'PUT',feedback:false,body:new URLSearchParams({itemIds:JSON.stringify(ids)})});const list=state.customizing.find((entry)=>entry.key===state.valueListKey);if(list){list.items=saved;list.itemCount=saved.length}notifySuccess('Die Reihenfolge wurde gespeichert.')}catch(error){const list=state.customizing.find((entry)=>entry.key===state.valueListKey);if(list)list.items=original;render();notifyError(error.message)}}
		bindSortableRows(valueBody,'[data-value-row]','value',saveValueOrder)
		document.getElementById('add-value')?.addEventListener('click', () => { const tbody=document.getElementById('value-list-rows'); tbody.querySelector('.bp-value-empty')?.remove(); const position=tbody.querySelectorAll('[data-value-row]').length; const variants=state.valueListKey==='BURIAL_VARIANT' ? state.customizing.find((list)=>list.key==='BURIAL_VARIANT')?.items || [] : null; const template=document.createElement('template'); template.innerHTML=valueRow({id:0,value:'',label:''},position,state.valueListKey==='BURIAL_VARIANT',variants); const row=template.content.firstElementChild; tbody.append(row); row.classList.add('is-new','is-dirty'); row.querySelector('.bp-row-save-state').textContent='Neu · ungespeichert'; bindValueRow(row); row.querySelector('[data-value-value]').focus(); row.scrollIntoView({block:'nearest'}) })
	}

	function bindScheduling(){
		const bindType=(row)=>row.querySelector('.save-schedule-type')?.addEventListener('click',async()=>{const id=Number(row.dataset.scheduleType||0);const number=(selector)=>Number(row.querySelector(selector)?.value||0);const data={key:row.querySelector('[data-key]').value.trim(),name:row.querySelector('[data-name]').value.trim(),scheduleKind:row.querySelector('[data-kind]').value,durationMinutes:number('[data-duration]'),bufferBeforeMinutes:number('[data-before]'),bufferAfterMinutes:number('[data-after]'),requiredStaff:number('[data-required-staff]'),requiredVehicles:number('[data-required-vehicles]'),requiredRooms:number('[data-required-rooms]'),requiredChapels:number('[data-required-chapels]'),requiredEquipment:number('[data-required-equipment]'),defaultResourceKeys:[...row.querySelectorAll('[data-default-resource]:checked')].map((input)=>input.value),active:row.querySelector('[data-active]').checked};try{await api(`${schedulingUrl()}/types/${id}`,{method:'PUT',body:new URLSearchParams({data:JSON.stringify(data)})});state.scheduling=await api(schedulingUrl());render();notifySuccess('Terminart einschließlich Ressourcenbedarf wurde gespeichert.')}catch(error){notifyError(error.message)}})
		const bindResource=(row)=>row.querySelector('.save-schedule-resource')?.addEventListener('click',async()=>{const id=Number(row.dataset.scheduleResource||0);const data={key:row.querySelector('[data-key]').value.trim(),name:row.querySelector('[data-name]').value.trim(),resourceType:row.querySelector('[data-type]').value,linkedUid:row.querySelector('[data-uid]').value.trim(),conflictRelevant:row.querySelector('[data-conflict]').checked,active:row.querySelector('[data-active]').checked};try{await api(`${schedulingUrl()}/resources/${id}`,{method:'PUT',body:new URLSearchParams({data:JSON.stringify(data)})});state.scheduling=await api(schedulingUrl());render();notifySuccess('Ressource wurde gespeichert.')}catch(error){notifyError(error.message)}})
		root.querySelectorAll('[data-schedule-type]').forEach(bindType);root.querySelectorAll('[data-schedule-resource]').forEach(bindResource)
		document.getElementById('new-schedule-type')?.addEventListener('click',()=>{const body=document.getElementById('schedule-type-rows');const defaults='<details class="bp-schedule-requirements"><summary>Pflichtbedarf festlegen</summary><div class="bp-form-grid">'+[['staff','Mitarbeitende'],['vehicles','Fahrzeuge'],['rooms','Räume'],['chapels','Kapellen'],['equipment','Ausstattung']].map(([key,label])=>`<label><span>${label}</span><input data-required-${key} type="number" min="0" value="0"></label>`).join('')+'</div></details>';body.insertAdjacentHTML('beforeend',`<tr data-schedule-type="0"><td><input data-key placeholder="automatisch aus Bezeichnung"></td><td><input data-name></td><td><select data-kind><option value="EXTERNAL_APPOINTMENT">Externer Fixtermin</option><option value="INTERNAL_ACTIVITY">Interne Tätigkeit</option></select></td><td><input data-duration type="number" min="15" step="15" value="60"></td><td><input data-before type="number" min="0" step="5" value="0"></td><td><input data-after type="number" min="0" step="5" value="0"></td><td>${defaults}</td><td><label class="bp-inline-check"><input data-active type="checkbox" checked> Aktiv</label></td><td><button type="button" class="bp-secondary save-schedule-type">Speichern</button></td></tr>`);const row=body.lastElementChild;bindType(row);row.querySelector('[data-key]').focus()})
		document.getElementById('new-schedule-resource')?.addEventListener('click',()=>{const body=document.getElementById('schedule-resource-rows');body.insertAdjacentHTML('beforeend','<tr data-schedule-resource="0"><td><input data-key placeholder="automatisch aus Bezeichnung"></td><td><input data-name></td><td><select data-type><option value="STAFF">Mitarbeiter</option><option value="VEHICLE">Fahrzeug</option><option value="ROOM">Raum</option><option value="CHAPEL">Kapelle</option><option value="EQUIPMENT">Ausstattung</option></select></td><td><input data-uid placeholder="optional"></td><td><label class="bp-inline-check"><input data-conflict type="checkbox" checked> Konflikt prüfen</label></td><td><label class="bp-inline-check"><input data-active type="checkbox" checked> Aktiv</label></td><td><button type="button" class="bp-secondary save-schedule-resource">Speichern</button></td></tr>');const row=body.lastElementChild;bindResource(row);row.querySelector('[data-key]').focus()})
	}

	function bindConfigurationAdministration() {
		const showCardError = (card, error) => { const status = card.querySelector('.bp-card-state'); if (status) { status.textContent = error.message; status.dataset.state = 'error' } }
		const validateConfigurationKey = (card, label) => {
			const key = card.querySelector('[name="key"]')?.value.trim() || ''
			const name = card.querySelector('[name="name"]')?.value.trim() || ''
			if (key && name && /^[A-Za-z0-9_-]+$/.test(key)) return true
			showCardError(card, new Error(`${label} darf keine Leerzeichen enthalten; Schlüssel und Bezeichnung sind Pflichtfelder.`))
			return false
		}
		document.getElementById('new-branch')?.addEventListener('click', () => { state.branches = [{ id: 0, key: '', name: '', street: '', postalCode: '', city: '', country: 'Deutschland', countryCode: 'DE', federalState: '', invoiceProfile: 'ZUGFERD', paymentQrStandard: 'EPC069-12', phone: '', email: '', accountHolder: '', bankName: '', iban: '', bic: '', creditorId: '', paymentTermDays: 14, vatId: '', taxNumber: '', registerCourt: '', registerNumber: '', managingDirectors: '', electronicAddress: '', memberUids: [state.team.currentUid], active: true }, ...state.branches.filter((entry) => entry.id)]; render() })
		root.querySelectorAll('[name="countryCode"]').forEach((select) => select.addEventListener('change', () => { const card=select.closest('[data-branch-id]'); const profile=(state.countryProfiles||[]).find((item)=>item.countryCode===select.value); card.querySelector('.bp-federal-state')?.classList.toggle('bp-hidden',select.value!=='AT'); if(profile){card.querySelector('[name="country"]').value=profile.name;card.querySelector('[name="invoiceProfile"]').value=profile.invoiceProfile;card.querySelector('[name="paymentQrStandard"]').value=profile.paymentQrStandard} }))
		root.querySelectorAll('.save-country-profile').forEach((button) => button.addEventListener('click', async () => { const card=button.closest('[data-country-profile]'); const status=card.querySelector('.bp-card-state'); const values={vatRates:card.querySelector('[name="vatRates"]').value.split(/[,;]+/).map((value)=>value.trim()).filter(Boolean),invoiceProfile:card.querySelector('[name="invoiceProfile"]').value,paymentQrStandard:card.querySelector('[name="paymentQrStandard"]').value}; button.disabled=true; try { const saved=await api(`${apiBase}/country-profiles/${card.dataset.countryProfile}`,{method:'PUT',feedback:false,body:new URLSearchParams({profile:JSON.stringify(values)})}); state.countryProfiles=(state.countryProfiles||[]).map((item)=>item.countryCode===saved.countryCode?saved:item); const catalog=await api(articleUrl()); state.allowedVatRates=catalog.allowedVatRates||state.allowedVatRates; status.textContent='Länderprofil gespeichert.';status.dataset.state='saved';render();notifySuccess('Länderprofil und zulässige Steuersätze wurden gespeichert.') } catch(error){button.disabled=false;showCardError(card,error)} }))
		root.querySelectorAll('.save-branch').forEach((button) => button.addEventListener('click', async () => { const card = button.closest('[data-branch-id]'); if (!validateConfigurationKey(card, 'Der Niederlassungsschlüssel')) return; const value = (name) => card.querySelector(`[name="${name}"]`); const names = ['key','name','street','postalCode','city','country','countryCode','federalState','invoiceProfile','paymentQrStandard','phone','email','accountHolder','bankName','iban','bic','creditorId','paymentTermDays','vatId','taxNumber','registerCourt','registerNumber','managingDirectors','electronicAddress']; const data = Object.fromEntries(names.map((name) => [name, value(name).value])); data.memberUids = [...card.querySelectorAll('[name="memberUids"]:checked')].map((input) => input.value); data.active = value('active').checked; button.disabled = true; try { await api(`${branchUrl()}/${button.dataset.id}`, { method: 'PUT', feedback: false, body: new URLSearchParams({ branch: JSON.stringify(data) }) }); state.branches = await api(branchUrl()); render(); notifySuccess('Niederlassungs-, Länder-, Bank- und Unternehmensdaten wurden gespeichert.') } catch (error) { button.disabled = false; showCardError(card, error) } }))
		root.querySelectorAll('.save-branch-bank').forEach((button) => button.addEventListener('click', async () => { const card = button.closest('[data-branch-id]'); const value = (name) => card.querySelector(`[name="${name}"]`); const names = ['accountHolder','bankName','iban','bic','creditorId','paymentTermDays','vatId','taxNumber','registerCourt','registerNumber','managingDirectors','electronicAddress']; const bankData = Object.fromEntries(names.map((name) => [name, value(name).value])); button.disabled = true; try { const saved = await api(`${branchUrl()}/${button.dataset.id}/bank-data`, { method: 'PUT', feedback: false, body: new URLSearchParams({ bankData: JSON.stringify(bankData) }) }); state.branches = state.branches.map((entry) => entry.id === saved.id ? saved : entry); render(); notifySuccess('Bank- und Unternehmensdaten wurden gespeichert.') } catch (error) { button.disabled = false; showCardError(card, error) } }))
		document.getElementById('save-invoice-settings')?.addEventListener('click', async () => { const form = document.getElementById('invoice-settings'); const value = (name) => form.querySelector(`[name="${name}"]`); const data = { prefix: value('prefix').value, correctionPrefix: value('correctionPrefix')?.value || 'GS', pattern: value('pattern').value, sequenceLength: Number(value('sequenceLength').value), sequenceScope: value('sequenceScope').value, caseReference: value('caseReference').checked, zugferdEnabled: value('zugferdEnabled').checked, xrechnungEnabled: value('xrechnungEnabled').checked, normativeValidationRequired: value('normativeValidationRequired').checked, qrEnabled: value('qrEnabled').checked }; try { state.invoiceSettings = await api(invoiceSettingsUrl(), { method: 'PUT', body: new URLSearchParams({ settings: JSON.stringify(data) }) }); render() } catch (error) { showCardError(form.closest('.bp-panel'), error) } })
		root.querySelectorAll('.delete-branch').forEach((button) => button.addEventListener('click', async () => { if (!await confirmAction('Niederlassung löschen?', { title: 'Niederlassung löschen', confirmLabel: 'Löschen', danger: true })) return; await api(`${apiBase}/configuration/branch/${button.dataset.id}`, { method: 'DELETE' }); state.branches = await api(branchUrl()); render() }))
		document.getElementById('provision-templates')?.addEventListener('click', async () => { const result = await api(`${documentTemplateUrl()}/provision`, { method: 'POST', feedback: false, loadingLabel: 'Fehlende Standardvorlagen werden ergänzt …' }); const files=result.files||[]; const created=files.filter((file)=>file.status==='CREATED').length; const preserved=files.filter((file)=>file.status==='PRESERVED').length; state.documentTemplateOptions=await api(documentTemplateOptionsUrl()); render(); notifySuccess(`${created} fehlende Standardvorlage${created===1?' wurde':'n wurden'} ergänzt; ${preserved} vorhandene individuelle Datei${preserved===1?' wurde':'en wurden'} unverändert beibehalten.`) })
		document.getElementById('download-logo-placeholder')?.addEventListener('click', async () => { try { await download(`${apiBase}/document-templates/logo-placeholder.png`, 'Logo-Platzhalter.png') } catch (error) { notifyError(error.message) } })
		document.getElementById('download-template-fields')?.addEventListener('click', async () => { try { await download(`${apiBase}/template-fields.csv`, 'Bestatter-Dokumentvorlagen-Feldkatalog.csv') } catch (error) { notifyError(error.message) } })
		document.getElementById('new-document-template')?.addEventListener('click', () => { state.documentTemplates = [{ id: 0, key: '', name: '', category: 'Allgemein', fileName: '', outputSubfolder: '03 Behörden', requiredFields: [], description: '', supportsPdf: false, active: true, sortOrder: 100 }, ...state.documentTemplates.filter((entry) => entry.id)]; render() })
		root.querySelectorAll('.save-document-template').forEach((button) => button.addEventListener('click', async () => { const card = button.closest('[data-document-template]'); if (!validateConfigurationKey(card, 'Der Vorlagenschlüssel')) return; const value = (name) => card.querySelector(`[name="${name}"]`); const template = { key: value('key').value, name: value('name').value, category: value('category').value, fileName: value('fileName').value, outputSubfolder: value('outputSubfolder').value, sortOrder: Number(value('sortOrder').value || 0), requiredFields: value('requiredFields').value.split(',').map((entry) => entry.trim()).filter(Boolean), description: value('description').value, supportsPdf: value('supportsPdf').checked, active: value('active').checked }; try { await api(`${documentTemplateUrl()}/${button.dataset.id}`, { method: 'PUT', body: new URLSearchParams({ template: JSON.stringify(template) }) }); state.documentTemplates = await api(documentTemplateUrl()); render() } catch (error) { showCardError(card, error) } }))
		root.querySelectorAll('.duplicate-document-template').forEach((button) => button.addEventListener('click', async () => { const card=button.closest('[data-document-template]'); button.disabled=true; try { await api(`${documentTemplateUrl()}/${button.dataset.id}/duplicate`, {method:'POST'}); [state.documentTemplates,state.documentTemplateOptions]=await Promise.all([api(documentTemplateUrl()),api(documentTemplateOptionsUrl())]); render() } catch(error){ button.disabled=false; showCardError(card,error) } }))
		root.querySelectorAll('.preview-document-template').forEach((button) => button.addEventListener('click', () => { const card = button.closest('[data-document-template]'); const value = (name) => card.querySelector(`[name="${name}"]`)?.value || ''; const fileName = value('fileName'); if (!fileName) { notifyError('Bitte zuerst eine DOCX-Datei auswählen.'); return } showDocumentTemplatePreview(fileName, value('name') || fileName) }))
		root.querySelectorAll('.delete-document-template').forEach((button) => button.addEventListener('click', async () => { if (!await confirmAction('Dokumentvorlage wirklich löschen?', { title: 'Dokumentvorlage löschen', confirmLabel: 'Löschen', danger: true })) return; await api(`${apiBase}/configuration/document/${button.dataset.id}`, { method: 'DELETE' }); state.documentTemplates = await api(documentTemplateUrl()); render() }))
		document.getElementById('new-dereg-template')?.addEventListener('click', () => { state.deregistrationTemplates = [{ id: 0, key: '', recipientType: '', name: '', contactCategory: '', deliveryChannels: ['POST'], formType: 'STANDARD', subjectTemplate: '', bodyTemplate: '', requiredFields: [], allowedStatuses: ['ENTWURF', 'VORBEREITET'], followUpDays: 0, documentTemplateKey: '', active: true }, ...state.deregistrationTemplates.filter((entry) => entry.id)]; render() })
		root.querySelectorAll('.save-dereg-template').forEach((button) => button.addEventListener('click', async () => { const card = button.closest('[data-dereg-template]'); if (!validateConfigurationKey(card, 'Der Vorgangsschlüssel')) return; const value = (name) => card.querySelector(`[name="${name}"]`); const template = { key: value('key').value, recipientType: value('recipientType').value || value('key').value, name: value('name').value, contactCategory: value('contactCategory').value, deliveryChannels: [...card.querySelectorAll('[name="deliveryChannels"]:checked')].map((input) => input.value), formType: value('formType').value, subjectTemplate: value('subjectTemplate').value, bodyTemplate: value('bodyTemplate').value, requiredFields: value('requiredFields').value.split(',').map((entry) => entry.trim()).filter(Boolean), allowedStatuses: value('allowedStatuses').value.split(',').map((entry) => entry.trim()).filter(Boolean), followUpDays: Number(value('followUpDays').value || 0), documentTemplateKey: value('documentTemplateKey').value, active: value('active').checked }; try { await api(`${deregistrationTemplateUrl()}/${button.dataset.id}`, { method: 'PUT', body: new URLSearchParams({ template: JSON.stringify(template) }) }); state.deregistrationTemplates = await api(deregistrationTemplateUrl()); render() } catch (error) { showCardError(card, error) } }))
		root.querySelectorAll('.preview-dereg-template').forEach((button) => button.addEventListener('click', () => { const card = button.closest('[data-dereg-template]'); const value = (name) => card.querySelector(`[name="${name}"]`)?.value || ''; showDeregistrationTemplatePreview({ name: value('name'), subjectTemplate: value('subjectTemplate'), bodyTemplate: value('bodyTemplate'), documentTemplateKey: value('documentTemplateKey') }) }))
		root.querySelectorAll('.delete-dereg-template').forEach((button) => button.addEventListener('click', async () => { if (!await confirmAction('Abmeldevorgang wirklich löschen?', { title: 'Abmeldevorgang löschen', confirmLabel: 'Löschen', danger: true })) return; await api(`${apiBase}/configuration/deregistration/${button.dataset.id}`, { method: 'DELETE' }); state.deregistrationTemplates = await api(deregistrationTemplateUrl()); render() }))
	}

	return { documentTemplatesView, deregistrationTemplatesView, customizing, bindCustomizing, bindConfigurationAdministration, schedulingView }
}
