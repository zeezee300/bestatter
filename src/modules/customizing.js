import { handbookMoreLink } from './handbook.js'
import { createOrderChoiceFieldsModule } from './order-choice-fields.js'
import { createMailTemplatesModule } from './mail-templates.js'
import { createTypicalCostsModule } from './typical-costs.js'
import { createCaseKindSettingsModule } from './case-kind-settings.js'
import { REFERENCE_TABS, referenceNotice } from './catalog-references.js'
import { allowedKeys, assignmentIssues, availableInBranch, groupByType, isAssignable, requiredResources, resourceDisplayName, resourceTypeIcon, resourceTypeList, suggestAllowed } from './scheduling-resources.js'
import { RESOURCE_ICONS, resourceIconSvg } from './resource-icons.js'
import { workingHours as scheduleWorkingHours } from './schedule-form.js'
import { invoiceNumberPatternError, invoiceNumberPreview } from './administration.js'

export function createCustomizingModule(ctx) {
	const { root, urls, apiBase, state } = ctx
	const orderChoiceFields = createOrderChoiceFieldsModule(ctx) // 0.89.3: Customizing → Ankreuzfelder
	const mailTemplates = createMailTemplatesModule(ctx) // 0.89.3: Customizing → E-Mail-Vorlagen
	const typicalCosts = createTypicalCostsModule(ctx) // 0.89.5: Customizing → Fremdkosten
	const caseKindSettings = createCaseKindSettingsModule(ctx) // 0.89.7: Auftragsarten, Vorsorge
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

	// 0.88.2: Übersicht in src/modules/document-templates.js (Suche, Gruppen, Zustand der Datei, Bearbeiten unter der Zeile)
	function documentTemplatesView() { return ctx.templateListView ? ctx.templateListView() : '' }


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
		return `<section class="bp-panel bp-config-admin">${suggestionLists}<div class="bp-panel-head"><div><h3>Abmeldungen</h3><p class="bp-muted">Empfängergruppen und bearbeitbare Entwürfe; ein automatischer Versand findet nicht statt. Der Empfängertyp ist unter Stammdaten → Wertelisten → „Empfängertyp (Abmeldungen)“ pflegbar (hinzufügen, umbenennen, sortieren).</p></div><button class="bp-primary" id="new-dereg-template">+ Abmeldevorgang</button></div><div class="bp-admin-list">${cards || '<p class="bp-empty">Noch keine Abmeldevorgänge vorhanden.</p>'}</div></section>`
	}

	function dragControls(kind, index, label) {
		return `<span class="bp-order-cell"><button type="button" class="bp-drag-handle" draggable="true" data-drag-kind="${kind}" aria-label="${esc(label)} verschieben" title="Ziehen oder Alt+Pfeiltaste verwenden">⋮⋮</button><span class="bp-position-number">${index + 1}</span></span>`
	}

	// 0.92.0: Werteliste „Ressourcentypen“ – zusätzlich Mehrzahl und Symbol; Standardtypen sind nicht löschbar.
	const RESOURCE_TYPE_LIST = 'RESOURCE_TYPE'
	function resourceTypeFields(item, index) {
		const icon = item.metadata?.icon || (item.value ? '' : 'box')
		const options = Object.entries(RESOURCE_ICONS).map(([key, entry]) => `<option value="${esc(key)}" ${key === icon ? 'selected' : ''}>${esc(entry.name)}</option>`).join('')
		return `<td><input data-value-plural value="${esc(item.metadata?.plural || '')}" placeholder="wie Bezeichnung" aria-label="Mehrzahl, Zeile ${index + 1}"></td><td><span class="bp-resource-icon-pick"><span class="bp-resource-icon" data-value-icon-preview aria-hidden="true">${resourceIconSvg(icon || 'box')}</span><select data-value-icon aria-label="Symbol, Zeile ${index + 1}">${icon ? '' : '<option value="">Standard</option>'}${options}</select></span></td>`
	}
	function valueRow(item, index, technicalValuesLocked = false, variantItems = null, listOptions = {}) {
		const isResourceType = listOptions.key === RESOURCE_TYPE_LIST
		const protectedValue = (listOptions.protectedValues || []).includes(item.value)
		const isVariant = Array.isArray(variantItems)
		const parent = isVariant ? variantItems.find((entry) => Number(entry.id) === Number(item.parentItemId)) : null
		const variantFields = isVariant ? `<td><select data-value-parent aria-label="Übergeordnete Variante, Zeile ${index + 1}"><option value="">Oberste Ebene</option>${variantItems.filter((entry) => Number(entry.id) !== Number(item.id)).map((entry) => `<option value="${esc(entry.value)}" ${entry.value === parent?.value ? 'selected' : ''}>${esc(entry.label)} (${esc(entry.value)})</option>`).join('')}</select></td><td><select data-value-scope aria-label="Bestattungszuordnung, Zeile ${index + 1}"><option value="" ${!item.metadata?.funeralScope ? 'selected' : ''}>Fachlich offen</option><option value="CREMATION" ${item.metadata?.funeralScope === 'CREMATION' ? 'selected' : ''}>Feuer-/Urnenbestattung</option><option value="BURIAL" ${item.metadata?.funeralScope === 'BURIAL' ? 'selected' : ''}>Erdbestattung</option></select></td>` : ''
		return `<tr data-value-row="${item.id}" draggable="false"><td class="bp-value-position">${dragControls('value', index, item.label)}</td><td><input data-value-value value="${esc(item.value)}" aria-label="Technischer Wert, Zeile ${index + 1}" ${item.id ? 'readonly title="Technischer Wert – nach Anlage nicht änderbar, damit bestehende Fälle ihre Zuordnung behalten"' : 'placeholder="wird aus der Bezeichnung erzeugt"'}></td><td><input data-value-label value="${esc(item.label)}" aria-label="Bezeichnung, Zeile ${index + 1}"></td>${variantFields}${isResourceType ? resourceTypeFields(item, index) : ''}<td><span class="bp-row-save-state" aria-live="polite"></span></td><td class="bp-value-actions"><button type="button" class="bp-secondary save-value">Speichern</button><button type="button" class="bp-danger delete-value" ${(technicalValuesLocked && !isVariant) || protectedValue ? `disabled title="${protectedValue ? 'Standard-Ressourcentyp – kann nicht gelöscht werden' : 'Verbindlicher Systemwert'}"` : ''}>Löschen</button></td></tr>`
	}

	// 0.92.0: Pflichtbedarf je Ressourcentyp aus der Werteliste „Ressourcentypen“ (auch eigene Typen).
	function requirementFields(item, resourceTypes) {
		const required = requiredResources(item)
		const fields = resourceTypeList(resourceTypes).map((type) => `<label><span>${resourceTypeIcon(type.key, resourceTypes, esc)} ${esc(type.plural || type.label)}</span><input data-required-resource="${esc(type.key)}" type="number" min="0" max="99" value="${Number(required[type.key] || 0)}"></label>`).join('')
		return `<details class="bp-schedule-requirements"><summary>Pflichtbedarf festlegen</summary><div class="bp-form-grid"><label><span>Mitarbeitende</span><input data-required-staff type="number" min="0" value="${Number(item.requiredStaff || 0)}"></label>${fields}</div><small>Welche Ressourcen zulässig und vorbelegt sind, legen Sie unten in „Ressourcen je Terminart“ fest.</small></details>`
	}

	function scheduleTypeRow(item, resourceTypes, newRow = false) {
		return `<tr data-schedule-type="${Number(item.id || 0)}"><td><input data-key value="${esc(item.key || '')}" ${newRow ? 'placeholder="automatisch aus Bezeichnung"' : 'readonly'}></td><td><input data-name value="${esc(item.name || '')}"></td><td><select data-kind><option value="EXTERNAL_APPOINTMENT" ${item.scheduleKind !== 'INTERNAL_ACTIVITY' ? 'selected' : ''}>Externer Fixtermin</option><option value="INTERNAL_ACTIVITY" ${item.scheduleKind === 'INTERNAL_ACTIVITY' ? 'selected' : ''}>Interne Tätigkeit</option></select></td><td><input data-duration type="number" min="15" step="15" value="${Number(item.durationMinutes || 60)}"></td><td><input data-before type="number" min="0" step="5" value="${Number(item.bufferBeforeMinutes || 0)}"></td><td><input data-after type="number" min="0" step="5" value="${Number(item.bufferAfterMinutes || 0)}"></td><td>${requirementFields(item, resourceTypes)}</td><td><label class="bp-inline-check"><input data-active type="checkbox" ${item.active !== false ? 'checked' : ''}> Aktiv</label></td><td><button type="button" class="bp-secondary save-schedule-type">Speichern</button></td></tr>`
	}

	function schedulingView() {
		const scheduling = schedulingAdminData()
		const workingHours = scheduleWorkingHours(scheduling)
		const typeRows = (scheduling.types || []).map((item) => scheduleTypeRow(item, scheduling.resourceTypes)).join('')
		const resourceCount = (scheduling.resources || []).filter(isAssignable).length
		// 0.92.0: Die Ressourcen selbst werden unter Stammdaten → Ressourcen gepflegt; hier bleiben Terminarten, Pflichtbedarf, Matrix und Arbeitszeit.
		const resourceNote = `<section class="bp-field-section" id="schedule-resources-moved"><div class="bp-panel-head"><h4>Ressourcen</h4><button type="button" class="bp-secondary" data-open-master-data="resources">Ressourcen pflegen</button></div><p class="bp-muted">Kapellen, Räume, Fahrzeuge, Ausstattung und eigene Ressourcentypen pflegen Sie unter <b>Stammdaten → Ressourcen</b> (mit Nummer/Kennung, Beschreibung und Niederlassung). ${resourceCount} Ressource(n) angelegt.</p></section>`
		return `<section class="bp-panel bp-custom-table bp-scheduling-admin"><div class="bp-panel-head"><div><h3>Terminplanung und Disposition</h3><p class="bp-muted">Terminarten definieren Dauer, Puffer und den mindestens zu disponierenden Bedarf. In „Ressourcen je Terminart“ legen Sie fest, welche Ressourcen eine Terminart anbietet und welche vorbelegt sind; der Server verhindert unpassende, unvollständige oder kollidierende Dispositionen.</p></div></div><section class="bp-field-section" id="schedule-working-hours"><div class="bp-panel-head"><h4>Arbeitszeit für die Terminplanung</h4></div><p class="bp-muted">Neue Termine beginnen mit dem Arbeitsbeginn, neue Aufgaben sind zum Arbeitsende fällig. Ersatzzeiten bei Konflikten und die Tagesleiste im Terminformular richten sich nach dieser Arbeitszeit. Sie gilt für alle Niederlassungen.</p><div class="bp-form-grid"><label><span>Arbeitsbeginn</span><input type="time" id="working-hours-start" step="300" value="${esc(workingHours.start)}"></label><label><span>Arbeitsende</span><input type="time" id="working-hours-end" step="300" value="${esc(workingHours.end)}"></label></div><div class="bp-row-actions"><button type="button" class="bp-secondary" id="save-working-hours">Arbeitszeit speichern</button></div></section><section class="bp-field-section"><div class="bp-panel-head"><h4>Terminarten</h4><button type="button" class="bp-secondary" id="new-schedule-type">+ Terminart</button></div><div class="bp-table-scroll"><table><thead><tr><th>Schlüssel</th><th>Bezeichnung</th><th>Einordnung</th><th>Dauer</th><th>Puffer davor</th><th>Puffer danach</th><th>Personal und Ressourcen</th><th>Status</th><th></th></tr></thead><tbody id="schedule-type-rows">${typeRows}</tbody></table></div></section>${resourceNote}${assignmentMatrixView(scheduling)}</section>`
	}

	// 0.90.3: Customizing lädt auch deaktivierte Terminarten und Ressourcen (state.schedulingAdmin), Formulare nur aktive.
	function schedulingAdminData() {
		return state.schedulingAdmin || state.scheduling || { types: [], resources: [] }
	}

	// 0.90.3: Matrix „Ressourcen je Terminart“ – je Ressource und Terminart „zulässig“ und „vorbelegt“.
	// 0.92.0: Gruppen nach der Werteliste „Ressourcentypen“ mit Symbol, Kennung in der Zeile, Filter nach Niederlassung.
	function assignmentMatrixView(scheduling) {
		const types = scheduling.types || []
		const branches = (scheduling.branches || []).filter((branch) => branch.active !== false)
		const branchFilter = state.assignmentBranchFilter || ''
		const resources = (scheduling.resources || []).filter(isAssignable)
		const filterHtml = branches.length > 1 ? `<label class="bp-inline-filter"><span>Niederlassung</span><select id="schedule-assignment-branch"><option value="">Alle Niederlassungen</option>${branches.map((branch) => `<option value="${esc(branch.key)}" ${branch.key === branchFilter ? 'selected' : ''}>${esc(branch.name || branch.key)}</option>`).join('')}</select></label>` : ''
		const head = `<section class="bp-field-section" id="schedule-assignment"><div class="bp-panel-head"><h4>Ressourcen je Terminart</h4><div class="bp-actions">${filterHtml}<button type="button" class="bp-secondary" id="suggest-schedule-assignment">Zuordnung vorschlagen</button><button type="button" class="bp-primary" id="save-schedule-assignment">Zuordnung speichern</button></div></div><p class="bp-muted">Im Terminformular erscheinen nur die Ressourcen, die für die gewählte Terminart „zulässig“ sind; „vorbelegt“ setzt das Häkchen bei neuen Terminen schon. Ohne Zuordnung bietet eine Terminart keine Ressourcen an, ohne Terminart („Freie Eingabe“) ebenfalls nicht. Bei einem Termin mit Fallbezug zählen nur Ressourcen der Niederlassung des Falls (oder „Alle Niederlassungen“). „Zuordnung vorschlagen“ füllt nur Terminarten ohne zulässige Ressource, nach Art der Ressource – prüfen Sie den Vorschlag und nehmen Sie unpassende Ressourcen (z. B. einen Aufbewahrungsraum) heraus.</p><div id="schedule-assignment-issues" aria-live="polite"></div><p class="bp-row-save-state" id="schedule-assignment-state" aria-live="polite"></p>`
		if (!types.length || !resources.length) return `${head}<p class="bp-muted">${!resources.length ? 'Noch keine Ressourcen angelegt (Stammdaten → Ressourcen).' : 'Noch keine Terminarten angelegt.'}</p></section>`
		const cell = (type, resource) => { const allowed = allowedKeys(type).includes(resource.key); const preset = (type.defaultResourceKeys || []).map((key) => String(key).toUpperCase()).includes(resource.key); const label = `${resourceDisplayName(resource)} für ${type.name}`; return `<td class="bp-assignment-cell"><label class="bp-inline-check"><input type="checkbox" data-assignment-allowed data-type-key="${esc(type.key)}" data-resource-key="${esc(resource.key)}" ${allowed ? 'checked' : ''} aria-label="${esc(label)}: zulässig"> zulässig</label><label class="bp-inline-check"><input type="checkbox" data-assignment-default data-type-key="${esc(type.key)}" data-resource-key="${esc(resource.key)}" ${preset ? 'checked' : ''} ${allowed ? '' : 'disabled'} aria-label="${esc(label)}: vorbelegt"> vorbelegt</label></td>` }
		const branchName = (key) => branches.find((branch) => branch.key === key)?.name || key
		const rows = groupByType(resources, scheduling.resourceTypes).map((group) => `<tr class="bp-assignment-group"><th colspan="${types.length + 1}" scope="rowgroup">${resourceTypeIcon(group.type, scheduling.resourceTypes, esc)} ${esc(group.label)}</th></tr>${group.items.map((resource) => { const hidden = branchFilter && !availableInBranch(resource, branchFilter); const where = (resource.branchKeys || []).length ? (resource.branchKeys || []).map(branchName).join(', ') : ''; return `<tr data-assignment-resource="${esc(resource.key)}" class="${resource.active ? '' : 'is-inactive'}" ${hidden ? 'hidden' : ''}><th scope="row">${esc(resourceDisplayName(resource))}${resource.active ? '' : ' <small>(inaktiv)</small>'}${where ? `<small>${esc(where)}</small>` : ''}</th>${types.map((type) => cell(type, resource)).join('')}</tr>` }).join('')}`).join('')
		return `${head}<div class="bp-table-scroll"><table class="bp-assignment-matrix"><thead><tr><th scope="col">Ressource</th>${types.map((type) => `<th scope="col" data-assignment-type="${esc(type.key)}">${esc(type.name)}${type.active ? '' : ' <small>(inaktiv)</small>'}</th>`).join('')}</tr></thead><tbody>${rows}</tbody></table></div></section>`
	}

	function surchargeView() {
		const articles = (state.articles || []).filter((item) => item.active && item.itemType !== 'PACKAGE')
		const row = (item = {}, isNew = false) => `<tr data-surcharge-rule="${Number(item.id || 0)}"><td><input data-rule-key value="${esc(item.ruleKey || '')}" ${isNew ? 'placeholder="automatisch aus Bezeichnung" required' : 'readonly'}></td><td><input data-label value="${esc(item.label || '')}" required></td><td><select data-dimension>${[['PICKUP','Abholzeit'],['HEIGHT_CM','Größe (cm)'],['WEIGHT_KG','Gewicht (kg)'],['OTHER','Weitere']].map(([key,label])=>`<option value="${key}" ${key===item.dimension?'selected':''}>${label}</option>`).join('')}</select></td><td><input data-from type="number" min="0" step="1" value="${item.thresholdFrom ?? ''}" aria-label="Staffel von"></td><td><input data-to type="number" min="0" step="1" value="${item.thresholdTo ?? ''}" aria-label="Staffel bis"></td><td><select data-article><option value="">Noch keine Katalogleistung</option>${articles.map((article)=>`<option value="${Number(article.id)}" ${Number(article.id)===Number(item.articleId)?'selected':''}>${esc(article.articleNumber)} · ${esc(article.shortName)} · ${Number(article.salesPriceCents || 0) / 100} €</option>`).join('')}</select></td><td><label class="bp-inline-check"><input data-active type="checkbox" ${item.active?'checked':''}> Aktiv</label></td><td><input data-sort type="number" min="0" value="${Number(item.sortOrder || 0)}" aria-label="Sortierung"></td><td><button type="button" class="bp-secondary save-surcharge-rule">Speichern</button></td></tr>`
		return `<section class="bp-panel bp-custom-table bp-surcharge-admin"><div class="bp-panel-head"><div><h3>Zuschlagsstaffeln</h3><p class="bp-muted">Staffeln und feste Katalogleistungen pflegen. Die Abholzeit-, Größen- und Gewichtswerte können bereits im Fall erfasst werden. Die Leistungs- und Zuschlagsprüfung im Ablauf (Knopf im Schritt bzw. Zusatzablauf 1) schlägt zutreffende Staffeln vor; übernommen wird bewusst über den Leistungsdialog. Änderungen nach Beauftragung erfolgen nur über den Nachtragsprozess.${handbookMoreLink('customizing-zuschlagsstaffeln', esc)}</p></div><button type="button" class="bp-primary" id="new-surcharge-rule">+ Staffel</button></div><div class="bp-table-scroll"><table><thead><tr><th>Schlüssel</th><th>Bezeichnung</th><th>Merkmal</th><th>Von</th><th>Bis</th><th>Katalogleistung / Preis</th><th>Status</th><th>Sortierung</th><th>Aktion</th></tr></thead><tbody id="surcharge-rule-rows">${(state.surchargeRules || []).map((item)=>row(item)).join('')}</tbody></table></div><template id="new-surcharge-template">${row({},true)}</template></section>`
	}

	function burialRuleView() {
		const variants = state.customizing.find((item) => item.key === 'BURIAL_VARIANT')?.items || []
		const articles = (state.articles || []).filter((item) => item.active && item.itemType !== 'PACKAGE')
		const articleOptions = (selected) => `<option value="">– keine –</option>${articles.map((article) => `<option value="${Number(article.id)}" ${Number(selected)===Number(article.id)?'selected':''}>${esc(article.articleNumber)} · ${esc(article.shortName)}</option>`).join('')}`
		const row = (item = {}, isNew = false) => `<tr data-burial-rule="${Number(item.id || 0)}"><td><select data-variant><option value="">Variante wählen</option>${variants.map((variant)=>`<option value="${esc(variant.value)}" ${variant.value===item.variantCode?'selected':''}>${esc(variant.label)} (${esc(variant.value)})</option>`).join('')}</select></td><td><select data-type>${[['MANDATORY_ARTICLE_GROUP','Gruppe erforderlich'],['MANDATORY_ARTICLE_GROUP_WITH_DEFAULT','Gruppe mit Standardartikel'],['SUGGESTED_ARTICLE','Artikel vorschlagen'],['EXCLUDED_ARTICLE_GROUP','Gruppe ausgeschlossen']].map(([key,label])=>`<option value="${key}" ${key===(item.ruleType||'MANDATORY_ARTICLE_GROUP')?'selected':''}>${label}</option>`).join('')}</select></td><td><select data-group><option value="">– keine –</option>${(state.catalogGroups || []).map((group)=>`<option value="${esc(group.key)}" ${group.key===item.articleGroup?'selected':''}>${esc(group.key)} · ${esc(group.name)}</option>`).join('')}</select></td><td><select data-article>${articleOptions(item.articleId)}</select></td><td><select data-default>${articleOptions(item.defaultArticleId)}</select></td><td><input data-note value="${esc(item.note || '')}" placeholder="Konkreter interner Hinweis"></td><td><label class="bp-inline-check"><input data-active type="checkbox" ${item.active?'checked':''}> Aktiv</label></td><td><input data-sort type="number" min="0" value="${Number(item.sortOrder || 0)}" aria-label="Sortierung"></td><td><button type="button" class="bp-secondary save-burial-rule">Speichern</button></td></tr>`
		return `<section class="bp-panel bp-custom-table"><div class="bp-panel-head"><div><h3>Varianten- und Leistungsregeln</h3><p class="bp-muted">Pflege von Pflicht-Warengruppen, Standards und Vorschlägen je Bestattungsvariante. Regeln verweisen auf den Warengruppen-Schlüssel des Leistungskatalogs. Ungeklärte Varianten können nicht aktiviert werden.${handbookMoreLink('customizing-variantenregeln', esc)}</p></div><button type="button" class="bp-primary" id="new-burial-rule">+ Regel</button></div><div class="bp-table-scroll"><table><thead><tr><th>Variante</th><th>Regel</th><th>Warengruppe</th><th>Artikel</th><th>Standardartikel</th><th>Interner Hinweis</th><th>Status</th><th>Sort.</th><th>Aktion</th></tr></thead><tbody id="burial-rule-rows">${(state.burialVariantRules || []).map((item)=>row(item)).join('')}</tbody></table></div><template id="new-burial-rule-template">${row({},true)}</template></section>`
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

	/** 0.89.5: Verweisprüfung je Reiter; neu geladen beim Reiterwechsel und nach dem Speichern (state.catalogReferences = undefined). */
	function catalogReferences(area) {
		if (state.catalogReferences === undefined || state.catalogReferencesTab !== state.customizingTab) {
			state.catalogReferences = null; state.catalogReferencesTab = state.customizingTab
			api(`${apiBase}/catalog-references`, { feedback: false }).then((result) => { state.catalogReferences = result; if (state.view === 'customizing') render() }).catch(() => { state.catalogReferences = { error: true } })
			return ''
		}
		return referenceNotice(state.catalogReferences, esc, area)
	}

	// 0.92.0: Wertelisten liegen unter Stammdaten → Wertelisten (vorher Customizing).
	function valueListsView() {
		const sortedLists = [...state.customizing].sort((a, b) => a.name.localeCompare(b.name, 'de', { sensitivity: 'base' }) || a.key.localeCompare(b.key))
		const activeKey = state.valueListKey && state.customizing.some((list) => list.key === state.valueListKey) ? state.valueListKey : (sortedLists[0]?.key || '')
		state.valueListKey = activeKey
		const activeList = state.customizing.find((list) => list.key === activeKey)
		const isVariant = activeKey === 'BURIAL_VARIANT'
		const isResourceType = activeKey === RESOURCE_TYPE_LIST
		const listOptions = { key: activeKey, protectedValues: activeList?.protectedValues || [] }
		const rows = (activeList?.items || []).map((item, index) => valueRow(item, index, activeList?.technicalValuesLocked, isVariant ? activeList.items : null, listOptions)).join('')
		return `<section class="bp-panel bp-custom-table"><div class="bp-panel-head"><div><h3>Wertelisten</h3><p class="bp-muted">Wertelisten alphabetisch anzeigen; die fachliche Reihenfolge der Einträge bleibt separat pflegbar.</p></div><span class="bp-status">${state.customizing.length} Listen</span></div><div class="bp-value-list-workspace"><nav class="bp-value-list-nav" aria-label="Wertelisten">${sortedLists.map((list) => `<button type="button" class="${list.key === activeKey ? 'active' : ''}" data-value-list="${esc(list.key)}"><span>${esc(list.name)}</span><small>${Number(list.itemCount ?? list.items.length)} Einträge</small></button>`).join('')}</nav><div class="bp-value-list-editor"><div class="bp-panel-head"><div><p class="bp-eyebrow">${esc(activeList?.key || '')}</p><h3>${esc(activeList?.name || 'Keine Werteliste vorhanden')}</h3>${activeList?.technicalValuesLocked ? `<small>${isVariant ? 'Bestehende Schlüssel bleiben fest; neue Ober- und Untervarianten können hier angelegt werden.' : 'Technische Schlüssel sind verbindlich; Bezeichnungen und Reihenfolge bleiben pflegbar.'}</small>` : ''}</div><button type="button" class="bp-primary" id="add-value" ${activeList && (!activeList.technicalValuesLocked || isVariant) ? '' : 'disabled'}>+ Neue Zeile</button></div>${isVariant ? '<p class="bp-muted">Einträge ohne übergeordnete Variante sind Bestattungsarten; darunter liegen die Untervarianten. „Zuordnung“ steuert nur die Katalogfilterung. Baum- und Waldbestattung sind eigenständige Bestattungsarten.</p>' : ''}${isResourceType ? '<p class="bp-muted">Ressourcentypen ordnen die Ressourcen (Stammdaten → Ressourcen) und bestimmen den Pflichtbedarf der Terminarten. Das Symbol erscheint bei Terminen, Aufgaben mit Terminbezug und in der Ressourcenpflege. Kapelle, Raum, Fahrzeug und Ausstattung sind fest; eigene Typen lassen sich löschen, solange keine Ressource sie verwendet.</p>' : ''}<div class="bp-table-scroll"><table class="bp-value-table"><thead><tr><th>Reihenfolge</th><th>Technischer Wert</th><th>Sprechende Bezeichnung</th>${isVariant ? '<th>Übergeordnete Variante</th><th>Zuordnung</th>' : ''}${isResourceType ? '<th>Mehrzahl</th><th>Symbol</th>' : ''}<th>Status</th><th>Aktionen</th></tr></thead><tbody id="value-list-rows">${rows || `<tr class="bp-value-empty"><td colspan="${isVariant ? 7 : (isResourceType ? 7 : 5)}" class="bp-empty">Noch keine Einträge. Mit „Neue Zeile“ beginnen.</td></tr>`}</tbody></table></div><p class="bp-muted">${isVariant ? '„Fachlich offen“ verhindert aktive Leistungsregeln. Neue Untervarianten erscheinen nach dem Speichern in der Fallauswahl.' : ''} Ziehen: Griff festhalten und Zeile verschieben. Tastatur: Griff fokussieren und Alt+Pfeil auf/ab verwenden. Ungespeicherte neue Zeilen zuerst speichern.</p></div></div></section>`
	}

	function customizing() {
		// 0.92.0: Marker und Wertelisten stehen unter Stammdaten.
		const tabs = [['processes', 'Abläufe'], ['document-kinds', 'Dokumentarten'], ['burial-rules', 'Variantenregeln'], ['surcharges', 'Zuschlagsstaffeln'], ['scheduling', 'Terminplanung'], ['documents', 'Dokumentvorlagen'], ['order-choices', 'Ankreuzfelder'], ['typical-costs', 'Fremdkosten'], ['mail-templates', 'E-Mail-Vorlagen'], ['deregistration', 'Abmeldungen'], ['order-types', 'Auftragsarten'], ['precare', 'Vorsorge']]
		let body = ''
		if (state.customizingTab === 'processes') body = ctx.processAdminView ? ctx.processAdminView() : ''
		else if (state.customizingTab === 'document-kinds') body = ctx.documentKindsAdminView ? ctx.documentKindsAdminView() : ''
		else if (state.customizingTab === 'burial-rules') body = burialRuleView()
		else if (state.customizingTab === 'surcharges') body = surchargeView()
		else if (state.customizingTab === 'scheduling') body = schedulingView()
		else if (state.customizingTab === 'documents') body = documentTemplatesView()
		else if (state.customizingTab === 'order-choices') body = orderChoiceFields.view()
		else if (state.customizingTab === 'mail-templates') body = mailTemplates.view()
		else if (state.customizingTab === 'typical-costs') body = typicalCosts.view()
		else if (state.customizingTab === 'deregistration') body = deregistrationTemplatesView()
		else if (['order-types', 'precare'].includes(state.customizingTab)) body = caseKindSettings.view(state.customizingTab)
		if (REFERENCE_TABS[state.customizingTab]) body = catalogReferences(REFERENCE_TABS[state.customizingTab]) + body
		return `<div class="bp-page-head"><div><p class="bp-eyebrow">Administration</p><h2>Customizing</h2></div></div><div class="bp-subtabs">${tabs.map((tab) => `<button class="${state.customizingTab === tab[0] ? 'active' : ''}" data-customizing-tab="${tab[0]}">${tab[1]}</button>`).join('')}</div>${body}`
	}

	function bindCustomizing() {
		orderChoiceFields.bind()
		mailTemplates.bind()
		typicalCosts.bind()
		caseKindSettings.bind()
		bindAutoKeys()
		bindScheduling()
		const bindBurialRule = (row) => row.querySelector('.save-burial-rule')?.addEventListener('click', async () => {
			const id = Number(row.dataset.burialRule || 0)
			const rule = { variantCode: row.querySelector('[data-variant]').value, ruleType: row.querySelector('[data-type]').value, articleGroup: row.querySelector('[data-group]').value.trim(), articleId: row.querySelector('[data-article]').value, defaultArticleId: row.querySelector('[data-default]').value, note: row.querySelector('[data-note]').value.trim(), active: row.querySelector('[data-active]').checked, sortOrder: row.querySelector('[data-sort]').value }
			const button = row.querySelector('.save-burial-rule'); button.disabled = true
			try { await api(`${apiBase}/customizing/burial-rules${id ? `/${id}` : ''}`, { method: id ? 'PUT' : 'POST', body: new URLSearchParams({rule: JSON.stringify(rule)}) }); state.burialVariantRules = await api(`${apiBase}/customizing/burial-rules`); state.catalogReferences = undefined; render(); notifySuccess('Variantenregel gespeichert.') }
			catch (error) { notifyError(error.message); button.disabled = false }
		})
		root.querySelectorAll('[data-burial-rule]').forEach(bindBurialRule)
		document.getElementById('new-burial-rule')?.addEventListener('click', () => { const row = document.getElementById('new-burial-rule-template').content.firstElementChild.cloneNode(true); document.getElementById('burial-rule-rows').append(row); bindBurialRule(row); row.querySelector('[data-variant]').focus() })
		const bindSurchargeRow = (row) => row.querySelector('.save-surcharge-rule')?.addEventListener('click', async () => {
			const id = Number(row.dataset.surchargeRule || 0)
			const rule = { ruleKey: row.querySelector('[data-rule-key]').value.trim(), label: row.querySelector('[data-label]').value.trim(), dimension: row.querySelector('[data-dimension]').value, thresholdFrom: row.querySelector('[data-from]').value, thresholdTo: row.querySelector('[data-to]').value, articleId: row.querySelector('[data-article]').value, active: row.querySelector('[data-active]').checked, sortOrder: row.querySelector('[data-sort]').value }
			const button = row.querySelector('.save-surcharge-rule'); button.disabled = true
			try { await api(`${apiBase}/customizing/surcharges${id ? `/${id}` : ''}`, { method: id ? 'PUT' : 'POST', body: new URLSearchParams({rule: JSON.stringify(rule)}) }); state.surchargeRules = await api(`${apiBase}/customizing/surcharges`); state.catalogReferences = undefined; render(); notifySuccess('Zuschlagsstaffel gespeichert.') }
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
			row.querySelector('[data-value-icon]')?.addEventListener('change', (event) => { const preview = row.querySelector('[data-value-icon-preview]'); if (preview) preview.innerHTML = resourceIconSvg(event.target.value || 'box'); markDirty() })
			row.querySelector('[data-value-parent]')?.addEventListener('change', (event) => { const parent = state.customizing.find((list) => list.key === 'BURIAL_VARIANT')?.items.find((item) => item.value === event.target.value); if (parent?.metadata?.funeralScope) row.querySelector('[data-value-scope]').value = parent.metadata.funeralScope; markDirty() })
			row.querySelector('.save-value')?.addEventListener('click', async () => {
				const value = row.querySelector('[data-value-value]').value.trim(); const label = row.querySelector('[data-value-label]').value.trim()
				if (!value || !label) { stateLabel.textContent = 'Beide Felder ausfüllen'; stateLabel.dataset.state = 'error'; return }
				const id = Number(row.dataset.valueRow || 0); const button = row.querySelector('.save-value'); button.disabled = true; stateLabel.textContent = 'Speichert …'; stateLabel.dataset.state = 'saving'
				try { const params = { value, label }; if (state.valueListKey === 'BURIAL_VARIANT') { params.parentValue = row.querySelector('[data-value-parent]').value; params.funeralScope = row.querySelector('[data-value-scope]').value } if (state.valueListKey === RESOURCE_TYPE_LIST) { params.plural = row.querySelector('[data-value-plural]')?.value.trim() || ''; params.icon = row.querySelector('[data-value-icon]')?.value || '' } const saved = await api(id ? `${urls.customizing}/item/${id}` : `${urls.customizing}/${encodeURIComponent(state.valueListKey)}`, { method: id ? 'PUT' : 'POST', feedback: false, body: new URLSearchParams(params) }); row.dataset.valueRow = saved.id; updateLocalItem(saved); row.classList.remove('is-dirty','is-new'); stateLabel.textContent = 'Gespeichert'; stateLabel.dataset.state = 'saved'; row.querySelector('.delete-value').disabled = false; if (state.valueListKey === 'BURIAL_VARIANT') { state.customizing = (await api(urls.customizing)).lists; render() } else bindSortableRows(document.getElementById('value-list-rows'),'[data-value-row]','value',saveValueOrder); if (state.valueListKey === RESOURCE_TYPE_LIST) reloadScheduling().catch(() => {}); notifySuccess(`„${saved.label}“ wurde gespeichert.`) } catch (error) { stateLabel.textContent = error.message; stateLabel.dataset.state = 'error' } finally { button.disabled = false }
			})
			row.querySelector('.delete-value')?.addEventListener('click', async () => {
				const id = Number(row.dataset.valueRow || 0); if (!id) { row.remove(); return }
				if (!await confirmAction('Diesen Wert wirklich aus der geöffneten Werteliste löschen?', { title: 'Listenwert löschen', confirmLabel: 'Löschen', danger: true })) return
				try { await api(`${urls.customizing}/item/${id}`, { method: 'DELETE', feedback: false }); const list=state.customizing.find((entry)=>entry.key===state.valueListKey); if(list){list.items=list.items.filter((entry)=>Number(entry.id)!==id);list.itemCount=list.items.length} if (state.valueListKey === 'BURIAL_VARIANT') { state.customizing = (await api(urls.customizing)).lists; render() } else { row.remove(); refreshPositions(document.getElementById('value-list-rows'),'[data-value-row]') } if (state.valueListKey === RESOURCE_TYPE_LIST) reloadScheduling().catch(() => {}); notifySuccess('Der Listenwert wurde gelöscht.') } catch(error) { stateLabel.textContent=error.message; stateLabel.dataset.state='error' }
			})
		}
		root.querySelectorAll('[data-value-row]').forEach(bindValueRow)
		const valueBody=document.getElementById('value-list-rows')
		const saveValueOrder=async()=>{ const rows=[...valueBody.querySelectorAll('[data-value-row]')]; const ids=rows.map((row)=>Number(row.dataset.valueRow||0)); if(ids.includes(0)){notifyError('Neue Zeilen bitte zuerst speichern; danach kann die Reihenfolge gespeichert werden.');return} const original=[...(state.customizing.find((entry)=>entry.key===state.valueListKey)?.items||[])]; try{const saved=await api(`${urls.customizing}/${encodeURIComponent(state.valueListKey)}/reorder`,{method:'PUT',feedback:false,body:new URLSearchParams({itemIds:JSON.stringify(ids)})});const list=state.customizing.find((entry)=>entry.key===state.valueListKey);if(list){list.items=saved;list.itemCount=saved.length}notifySuccess('Die Reihenfolge wurde gespeichert.')}catch(error){const list=state.customizing.find((entry)=>entry.key===state.valueListKey);if(list)list.items=original;render();notifyError(error.message)}}
		bindSortableRows(valueBody,'[data-value-row]','value',saveValueOrder)
		document.getElementById('add-value')?.addEventListener('click', () => { const tbody=document.getElementById('value-list-rows'); tbody.querySelector('.bp-value-empty')?.remove(); const position=tbody.querySelectorAll('[data-value-row]').length; const variants=state.valueListKey==='BURIAL_VARIANT' ? state.customizing.find((list)=>list.key==='BURIAL_VARIANT')?.items || [] : null; const template=document.createElement('template'); template.innerHTML=valueRow({id:0,value:'',label:''},position,state.valueListKey==='BURIAL_VARIANT',variants,{key:state.valueListKey}); const row=template.content.firstElementChild; tbody.append(row); row.classList.add('is-new','is-dirty'); row.querySelector('.bp-row-save-state').textContent='Neu · ungespeichert'; bindValueRow(row); row.querySelector('[data-value-value]').focus(); row.scrollIntoView({block:'nearest'}) })
	}

	function bindScheduling(){
		const scheduling = schedulingAdminData()
		const bindType=(row)=>row.querySelector('.save-schedule-type')?.addEventListener('click',async()=>{const id=Number(row.dataset.scheduleType||0);const number=(selector)=>Number(row.querySelector(selector)?.value||0);const requiredResourcesMap=Object.fromEntries([...row.querySelectorAll('[data-required-resource]')].map((input)=>[input.dataset.requiredResource,Number(input.value||0)]).filter(([,count])=>count>0));const data={key:row.querySelector('[data-key]').value.trim(),name:row.querySelector('[data-name]').value.trim(),scheduleKind:row.querySelector('[data-kind]').value,durationMinutes:number('[data-duration]'),bufferBeforeMinutes:number('[data-before]'),bufferAfterMinutes:number('[data-after]'),requiredStaff:number('[data-required-staff]'),requiredResources:requiredResourcesMap,active:row.querySelector('[data-active]').checked};try{await api(`${schedulingUrl()}/types/${id}`,{method:'PUT',body:new URLSearchParams({data:JSON.stringify(data)})});await reloadScheduling();render();notifySuccess('Terminart einschließlich Ressourcenbedarf wurde gespeichert.')}catch(error){notifyError(error.message)}})
		root.querySelectorAll('[data-schedule-type]').forEach(bindType)
		bindAssignmentMatrix()
		// 0.92.0: Verweis auf die Ressourcenpflege unter Stammdaten.
		root.querySelectorAll('[data-open-master-data]').forEach((button) => button.addEventListener('click', () => ctx.openMasterData?.(button.dataset.openMasterData)))
		// 0.90.5: Arbeitszeit für die Terminplanung (Vorbelegung neuer Termine und Aufgaben, Ersatzzeiten, Tagesleiste).
		document.getElementById('save-working-hours')?.addEventListener('click',async()=>{const data={start:document.getElementById('working-hours-start').value,end:document.getElementById('working-hours-end').value};try{await api(`${schedulingUrl()}/working-hours`,{method:'PUT',body:new URLSearchParams({data:JSON.stringify(data)})});await reloadScheduling();render();notifySuccess('Arbeitszeit für die Terminplanung wurde gespeichert.')}catch(error){notifyError(error.message)}})
		document.getElementById('new-schedule-type')?.addEventListener('click',()=>{const body=document.getElementById('schedule-type-rows');const template=document.createElement('template');template.innerHTML=scheduleTypeRow({id:0,scheduleKind:'EXTERNAL_APPOINTMENT',durationMinutes:60,active:true},scheduling.resourceTypes,true);const row=template.content.firstElementChild;body.append(row);bindType(row);row.querySelector('[data-key]').focus()})
	}

	// 0.90.3: Customizing mit deaktivierten Einträgen, Formulare nur mit aktiven Terminarten und Ressourcen.
	async function reloadScheduling() {
		const [all, active] = await Promise.all([api(`${schedulingUrl()}?all=1`), api(schedulingUrl())])
		state.schedulingAdmin = all; state.scheduling = active
	}

	function bindAssignmentMatrix() {
		const section = root.querySelector('#schedule-assignment'); if (!section) return
		const scheduling = schedulingAdminData()
		const matrixTypes = () => (scheduling.types || []).map((type) => ({ ...type, allowedResourceKeys: [...section.querySelectorAll(`[data-assignment-allowed][data-type-key="${CSS.escape(type.key)}"]:checked`)].map((input) => input.dataset.resourceKey), defaultResourceKeys: [...section.querySelectorAll(`[data-assignment-default][data-type-key="${CSS.escape(type.key)}"]:checked`)].map((input) => input.dataset.resourceKey) }))
		const showIssues = () => { const box = section.querySelector('#schedule-assignment-issues'); if (!box) return; const issues = assignmentIssues(matrixTypes(), scheduling.resources || [], scheduling.resourceTypes, scheduling.branches || []); box.innerHTML = issues.length ? `<div class="bp-warning" role="status"><b>Pflichtbedarf nicht erfüllbar – ein Termin dieser Art lässt sich so nicht speichern:</b><ul>${issues.map((issue) => `<li>${esc(issue.text)}</li>`).join('')}</ul></div>` : '' }
		const markDirty = () => { const stateText = section.querySelector('#schedule-assignment-state'); if (stateText) stateText.textContent = 'Ungespeicherte Änderungen'; showIssues() }
		section.querySelector('#schedule-assignment-branch')?.addEventListener('change', (event) => { state.assignmentBranchFilter = event.target.value; section.querySelectorAll('[data-assignment-resource]').forEach((row) => { const resource = (scheduling.resources || []).find((entry) => entry.key === row.dataset.assignmentResource); row.hidden = Boolean(state.assignmentBranchFilter) && !availableInBranch(resource, state.assignmentBranchFilter) }) })
		section.querySelectorAll('[data-assignment-allowed]').forEach((input) => input.addEventListener('change', () => { const preset = section.querySelector(`[data-assignment-default][data-type-key="${CSS.escape(input.dataset.typeKey)}"][data-resource-key="${CSS.escape(input.dataset.resourceKey)}"]`); if (preset) { preset.disabled = !input.checked; if (!input.checked) preset.checked = false } markDirty() }))
		section.querySelectorAll('[data-assignment-default]').forEach((input) => input.addEventListener('change', markDirty))
		section.querySelector('#suggest-schedule-assignment')?.addEventListener('click', () => {
			let filled = 0
			for (const type of matrixTypes()) {
				if (type.allowedResourceKeys.length) continue
				const suggestion = suggestAllowed(type, scheduling.resources || [])
				section.querySelectorAll(`[data-assignment-allowed][data-type-key="${CSS.escape(type.key)}"]`).forEach((input) => { input.checked = suggestion.includes(input.dataset.resourceKey); const preset = section.querySelector(`[data-assignment-default][data-type-key="${CSS.escape(type.key)}"][data-resource-key="${CSS.escape(input.dataset.resourceKey)}"]`); if (preset) preset.disabled = !input.checked })
				if (suggestion.length) filled++
			}
			markDirty()
			if (filled) notifySuccess(`Vorschlag für ${filled} Terminart(en) eingetragen. Bitte prüfen und „Zuordnung speichern“.`)
			else notifySuccess('Alle Terminarten haben bereits eine Zuordnung oder es gibt keine passenden Ressourcen.')
		})
		section.querySelector('#save-schedule-assignment')?.addEventListener('click', async () => {
			const assignments = Object.fromEntries(matrixTypes().map((type) => [type.key, { allowed: type.allowedResourceKeys, defaults: type.defaultResourceKeys }]))
			try { state.schedulingAdmin = await api(`${schedulingUrl()}/assignments`, { method: 'PUT', body: new URLSearchParams({ data: JSON.stringify({ assignments }) }) }); state.scheduling = await api(schedulingUrl()); render(); notifySuccess('Zuordnung „Ressourcen je Terminart“ wurde gespeichert.') } catch (error) { notifyError(error.message) }
		})
		showIssues()
	}

	// 0.90.2: Fallnummern mit Kennung der Niederlassung (Stammdaten → Niederlassungen).
	function caseNumberPreview(layout, kindPrefix, code) {
		const year = String(new Date().getFullYear())
		if (layout === 'BRANCH_YEAR_SEQ') return `${kindPrefix}${code}-${year}-0001`
		if (layout === 'YEAR_BRANCH_SEQ') return `${kindPrefix}${year}-${code}-0001`
		return `${kindPrefix}${year}-0001`
	}
	function bindCaseNumberSettings(showCardError) {
		const panel = document.getElementById('case-number-settings')
		if (!panel) return
		if (panel.dataset.loading === '1') {
			if (state.caseNumberSettingsLoading) return
			state.caseNumberSettingsLoading = true
			api(`${apiBase}/case-number-settings`).then((view) => { state.caseNumberSettings = view; render() }).catch((error) => showCardError(panel, error)).finally(() => { state.caseNumberSettingsLoading = false })
			return
		}
		const layout = panel.querySelector('[name="layout"]')
		const scope = panel.querySelector('[name="scope"]')
		const code = (state.caseNumberSettings?.branches || []).find((entry) => entry.active && entry.effectiveCode)?.effectiveCode || 'HB'
		layout?.addEventListener('change', () => {
			if (scope) scope.disabled = layout.value === 'YEAR_SEQ'
			const target = panel.querySelector('[data-case-number-preview]')
			if (target) target.textContent = ['', 'A-', 'V-'].map((prefix) => caseNumberPreview(layout.value, prefix, code)).join(' · ')
		})
		document.getElementById('save-case-number-settings')?.addEventListener('click', async (event) => {
			const button = event.currentTarget
			button.disabled = true
			try {
				state.caseNumberSettings = await api(`${apiBase}/case-number-settings`, { method: 'PUT', feedback: false, body: new URLSearchParams({ settings: JSON.stringify({ layout: layout.value, scope: scope?.value || 'YEAR_GLOBAL' }) }) })
				render()
				notifySuccess('Aufbau der Fallnummern wurde gespeichert. Er gilt für neu angelegte Fälle.')
			} catch (error) { button.disabled = false; showCardError(panel, error) }
		})
	}

	// 0.92.1: Aufbau der Rechnungsnummer – Bausteine per Klick einfügen, Beispiele übernehmen, Vorschau sofort.
	function bindInvoiceNumberPattern() {
		const form = document.getElementById('invoice-settings')
		const panel = form?.closest('.bp-panel')
		const pattern = form?.querySelector('[name="pattern"]')
		if (!pattern || !panel) return
		const preview = panel.querySelector('[data-invoice-number-preview]')
		const correction = panel.querySelector('[data-invoice-correction-preview]')
		const status = panel.querySelector('.bp-card-state')
		const update = () => {
			const options = { sequenceLength: form.querySelector('[name="sequenceLength"]')?.value, branchKey: preview?.dataset.branchKey || 'STAMMHAUS', branchCode: preview?.dataset.branchCode || 'HB' }
			const error = invoiceNumberPatternError(pattern.value)
			if (preview) preview.textContent = invoiceNumberPreview(pattern.value, { ...options, prefix: form.querySelector('[name="prefix"]')?.value })
			if (correction) correction.textContent = invoiceNumberPreview(pattern.value, { ...options, prefix: form.querySelector('[name="correctionPrefix"]')?.value || 'GS' })
			if (status) { status.textContent = error; if (error) status.dataset.state = 'error'; else delete status.dataset.state }
		}
		;['pattern', 'prefix', 'correctionPrefix', 'sequenceLength'].forEach((name) => form.querySelector(`[name="${name}"]`)?.addEventListener('input', update))
		panel.querySelectorAll('[data-invoice-token]').forEach((button) => button.addEventListener('click', () => {
			const start = pattern.selectionStart ?? pattern.value.length
			const end = pattern.selectionEnd ?? start
			pattern.value = pattern.value.slice(0, start) + button.dataset.invoiceToken + pattern.value.slice(end)
			const caret = start + button.dataset.invoiceToken.length
			pattern.focus(); pattern.setSelectionRange?.(caret, caret); update()
		}))
		panel.querySelectorAll('[data-invoice-pattern]').forEach((button) => button.addEventListener('click', () => { pattern.value = button.dataset.invoicePattern; pattern.focus(); update() }))
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
		document.getElementById('new-branch')?.addEventListener('click', () => { state.branches = [{ id: 0, key: '', name: '', street: '', postalCode: '', city: '', country: 'Deutschland', countryCode: 'DE', federalState: '', invoiceProfile: 'ZUGFERD', paymentQrStandard: 'EPC069-12', phone: '', email: '', numberCode: '', accountHolder: '', bankName: '', iban: '', bic: '', creditorId: '', paymentTermDays: 14, vatId: '', taxNumber: '', registerCourt: '', registerNumber: '', managingDirectors: '', electronicAddress: '', memberUids: [state.team.currentUid], active: true }, ...state.branches.filter((entry) => entry.id)]; render() })
		root.querySelectorAll('[name="countryCode"]').forEach((select) => select.addEventListener('change', () => { const card=select.closest('[data-branch-id]'); const profile=(state.countryProfiles||[]).find((item)=>item.countryCode===select.value); card.querySelector('.bp-federal-state')?.classList.toggle('bp-hidden',select.value!=='AT'); if(profile){card.querySelector('[name="country"]').value=profile.name;card.querySelector('[name="invoiceProfile"]').value=profile.invoiceProfile;card.querySelector('[name="paymentQrStandard"]').value=profile.paymentQrStandard} }))
		root.querySelectorAll('.save-country-profile').forEach((button) => button.addEventListener('click', async () => { const card=button.closest('[data-country-profile]'); const status=card.querySelector('.bp-card-state'); const values={vatRates:card.querySelector('[name="vatRates"]').value.split(/[,;]+/).map((value)=>value.trim()).filter(Boolean),invoiceProfile:card.querySelector('[name="invoiceProfile"]').value,paymentQrStandard:card.querySelector('[name="paymentQrStandard"]').value}; button.disabled=true; try { const saved=await api(`${apiBase}/country-profiles/${card.dataset.countryProfile}`,{method:'PUT',feedback:false,body:new URLSearchParams({profile:JSON.stringify(values)})}); state.countryProfiles=(state.countryProfiles||[]).map((item)=>item.countryCode===saved.countryCode?saved:item); const catalog=await api(articleUrl()); state.allowedVatRates=catalog.allowedVatRates||state.allowedVatRates; status.textContent='Länderprofil gespeichert.';status.dataset.state='saved';render();notifySuccess('Länderprofil und zulässige Steuersätze wurden gespeichert.') } catch(error){button.disabled=false;showCardError(card,error)} }))
		root.querySelectorAll('.save-branch').forEach((button) => button.addEventListener('click', async () => { const card = button.closest('[data-branch-id]'); if (!validateConfigurationKey(card, 'Der Niederlassungsschlüssel')) return; const value = (name) => card.querySelector(`[name="${name}"]`); const names = ['key','name','numberCode','street','postalCode','city','country','countryCode','federalState','invoiceProfile','paymentQrStandard','phone','email','accountHolder','bankName','iban','bic','creditorId','paymentTermDays','vatId','taxNumber','registerCourt','registerNumber','managingDirectors','electronicAddress']; const data = Object.fromEntries(names.map((name) => [name, value(name).value])); data.memberUids = [...card.querySelectorAll('[name="memberUids"]:checked')].map((input) => input.value); data.active = value('active').checked; data.numberCode = String(data.numberCode || '').trim().toUpperCase(); card.querySelectorAll('.save-branch').forEach((other) => { other.disabled = true }); try { await api(`${branchUrl()}/${button.dataset.id}`, { method: 'PUT', feedback: false, body: new URLSearchParams({ branch: JSON.stringify(data) }) }); state.invoiceSettings = await api(invoiceSettingsUrl()).catch(() => state.invoiceSettings); state.branches = await api(branchUrl()); state.caseNumberSettings = null; render(); notifySuccess('Die Niederlassung wurde gespeichert (einschließlich Kennung, Bank- und Unternehmensdaten).') } catch (error) { card.querySelectorAll('.save-branch').forEach((other) => { other.disabled = false }); showCardError(card, error) } }))
		// 0.92.1: Eine Schaltfläche „Niederlassung speichern“ für die ganze Karte (bisher zusätzlich „Bank- und Unternehmensdaten speichern“, das die übrigen Eingaben der Karte verwarf). Ungespeicherte Eingaben werden angezeigt.
		root.querySelectorAll('[data-branch-id]').forEach((card) => { const mark = (event) => { if (card.dataset.dirty === '1' || event.target.closest?.('[data-branch-logo]')) return; card.dataset.dirty = '1'; const status = card.querySelector('.bp-card-state'); if (status && status.dataset.state !== 'error') { status.textContent = 'Ungespeicherte Änderungen – bitte „Niederlassung speichern“.'; status.dataset.state = 'warning' } }; card.addEventListener('input', mark); card.addEventListener('change', mark) })
		bindCaseNumberSettings(showCardError)
		bindInvoiceNumberPattern()
		document.getElementById('save-invoice-settings')?.addEventListener('click', async () => { const form = document.getElementById('invoice-settings').closest('.bp-panel'); /* 0.92.1: die Ankreuzfelder stehen außerhalb von #invoice-settings – bisher brach das Speichern deshalb ohne Meldung ab. */ const value = (name) => form.querySelector(`[name="${name}"]`); const data = { prefix: value('prefix').value, correctionPrefix: value('correctionPrefix')?.value || 'GS', pattern: value('pattern').value, sequenceLength: Number(value('sequenceLength').value), sequenceScope: value('sequenceScope').value, caseReference: value('caseReference').checked, zugferdEnabled: value('zugferdEnabled').checked, xrechnungEnabled: value('xrechnungEnabled').checked, normativeValidationRequired: value('normativeValidationRequired').checked, qrEnabled: value('qrEnabled').checked }; try { const patternError = invoiceNumberPatternError(data.pattern); if (patternError) throw new Error(patternError); state.invoiceSettings = await api(invoiceSettingsUrl(), { method: 'PUT', feedback: false, body: new URLSearchParams({ settings: JSON.stringify(data) }) }); render(); notifySuccess('Rechnungseinstellungen wurden gespeichert. Der Aufbau gilt für neu vergebene Rechnungsnummern.') } catch (error) { showCardError(form, error) } })
		root.querySelectorAll('.delete-branch').forEach((button) => button.addEventListener('click', async () => { if (!await confirmAction('Niederlassung löschen?', { title: 'Niederlassung löschen', confirmLabel: 'Löschen', danger: true })) return; await api(`${apiBase}/configuration/branch/${button.dataset.id}`, { method: 'DELETE' }); state.branches = await api(branchUrl()); render() }))
		document.getElementById('provision-templates')?.addEventListener('click', async () => { const result = await api(`${documentTemplateUrl()}/provision`, { method: 'POST', feedback: false, loadingLabel: 'Fehlende Standardvorlagen werden ergänzt …' }); const files=result.files||[]; const created=files.filter((file)=>file.status==='CREATED').length; const preserved=files.filter((file)=>file.status==='PRESERVED').length; state.documentTemplateOptions=await api(documentTemplateOptionsUrl()); if (ctx.loadTemplateFiles) await ctx.loadTemplateFiles(); render(); notifySuccess(`${created} fehlende Standardvorlage${created===1?' wurde':'n wurden'} ergänzt; ${preserved} vorhandene individuelle Datei${preserved===1?' wurde':'en wurden'} unverändert beibehalten.`) })
		document.getElementById('download-logo-placeholder')?.addEventListener('click', async () => { try { await download(`${apiBase}/document-templates/logo-placeholder.png`, 'Logo-Platzhalter.png') } catch (error) { notifyError(error.message) } })
		document.getElementById('download-template-fields')?.addEventListener('click', async () => { try { await download(`${apiBase}/template-fields.csv`, 'Bestatter-Dokumentvorlagen-Feldkatalog.csv') } catch (error) { notifyError(error.message) } })
		document.getElementById('new-document-template')?.addEventListener('click', () => { state.documentTemplates = [{ id: 0, key: '', name: '', category: 'Allgemein', fileName: '', outputSubfolder: '03 Behörden', requiredFields: [], description: '', supportsPdf: false, active: true, sortOrder: 100 }, ...state.documentTemplates.filter((entry) => entry.id)]; state.documentTemplateEditing = 0; if (state.documentTemplateFilter) state.documentTemplateFilter = { query: '', category: '', attention: false }; render() })
		root.querySelectorAll('.save-document-template').forEach((button) => button.addEventListener('click', async () => { const card = button.closest('[data-document-template]'); if (!validateConfigurationKey(card, 'Der Vorlagenschlüssel')) return; const value = (name) => card.querySelector(`[name="${name}"]`); const template = { key: value('key').value, name: value('name').value, category: value('category').value, fileName: value('fileName').value, outputSubfolder: value('outputSubfolder').value, sortOrder: Number(value('sortOrder').value || 0), requiredFields: value('requiredFields').value.split(',').map((entry) => entry.trim()).filter(Boolean), description: value('description').value, supportsPdf: value('supportsPdf').checked, active: value('active').checked }; try { await api(`${documentTemplateUrl()}/${button.dataset.id}`, { method: 'PUT', body: new URLSearchParams({ template: JSON.stringify(template) }) }); state.documentTemplates = await api(documentTemplateUrl()); state.documentTemplateEditing = null; if (ctx.loadTemplateFiles) await ctx.loadTemplateFiles(); render() } catch (error) { showCardError(card, error) } }))
		root.querySelectorAll('.duplicate-document-template').forEach((button) => button.addEventListener('click', async () => { const card=button.closest('[data-document-template]'); button.disabled=true; try { const copy = await api(`${documentTemplateUrl()}/${button.dataset.id}/duplicate`, {method:'POST'}); [state.documentTemplates,state.documentTemplateOptions]=await Promise.all([api(documentTemplateUrl()),api(documentTemplateOptionsUrl())]); state.documentTemplateEditing = copy?.id ?? null; if (ctx.loadTemplateFiles) await ctx.loadTemplateFiles(); render() } catch(error){ button.disabled=false; showCardError(card,error) } }))
		root.querySelectorAll('.preview-document-template').forEach((button) => button.addEventListener('click', () => { const card = button.closest('[data-document-template]'); const value = (name) => card.querySelector(`[name="${name}"]`)?.value || ''; const fileName = value('fileName'); if (!fileName) { notifyError('Bitte zuerst eine DOCX-Datei auswählen.'); return } showDocumentTemplatePreview(fileName, value('name') || fileName) }))
		root.querySelectorAll('.delete-document-template').forEach((button) => button.addEventListener('click', async () => { if (!await confirmAction('Dokumentvorlage wirklich löschen?', { title: 'Dokumentvorlage löschen', confirmLabel: 'Löschen', danger: true })) return; await api(`${apiBase}/configuration/document/${button.dataset.id}`, { method: 'DELETE' }); state.documentTemplates = await api(documentTemplateUrl()); state.documentTemplateEditing = null; if (ctx.loadTemplateFiles) await ctx.loadTemplateFiles(); render() }))
		document.getElementById('new-dereg-template')?.addEventListener('click', () => { state.deregistrationTemplates = [{ id: 0, key: '', recipientType: '', name: '', contactCategory: '', deliveryChannels: ['POST'], formType: 'STANDARD', subjectTemplate: '', bodyTemplate: '', requiredFields: [], allowedStatuses: ['ENTWURF', 'VORBEREITET'], followUpDays: 0, documentTemplateKey: '', active: true }, ...state.deregistrationTemplates.filter((entry) => entry.id)]; render() })
		root.querySelectorAll('.save-dereg-template').forEach((button) => button.addEventListener('click', async () => { const card = button.closest('[data-dereg-template]'); if (!validateConfigurationKey(card, 'Der Vorgangsschlüssel')) return; const value = (name) => card.querySelector(`[name="${name}"]`); const template = { key: value('key').value, recipientType: value('recipientType').value || value('key').value, name: value('name').value, contactCategory: value('contactCategory').value, deliveryChannels: [...card.querySelectorAll('[name="deliveryChannels"]:checked')].map((input) => input.value), formType: value('formType').value, subjectTemplate: value('subjectTemplate').value, bodyTemplate: value('bodyTemplate').value, requiredFields: value('requiredFields').value.split(',').map((entry) => entry.trim()).filter(Boolean), allowedStatuses: value('allowedStatuses').value.split(',').map((entry) => entry.trim()).filter(Boolean), followUpDays: Number(value('followUpDays').value || 0), documentTemplateKey: value('documentTemplateKey').value, active: value('active').checked }; try { await api(`${deregistrationTemplateUrl()}/${button.dataset.id}`, { method: 'PUT', body: new URLSearchParams({ template: JSON.stringify(template) }) }); state.deregistrationTemplates = await api(deregistrationTemplateUrl()); render() } catch (error) { showCardError(card, error) } }))
		root.querySelectorAll('.preview-dereg-template').forEach((button) => button.addEventListener('click', () => { const card = button.closest('[data-dereg-template]'); const value = (name) => card.querySelector(`[name="${name}"]`)?.value || ''; showDeregistrationTemplatePreview({ name: value('name'), subjectTemplate: value('subjectTemplate'), bodyTemplate: value('bodyTemplate'), documentTemplateKey: value('documentTemplateKey') }) }))
		root.querySelectorAll('.delete-dereg-template').forEach((button) => button.addEventListener('click', async () => { if (!await confirmAction('Abmeldevorgang wirklich löschen?', { title: 'Abmeldevorgang löschen', confirmLabel: 'Löschen', danger: true })) return; await api(`${apiBase}/configuration/deregistration/${button.dataset.id}`, { method: 'DELETE' }); state.deregistrationTemplates = await api(deregistrationTemplateUrl()); render() }))
	}

	return { documentTemplatesView, deregistrationTemplatesView, customizing, bindCustomizing, bindConfigurationAdministration, schedulingView, showDocumentTemplatePreview, valueListsView, reloadScheduling }
}
