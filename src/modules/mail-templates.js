// 0.89.3: Customizing → E-Mail-Vorlagen für Auftrag, Kostenvoranschlag und Rechnung (Betreff, Text, Platzhalter, Vorschau)
// und Signaturzusatz. Die Signatur selbst entsteht aus den Angaben der Niederlassung (Stammdaten → Niederlassungen).

export function createMailTemplatesModule(ctx) {
	const { root, apiBase, state } = ctx
	const api = (...args) => ctx.api(...args)
	const esc = (...args) => ctx.esc(...args)
	const render = (...args) => ctx.render(...args)
	const url = (suffix = '') => `${apiBase}/mail-templates${suffix}`

	async function load() {
		try { state.mailTemplates = await api(url(), { feedback: false, loadingLabel: false }) } catch (error) { state.mailTemplates = { templates: [], error: error.message } }
		return state.mailTemplates
	}

	function card(template, catalog) {
		const disabled = catalog.canEdit ? '' : 'disabled'
		const chips = Object.entries(catalog.placeholders || {}).map(([placeholder, meaning]) => `<button type="button" class="bp-mail-chip" data-mail-insert="${esc(placeholder)}" title="${esc(meaning)}" ${disabled}>${esc(placeholder)}</button>`).join('')
		return `<article class="bp-mail-template" data-mail-template="${esc(template.key)}">
			<div class="bp-panel-head"><div><h4>${esc(template.name)}</h4><p class="bp-muted">${template.isDefault ? 'Mitgelieferter Text' : 'Eigener Text'} · gilt für die Versandvorschau im Geschäfts-Postfach${template.key === 'ORDER' ? ' und die Ausfertigung nach der Unterschrift am Tablet' : ''}.</p></div></div>
			<label><span>Betreff</span><input data-mail-field="subject" value="${esc(template.subject)}" maxlength="250" ${disabled}></label>
			<label><span>Text</span><textarea data-mail-field="body" rows="14" maxlength="8000" ${disabled}>${esc(template.body)}</textarea></label>
			<details class="bp-mail-placeholders"><summary>Platzhalter einfügen</summary><div>${chips}</div></details>
			<pre class="bp-mail-preview" data-mail-preview hidden></pre>
			<p class="bp-card-state" data-mail-state aria-live="polite"></p>
			<div class="bp-row-actions"><button type="button" class="bp-secondary" data-mail-preview-button>Vorschau</button>${catalog.canEdit ? `<button type="button" class="bp-secondary" data-mail-reset ${template.isDefault ? 'disabled' : ''}>Mitgelieferten Text wiederherstellen</button><button type="button" class="bp-primary" data-mail-save>Speichern</button>` : ''}</div>
		</article>`
	}

	function view() {
		const catalog = state.mailTemplates
		if (!catalog) return '<section class="bp-panel"><p class="bp-muted">E-Mail-Vorlagen werden geladen …</p></section>'
		if (catalog.error) return `<section class="bp-panel"><p class="bp-warning">${esc(catalog.error)}</p></section>`
		const signatures = (catalog.signatures || []).map((item) => `<div class="bp-mail-signature"><b>${esc(item.name)}</b><pre>${esc(item.signature)}</pre></div>`).join('') || '<p class="bp-muted">Noch keine aktive Niederlassung angelegt.</p>'
		return `<section class="bp-panel bp-mail-admin" data-mail-admin>
			<div class="bp-panel-head"><div><h3>E-Mail-Vorlagen</h3><p class="bp-muted">Betreff und Text für den Versand von Bestattungsauftrag, Kostenvoranschlag und Rechnung an die Angehörigen. Die Versandvorschau im Geschäfts-Postfach übernimmt den Text; er lässt sich dort für die einzelne Nachricht ändern. Anrede, Dokumentangaben und Signatur setzt die App über die Platzhalter ein.</p></div></div>
			${catalog.canEdit ? '' : '<p class="bp-muted">Nur Bestatter-Administratoren können die Vorlagen ändern.</p>'}
			<div class="bp-mail-templates">${(catalog.templates || []).map((template) => card(template, catalog)).join('')}</div>
			<article class="bp-mail-template" data-mail-signature-admin><div class="bp-panel-head"><div><h4>Signatur</h4><p class="bp-muted">Die Signatur ({{mail.signature}}) entsteht aus Name, Anschrift, Telefon und E-Mail der Niederlassung des Falls; Geschäftsführung, Registergericht und USt-IdNr. stehen darunter, soweit unter Stammdaten → Niederlassungen eingetragen. Der Signaturzusatz erscheint bei allen Niederlassungen, zum Beispiel Erreichbarkeit oder Internetseite.</p></div></div>
				<label><span>Signaturzusatz</span><textarea data-mail-signature-extra rows="3" maxlength="1000" ${catalog.canEdit ? '' : 'disabled'}>${esc(catalog.signatureExtra || '')}</textarea></label>
				<p class="bp-card-state" data-mail-signature-state aria-live="polite"></p>
				${catalog.canEdit ? '<div class="bp-row-actions"><button type="button" class="bp-primary" data-mail-signature-save>Signaturzusatz speichern</button></div>' : ''}
				<div class="bp-mail-signatures">${signatures}</div></article>
		</section>`
	}

	function bind() {
		if (state.view !== 'customizing' || state.customizingTab !== 'mail-templates') return
		if (!state.mailTemplates) { load().then(() => { if (state.view === 'customizing' && state.customizingTab === 'mail-templates') render() }); return }
		const panel = root.querySelector('[data-mail-admin]')
		if (!panel) return
		panel.querySelectorAll('[data-mail-template]').forEach((article) => {
			const key = article.dataset.mailTemplate
			const field = (name) => article.querySelector(`[data-mail-field="${name}"]`)
			const message = article.querySelector('[data-mail-state]')
			const fail = (error) => { message.textContent = error.message; message.dataset.state = 'error' }
			let lastFocus = field('body')
			;['subject', 'body'].forEach((name) => field(name)?.addEventListener('focus', () => { lastFocus = field(name) }))
			article.querySelectorAll('[data-mail-insert]').forEach((chip) => chip.addEventListener('click', () => {
				const target = lastFocus || field('body'), text = chip.dataset.mailInsert
				const start = target.selectionStart ?? target.value.length, end = target.selectionEnd ?? start
				target.value = target.value.slice(0, start) + text + target.value.slice(end)
				target.focus(); target.selectionStart = target.selectionEnd = start + text.length
			}))
			article.querySelector('[data-mail-preview-button]')?.addEventListener('click', async () => {
				try {
					const result = await api(url(`/${encodeURIComponent(key)}/preview`), { method: 'POST', feedback: false, body: new URLSearchParams({ subject: field('subject').value, body: field('body').value }) })
					const preview = article.querySelector('[data-mail-preview]')
					preview.textContent = `Betreff: ${result.subject}\n\n${result.body}`; preview.hidden = false
					message.textContent = 'Vorschau mit Beispieldaten (Frau Erika Muster, erste aktive Niederlassung).'; message.dataset.state = ''
				} catch (error) { fail(error) }
			})
			const save = async (subject, body, success) => {
				try {
					state.mailTemplates = await api(url(`/${encodeURIComponent(key)}`), { method: 'PUT', feedback: false, body: new URLSearchParams({ subject, body }) })
					render(); ctx.notifySuccess(success)
				} catch (error) { fail(error) }
			}
			article.querySelector('[data-mail-save]')?.addEventListener('click', () => save(field('subject').value, field('body').value, 'E-Mail-Vorlage gespeichert.'))
			article.querySelector('[data-mail-reset]')?.addEventListener('click', async () => {
				if (!await ctx.confirmAction('Den eigenen Text verwerfen und den mitgelieferten Text wiederherstellen?', { title: 'Mitgelieferten Text wiederherstellen', confirmLabel: 'Wiederherstellen' })) return
				save('', '', 'Mitgelieferter Text wiederhergestellt.')
			})
		})
		panel.querySelector('[data-mail-signature-save]')?.addEventListener('click', async () => {
			const message = panel.querySelector('[data-mail-signature-state]')
			try {
				state.mailTemplates = await api(url('/signature'), { method: 'PUT', feedback: false, body: new URLSearchParams({ signatureExtra: panel.querySelector('[data-mail-signature-extra]').value }) })
				render(); ctx.notifySuccess('Signaturzusatz gespeichert.')
			} catch (error) { message.textContent = error.message; message.dataset.state = 'error' }
		})
	}

	return { view, bind, load }
}
