/**
 * 0.75.3 – Einrichtungspaket (Administration → „Einrichtungspaket“) und Logo je Niederlassung
 * (Administration → „Niederlassungen“). Pflege nur für Bestatter-Administratoren.
 */
export function createSetupPackageModule(ctx) {
	const { state, esc, api, apiBase, render, notifySuccess, notifyError, notifyWarning, confirmAction, download } = ctx
	const TYPES = [
		['article-groups', 'Warengruppen'], ['articles', 'Leistungskatalog (Artikel)'], ['price-list', 'Preislisten'], ['document-template', 'Dokumentvorlagen'],
		['order-form', 'Auftragsformulare'], ['labels', 'Marker (mit Auswahl aktiv/inaktiv)'], ['document-kinds', 'Dokumentarten (mit Paperless-Zuordnung)'], ['process-template', 'Ablaufvorlagen'], ['logo', 'Logos der Niederlassungen'],
	]
	const pkg = () => (state.setupPackage ||= { customer: '', types: TYPES.map(([key]) => key), file: null, preview: null, decisions: {}, result: null })

	async function loadBranchLogos() {
		try { state.branchLogos = (await api(`${apiBase}/branch-logos`, { feedback: false })).logos || {} } catch (_) { state.branchLogos = {} }
	}

	// ------------------------------------------------------------------------------------- Logo je Niederlassung
	function branchLogoBlock(entry) {
		const key = String(entry.key || '')
		if (!entry.id || !key) return '<h4>Logo</h4><p class="bp-muted">Das Logo kann nach dem ersten Speichern der Niederlassung hinterlegt werden.</p>'
		const logo = (state.branchLogos || {})[key.toUpperCase()]
		const preview = logo ? `<img class="bp-branch-logo-preview" src="${esc(`${apiBase}/branch-logos/${encodeURIComponent(key)}?v=${encodeURIComponent(logo.updatedAt || '')}`)}" alt="Logo ${esc(entry.name)}"><small>${esc(logo.width)} × ${esc(logo.height)} px</small>` : '<span class="bp-muted">Kein Logo hinterlegt.</span>'
		return `<h4>Logo</h4><div class="bp-branch-logo" data-branch-logo="${esc(key)}"><div class="bp-branch-logo-image">${preview}</div><div><p class="bp-muted">Ersetzt in Dokumentvorlagen das Bild „Logo-Platzhalter“ (Customizing → Dokumentvorlagen). PNG, JPEG, GIF oder WebP bis 2 MB; das Seitenverhältnis bleibt erhalten.</p><div class="bp-row-actions"><label class="bp-secondary bp-file-button">${logo ? 'Logo ersetzen' : 'Logo hochladen'}<input type="file" data-branch-logo-upload="${esc(key)}" accept="image/png,image/jpeg,image/gif,image/webp" hidden></label>${logo ? `<button type="button" class="bp-danger" data-branch-logo-delete="${esc(key)}">Logo entfernen</button>` : ''}</div></div></div>`
	}

	function bindBranchLogos() {
		document.querySelectorAll('[data-branch-logo-upload]').forEach((input) => input.addEventListener('change', async () => {
			const file = input.files?.[0]
			input.value = ''
			if (!file) return
			try {
				if (file.size > 2 * 1024 * 1024) throw new Error('Das Logo ist größer als 2 MB.')
				const body = new FormData(); body.append('file', file)
				await api(`${apiBase}/branch-logos/${encodeURIComponent(input.dataset.branchLogoUpload)}`, { method: 'POST', body, feedback: 'Logo gespeichert.' })
				await loadBranchLogos(); render()
			} catch (error) { notifyError(error.message) }
		}))
		document.querySelectorAll('[data-branch-logo-delete]').forEach((button) => button.addEventListener('click', async () => {
			if (!await confirmAction('Logo dieser Niederlassung entfernen? Dokumente zeigen an der Stelle des Logo-Platzhalters dann nichts mehr an.', { title: 'Logo entfernen', confirmLabel: 'Entfernen', danger: true })) return
			try { await api(`${apiBase}/branch-logos/${encodeURIComponent(button.dataset.branchLogoDelete)}`, { method: 'DELETE', feedback: 'Logo entfernt.' }); await loadBranchLogos(); render() } catch (error) { notifyError(error.message) }
		}))
	}

	// ------------------------------------------------------------------------------------- Einrichtungspaket
	function setupPackageView() {
		const p = pkg()
		const typeChecks = TYPES.map(([key, label]) => `<label class="bp-inline-check"><input type="checkbox" data-setup-type="${key}" ${p.types.includes(key) ? 'checked' : ''}> ${esc(label)}</label>`).join('')
		const exportPanel = `<section class="bp-panel"><div class="bp-panel-head"><div><h3>Einrichtungspaket erstellen</h3><p class="bp-muted">Fasst die kundenspezifische Einrichtung dieser Installation in einer ZIP-Datei zusammen – zum Übertragen von einer Test- in die Produktivinstallation oder als Vorlage für einen ähnlichen Kunden. Enthalten sind keine Falldaten, keine Bankdaten und keine Zugangsdaten.</p></div></div>
			<div class="bp-form-grid"><label><span>Kunde / Bezeichnung</span><input id="setup-customer" value="${esc(p.customer)}" maxlength="120" placeholder="z. B. Bestattungshaus Muster"></label></div>
			<fieldset class="bp-setup-types"><legend>Inhalte</legend>${typeChecks}</fieldset>
			<div class="bp-row-actions"><button type="button" class="bp-primary" id="setup-export">Einrichtungspaket herunterladen</button></div></section>`
		const items = p.preview?.items || []
		const statusLabel = { NEU: 'neu', VORHANDEN: 'vorhanden', FEHLER: 'Fehler' }
		const rows = items.map((item) => {
			const selected = p.decisions[item.index] || item.defaultAction
			const options = item.actions.map((action, index) => `<option value="${esc(action)}" ${action === selected ? 'selected' : ''}>${esc(item.actionLabels[index])}</option>`).join('')
			const warnings = item.warnings?.length ? `<ul class="bp-setup-warnings">${item.warnings.map((warning) => `<li>${esc(warning)}</li>`).join('')}</ul>` : ''
			return `<tr><td>${esc(item.typeLabel)}</td><td><b>${esc(item.title)}</b><small>${esc(item.key)}</small></td><td><span class="bp-status ${item.status === 'NEU' ? 'ready' : item.status === 'FEHLER' ? 'error' : ''}">${esc(statusLabel[item.status] || item.status)}</span></td><td>${esc(item.details || '')}${warnings}</td><td><select data-setup-decision="${item.index}" aria-label="Aktion für ${esc(item.title)}" ${item.actions.length < 2 ? 'disabled' : ''}>${options}</select><small>Vorschlag: ${esc(item.actionLabels[item.actions.indexOf(item.defaultAction)] || item.defaultAction)}</small></td></tr>`
		}).join('')
		const result = p.result ? `<aside class="bp-of-report ${p.result.summary.failed ? 'has-warnings' : ''}" role="status"><b>Eingespielt: ${p.result.summary.done} · übersprungen: ${p.result.summary.skipped} · fehlgeschlagen: ${p.result.summary.failed}</b><small>Sicherung vorher: ${esc(p.result.backup.path || '–')}</small><ul>${p.result.results.map((row) => `<li>${esc(row.typeLabel)} „${esc(row.title)}“ – ${esc(row.actionLabel)}: ${row.ok ? '' : 'Fehler: '}${esc(row.message)}</li>`).join('')}</ul></aside>` : ''
		const manifest = p.preview?.manifest
		const importPanel = `<section class="bp-panel"><div class="bp-panel-head"><div><h3>Einrichtungspaket einspielen</h3><p class="bp-muted">Zuerst zeigt eine Vorschau jeden Eintrag mit Stand und vorgeschlagener Aktion; vorhandene Inhalte werden nur geändert, wenn Sie das wählen. Vor dem Einspielen legt die App automatisch eine Sicherung der App-Daten an. Ersetzte Dokumentvorlagen bleiben als datierte Kopie im Vorlagenordner.</p></div></div>
			<div class="bp-row-actions"><label class="bp-secondary bp-file-button">Paket auswählen …<input type="file" id="setup-import-file" accept=".zip,application/zip" hidden></label>${p.file ? `<span class="bp-muted">${esc(p.file.name)}</span>` : ''}</div>
			${manifest ? `<p><b>${esc(manifest.customer || 'Ohne Bezeichnung')}</b> · erstellt ${esc(String(manifest.createdAt || '').slice(0, 10))} mit Bestatter ${esc(manifest.appVersion || '')} · ${esc(manifest.itemCount)} Einträge</p>` : ''}
			${(p.preview?.warnings || []).map((warning) => `<aside class="bp-warning">${esc(warning)}</aside>`).join('')}
			${items.length ? `<div class="bp-table-scroll"><table class="bp-setup-table"><colgroup><col class="bp-setup-col-type"><col class="bp-setup-col-entry"><col class="bp-setup-col-status"><col><col class="bp-setup-col-action"></colgroup><thead><tr><th>Inhalt</th><th>Eintrag</th><th>Stand</th><th>Hinweise</th><th>Aktion</th></tr></thead><tbody>${rows}</tbody></table></div><div class="bp-row-actions"><button type="button" class="bp-secondary" id="setup-discard">Verwerfen</button><button type="button" class="bp-primary" id="setup-apply" ${p.preview?.warnings?.length ? 'disabled' : ''}>Einspielen</button></div>` : ''}
			${result}</section>`
		return exportPanel + importPanel
	}

	function bindSetupPackage() {
		const p = pkg()
		document.getElementById('setup-customer')?.addEventListener('input', (event) => { p.customer = event.target.value })
		document.querySelectorAll('[data-setup-type]').forEach((input) => input.addEventListener('change', () => {
			p.types = TYPES.map(([key]) => key).filter((key) => document.querySelector(`[data-setup-type="${key}"]`)?.checked)
		}))
		document.getElementById('setup-export')?.addEventListener('click', async () => {
			if (!p.types.length) { notifyWarning('Bitte mindestens einen Inhalt auswählen.'); return }
			const query = new URLSearchParams({ customer: p.customer, types: p.types.join(',') })
			try { await download(`${apiBase}/setup-package/export?${query}`, 'Einrichtungspaket.zip', { saveAs: true, description: 'Einrichtungspaket' }) } catch (error) { notifyError(error.message) }
		})
		document.getElementById('setup-import-file')?.addEventListener('change', async (event) => {
			const file = event.target.files?.[0]
			event.target.value = ''
			if (!file) return
			try {
				if (file.size > 50 * 1024 * 1024) throw new Error('Das Paket ist größer als 50 MB.')
				const body = new FormData(); body.append('file', file)
				p.preview = await api(`${apiBase}/setup-package/preview`, { method: 'POST', body, feedback: false })
				p.file = file; p.decisions = {}; p.result = null
				render()
			} catch (error) { notifyError(error.message) }
		})
		document.querySelectorAll('[data-setup-decision]').forEach((select) => select.addEventListener('change', () => { p.decisions[select.dataset.setupDecision] = select.value }))
		document.getElementById('setup-discard')?.addEventListener('click', () => { p.preview = null; p.file = null; p.decisions = {}; render() })
		document.getElementById('setup-apply')?.addEventListener('click', async () => {
			if (!p.file || !p.preview) return
			const decisions = Object.fromEntries(p.preview.items.map((item) => [String(item.index), p.decisions[item.index] || item.defaultAction]))
			const changes = p.preview.items.filter((item) => decisions[item.index] !== 'SKIP').length
			if (!await confirmAction(`${changes} Eintrag/Einträge einspielen? Vorher wird automatisch eine Sicherung der App-Daten angelegt.`, { title: 'Einrichtungspaket einspielen', confirmLabel: 'Einspielen' })) return
			try {
				const body = new FormData(); body.append('file', p.file); body.append('decisions', JSON.stringify(decisions))
				p.result = await api(`${apiBase}/setup-package/apply`, { method: 'POST', body, feedback: false })
				p.preview = null; p.file = null; p.decisions = {}
				if (p.result.summary.failed) notifyWarning(`${p.result.summary.failed} Eintrag/Einträge konnten nicht eingespielt werden – Details unten.`)
				else notifySuccess('Einrichtungspaket eingespielt.')
				await loadBranchLogos(); render()
			} catch (error) { notifyError(error.message) }
		})
	}

	return { loadBranchLogos, branchLogoBlock, bindBranchLogos, setupPackageView, bindSetupPackage }
}
