import { mailButton, printButton } from './icons.js'
import { UNSAVED_CASE_HINT, UNSAVED_CASE_TABS, isUnsavedCase } from './case-unsaved.js'

export function createDocumentsModule(ctx) {
	const { root, apiBase, state } = ctx
	const api = (...args) => ctx.api(...args)
	const caseOverview = (...args) => ctx.caseOverview(...args)
	const sideOrdersPanel = (...args) => ctx.sideOrdersPanel(...args)
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
	const notifyError = (...args) => ctx.notifyError(...args)
	const confirmAction = (...args) => ctx.confirmAction(...args)

	// 0.86.0: Fallbezogene Kontakte. Die Auftraggeberin / der Auftraggeber kommt aus dem Auftrag (keine Kopie), weitere
	// Angehörige lassen sich „nur für diesen Fall“ erfassen. Ins Adressbuch geht eine Person nur auf ausdrücklichen Wunsch,
	// nach einer Dublettenprüfung; die Verknüpfung wird im Fall gespeichert.
	const caseContactRecords = () => (state.records.case_contact || []).filter((item) => item.caseId === state.currentCase.id)
	const orderClientLink = () => caseContactRecords().find((item) => item.data?.source === 'order-client')

	function orderClientPerson() {
		const master = state.currentCase?.masterData || {}
		const postalCity = String(master.order_client_postal_city || '').trim()
		const postal = postalCity.match(/^(\d{4,5})\s+(.+)$/)
		return {
			salutation: master.order_client_salutation || '', firstName: String(master.order_client_first_name || '').trim(), lastName: String(master.order_client_name || '').trim(),
			relation: String(master.order_client_relation || '').trim(), street: String(master.order_client_street || '').trim(), postalCode: postal ? postal[1] : '', city: postal ? postal[2] : postalCity,
			country: master.order_client_country || '', phone: String(master.order_client_phone || '').trim(), mobile: String(master.order_client_mobile || '').trim(), email: String(master.order_client_email || '').trim(),
		}
	}

	function personLines(person) {
		const address = [person.street, [person.postalCode, person.city].filter(Boolean).join(' ')].filter(Boolean).join(', ')
		return [address, [person.mobile && `Mobil ${person.mobile}`, person.phone && `Tel. ${person.phone}`].filter(Boolean).join(' · '), person.email].filter(Boolean)
	}

	function personCard({ name, role, badges, lines, actions }) {
		return `<article class="bp-person-card"><div><b>${esc(name)}</b><small>${esc(role)}</small><div class="bp-person-badges">${badges.map(([label, kind]) => `<span class="bp-status ${kind || ''}">${esc(label)}</span>`).join('')}</div>${lines.length ? `<ul>${lines.map((line) => `<li>${esc(line)}</li>`).join('')}</ul>` : ''}</div><div class="bp-row-actions">${actions}</div></article>`
	}

	function caseContactsPanel() {
		const client = orderClientPerson()
		const link = orderClientLink()
		const clientCard = client.firstName && client.lastName
			? personCard({ name: `${client.firstName} ${client.lastName}`, role: `Auftraggeber/in${client.relation ? ` · ${client.relation}` : ''}`, badges: [['aus dem Auftrag', ''], ...(link ? [['im Adressbuch', 'ready']] : [])], lines: personLines(client),
				actions: `<button type="button" class="bp-secondary" data-case-tab="order">Im Auftrag bearbeiten</button><button type="button" class="bp-secondary" id="order-client-address-book">${link ? 'Adressbuch-Eintrag aktualisieren' : 'Ins Adressbuch übernehmen'}</button>` })
			: `<article class="bp-person-card bp-person-card-empty"><div><b>Auftraggeber/in</b><small>Noch nicht im Auftrag erfasst. Sobald Vor- und Nachname im Auftrag stehen, erscheint die Person hier.</small></div><div class="bp-row-actions"><button type="button" class="bp-secondary" data-case-tab="order">Auftrag öffnen</button></div></article>`
		const others = caseContactRecords().filter((item) => item.data?.source !== 'order-client').map((item) => {
			if (item.data?.source === 'case-person') {
				const person = item.data.person || {}
				return personCard({ name: item.title, role: item.data.role || 'Angehörige/r', badges: [['nur in diesem Fall', ''], ...(item.data.addressBook?.contactId ? [['im Adressbuch', 'ready']] : [])], lines: [...personLines(person), person.note].filter(Boolean),
					actions: `<button type="button" class="bp-secondary edit-case-contact" data-id="${item.id}">Bearbeiten</button><button type="button" class="bp-secondary case-person-address-book" data-id="${item.id}">${item.data.addressBook?.contactId ? 'Adressbuch-Eintrag aktualisieren' : 'Ins Adressbuch übernehmen'}</button><button type="button" class="bp-secondary remove-case-contact" data-id="${item.id}">Entfernen</button>` })
			}
			return personCard({ name: item.title, role: item.data?.role || 'Fallkontakt', badges: [['Adressbuch', '']], lines: [item.data?.contactSnapshot?.addressText || String(item.data?.contactSnapshot?.address || '').split(';').filter(Boolean).join(', '), item.data?.contactSnapshot?.phone, item.data?.contactSnapshot?.email].filter(Boolean),
				actions: `<button type="button" class="bp-secondary edit-case-contact" data-id="${item.id}">Bearbeiten</button><button type="button" class="bp-secondary remove-case-contact" data-id="${item.id}">Entfernen</button>` })
		}).join('')
		return `<div class="bp-page-head"><div><p class="bp-eyebrow">Kontakte</p><h2>Fallbezogene Kontakte</h2><p class="bp-muted">Die Auftraggeberin bzw. der Auftraggeber kommt aus dem Auftrag. Behörden, Friedhöfe und Dienstleister ordnen Sie aus dem Adressbuch zu; weitere Angehörige können Sie nur für diesen Fall erfassen.</p></div><button class="bp-primary" id="assign-case-contact">Kontakt zuordnen</button></div>
			<section class="bp-panel bp-person-list"><h3>Auftraggeber/in</h3>${clientCard}</section>
			<section class="bp-panel bp-person-list"><h3>Weitere Kontakte</h3>${others || '<p class="bp-empty">Noch keine weiteren Kontakte zugeordnet.</p>'}</section>`
	}

	const sameName = (a, b) => normalizeName(a) !== '' && normalizeName(a) === normalizeName(b)
	function normalizeName(value) {
		return String(value || '').toLowerCase().replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss').replace(/[^\p{L}\p{N}]+/gu, ' ').split(' ').filter((token) => token && !['herr', 'frau', 'dr', 'prof'].includes(token)).sort().join(' ')
	}

	function showCaseContactForm(item = null) {
		const isPerson = item ? item.data?.source === 'case-person' : false
		const person = item?.data?.person || {}
		const currentId = item?.data?.contactId || ''
		const options = state.records.contact.filter((contact) => contact.data?.source !== 'nextcloud-group').map((contact) => `<option value="${esc(contact.id)}" ${String(contact.id) === String(currentId) ? 'selected' : ''}>${esc(contact.title)} · ${esc(contact.data?.categories || '')}</option>`).join('')
		const input = (name, label, value = '', attrs = '') => `<label><span>${esc(label)}</span><input name="${name}" value="${esc(value)}" ${attrs}></label>`
		const modeSwitch = item ? '' : `<fieldset class="bp-contact-mode"><legend>Art des Kontakts</legend><label class="bp-inline-check"><input type="radio" name="mode" value="book" checked> <span>Kontakt aus dem Adressbuch</span></label><label class="bp-inline-check"><input type="radio" name="mode" value="person"> <span>Person nur für diesen Fall erfassen</span></label></fieldset>`
		root.insertAdjacentHTML('beforeend', `<div class="bp-modal"><form id="case-contact-form" class="bp-case-contact-form"><h2>Fallkontakt ${item ? 'bearbeiten' : 'zuordnen'}</h2>${modeSwitch}
			<div data-mode-panel="book" ${isPerson ? 'hidden' : ''}><label>Nextcloud-Kontakt<select name="contactId"><option value="">Kontakt wählen</option>${options}</select></label></div>
			<div data-mode-panel="person" ${isPerson ? '' : 'hidden'}><p class="bp-muted">Für Angehörige, die nur zu diesem Fall gehören. Die Angaben werden mit dem Fall gelöscht bzw. anonymisiert und erscheinen nicht im Adressbuch, solange Sie sie nicht ausdrücklich übernehmen.</p><div class="bp-form-grid">${input('salutation', 'Anrede', person.salutation)}${input('firstName', 'Vorname', person.firstName)}${input('lastName', 'Nachname', person.lastName)}${input('mobile', 'Mobilfunknummer', person.mobile, 'type="tel"')}${input('phone', 'Telefon', person.phone, 'type="tel"')}${input('email', 'E-Mail', person.email, 'type="email"')}${input('street', 'Straße / Hausnummer', person.street)}${input('postalCode', 'PLZ', person.postalCode)}${input('city', 'Ort', person.city)}</div><label><span>Notiz</span><textarea name="note" rows="2">${esc(person.note || '')}</textarea></label></div>
			<label>Rolle im Fall<input name="role" value="${esc(item?.data?.role || '')}" placeholder="z. B. Tochter, Standesamt, Friedhof" required></label><p class="bp-error" id="case-contact-error" hidden></p><div class="bp-modal-actions"><button type="button" class="bp-secondary" data-close-modal>Abbrechen</button><button class="bp-primary">Zuordnung speichern</button></div></form></div>`)
		const modal = [...root.querySelectorAll('.bp-modal')].pop(); modal.querySelector('[data-close-modal]').onclick = () => modal.remove()
		const form = modal.querySelector('form')
		const mode = () => item ? (isPerson ? 'person' : 'book') : (form.querySelector('[name="mode"]:checked')?.value || 'book')
		const sync = () => { form.querySelectorAll('[data-mode-panel]').forEach((panel) => { panel.hidden = panel.dataset.modePanel !== mode() }); form.querySelector('[name="contactId"]').required = mode() === 'book'; ['firstName', 'lastName'].forEach((name) => { form.querySelector(`[name="${name}"]`).required = mode() === 'person' }) }
		form.querySelectorAll('[name="mode"]').forEach((radio) => radio.addEventListener('change', sync)); sync()
		const showError = (message) => { const error = modal.querySelector('#case-contact-error'); error.textContent = message; error.hidden = false }
		form.onsubmit = async (event) => {
			event.preventDefault(); const data = Object.fromEntries(new FormData(form))
			const client = orderClientPerson()
			if (mode() === 'person') {
				const name = `${data.firstName.trim()} ${data.lastName.trim()}`
				if (sameName(name, `${client.firstName} ${client.lastName}`) && !await confirmAction(`${name} ist bereits als Auftraggeber/in im Auftrag erfasst. Trotzdem zusätzlich als Fallkontakt anlegen?`, { title: 'Person bereits erfasst', confirmLabel: 'Trotzdem anlegen' })) return
				const twin = caseContactRecords().find((entry) => entry.id !== item?.id && entry.data?.source === 'case-person' && sameName(entry.title, name))
				if (twin) { showError(`${name} ist in diesem Fall bereits als ${twin.data?.role || 'Kontakt'} erfasst.`); return }
				const personData = Object.fromEntries(['salutation', 'firstName', 'lastName', 'mobile', 'phone', 'email', 'street', 'postalCode', 'city', 'note'].map((key) => [key, String(data[key] || '').trim()]))
				const payload = { ...(item?.data || {}), source: 'case-person', role: data.role, person: personData }
				await api(item ? recordItemUrl(item.id) : recordUrl('case_contact'), { method: item ? 'PUT' : 'POST', body: new URLSearchParams({ title: name, date: item?.date || new Date().toISOString(), status: 'AKTIV', caseId: state.currentCase.id, data: JSON.stringify(payload) }) })
			} else {
				const contact = state.records.contact.find((entry) => String(entry.id) === String(data.contactId)); if (!contact) return
				const already = caseContactRecords().find((entry) => entry.id !== item?.id && String(entry.data?.contactId || entry.data?.addressBook?.contactId || '') === String(contact.id))
				if (already) { showError(`${contact.title} ist diesem Fall bereits zugeordnet (${already.data?.source === 'order-client' ? 'als Auftraggeber/in' : already.data?.role || 'Fallkontakt'}).`); return }
				const payload = { contactId: contact.id, addressBookKey: contact.data?.addressBookKey || '', role: data.role, source: 'case-assignment', contactSnapshot: { title: contact.title, email: contact.data?.email || '', phone: contact.data?.phone || '', address: contact.data?.address || '', addressText: contact.data?.addressText || '', country: contact.data?.country || '' } }
				await api(item ? recordItemUrl(item.id) : recordUrl('case_contact'), { method: item ? 'PUT' : 'POST', body: new URLSearchParams({ title: contact.title, date: new Date().toISOString(), status: 'AKTIV', caseId: state.currentCase.id, data: JSON.stringify(payload) }) })
			}
			await loadRecordType('case_contact', state.currentCase.id); modal.remove(); render()
		}
	}

	/**
	 * Mögliche Dubletten anzeigen. Ergebnis: {useId} (vorhandenen Kontakt verwenden), {create: true} oder null (abgebrochen).
	 * Wird auch beim Anlegen eines Kontakts über „Neu“ verwendet.
	 */
	function chooseDuplicate(duplicates, newLabel) {
		return new Promise((resolve) => {
			root.insertAdjacentHTML('beforeend', `<div class="bp-modal"><section class="bp-duplicate-dialog"><h2>Mögliche Dublette</h2><p>Im Adressbuch gibt es bereits ähnliche Kontakte. Bitte prüfen Sie, ob ${esc(newLabel)} schon vorhanden ist. Zusammengeführt wird nichts automatisch.</p><ul>${duplicates.map((item) => `<li><div><b>${esc(item.title)}</b><small>${esc(item.reasonText || '')}${item.addressBook ? ` · ${esc(item.addressBook)}` : ''}</small><small>${esc([item.contact?.data?.addressText, item.contact?.data?.phone, item.contact?.data?.email].filter(Boolean).join(' · '))}</small></div><button type="button" class="bp-secondary" data-use-duplicate="${esc(item.id)}">Vorhandenen Kontakt verwenden</button></li>`).join('')}</ul><div class="bp-modal-actions"><button type="button" class="bp-secondary" data-duplicate-cancel>Abbrechen</button><button type="button" class="bp-primary" data-duplicate-create>Trotzdem neu anlegen</button></div></section></div>`)
			const modal = [...root.querySelectorAll('.bp-modal')].pop()
			const done = (value) => { modal.remove(); resolve(value) }
			modal.querySelector('[data-duplicate-cancel]').onclick = () => done(null)
			modal.querySelector('[data-duplicate-create]').onclick = () => done({ create: true })
			modal.querySelectorAll('[data-use-duplicate]').forEach((button) => { button.onclick = () => done({ useId: button.dataset.useDuplicate, duplicate: duplicates.find((item) => String(item.id) === button.dataset.useDuplicate) }) })
		})
	}

	/** Person (Auftraggeber/in oder Fallperson) ins Adressbuch übernehmen bzw. den Eintrag aktualisieren. */
	async function savePersonToAddressBook(person, role, existingLink) {
		const body = (force, uri = '') => new URLSearchParams({ person: JSON.stringify({ ...person, role }), uri, force: force ? '1' : '' })
		const url = `${apiBase}/cases/${state.currentCase.id}/address-book-person`
		if (existingLink?.uri) {
			if (!await confirmAction(`Den Adressbuch-Eintrag „${existingLink.title || ''}“ mit den aktuellen Angaben überschreiben?`, { title: 'Adressbuch-Eintrag aktualisieren', confirmLabel: 'Aktualisieren' })) return null
			const result = await api(url, { method: 'POST', loadingLabel: 'Adressbuch-Eintrag wird aktualisiert …', body: body(true, existingLink.uri) })
			return result.contact
		}
		let result = await api(url, { method: 'POST', feedback: false, loadingLabel: 'Adressbuch wird auf Dubletten geprüft …', body: body(false) })
		if (result.status === 'DUPLICATES') {
			const choice = await chooseDuplicate(result.duplicates, `${person.firstName} ${person.lastName}`)
			if (!choice) return null
			if (choice.useId) return choice.duplicate.contact
			result = await api(url, { method: 'POST', loadingLabel: 'Kontakt wird im Adressbuch angelegt …', body: body(true) })
		}
		return result.contact
	}

	const linkOf = (contact) => ({ contactId: contact.id, addressBookKey: contact.data?.addressBookKey || '', uri: contact.data?.uri || '', title: contact.title })

	async function orderClientToAddressBook() {
		const client = orderClientPerson(); const link = orderClientLink()
		const role = `Auftraggeber/in${client.relation ? ` · ${client.relation}` : ''}`
		try {
			const contact = await savePersonToAddressBook(client, role, link ? { uri: link.data?.uri, title: link.title } : null); if (!contact) return
			const payload = { ...(link?.data || {}), source: 'order-client', role, ...linkOf(contact), contactSnapshot: { title: contact.title, email: contact.data?.email || '', phone: contact.data?.phone || '', address: contact.data?.address || '', addressText: contact.data?.addressText || '' } }
			await api(link ? recordItemUrl(link.id) : recordUrl('case_contact'), { method: link ? 'PUT' : 'POST', body: new URLSearchParams({ title: contact.title, date: link?.date || new Date().toISOString(), status: 'AKTIV', caseId: state.currentCase.id, data: JSON.stringify(payload) }) })
			await Promise.all([loadRecordType('case_contact', state.currentCase.id), loadRecordType('contact')]); notifySuccess(link ? 'Adressbuch-Eintrag aktualisiert.' : `${contact.title} ist mit dem Adressbuch verknüpft.`); render()
		} catch (error) { notifyError(error.message) }
	}

	async function casePersonToAddressBook(item) {
		try {
			const contact = await savePersonToAddressBook(item.data?.person || {}, item.data?.role || '', item.data?.addressBook?.uri ? item.data.addressBook : null); if (!contact) return
			await api(recordItemUrl(item.id), { method: 'PUT', body: new URLSearchParams({ title: item.title, date: item.date || new Date().toISOString(), status: item.status || 'AKTIV', caseId: state.currentCase.id, data: JSON.stringify({ ...item.data, addressBook: linkOf(contact) }) }) })
			await Promise.all([loadRecordType('case_contact', state.currentCase.id), loadRecordType('contact')]); notifySuccess(`${item.title} ist mit dem Adressbuch verknüpft.`); render()
		} catch (error) { notifyError(error.message) }
	}

	function bindCaseContacts() {
		document.getElementById('assign-case-contact')?.addEventListener('click', () => showCaseContactForm())
		root.querySelectorAll('.edit-case-contact').forEach((button) => button.addEventListener('click', () => showCaseContactForm(caseContactRecords().find((entry) => entry.id === Number(button.dataset.id)))))
		document.getElementById('order-client-address-book')?.addEventListener('click', orderClientToAddressBook)
		root.querySelectorAll('.case-person-address-book').forEach((button) => button.addEventListener('click', () => { const item = caseContactRecords().find((entry) => entry.id === Number(button.dataset.id)); if (item) casePersonToAddressBook(item) }))
		root.querySelectorAll('.remove-case-contact').forEach((button) => button.addEventListener('click', async () => {
			const item = caseContactRecords().find((entry) => entry.id === Number(button.dataset.id)); if (!item) return
			const inBook = item.data?.source === 'case-person' && item.data?.addressBook?.contactId
			if (!await confirmAction(`${item.title} aus diesem Fall entfernen?${inBook ? ' Der Eintrag im Adressbuch bleibt bestehen.' : ''}`, { title: 'Fallkontakt entfernen', confirmLabel: 'Entfernen' })) return
			try { await api(recordItemUrl(item.id), { method: 'DELETE' }); await loadRecordType('case_contact', state.currentCase.id); render() } catch (error) { notifyError(error.message) }
		}))
	}

	function expandDeregistration(value, data) {
		const master = state.currentCase.masterData || {}
		// Befund (Abmeldungen-Test 2026-09-22): Beitragsnummer/Vertragsreferenz/Versicherungsnummer
		// sind je Abmeldung erfasste Referenznummern (siehe referenceField in
		// deregistration-templates.json, Formularfeld "referenceNumber"), keine Fall-Stammdaten wie
		// die Postrentennummer - deshalb hier alle drei aus demselben Formularfeld gespeist.
		return String(value || '').replace(/{{case.number}}/g, state.currentCase.caseNumber || '').replace(/{{case.first_name}}/g, state.currentCase.firstName || '').replace(/{{case.last_name}}/g, state.currentCase.lastName || '').replace(/{{case.date_of_birth}}/g, master.date_of_birth || '').replace(/{{case.date_of_death}}/g, state.currentCase.dateOfDeath || master.date_of_death || '').replace(/{{case.pension_number}}/g, data.pensionNumber || master.pension_insurance_number || '').replace(/{{case.broadcast_contribution_number}}/g, data.referenceNumber || '').replace(/{{case.contract_reference}}/g, data.referenceNumber || '').replace(/{{case.insurance_number}}/g, data.referenceNumber || '').replace(/{{advance.application}}/g, data.advanceApplication || 'NEIN')
	}

	// Befund (Review 2026-09-22, Kundenmeldung zum Prozess "Abmeldungen"): Muss deckungsgleich mit
	// DeregistrationService::ATTACHMENT_RELEVANT_DOCUMENT_TYPES (PHP) bleiben. Der Server filtert die
	// Anlagenvorschläge in der /preview-Antwort bereits, aber diese Anlagenauswahl im Formular liest
	// state.caseFiles.files direkt und unabhängig davon - ohne diesen Filter würden Rechnungen,
	// Auftragsunterlagen usw. hier trotzdem wieder zur Auswahl angeboten.
	const DEREGISTRATION_ATTACHMENT_DOCUMENT_TYPES = ['Urkunde', 'Formular', 'Nachweis', 'Korrespondenz', 'Behörde', 'Abmeldung', 'Sonstiges']

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

	// Befund (Review 2026-09-22, Kundenmeldung zum Prozess "Abmeldungen"): "Der Mailversand oder die
	// auch die Druckausgabe wird noch nicht angeboten." Für den Übertragungsweg POST/BRIEF ist die
	// Briefvorschau bereits die vollständige, layoutfertige Ausgabe (Absender, Empfänger, Datum,
	// Betreff, Text); es fehlte lediglich eine Möglichkeit, sie tatsächlich auszudrucken. Die Klasse
	// bp-letter-preview-modal schaltet beim Drucken (@media print, css/style.css) die
	// Modal-Bedienelemente sowie den Fixed-Overlay-Hintergrund ab, damit ausschließlich der eigentliche
	// Briefinhalt seitenfüllend gedruckt wird.
	function showLetterPreview(preview) {
		root.insertAdjacentHTML('beforeend', `<div class="bp-modal bp-letter-preview-modal"><section class="bp-wide-modal"><div class="bp-panel-head"><div><p class="bp-eyebrow">Briefvorschau ohne Versand · ${esc(preview.letter.templateName || preview.documentTemplateKey || 'Briefvorlage')}</p><h2>${esc(preview.template.name)}</h2></div><div class="bp-row-actions"><button type="button" class="bp-secondary" data-print-letter>Drucken</button><button class="bp-secondary" data-close-preview>Schließen</button></div></div>${preview.missingFields.length?`<div class="bp-error">Fehlende Pflichtangaben: ${preview.missingFields.map(esc).join(', ')}</div>`:''}<article class="bp-letter-preview"><small class="bp-letter-sender">${esc(preview.letter.sender)}</small><pre class="bp-letter-recipient">${esc(preview.letter.recipient)}</pre><p class="bp-letter-date">${esc(preview.letter.date)}</p><h3>${esc(preview.subject)}</h3><pre>${esc(preview.body)}</pre><div class="bp-letter-attachments"><b>Anlagen</b>${(preview.attachments||[]).map((item)=>`<span>${esc(item.title)}</span>`).join('')||'<span>Keine Anlagen ausgewählt</span>'}</div></article>${preview.attachmentWarning?`<p class="bp-warning">${esc(preview.attachmentWarning)}</p>`:''}</section></div>`); const modal=[...root.querySelectorAll('.bp-modal')].pop();modal.querySelector('[data-close-preview]').onclick=()=>modal.remove();modal.querySelector('[data-print-letter]').onclick=()=>window.print()
	}

	// Befund (Review 2026-09-22, Kundenmeldung zum Prozess "Abmeldungen"): "Der Mailversand ... wird
	// noch nicht angeboten." Für Abmeldungen mit Übertragungsweg E-Mail wird jetzt ein echter Versand
	// (DeregistrationMailService) angeboten, sobald die Abmeldung VORBEREITET ist; die bisherige
	// manuelle Nachweiserfassung bleibt als bewusst gewählte Zusatzoption erreichbar (z. B. falls der
	// Mailserver ausfällt oder abweichend vom hinterlegten Kontakt tatsächlich postalisch versendet
	// wurde). Für alle anderen Übertragungswege (Post, Portal, Telefon) bleibt der Versand weiterhin
	// bewusst manuell dokumentiert - dafür bietet die Briefvorschau (showLetterPreview) jetzt eine
	// Druckfunktion an.
	function deregistrationPanel() {
		const records = (state.records.deregistration || []).filter((item) => item.caseId === state.currentCase.id)
		const next = { ENTWURF: ['VORBEREITET', 'Vorbereiten'], VORBEREITET: ['VERSENDET', 'Versand erfassen'], VERSENDET: ['BESTAETIGT', 'Bestätigen'], BESTAETIGT: ['ERLEDIGT', 'Erledigen'] }
		const mailReady = state.businessMailAvailability?.enabled === true
		const mailHistory = Number(state.deregistrationMailCaseId) === Number(state.currentCase.id) ? (state.deregistrationMailHistory || []) : []
		const rows = records.map((item) => {
			const action = next[item.status]
			const editable = ['ENTWURF', 'VORBEREITET'].includes(item.status)
			const emailSendable = item.status === 'VORBEREITET' && String(item.data?.deliveryChannel || '').toUpperCase() === 'EMAIL'
			const lastAttempt = mailHistory.find((entry) => Number(entry.recordId) === Number(item.id))
			const mailHint = lastAttempt ? (lastAttempt.status === 'UNCERTAIN' ? 'Versandstatus unklar – bitte Postausgang prüfen, bevor erneut gesendet wird' : `Zuletzt per E-Mail versendet am ${esc(lastAttempt.createdAt)}`) : ''
			const mailUnavailableHint = emailSendable && !mailReady ? esc(state.businessMailAvailability?.reason || 'Nextcloud-Mailversand ist nicht konfiguriert.') : ''
			const primaryAction = emailSendable
				? `<button class="bp-primary send-deregistration-mail" data-id="${item.id}" ${mailReady ? '' : 'disabled'}>Per E-Mail versenden</button>`
				: (action ? `<button class="bp-primary transition-deregistration" data-id="${item.id}" data-status="${action[0]}">${action[1]}</button>` : '')
			const secondaryManual = emailSendable ? `<button class="bp-secondary transition-deregistration" data-id="${item.id}" data-status="VERSENDET">Manuell erfassen</button>` : ''
			return `<article class="bp-record-row bp-deregistration-row"><span class="bp-record-check">↗</span><div class="bp-record-content"><b>${esc(item.title)}</b><small>${esc(item.data?.recipientName || '')} · ${esc(item.data?.deliveryChannel || 'kein Übertragungsweg')} · <span class="bp-status">${esc(item.status)}</span></small><small>${(item.data?.attachments||[]).length} Anlage(n)${item.data?.attachmentWarning?` · ${esc(item.data.attachmentWarning)}`:''}${mailHint?` · ${mailHint}`:''}${mailUnavailableHint?` · ${mailUnavailableHint}`:''}</small></div><div class="bp-row-actions bp-deregistration-actions">${editable?`<button class="bp-secondary edit-deregistration" data-id="${item.id}">Bearbeiten</button>`:''}<button class="bp-secondary preview-deregistration" data-id="${item.id}">Vorschau</button>${secondaryManual}${primaryAction}</div></article>`
		}).join('')
		return `<div class="bp-page-head"><div><p class="bp-eyebrow">Abmeldungen</p><h2>Abmeldungen und Mitteilungen</h2><p class="bp-muted">Kontaktwege, Pflichtfelder und Statuswechsel werden serverseitig geprüft. Bei Übertragungsweg E-Mail kann direkt aus der Anwendung versendet werden; für Post, Portal und Telefon bleibt der Versand bewusst manuell und wird hier nur nachträglich dokumentiert - die Briefvorschau bietet dafür eine Druckfunktion.</p></div><button class="bp-primary" id="new-deregistration">Abmeldung vorbereiten</button></div><section class="bp-panel bp-record-panel"><div class="bp-record-list">${rows || '<p class="bp-empty">Noch keine Abmeldung vorbereitet.</p>'}</div></section>`
	}

	function showDeregistrationForm(item = null) {
		const data = item?.data || {}; const templates = state.deregistrationTemplates.filter((entry) => entry.active)
		const allFiles=state.caseFiles.files||[]; const selectedIds=new Set((data.attachments||[]).map((entry)=>Number(entry.fileId||entry.id)))
		const documents=allFiles.filter((entry)=>DEREGISTRATION_ATTACHMENT_DOCUMENT_TYPES.includes(String(entry.documentType||''))||selectedIds.has(Number(entry.fileId)))
		if(!item){const death=documents.find((entry)=>/sterbeurk/i.test(`${entry.title} ${entry.documentType||''} ${entry.path||''}`));if(death)selectedIds.add(death.fileId)}
		const attachmentRow=(entry,checked=false)=>`<label class="bp-inline-check"><input type="checkbox" name="attachmentIds" value="${entry.fileId}" ${checked?'checked':''}> <span>${esc(entry.title)} <small>${esc(entry.documentType||'Sonstiges')} · ${esc(entry.status)} · ${esc(entry.subfolder||'Fallakte')}</small></span></label>`
		const attachments=documents.map((entry)=>attachmentRow(entry,selectedIds.has(entry.fileId))).join('')
		root.insertAdjacentHTML('beforeend', `<div class="bp-modal"><form id="deregistration-form" class="bp-wide-modal"><h2>Abmeldung ${item ? 'bearbeiten' : 'vorbereiten'}</h2><div class="bp-form-grid"><label>Vorgang<select name="templateKey" required>${templates.map((entry) => `<option value="${esc(entry.key)}" ${entry.key === data.templateKey ? 'selected' : ''}>${esc(entry.name)}</option>`).join('')}</select></label><label>Empfänger<select name="contactId"></select></label><label>Übertragungsweg<select name="deliveryChannel"></select><small id="channel-hint"></small></label><label>Status<select name="status">${['ENTWURF', 'VORBEREITET'].map((status) => `<option ${status === (item?.status || 'ENTWURF') ? 'selected' : ''}>${status}</option>`).join('')}</select></label></div><p class="bp-muted">Ein Entwurf kann unvollständig gespeichert werden und bleibt bis zum Versand bearbeitbar.</p><section id="pension-fields" class="bp-field-section bp-hidden"><h3>Rentenservice und Vorschussprüfung</h3><div class="bp-form-grid"><label>Postrentennummer<input name="pensionNumber" value="${esc(data.pensionNumber || state.currentCase.masterData?.pension_insurance_number || '')}"></label><label>Weitere Postrentennummer<input name="pensionNumber2" value="${esc(data.pensionNumber2 || state.currentCase.masterData?.pension_insurance_number_2 || '')}"></label><label>Vorschuss für Witwe/Witwer beantragen?<select name="advanceApplication"><option value="NEIN">Nein</option><option value="JA" ${data.advanceApplication === 'JA' ? 'selected' : ''}>Ja</option></select></label><div></div><label>Antragsteller/in<input name="advanceApplicantName" value="${esc(data.advanceApplicantName || '')}"></label><label>Geburtsdatum<input type="date" name="advanceApplicantBirthDate" value="${esc(data.advanceApplicantBirthDate || '')}"></label><label>Anschrift<input name="advanceApplicantAddress" value="${esc(data.advanceApplicantAddress || '')}"></label><label>Eigene Renten-/Versicherungsnummer<input name="advanceApplicantPensionNumber" value="${esc(data.advanceApplicantPensionNumber || '')}"></label></div></section><section id="reference-field-section" class="bp-field-section bp-hidden"><div class="bp-form-grid"><label><span id="reference-field-label-text">Referenznummer</span><input name="referenceNumber" value="${esc(data.referenceNumber || '')}"></label></div></section><section class="bp-field-section"><div class="bp-panel-head"><div><h3>Anlagen aus der Fallakte</h3><p class="bp-muted">Eine vorhandene Sterbeurkunde wird standardmäßig ausgewählt. Direkt in Nextcloud abgelegte Dateien stehen ebenfalls zur Auswahl.</p></div><div><input id="deregistration-local-files" type="file" multiple hidden><button type="button" class="bp-secondary" id="add-local-attachments">Dateien vom Computer hinzufügen</button></div></div><div class="bp-attachment-picker">${attachments||'<p class="bp-warning bp-no-attachments">Im Fall ist noch keine Sterbeurkunde oder andere Anlage verfügbar.</p>'}</div><p class="bp-save-state" id="attachment-upload-state"></p><p class="bp-warning" id="attachment-warning"></p></section><label>Betreff<input name="subject" value="${esc(data.subject || '')}"></label><label>Text<textarea name="body" rows="10">${esc(data.body || '')}</textarea></label><p class="bp-save-state" id="deregistration-state"></p><div class="bp-modal-actions"><button type="button" class="bp-secondary" data-close-modal>Abbrechen</button><button type="button" class="bp-secondary" id="preview-deregistration-form">Vorschau</button><button class="bp-primary">Entwurf speichern</button></div></form></div>`)
		const modal = [...root.querySelectorAll('.bp-modal')].pop(); const form = modal.querySelector('form'); const templateSelect = form.elements.templateKey; const contactSelect = form.elements.contactId; const channelSelect = form.elements.deliveryChannel
		const refresh = (preserveText = false) => { const selectedContactId = contactSelect.value || String(data.contactId || ''); const selectedChannel = channelSelect.value || data.deliveryChannel || ''; const template = templates.find((entry) => entry.key === templateSelect.value) || templates[0]; const contacts = contactsByCategory(template?.contactCategory || ''); contactSelect.innerHTML = '<option value="">Empfänger wählen</option>' + contacts.map((contact) => `<option value="${esc(contact.id)}">${esc(contact.title)}</option>`).join(''); contactSelect.value = contacts.some((entry) => String(entry.id) === selectedContactId) ? selectedContactId : ''; const contact = contacts.find((entry) => String(entry.id) === String(contactSelect.value)); const channels = deliveryChannels(template, contact); const remembered = localStorage.getItem(`bestatter:dereg-channel:${template?.key}`) || template?.defaultChannel || ''; channelSelect.innerHTML = '<option value="">Übertragungsweg wählen</option>' + channels.map((channel) => `<option value="${channel}">${channel}</option>`).join(''); channelSelect.value = channels.includes(selectedChannel) ? selectedChannel : (channels.includes(remembered) ? remembered : ''); form.querySelector('#channel-hint').textContent = channels.length ? '' : 'Am Kontakt ist kein zulässiger Übertragungsweg gepflegt.'; form.querySelector('#pension-fields').classList.toggle('bp-hidden', template?.formType !== 'PENSION_SERVICE');
			// Befund (Abmeldungen-Test 2026-09-22, Kundenwunsch "Eingabefeld einbauen"): generisches,
			// je Abmeldung erfasstes Referenzfeld (Beitragsnummer/Vertragsreferenz/Versicherungsnummer je
			// nach Vorgang, siehe referenceField in deregistration-templates.json). Nur Sichtbarkeit und
			// Beschriftung werden hier umgeschaltet, der Eingabewert selbst bleibt beim Kontaktwechsel
			// (refresh(true)) unangetastet - er wird nur beim Vorgangswechsel wie Betreff/Text neu
			// vorbelegt (siehe unten).
			const referenceField = template?.referenceField || null;
			form.querySelector('#reference-field-section').classList.toggle('bp-hidden', !referenceField);
			if (referenceField) form.querySelector('#reference-field-label-text').textContent = referenceField.label;
			if (!preserveText) { form.elements.subject.value = expandDeregistration(template?.subjectTemplate, data); form.elements.body.value = expandDeregistration(template?.bodyTemplate, data); form.elements.referenceNumber.value = data.referenceNumber || '' } }
		const missing = () => { const template = templates.find((entry) => entry.key === templateSelect.value); const values = Object.fromEntries(new FormData(form)); const result = []; if (!values.contactId) result.push('Empfänger'); if (!values.deliveryChannel) result.push('Übertragungsweg'); if (template?.formType === 'PENSION_SERVICE') { if (!values.pensionNumber) result.push('Postrentennummer'); if (values.advanceApplication === 'JA') { if (!values.advanceApplicantName) result.push('Name Antragsteller/in'); if (!values.advanceApplicantBirthDate) result.push('Geburtsdatum Antragsteller/in'); if (!values.advanceApplicantAddress) result.push('Anschrift Antragsteller/in') } } if (template?.referenceField?.required && !values.referenceNumber) result.push(template.referenceField.label); return result }
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

	// Befund (Review 2026-09-22, Kundenmeldung zum Prozess "Abmeldungen"): "Der Mailversand ... wird
	// noch nicht angeboten." Analog zu showBusinessMailDialog() (Geschäfts-Postfach), aber für mehrere,
	// im Formular frei ausgewählte Anlagen statt genau einer finalen PDF: Versandkennung (requestKey)
	// als Idempotenzschutz gegen Doppelklick, Anlagen-Fingerabdruck gegen Inhaltsänderung seit der
	// Vorschau, ausdrückliche Bestätigung durch die Anwenderin vor dem Versand. Bei angenommenem
	// Versand setzt der Server die Abmeldung automatisch auf "Versendet" (DeregistrationMailService).
	async function showDeregistrationMailDialog(recordId) {
		const caseId = Number(state.currentCase?.id || 0)
		if (!caseId || !state.businessMailAvailability?.enabled) return
		let preview
		try { preview = await api(`${apiBase}/cases/${caseId}/deregistrations/${Number(recordId)}/mail/preview`) }
		catch (error) { notifyWarning(error.message); return }
		const requestKey = globalThis.crypto?.randomUUID?.()
		if (!requestKey) { notifyWarning('Dieser Browser kann keine sichere Versandkennung erzeugen.'); return }
		const prior = (state.deregistrationMailHistory || []).filter((item) => Number(item.recordId) === Number(recordId))
		const attachmentRows = (preview.attachments || []).map((item) => `<li>${esc(item.title)} <small>${esc(item.fileName)} · ${Math.ceil(Number(item.size) / 1024)} KB</small></li>`).join('')
		root.insertAdjacentHTML('beforeend', `<div class="bp-modal"><form class="bp-wide-modal" id="deregistration-mail-form"><div class="bp-panel-head"><div><p class="bp-eyebrow">E-Mail-Versand · Abmeldung</p><h2>${esc(preview.title)}</h2></div><button type="button" class="bp-secondary" data-close-modal>Schließen</button></div><p class="bp-muted">Fall ${esc(preview.caseNumber)} · Absender ${esc(preview.sender)}. Es werden ${preview.attachments.length} Anlage(n) mitgesendet:</p><ul class="bp-letter-attachments">${attachmentRows || '<li>Keine Anlagen ausgewählt</li>'}</ul>${preview.replyTo ? `<p class="bp-muted">Antworten an: ${esc(preview.replyTo)}</p>` : ''}${!preview.enabled ? `<p class="bp-warning">${esc(preview.reason || 'Versand aktuell nicht möglich.')}</p>` : ''}${prior.length ? '<p class="bp-warning">Für diese Abmeldung gibt es bereits Versandversuche. Prüfen Sie den Verlauf und das Geschäfts-Postfach vor einem erneuten Versand.</p>' : ''}<label>Empfängeradresse<input type="email" name="recipient" autocomplete="off" required value="${esc(preview.recipient)}"></label><label>Betreff<input name="subject" required maxlength="250" value="${esc(preview.subject)}"></label><label>Nachricht<textarea name="body" rows="7" required maxlength="10000">${esc(preview.body)}</textarea></label><label>Begründung für erneuten Versand (falls diese Abmeldung an dieselbe Adresse schon einmal gesendet wurde)<input name="repeatReason" maxlength="500"></label><label class="bp-inline-check"><input type="checkbox" name="confirmed" required> Empfänger, Text und Anlagen habe ich geprüft.</label><p class="bp-muted">„Vom Mailserver angenommen“ ist kein Zustellnachweis. Nach dem Versand wird die Abmeldung automatisch auf „Versendet“ gesetzt.</p><p class="bp-save-state" aria-live="polite"></p><div class="bp-modal-actions"><button type="button" class="bp-secondary" data-close-modal>Abbrechen</button><button class="bp-primary" ${preview.enabled ? '' : 'disabled'}>Jetzt senden</button></div></form></div>`)
		const modal = [...root.querySelectorAll('.bp-modal')].pop(); const form = modal.querySelector('form')
		modal.querySelectorAll('[data-close-modal]').forEach((button) => button.onclick = () => modal.remove())
		form.onsubmit = async (event) => {
			event.preventDefault(); const status = form.querySelector('.bp-save-state'); const button = form.querySelector('button.bp-primary'); button.disabled = true
			const values = Object.fromEntries(new FormData(form)); values.requestKey = requestKey; values.attachmentsFingerprint = preview.attachmentsFingerprint
			try {
				const result = await api(`${apiBase}/cases/${caseId}/deregistrations/${Number(recordId)}/mail/send`, { method: 'POST', body: new URLSearchParams({ mail: JSON.stringify(values) }) })
				state.records.deregistration = await api(deregistrationUrl(caseId))
				try { state.deregistrationMailHistory = await api(`${apiBase}/cases/${caseId}/deregistrations/mail`, { feedback: false }); state.deregistrationMailCaseId = caseId } catch (_) {}
				if (result.status === 'ACCEPTED') { modal.remove(); render(); notifySuccess('Die Abmeldung wurde per E-Mail versendet (vom Mailserver angenommen) und automatisch auf „Versendet“ gesetzt.') }
				else { status.textContent = 'Dieser Versandversuch ist bereits erfasst. Bitte den Versandverlauf prüfen.'; status.dataset.state = 'error' }
			} catch (error) {
				button.disabled = false
				status.textContent = error.message; status.dataset.state = 'error'
			}
		}
	}

	function documentPanel() {
		const templates = state.documentTemplates.filter((entry) => entry.active && String(entry.fileName || '').toLowerCase().endsWith('.docx'))
		const cards = templates.map((entry) => `<article class="bp-document-card"><div><p class="bp-eyebrow">${esc(entry.category)}</p><h3>${esc(entry.name)}</h3><p>${esc(entry.description || '')}</p><small>${esc(entry.outputSubfolder || '')} · ${entry.supportsPdf ? 'DOCX + PDF' : 'DOCX'}</small></div><button class="bp-primary create-document" data-template-key="${esc(entry.key)}">Vorschau / erzeugen</button></article>`).join('')
		const caseDocuments = (state.records.document || []).filter((item) => item.caseId === state.currentCase.id)
		const outputs = caseDocuments.map(documentOutputRow).join('')
		const finalOrders = caseDocuments.filter((item) => item.status === 'FINAL' && item.data?.templateKey === 'BESTATTUNGSAUFTRAG' && item.data?.pdf?.fileId)
		const paperPanel = `<section class="bp-field-section"><div class="bp-panel-head"><div><h3>Handschriftlich unterschriebener Vertrag</h3><p class="bp-muted">Separaten Vertrag einscannen oder eine finale PDF-Revision des Bestattungsauftrags ausdrucken, unterschreiben lassen und als PDF-Scan hochladen. Der Scan gilt erst nach Sichtprüfung als unterschrieben; das Papieroriginal bitte gesondert aufbewahren.</p></div></div><div class="bp-row-actions"><button type="button" class="bp-secondary" id="paper-contract-external">Separaten Vertrag hochladen</button><button type="button" class="bp-secondary" id="paper-contract-generated" ${finalOrders.length ? '' : 'disabled'}>Unterschriebene PDF-Revision hochladen</button><button type="button" class="bp-secondary" id="paper-contract-create-final">Finale PDF-Revision erzeugen</button></div></section>`
		const mailReady = state.businessMailAvailability?.enabled === true
		const mailCandidates = caseDocuments.filter((item) => ['FINAL', 'UNTERSCHRIEBEN', 'VERSENDET'].includes(item.status) && (item.data?.pdf?.fileId || (item.data?.fileId && (String(item.data?.mimeType || '').toLowerCase() === 'application/pdf' || String(item.data?.path || '').toLowerCase().endsWith('.pdf')))))
		const mailRows = mailCandidates.map((item) => `<article class="bp-record-row"><div class="bp-record-content"><b>${esc(item.title)}</b><small>${esc(item.status)} · ${esc(item.data?.pdf?.fileName || item.data?.path || 'PDF')}</small></div><button type="button" class="bp-secondary business-mail-send" data-record-id="${Number(item.id)}" ${mailReady ? '' : 'disabled'}>Mit Geschäfts-Postfach senden</button></article>`).join('')
		const mailHistory = Number(state.businessMailCaseId) === Number(state.currentCase.id) ? (state.businessMailHistory || []) : []
		const historyRows = mailHistory.map((entry) => `<article class="bp-record-row"><div class="bp-record-content"><b>${esc(entry.subject)}</b><small>An ${esc(entry.recipient)} · ${esc(entry.fileName)} · ${esc(entry.createdAt)}</small><small>${entry.status === 'ACCEPTED' ? 'Vom Mailserver angenommen (Zustellung nicht bestätigt)' : entry.status === 'UNCERTAIN' ? 'Versandstatus unklar – Postfach prüfen' : 'Versand in Bearbeitung'} · ${esc(entry.createdBy)}</small>${entry.repeatReason ? `<small>Erneut gesendet: ${esc(entry.repeatReason)}</small>` : ''}${entry.replyTo ? `<small>Antworten an: ${esc(entry.replyTo)}</small>` : ''}</div></article>`).join('')
		const businessMailPanel = `<section class="bp-field-section"><div class="bp-panel-head"><div><h3>E-Mail aus dem Geschäfts-Postfach</h3><p class="bp-muted">Nur finale PDF-Dokumente, mit ausdrücklicher Empfänger- und Anlagenprüfung. Die lokale Aktion „Mit Mailprogramm teilen“ bleibt unabhängig davon bestehen.</p></div></div>${mailReady ? `<p class="bp-success">Absender: ${esc(state.businessMailAvailability.sender)}</p>` : `<p class="bp-warning">${esc(state.businessMailAvailability?.reason || 'Nextcloud-Mailversand nicht verfügbar.')}</p>`}${state.businessMailAvailability?.replyTo ? `<p class="bp-muted">Antworten an: ${esc(state.businessMailAvailability.replyTo)}</p>` : ''}<div class="bp-record-list">${mailRows || '<p class="bp-empty">Noch keine finale PDF zum Versenden vorhanden.</p>'}</div><h4>Versandverlauf</h4><div class="bp-record-list">${historyRows || '<p class="bp-empty">Noch kein Versandversuch dokumentiert.</p>'}</div></section>`
		const folders=(state.caseFiles.folders||[]).map((value)=>`<option>${esc(value)}</option>`).join(''); const types=(state.caseFiles.documentTypes||[]).map((value)=>`<option>${esc(value)}</option>`).join('')
		const files=(state.caseFiles.files||[]).map((file)=>{
			const sharedRow = documentOutputRow({ id: -Number(file.fileId), caseId: Number(state.currentCase.id), caseNumber: state.currentCase.caseNumber, title: file.title, date: file.modifiedAt, status: file.status || 'ABLAGE', data: { fileId: file.fileId, documentType: file.documentType, path: file.relativePath || file.path, source: file.source, extension: file.extension, mimeType: file.mimeType, readOnly: file.readOnly } })
			if (sharedRow) return sharedRow
			// Defensive fallback for embedded consumers that have not supplied the shared renderer yet.
			const actionData = `data-file-id="${Number(file.fileId)}" data-document-title="${esc(file.title)}" data-case-number="${esc(state.currentCase.caseNumber)}" data-file-name="${esc(file.name || file.relativePath?.split('/').pop() || file.title)}" data-mime-type="${esc(file.mimeType || '')}" data-share-url="${esc(`${apiBase}/cases/${Number(state.currentCase.id)}/files/${Number(file.fileId)}/content`)}"`
			const printData = `${actionData} data-print-url="${esc(`${apiBase}/cases/${Number(state.currentCase.id)}/files/${Number(file.fileId)}/content.pdf`)}"`
			const isPdf = String(file.extension || '').toLowerCase() === 'pdf' || String(file.mimeType || '').toLowerCase() === 'application/pdf'
			return `<article class="bp-file-row"><div class="bp-file-icon">${esc((file.extension||'DATEI').toUpperCase())}</div><div class="bp-record-content"><b>${esc(file.title)}</b><small>${esc(file.documentType)} · ${esc(file.status)} · ${esc(file.source==='NEXTCLOUD'?'direkt in Nextcloud abgelegt':'über die Bestatter-App/Erzeugung')} · ${esc(file.relativePath)}</small></div><div class="bp-row-actions"><button class="bp-secondary preview-nextcloud-file" data-file-id="${file.fileId}" aria-label="${esc(file.title)}">Vorschau</button><button class="bp-secondary open-nextcloud-file" data-file-id="${file.fileId}">${file.readOnly?'Öffnen':'In Nextcloud öffnen'}</button>${isPdf ? printButton('print-nextcloud-pdf', 'Drucken', printData, 'PDF direkt drucken') : ''}${mailButton('mail-nextcloud-file', 'Mit Mailprogramm teilen', actionData, 'Datei an ein lokales Mailprogramm übergeben')}</div></article>`
		}).join('')
		return `<div class="bp-page-head"><div><p class="bp-eyebrow">Dokumente</p><h2>Dokumente zum Fall</h2><p class="bp-muted">Vorlagen, erzeugte Ausgaben und die tatsächlichen Dateien der Nextcloud-Fallakte werden gemeinsam berücksichtigt.</p></div></div>${paperPanel}${businessMailPanel}<section class="bp-field-section"><div class="bp-panel-head"><div><h3>Dokumente hochladen</h3><p class="bp-muted">Mehrfachauswahl bis 50 MB je Datei. Gleichnamige Dateien werden nicht überschrieben.</p></div><button class="bp-primary" id="upload-case-documents">Dateien auswählen</button></div><input id="case-document-files" type="file" multiple hidden><div class="bp-form-grid"><label>Dokumentart<select id="case-upload-kind">${ctx.documentKindOptions ? ctx.documentKindOptions('', '– ohne Dokumentart –', ['FILE', 'DEREGISTRATION']) : '<option value="">– ohne Dokumentart –</option>'}</select></label><label>Ablage im Fall<select id="case-upload-folder">${folders}</select></label><label>Kategorie<select id="case-upload-type">${types}</select></label></div><p class="bp-muted bp-upload-kind-hint" id="case-upload-kind-hint" hidden></p><p class="bp-save-state" id="case-upload-state"></p></section><section class="bp-field-section"><div class="bp-panel-head"><div><h3>Vorlagen auswählen</h3><p class="bp-muted">Erzeugt einen aktuellen Entwurf oder eine finale, nicht bearbeitbare PDF-Ausgabe.</p></div></div><div class="bp-document-grid">${cards}</div></section><section class="bp-field-section"><div class="bp-panel-head"><div><h3>Erstellte Ausgaben</h3><p class="bp-muted">Fachlich erzeugte Entwürfe und finale Ausgaben mit Status und Metadaten.</p></div></div><div class="bp-record-list">${outputs || '<p class="bp-empty">Noch kein Dokument erzeugt.</p>'}</div></section><section class="bp-field-section"><div class="bp-panel-head"><div><h3>Dateien in der Nextcloud-Fallakte</h3><p class="bp-muted">Enthält auch Dateien, die außerhalb der Bestatter-App direkt in den Fallordner hochgeladen wurden.</p></div><span class="bp-status">${files?state.caseFiles.files.length:0} Datei(en)</span></div><div class="bp-file-list">${files||'<p class="bp-empty">Der Fallordner enthält noch keine Dateien.</p>'}</div></section>`
	}

	async function showBusinessMailDialog(recordId) {
		const caseId = Number(state.currentCase?.id || 0)
		if (!caseId || !state.businessMailAvailability?.enabled) return
		let preview
		try { preview = await api(`${apiBase}/cases/${caseId}/business-mail/${Number(recordId)}/preview`) }
		catch (error) { notifyWarning(error.message); return }
		const requestKey = globalThis.crypto?.randomUUID?.()
		if (!requestKey) { notifyWarning('Dieser Browser kann keine sichere Versandkennung erzeugen.'); return }
		const prior = (state.businessMailHistory || []).filter((item) => Number(item.recordId) === Number(recordId))
		root.insertAdjacentHTML('beforeend', `<div class="bp-modal"><form class="bp-wide-modal" id="business-mail-form"><div class="bp-panel-head"><div><p class="bp-eyebrow">Geschäfts-Postfach · Versandvorschau</p><h2>${esc(preview.title)}</h2></div><button type="button" class="bp-secondary" data-close-modal>Schließen</button></div><p class="bp-muted">Fall ${esc(preview.caseNumber)} · Absender ${esc(preview.sender)}. Es wird genau eine finale PDF angehängt: <b>${esc(preview.fileName)}</b> (${Math.ceil(Number(preview.fileSize) / 1024)} KB).</p>${preview.replyTo ? `<p class="bp-muted">Antworten an: ${esc(preview.replyTo)}</p>` : ''}<div class="bp-row-actions"><button type="button" class="bp-secondary" data-open-attachment>PDF vor Versand öffnen</button></div>${prior.length ? '<p class="bp-warning">Für dieses Dokument gibt es bereits Versandversuche. Prüfen Sie den Verlauf und das Geschäfts-Postfach vor einem erneuten Versand.</p>' : ''}<label>Empfängeradresse<input type="email" name="recipient" autocomplete="off" required placeholder="empfaenger@beispiel.de"></label><label>Betreff<input name="subject" required maxlength="250" value="${esc(`Fall ${preview.caseNumber} – ${preview.title}`)}"></label><label>Nachricht<textarea name="body" rows="7" required maxlength="10000"></textarea></label><label>Begründung für erneuten Versand (falls dieses Dokument an dieselbe Adresse schon versendet wurde)<input name="repeatReason" maxlength="500"></label><label class="bp-inline-check"><input type="checkbox" name="confirmed" required> Empfänger, Fall und angehängte PDF-Revision habe ich geprüft.</label><p class="bp-muted">„Vom Mailserver angenommen“ ist kein Zustellnachweis. Bei unklarem Ergebnis zuerst den Postausgang prüfen; nicht sofort erneut senden.</p><p class="bp-save-state" aria-live="polite"></p><div class="bp-modal-actions"><button type="button" class="bp-secondary" data-close-modal>Abbrechen</button><button class="bp-primary" ${preview.enabled ? '' : 'disabled'}>Jetzt senden</button></div></form></div>`)
		const modal = [...root.querySelectorAll('.bp-modal')].pop(); const form = modal.querySelector('form')
		modal.querySelectorAll('[data-close-modal]').forEach((button) => button.onclick = () => modal.remove())
		modal.querySelector('[data-open-attachment]').onclick = () => window.open(OC.generateUrl(`/f/${Number(preview.fileId)}`), '_blank', 'noopener')
		form.onsubmit = async (event) => {
			event.preventDefault(); const status = form.querySelector('.bp-save-state'); const button = form.querySelector('button.bp-primary'); button.disabled = true
			const values = Object.fromEntries(new FormData(form)); values.requestKey = requestKey; values.fileSha256 = preview.fileSha256
			try {
				const result = await api(`${apiBase}/cases/${caseId}/business-mail/${Number(recordId)}/send`, { method: 'POST', body: new URLSearchParams({ mail: JSON.stringify(values) }) })
				state.businessMailHistory = await api(`${apiBase}/cases/${caseId}/business-mail`)
				state.businessMailCaseId = caseId
				if (result.status === 'ACCEPTED') { modal.remove(); render(); notifySuccess('Die Nachricht wurde vom Mailserver angenommen; die Zustellung ist noch nicht bestätigt.') }
				else { status.textContent = 'Dieser Versandversuch ist bereits erfasst. Bitte den Versandverlauf und das Geschäfts-Postfach prüfen.'; status.dataset.state = 'error' }
			} catch (error) {
				try { state.businessMailHistory = await api(`${apiBase}/cases/${caseId}/business-mail`, { feedback: false }); state.businessMailCaseId = caseId } catch (_) {}
				status.textContent = error.message; status.dataset.state = 'error'
			}
		}
	}

	function showPaperContractUpload(source) {
		const generated = source === 'GENERATED'
		const finals = (state.records.document || []).filter((item) => item.caseId === state.currentCase.id && item.status === 'FINAL' && item.data?.templateKey === 'BESTATTUNGSAUFTRAG' && item.data?.pdf?.fileId)
		if (generated && !finals.length) return
		const options = finals.map((item) => `<option value="${Number(item.id)}">${esc(item.title)} · ${esc(item.date || '')} · ${esc(item.data?.pdf?.fileName || 'PDF')}</option>`).join('')
		root.insertAdjacentHTML('beforeend', `<div class="bp-modal"><form class="bp-wide-modal" id="paper-contract-upload"><div class="bp-panel-head"><div><p class="bp-eyebrow">Papiervertrag</p><h2>${generated ? 'Unterschriebene PDF-Revision' : 'Separater Bestattervertrag'}</h2></div><button type="button" class="bp-secondary" data-close-modal>Schließen</button></div><p class="bp-muted">Den vollständigen, lesbaren Scan aller Vertragsseiten als PDF hochladen. Danach ist eine ausdrückliche Sichtprüfung erforderlich. Das Papieroriginal bleibt separat aufzubewahren.</p>${generated ? `<label>Gedruckte finale Revision<select name="sourceRecordId" required>${options}</select></label>` : ''}<label>Unterschriebener PDF-Scan<input type="file" name="file" accept=".pdf,application/pdf" required></label><p class="bp-save-state" aria-live="polite"></p><div class="bp-modal-actions"><button type="button" class="bp-secondary" data-close-modal>Abbrechen</button><button class="bp-primary">Scan zur Prüfung hochladen</button></div></form></div>`)
		const modal = [...root.querySelectorAll('.bp-modal')].pop(); const form = modal.querySelector('form')
		modal.querySelectorAll('[data-close-modal]').forEach((button) => button.onclick = () => modal.remove())
		form.onsubmit = async (event) => {
			event.preventDefault(); const status = form.querySelector('.bp-save-state'); const file = form.elements.file.files?.[0]
			if (!file) return
			const body = new FormData(); body.append('file', file); body.append('source', source); body.append('sourceRecordId', generated ? form.elements.sourceRecordId.value : '0')
			const button = form.querySelector('button.bp-primary'); button.disabled = true; status.textContent = 'Scan wird hochgeladen …'; status.dataset.state = 'saving'
			try { await api(`${apiBase}/cases/${state.currentCase.id}/paper-contracts`, { method: 'POST', body }); await Promise.all([loadRecordType('document', state.currentCase.id), loadCaseFiles(state.currentCase.id)]); modal.remove(); render(); notifySuccess('Der Scan wartet auf Sichtprüfung.') }
			catch (error) { button.disabled = false; status.textContent = error.message; status.dataset.state = 'error' }
		}
	}

	function showPaperContractReview(recordId) {
		const item = (state.records.document || []).find((entry) => Number(entry.id) === Number(recordId) && Number(entry.caseId) === Number(state.currentCase.id))
		if (!item || item.status !== 'SIGNATURE_REVIEW' || !item.data?.paperSignature) return
		const paper = item.data.paperSignature; const scanId = Number(item.data.fileId || 0)
		root.insertAdjacentHTML('beforeend', `<div class="bp-modal"><form class="bp-wide-modal" id="paper-contract-review"><div class="bp-panel-head"><div><p class="bp-eyebrow">Manuelle Sichtprüfung</p><h2>Unterschriebenen Vertrag prüfen</h2></div><button type="button" class="bp-secondary" data-close-modal>Schließen</button></div><p class="bp-muted">${paper.source === 'GENERATED' ? `Gedruckte PDF-Revision #${Number(paper.sourceRecordId)}` : 'Separater Bestattervertrag'} · Fall ${esc(state.currentCase.caseNumber)}. Öffnen und prüfen Sie alle Seiten, bevor Sie bestätigen.</p><div class="bp-row-actions"><button type="button" class="bp-secondary" data-open-scan>Scan öffnen</button>${paper.source === 'GENERATED' ? '<button type="button" class="bp-secondary" data-open-source>Finale PDF-Revision öffnen</button>' : ''}</div><div class="bp-form-grid"><label>Unterschreibende Parteien<input name="signers" required maxlength="500" placeholder="z. B. Auftraggeber und Bestatter"></label><label>Datum der Unterschrift (falls erkennbar)<input name="signatureDate" type="date"></label><label class="bp-span-2">Ablageort des Papieroriginals<input name="paperLocation" maxlength="500" placeholder="z. B. Akte im Archiv"></label></div><label class="bp-inline-check"><input type="checkbox" name="complete" required> Scan ist vollständig und lesbar (alle Seiten).</label><label class="bp-inline-check"><input type="checkbox" name="signaturesPresent" required> Handschriftliche Unterschriften sind erkennbar.</label><label class="bp-inline-check"><input type="checkbox" name="contentMatches" required> Vertragsinhalt und Fallzuordnung sind geprüft${paper.source === 'GENERATED' ? '; der Scan entspricht der finalen PDF-Revision' : ''}.</label><p class="bp-save-state" aria-live="polite"></p><div class="bp-modal-actions"><button type="button" class="bp-secondary" data-close-modal>Abbrechen</button><button class="bp-primary">Als handschriftlich unterschrieben bestätigen</button></div></form></div>`)
		const modal = [...root.querySelectorAll('.bp-modal')].pop(); const form = modal.querySelector('form')
		modal.querySelectorAll('[data-close-modal]').forEach((button) => button.onclick = () => modal.remove())
		modal.querySelector('[data-open-scan]').onclick = () => window.open(OC.generateUrl(`/f/${scanId}`), '_blank', 'noopener')
		if (paper.source === 'GENERATED') modal.querySelector('[data-open-source]').onclick = () => window.open(OC.generateUrl(`/f/${Number(paper.sourcePdfFileId)}`), '_blank', 'noopener')
		form.onsubmit = async (event) => {
			event.preventDefault(); const status = form.querySelector('.bp-save-state'); const values = Object.fromEntries(new FormData(form)); const button = form.querySelector('button.bp-primary'); button.disabled = true
			try { await api(`${apiBase}/cases/${state.currentCase.id}/paper-contracts/${Number(recordId)}/confirm`, { method: 'POST', body: new URLSearchParams({ review: JSON.stringify(values) }) }); await Promise.all([loadRecordType('document', state.currentCase.id), loadCaseFiles(state.currentCase.id)]); modal.remove(); render(); notifySuccess('Handschriftliche Unterschrift nach Sichtprüfung bestätigt.') }
			catch (error) { button.disabled = false; status.textContent = error.message; status.dataset.state = 'error' }
		}
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
		// 0.88.0: Das Paket kommt vom Server (Auftrag und Vollmacht in einem Dokument, ggf. Widerrufsbelehrung).
		let definitions = isQuote ? [{ key: 'BESTATTUNGSAUFTRAG', title: 'Kostenvoranschlag' }] : [{ key: 'BESTATTUNGSAUFTRAG', title: 'Bestattungsauftrag und Vollmacht' }]
		try { const list = await api(`${apiBase}/cases/${state.currentCase.id}/order-documents`, { feedback: false }); if ((list.documents || []).length) definitions = list.documents } catch (_) { /* Fallback oben */ }
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

	async function showInvoiceDocumentPreview(invoiceId, invoiceNumber = '') {
		const title = invoiceNumber ? `Rechnung ${invoiceNumber} – Vorschau` : 'Rechnung – Vorschau'
		root.insertAdjacentHTML('beforeend', `<div class="bp-modal" role="dialog" aria-modal="true" aria-labelledby="bp-invoice-preview-title"><section class="bp-wide-modal bp-order-preview"><div class="bp-panel-head bp-file-preview-head"><div><p class="bp-eyebrow">Nebenwirkungsfreie PDF-Vorschau</p><h2 id="bp-invoice-preview-title">${esc(title)}</h2><p class="bp-muted">Die Vorschau legt keine Datei und keinen Dokumentdatensatz in der Fallakte an; es wird kein verbindlicher Rechnungsabschluss erzeugt.</p></div><button type="button" class="bp-secondary" data-close-invoice-preview>Schließen</button></div><div class="bp-order-preview-stage" aria-busy="true"><p class="bp-empty" data-invoice-preview-loading>PDF-Vorschau wird erzeugt …</p><iframe title="${esc(title)}" data-invoice-preview-frame hidden></iframe></div><p class="bp-save-state" data-invoice-preview-state aria-live="polite"></p><div class="bp-modal-actions"><button type="button" class="bp-secondary" data-open-invoice-preview disabled>PDF öffnen / drucken</button></div></section></div>`)
		const modal = [...root.querySelectorAll('.bp-modal')].pop()
		const frame = modal.querySelector('[data-invoice-preview-frame]')
		const stage = modal.querySelector('.bp-order-preview-stage')
		const status = modal.querySelector('[data-invoice-preview-state]')
		const openButton = modal.querySelector('[data-open-invoice-preview]')
		let closed = false
		let objectUrl = null
		const close = () => { closed = true; if (objectUrl) URL.revokeObjectURL(objectUrl); modal.remove() }
		modal.querySelector('[data-close-invoice-preview]').onclick = close
		try {
			const response = await fetch(`${apiBase}/commercial/invoices/${invoiceId}/preview.pdf`, { credentials: 'same-origin', headers: { requesttoken: OC.requestToken } })
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
			modal.querySelector('[data-invoice-preview-loading]')?.remove()
			stage.setAttribute('aria-busy', 'false')
			frame.src = objectUrl
			frame.hidden = false
			openButton.disabled = false
			openButton.onclick = () => window.open(objectUrl, '_blank', 'noopener')
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
		root.insertAdjacentHTML('beforeend', `<div class="bp-modal"><form class="bp-wide-modal" id="document-generate-form"><div class="bp-panel-head"><div><p class="bp-eyebrow">Dokumentvorschau</p><h2>${esc(preview.title)}</h2></div><button type="button" class="bp-secondary" data-close-modal>Schließen</button></div>${scheduleSelection}${preview.missingFields.length ? `<div class="bp-error">Fehlend: ${preview.missingFields.map(esc).join(', ')}</div>` : '<div class="bp-success">Alle Pflichtangaben vorhanden.</div>'}<div class="bp-paper-preview"><div class="bp-paper-head"><b>${esc(preview.title)}</b><span>Fall ${esc(preview.summary.caseNumber)}</span></div><h3>${esc(preview.summary.deceased)}</h3><p>${esc(preview.summary.death)}</p><dl><div><dt>Dokumentenart</dt><dd>${esc(preview.documentType || 'Allgemein')}</dd></div>${contextRows}<div><dt>Ablage</dt><dd>${esc(preview.outputSubfolder)} / ${esc(preview.fileName)}</dd></div></dl><p class="bp-muted">Die bildgenaue Seitenvorschau steht nach der Erzeugung über das Vorschaubild der Ausgabe bereit.</p></div><label class="bp-inline-check"><input type="checkbox" name="createPdf" ${preview.supportsPdf ? 'checked' : 'disabled'}> PDF zusätzlich erzeugen</label>${preview.pdfMessage ? `<p class="bp-muted">${esc(preview.pdfMessage)}</p>` : ''}<label>Status<select name="documentStatus"><option value="ENTWURF">Entwurf – in Nextcloud Office bearbeitbar</option><option value="FINAL" ${preview.ready ? '' : 'disabled'}>Final – nur als PDF, nicht bearbeitbar</option></select></label>${preview.ready ? '' : '<label class="bp-inline-check"><input type="checkbox" name="allowIncomplete"> Unvollständigen Entwurf erzeugen</label>'}<p class="bp-save-state" aria-live="polite"></p><div class="bp-modal-actions"><button class="bp-primary">Aktuelle Ausgabe erzeugen</button></div></form></div>`)
		const modal = [...root.querySelectorAll('.bp-modal')].pop()
		const form = modal.querySelector('form')
		modal.querySelector('[data-close-modal]').onclick = () => modal.remove()
		form.elements.scheduleId?.addEventListener('change', () => {
			const selectedId = Number(form.elements.scheduleId.value || 0)
			modal.remove()
			showDocumentDialog(key, selectedId)
		})
		form.elements.documentStatus.onchange = () => {
			if (form.elements.documentStatus.value === 'FINAL' && form.elements.createPdf) {
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
		const unsaved = isUnsavedCase(state)
		if (unsaved && !UNSAVED_CASE_TABS.includes(state.caseTab)) state.caseTab = 'master'
		let body = unsaved ? '' : caseOverview()
		if (state.caseTab === 'master') body = masterDataForm(data)
		else if (state.caseTab === 'order') body = orderPanel(data)
		else if (state.caseTab === 'side-orders') body = sideOrdersPanel()
		else if (state.caseTab === 'services') body = state.orderForm?.open && Number(state.orderForm.caseId || state.currentCase?.id) === Number(state.currentCase?.id) ? ctx.orderFormView() : serviceSelectionPanel()
		// 0.88.1: Aufgaben- bzw. Terminliste samt „Neu anlegen“ zuerst, der Ablauf (einklappbar) darunter.
		else if (state.caseTab === 'task') body = `${recordsView('task', item.id)}${ctx.processTaskPanel ? ctx.processTaskPanel() : ''}`
		else if (state.caseTab === 'contact') body = caseContactsPanel()
		else if (state.caseTab === 'deregistration') body = deregistrationPanel()
		else if (state.caseTab === 'document') body = documentPanel()
		else if (state.caseTab === 'finances') body = financesPanel()
		else if (state.caseTab === 'schedule') body = `${recordsView(state.caseTab, item.id)}${ctx.processScheduleStrip ? ctx.processScheduleStrip() : ''}`
		else if (state.caseTab === 'history') body = recordsView('activity', item.id)
		else if (!['overview', 'master', 'task', 'history'].includes(state.caseTab)) body = `<section class="bp-panel bp-placeholder"><h3>${esc(tabs.find((tab) => tab[0] === state.caseTab)?.[1] || state.caseTab)}</h3><p>Dieser fallbezogene Arbeitsbereich ist vorbereitet.</p></section>`
		// 0.88.1: Speicherstatus im Kopf; seltene Aktionen im Menü „Weitere Aktionen“; unter 768 px eine Bereichsauswahl statt der Reiter.
		const isLocked = (key) => unsaved && !UNSAVED_CASE_TABS.includes(key)
		if (state.caseTab === 'overview' && !unsaved && ctx.creationNoticeView) body = `${ctx.creationNoticeView()}${body}`
		return `<div class="bp-page-head bp-case-head"><div><p class="bp-eyebrow">Fallakte ${esc(item.caseNumber)}</p><div class="bp-case-title-row"><h2>${esc(item.lastName)}, ${esc(item.firstName)}</h2>${ctx.saveIndicator ? ctx.saveIndicator() : ''}${ctx.creationChip ? ctx.creationChip() : ''}</div>${ctx.caseLabelsHead ? ctx.caseLabelsHead(item) : ''}${item.retentionHold?`<span class="bp-status" title="${esc(holdTitle)}" aria-label="Aufbewahrung gesperrt. ${esc(holdTitle)}">Aufbewahrung gesperrt</span><small class="bp-hold-summary">Verantwortlich: ${esc(item.retentionHoldResponsible || '–')} · Prüfung: ${esc(holdReviewAt)}</small>`:''}</div><div class="bp-head-actions"><button class="bp-secondary" data-view="cases" title="Zur Fallübersicht zurückkehren.">Zurück zu Fällen</button>${ctx.caseActionMenu ? ctx.caseActionMenu(item, unsaved) : ''}</div></div>${ctx.caseStickyBar ? ctx.caseStickyBar(item, tabs.find((tab) => tab[0] === state.caseTab)?.[1] || '') : ''}${ctx.caseAreaSelect ? ctx.caseAreaSelect(tabs, isLocked) : ''}<div class="bp-tabs bp-case-tabs">${tabs.map((tab) => { const locked = isLocked(tab[0]); return `<button class="${state.caseTab === tab[0] ? 'active' : ''}${locked ? ' is-locked' : ''}" data-case-tab="${tab[0]}"${locked ? ` disabled aria-disabled="true" title="${esc(tab[1])}: ${esc(UNSAVED_CASE_HINT)}"` : ` title="Arbeitsbereich ${esc(tab[1])} öffnen."`}>${tab[1]}</button>` }).join('')}</div>${unsaved ? `<p class="bp-case-unsaved-hint" role="note">Die weiteren Reiter sind ${esc(UNSAVED_CASE_HINT.charAt(0).toLowerCase() + UNSAVED_CASE_HINT.slice(1))}</p>` : ''}${body}`
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


	return { caseContactsPanel, showCaseContactForm, bindCaseContacts, chooseDuplicate, expandDeregistration, deliveryChannels, showTextPreview, showLetterPreview, deregistrationPanel, showDeregistrationForm, showDeregistrationTransitionForm, showDeregistrationMailDialog, documentPanel, showBusinessMailDialog, showPaperContractUpload, showPaperContractReview, showFilePreview, showOrderDocumentPreview, showInvoiceDocumentPreview, showDocumentDialog, caseDetail, showRetentionHoldDialog }
}
