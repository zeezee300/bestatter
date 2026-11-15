/**
 * 0.90.0 – Bereich „Buchhaltung“: Export der Rechnungsdaten als DATEV-Buchungsstapel.
 * Reiter Export (Zeitraum → Vorschau mit Prüfliste → Export), Exportprotokoll (Wiederholungs- und Nachexport), Debitoren, Einstellungen.
 * Sichtbar für Bestatter-Administratoren und die Rollengruppe „Buchhaltung“ (team.canUseAccounting).
 */
export const ACCOUNTING_TABS = [['export', 'Export'], ['protocol', 'Exportprotokoll'], ['debtors', 'Debitoren'], ['settings', 'Einstellungen']]
const SEVERITY_LABELS = { ERROR: 'Fehler', WARNING: 'Warnung', INFO: 'Hinweis' }
const KIND_LABELS = { INVOICE: 'Rechnung', CANCELLATION: 'Stornorechnung', CREDIT_NOTE: 'Gutschrift' }
const SUGGEST_LABELS = { SKR03: 'Vorschlag für SKR03 eintragen', SKR04: 'Vorschlag für SKR04 eintragen' }

export function money(cents) {
	return `${(Number(cents || 0) / 100).toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} €`
}

export function germanDate(value) {
	const match = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})/)
	return match ? `${match[3]}.${match[2]}.${match[1]}` : ''
}

/** Vorbelegung: Vormonat (Monatserster bis Monatsletzter). */
export function previousMonth(today = new Date()) {
	const first = new Date(today.getFullYear(), today.getMonth() - 1, 1)
	const last = new Date(today.getFullYear(), today.getMonth(), 0)
	const iso = (date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
	return { from: iso(first), to: iso(last) }
}

/** Formularwerte der Einstellungen einsammeln (Kontenzuordnung zeilenweise). */
export function readSettingsForm(form) {
	const value = (name) => form.querySelector(`[name="${name}"]`)?.value ?? ''
	const settings = {
		consultantNumber: value('consultantNumber'), clientNumber: value('clientNumber'), fiscalYearStart: value('fiscalYearStart'), accountLength: value('accountLength'),
		chart: value('chart'), origin: value('origin'), lockBookings: Boolean(form.querySelector('[name="lockBookings"]')?.checked), debtorMode: value('debtorMode'),
		debtorStart: value('debtorStart'), collectiveDebtor: value('collectiveDebtor'), passThroughAccount: value('passThroughAccount'), kost1: value('kost1'), kost2: value('kost2'),
		exportFolder: value('exportFolder'), costCenters: {}, rules: [],
	}
	form.querySelectorAll('[data-cost-center]').forEach((input) => { if (input.value.trim()) settings.costCenters[input.dataset.costCenter] = input.value.trim() })
	form.querySelectorAll('[data-rule-row]').forEach((row) => {
		const field = (name) => row.querySelector(`[data-rule="${name}"]`)
		settings.rules.push({ label: field('label').value, area: field('area').value, group: field('group').value, articleType: field('articleType').value, passThrough: field('passThrough').checked, vatRate: field('vatRate').value, account: field('account').value, taxKey: field('taxKey').value })
	})
	return settings
}

export function createAccountingModule(ctx) {
	const { state, esc, api, apiBase, render, notifySuccess, notifyError, confirmAction, download } = ctx
	const base = `${apiBase}/accounting`
	const acc = () => (state.accounting ||= { tab: 'export', overview: null, form: { ...previousMonth(), includeLate: true }, preview: null, selection: null, details: {}, debtors: null, settingsDraft: null })

	async function loadAccounting() {
		const data = await api(base, { feedback: false, loadingLabel: 'Buchhaltung wird geladen …' })
		acc().overview = data
		if (!acc().settingsDraft) acc().settingsDraft = data.configuration?.settings || null
		if (acc().tab === 'debtors') await loadDebtors()
	}

	async function loadDebtors() {
		acc().debtors = (await api(`${base}/debtors`, { feedback: false })).debtors || []
	}

	const notice = (messages) => messages.length ? `<aside class="bp-warning"><b>Vor dem ersten Export einzurichten:</b><ul>${messages.map((m) => `<li>${esc(m)}</li>`).join('')}</ul><button type="button" class="bp-link-button" data-accounting-tab="settings">Zu den Einstellungen</button></aside>` : ''

	function messagesTable(messages) {
		if (!messages?.length) return '<p class="bp-muted">Keine Meldungen – die Prüfliste ist leer.</p>'
		return `<div class="bp-table-scroll"><table class="bp-report-table bp-accounting-messages"><thead><tr><th>Prüfung</th><th>Art</th><th>Beleg</th><th>Meldung</th></tr></thead><tbody>${messages.map((m) => `<tr class="bp-severity-${esc(String(m.severity).toLowerCase())}"><td>${esc(m.code)}</td><td>${esc(SEVERITY_LABELS[m.severity] || m.severity)}</td><td>${esc(m.invoice || '–')}</td><td>${esc(m.message)}</td></tr>`).join('')}</tbody></table></div>`
	}

	function previewView(preview) {
		const s = preview.summary || {}
		const errors = (preview.messages || []).filter((m) => m.severity === 'ERROR').length
		const warnings = (preview.messages || []).filter((m) => m.severity === 'WARNING').length
		const cards = [
			['Belege', s.invoiceCount], ['Buchungszeilen', s.lineCount], ['Soll', money(s.debitCents)], ['Haben', money(s.creditCents)],
			['Neue Debitoren', s.newDebtors], ['Zeitraum im Stapel', `${germanDate(preview.period?.from)} – ${germanDate(preview.period?.to)}`],
		].map(([label, value]) => `<article><p class="bp-eyebrow">${esc(label)}</p><strong>${esc(String(value ?? 0))}</strong></article>`).join('')
		const invoices = (preview.invoices || []).map((invoice) => `<tr><td>${esc(invoice.number)}${invoice.late ? ' <span class="bp-badge">Nachzügler</span>' : ''}</td><td>${esc(KIND_LABELS[invoice.kind] || invoice.kind)}</td><td>${esc(germanDate(invoice.date))}</td><td>${esc(invoice.caseNumber)}</td><td>${esc(String(invoice.debtor || '–'))}${invoice.debtorNew ? ' <span class="bp-badge">neu</span>' : ''}</td><td class="bp-number">${esc(money(invoice.totals?.gross))}</td><td class="bp-number">${esc(String(invoice.lineCount ?? 0))}</td></tr>`).join('')
		const lines = (preview.lines || []).map((line) => `<tr><td class="bp-number">${esc(money(line.amountCents))}</td><td>${esc(line.side)}</td><td>${esc(line.account)}</td><td>${esc(line.contraAccount)}</td><td>${esc(line.taxKey || '')}</td><td>${esc(germanDate(line.date))}</td><td>${esc(line.documentNo)}</td><td>${esc(line.text)}</td></tr>`).join('')
		const excluded = (preview.excluded || []).length ? `<p class="bp-muted">Nicht enthalten: ${preview.excluded.map((entry) => `${esc(entry.number)} (${esc(entry.reason)})`).join(', ')}</p>` : ''
		const reasonField = preview.kind === 'REEXPORT' ? `<label class="bp-wide"><span>Begründung für den Nachexport</span><textarea id="accounting-reason" rows="2" placeholder="z. B. Stapel von der Steuerberatung verworfen">${esc(acc().selection?.reason || '')}</textarea></label>` : ''
		return `<section class="bp-panel bp-accounting-preview"><div class="bp-panel-head"><div><h3>Vorschau und Prüfliste</h3><p class="bp-muted">${errors ? `${errors} Fehler verhindern den Export.` : 'Keine Fehler.'}${warnings ? ` ${warnings} Warnung(en) bitte prüfen.` : ''}</p></div></div>
			<div class="bp-report-grid">${cards}</div>
			<h4>Prüfliste</h4>${messagesTable(preview.messages)}
			<h4>Belege</h4><div class="bp-table-scroll"><table class="bp-report-table"><thead><tr><th>Beleg</th><th>Art</th><th>Belegdatum</th><th>Fall</th><th>Debitor</th><th>Betrag</th><th>Zeilen</th></tr></thead><tbody>${invoices || '<tr><td colspan="7" class="bp-empty">Keine Belege.</td></tr>'}</tbody></table></div>${excluded}
			<details><summary>Buchungszeilen anzeigen${preview.linesTruncated ? ' (die ersten 200)' : ''}</summary><div class="bp-table-scroll"><table class="bp-report-table"><thead><tr><th>Umsatz</th><th>S/H</th><th>Konto</th><th>Gegenkonto</th><th>BU</th><th>Belegdatum</th><th>Belegfeld 1</th><th>Buchungstext</th></tr></thead><tbody>${lines}</tbody></table></div></details>
			<div class="bp-form-grid">${reasonField}</div>
			<div class="bp-row-actions"><button type="button" class="bp-primary" id="accounting-export" ${preview.blocked && !(preview.kind === 'REEXPORT' && errors === 1) ? 'disabled' : ''}>Export erstellen und herunterladen</button><button type="button" class="bp-secondary" id="accounting-preview-close">Vorschau schließen</button></div></section>`
	}

	function exportTab() {
		const a = acc()
		const o = a.overview || {}
		const open = o.open || {}
		const form = a.form
		const selection = a.selection ? `<p class="bp-muted"><b>Nachexport ausgewählter Belege:</b> ${esc(a.selection.numbers.join(', '))} <button type="button" class="bp-link-button" id="accounting-selection-clear">Auswahl aufheben</button></p>` : ''
		return `${notice(o.problems || [])}
			<section class="bp-panel"><div class="bp-panel-head"><div><h3>Rechnungsdaten exportieren</h3><p class="bp-muted">Exportiert werden freigegebene Rechnungen, Stornorechnungen und Gutschriften, die noch in keinem Export enthalten waren. Belegdatum ist das Rechnungsdatum. Noch nicht exportiert: <b>${esc(String(open.count || 0))}</b> Beleg(e)${open.count ? ` vom ${esc(germanDate(open.from))} bis ${esc(germanDate(open.to))}, zusammen ${esc(money(open.grossCents))}` : ''}.</p></div></div>
			${selection}
			<form id="accounting-form" class="bp-form-grid">
				<label><span>Rechnungsdatum von</span><input type="date" name="from" value="${esc(form.from)}" ${a.selection ? 'disabled' : ''}></label>
				<label><span>Rechnungsdatum bis</span><input type="date" name="to" value="${esc(form.to)}" ${a.selection ? 'disabled' : ''}></label>
				<label class="bp-inline-check"><input type="checkbox" name="includeLate" ${form.includeLate ? 'checked' : ''} ${a.selection ? 'disabled' : ''}> Nachzügler einbeziehen (ältere, noch nicht exportierte Belege desselben Wirtschaftsjahres)</label>
				<div class="bp-row-actions"><button class="bp-primary" id="accounting-preview">Vorschau und Prüfliste</button></div>
			</form></section>
			${a.preview ? previewView(a.preview) : ''}`
	}

	function protocolTab() {
		const exports = acc().overview?.exports || []
		const rows = exports.map((entry) => {
			const details = acc().details[entry.id]
			const detailRow = details ? `<tr class="bp-accounting-details"><td colspan="9"><p class="bp-muted">Datei: ${esc(entry.fileName)} · SHA-256 ${esc(entry.sha256)} · Format ${esc(entry.formatVersion)}${entry.filePath ? ` · abgelegt unter „${esc(entry.filePath)}“ (eigener Nextcloud-Ordner)` : ''}${entry.reason ? ` · Begründung: ${esc(entry.reason)}` : ''}</p>
				<table class="bp-report-table"><thead><tr><th></th><th>Beleg</th><th>Debitor</th><th>Betrag</th><th>Zeilen</th></tr></thead><tbody>${details.invoices.map((invoice) => `<tr><td><input type="checkbox" data-reexport="${esc(String(invoice.invoiceId))}" data-reexport-number="${esc(invoice.number)}" aria-label="${esc(invoice.number)} für Nachexport auswählen"></td><td>${esc(invoice.number)}</td><td>${esc(String(invoice.debtor ?? '–'))}</td><td class="bp-number">${esc(money(invoice.grossCents))}</td><td class="bp-number">${esc(String(invoice.lines))}</td></tr>`).join('')}</tbody></table>
				${entry.messages?.length ? `<details><summary>Meldungen beim Export (${entry.messages.length})</summary>${messagesTable(entry.messages)}</details>` : ''}
				<div class="bp-row-actions"><button type="button" class="bp-secondary" data-reexport-start="${esc(String(entry.id))}">Ausgewählte Belege erneut exportieren (Nachexport) …</button></div></td></tr>` : ''
			return `<tr><td>${esc(entry.number)}</td><td>${esc(entry.kindLabel)}</td><td>${esc(germanDate(entry.from))} – ${esc(germanDate(entry.to))}</td><td class="bp-number">${esc(String(entry.invoiceCount))}</td><td class="bp-number">${esc(String(entry.lineCount))}</td><td class="bp-number">${esc(money(entry.debitCents))}</td><td class="bp-number">${esc(money(entry.creditCents))}</td><td>${esc(entry.createdBy)}<br><small>${esc(germanDate(entry.createdAt))}</small>${entry.warnings ? `<br><small>${esc(String(entry.warnings))} Warnung(en)</small>` : ''}</td><td><div class="bp-row-actions"><button type="button" class="bp-secondary" data-accounting-download="${esc(String(entry.id))}" data-file-name="${esc(entry.fileName)}">Datei erneut herunterladen</button><button type="button" class="bp-link-button" data-accounting-details="${esc(String(entry.id))}">${details ? 'Belege ausblenden' : 'Belege'}</button></div></td></tr>${detailRow}`
		}).join('')
		return `<section class="bp-panel"><div class="bp-panel-head"><div><h3>Exportprotokoll</h3><p class="bp-muted">Jeder Export mit Zeitraum, Anzahl, Summen, Datei und Prüfsumme. „Datei erneut herunterladen“ liefert genau dieselbe Datei (Wiederholungsexport). Einzelne Belege lassen sich mit Begründung erneut exportieren (Nachexport).</p></div></div>
			<div class="bp-table-scroll"><table class="bp-report-table"><thead><tr><th>Export</th><th>Art</th><th>Zeitraum</th><th>Belege</th><th>Zeilen</th><th>Soll</th><th>Haben</th><th>Erstellt</th><th></th></tr></thead><tbody>${rows || '<tr><td colspan="9" class="bp-empty">Noch kein Export.</td></tr>'}</tbody></table></div></section>`
	}

	function debtorsTab() {
		const debtors = acc().debtors || []
		const rows = debtors.map((debtor) => `<tr><td>${esc(String(debtor.number))}</td><td>${esc(debtor.firstInvoice)}</td><td>${esc(germanDate(debtor.createdAt))}</td><td class="bp-number">${esc(String(debtor.exportedDocuments))}</td></tr>`).join('')
		return `<section class="bp-panel"><div class="bp-panel-head"><div><h3>Debitoren</h3><p class="bp-muted">Jeder Rechnungsempfänger erhält beim ersten Export eine Debitorennummer (gleicher Name und gleiche Anschrift bzw. gleiche USt-IdNr. = gleicher Debitor). Die App speichert dazu keine Namen. Die Debitorenliste enthält Name und Anschrift aus dem jeweils letzten exportierten Beleg – zum Anlegen der Personenkonten in DATEV.</p></div><button type="button" class="bp-secondary" id="accounting-debtors-csv">Debitorenliste (CSV) herunterladen</button></div>
			<div class="bp-table-scroll"><table class="bp-report-table"><thead><tr><th>Debitor</th><th>Erster Beleg</th><th>Angelegt</th><th>Exportierte Belege</th></tr></thead><tbody>${rows || '<tr><td colspan="4" class="bp-empty">Noch keine Debitoren – sie entstehen beim ersten Export.</td></tr>'}</tbody></table></div></section>`
	}

	function settingsTab() {
		const configuration = acc().overview?.configuration || {}
		const s = acc().settingsDraft || configuration.settings || {}
		const options = (map, selected) => Object.entries(map || {}).map(([key, label]) => `<option value="${esc(key)}" ${key === selected ? 'selected' : ''}>${esc(label)}</option>`).join('')
		const fiscal = /^(\d{2})-(\d{2})$/.test(s.fiscalYearStart || '') ? `${s.fiscalYearStart.slice(3)}.${s.fiscalYearStart.slice(0, 2)}.` : (s.fiscalYearStart || '')
		const groups = (state.catalogGroups || []).map((group) => `<option value="${esc(group.key || group.groupKey || '')}">${esc(group.name || group.groupName || '')}</option>`).join('')
		const areas = (state.catalogAreas || []).map((area) => `<option value="${esc(area.key || area.areaKey || '')}">${esc(area.name || '')}</option>`).join('')
		const typeOptions = (selected) => (configuration.articleTypes || []).map((type) => `<option value="${esc(type)}" ${type === selected ? 'selected' : ''}>${esc(type)}</option>`).join('')
		const ruleRow = (rule = {}) => `<tr data-rule-row><td><input data-rule="label" value="${esc(rule.label || '')}" maxlength="60" aria-label="Bezeichnung"></td><td><input data-rule="area" value="${esc(rule.area || '')}" list="accounting-areas" size="4" aria-label="Bereich"></td><td><input data-rule="group" value="${esc(rule.group || '')}" list="accounting-groups" size="5" aria-label="Warengruppe"></td><td><select data-rule="articleType" aria-label="Artikelart"><option value="">jede</option>${typeOptions(rule.articleType || '')}</select></td><td><input type="checkbox" data-rule="passThrough" ${rule.passThrough ? 'checked' : ''} aria-label="Durchlaufender Posten"></td><td><input data-rule="vatRate" value="${esc(rule.vatRate ?? '')}" size="3" inputmode="numeric" aria-label="Steuersatz" placeholder="jeder"></td><td><input data-rule="account" value="${esc(rule.account || '')}" size="8" inputmode="numeric" aria-label="Konto"></td><td><input data-rule="taxKey" value="${esc(rule.taxKey || '')}" size="6" inputmode="numeric" aria-label="BU-Schlüssel" placeholder="leer"></td><td><button type="button" class="bp-icon-button" data-rule-remove title="Zuordnung entfernen" aria-label="Zuordnung entfernen">×</button></td></tr>`
		const costCenters = (state.branches || []).map((branch) => `<label><span>Kostenstelle ${esc(branch.name)}</span><input data-cost-center="${esc(branch.key)}" value="${esc((s.costCenters || {})[branch.key] || '')}" placeholder="${esc(branch.key)}" maxlength="36"></label>`).join('')
		const problems = configuration.problems || acc().overview?.problems || []
		return `${problems.length ? `<aside class="bp-warning"><b>Noch offen:</b><ul>${problems.map((p) => `<li>${esc(p)}</li>`).join('')}</ul></aside>` : '<aside class="bp-compliance-note">Die Einstellungen sind vollständig. Bitte Konten, Steuerschlüssel und Debitorenlogik vor dem ersten produktiven Export mit der Steuerberatung abstimmen und einen Probeimport in DATEV durchführen.</aside>'}
			<form id="accounting-settings" class="bp-panel">
				<h3>DATEV-Mandant</h3>
				<div class="bp-form-grid">
					<label><span>Beraternummer</span><input name="consultantNumber" value="${esc(s.consultantNumber || '')}" inputmode="numeric" maxlength="7"></label>
					<label><span>Mandantennummer</span><input name="clientNumber" value="${esc(s.clientNumber || '')}" inputmode="numeric" maxlength="5"></label>
					<label><span>Beginn Wirtschaftsjahr (TT.MM.)</span><input name="fiscalYearStart" value="${esc(fiscal)}" placeholder="01.01." maxlength="6"></label>
					<label><span>Sachkontenlänge</span><select name="accountLength">${[4, 5, 6, 7, 8].map((n) => `<option value="${n}" ${Number(s.accountLength || 4) === n ? 'selected' : ''}>${n}</option>`).join('')}</select></label>
					<label><span>Kontenrahmen</span><select name="chart"><option value="">bitte wählen</option>${options(configuration.charts, s.chart)}</select></label>
					<label><span>Herkunftskürzel</span><input name="origin" value="${esc(s.origin || 'RE')}" maxlength="2"></label>
					<label class="bp-inline-check"><input type="checkbox" name="lockBookings" ${s.lockBookings ? 'checked' : ''}> Buchungen beim Import in DATEV festschreiben</label>
				</div>
				<h3>Debitoren und Kostenstellen</h3>
				<div class="bp-form-grid">
					<label><span>Debitoren</span><select name="debtorMode">${options(configuration.debtorModes, s.debtorMode)}</select></label>
					<label><span>Debitorennummern ab</span><input name="debtorStart" value="${esc(String(s.debtorStart ?? 10000))}" inputmode="numeric"></label>
					<label><span>Sammeldebitor</span><input name="collectiveDebtor" value="${esc(s.collectiveDebtor || '')}" inputmode="numeric" placeholder="nur bei Sammeldebitor"></label>
					<label><span>KOST1 (Kostenstelle)</span><select name="kost1">${options(configuration.kost1Sources, s.kost1)}</select></label>
					<label><span>KOST2 (Kostenträger)</span><select name="kost2">${options(configuration.kost2Sources, s.kost2)}</select></label>
					${costCenters}
				</div>
				<h3>Kontenzuordnung</h3>
				<p class="bp-muted">Die speziellste passende Zeile gilt: Warengruppe vor Bereich vor Artikelart vor einer allgemeinen Zeile; mit passendem Steuersatz vor „jeder“. Echte durchlaufende Posten (Positionsart DP) nutzen nur Zeilen mit „durchl. Posten“, sonst das Konto darunter. BU-Schlüssel leer lassen, wenn das Konto ein Automatikkonto ist.</p>
				<div class="bp-row-actions"><button type="button" class="bp-secondary" id="accounting-suggest" ${s.chart ? '' : 'disabled'}>${esc(SUGGEST_LABELS[s.chart] || 'Vorschlag (erst Kontenrahmen wählen)')}</button></div>
				<div class="bp-table-scroll"><table class="bp-report-table bp-accounting-rules"><thead><tr><th>Bezeichnung (Buchungstext)</th><th>Bereich</th><th>Warengruppe</th><th>Artikelart</th><th>durchl. Posten</th><th>Steuersatz %</th><th>Konto</th><th>BU-Schlüssel</th><th></th></tr></thead><tbody id="accounting-rule-rows">${(s.rules || []).map(ruleRow).join('')}</tbody></table></div>
				<template id="accounting-rule-template">${ruleRow()}</template>
				<datalist id="accounting-groups">${groups}</datalist><datalist id="accounting-areas">${areas}</datalist>
				<div class="bp-row-actions"><button type="button" class="bp-secondary" id="accounting-rule-add">Zuordnung hinzufügen</button></div>
				<div class="bp-form-grid">
					<label><span>Konto für durchlaufende Posten</span><input name="passThroughAccount" value="${esc(s.passThroughAccount || '')}" inputmode="numeric"></label>
					<label><span>Ablage im eigenen Nextcloud-Ordner</span><input name="exportFolder" value="${esc(s.exportFolder || '')}" placeholder="leer = keine Ablage"></label>
				</div>
				<div class="bp-row-actions"><button class="bp-primary" id="accounting-settings-save">Einstellungen speichern</button></div>
			</form>`
	}

	function accountingView() {
		const tab = acc().tab
		const tabs = `<div class="bp-subtabs"><div>${ACCOUNTING_TABS.map(([key, label]) => `<button type="button" data-accounting-tab="${key}" ${tab === key ? 'class="active"' : ''}>${esc(label)}</button>`).join('')}</div></div>`
		const body = { export: exportTab, protocol: protocolTab, debtors: debtorsTab, settings: settingsTab }[tab] || exportTab
		return `${tabs}<div class="bp-page-head"><div><p class="bp-eyebrow">Buchhaltung</p><h2>Buchhaltungsexport (DATEV)</h2><p class="bp-muted">Rechnungen, Stornorechnungen und Gutschriften als Buchungsstapel im DATEV-Format für die Steuerberatung.</p><p class="bp-muted bp-accounting-format" title="Dateiformat des Exports">Dateiformat: DATEV-Format (EXTF), Buchungsstapel, Formatversion ${esc(String(acc().overview?.formatVersion || 13))}. DATEV ist eine eingetragene Marke der DATEV eG. Diese App ist kein Produkt der DATEV eG; die Schnittstelle wurde nicht von DATEV geprüft.</p></div></div>${acc().overview ? body() : '<p class="bp-muted">Wird geladen …</p>'}`
	}

	async function runPreview() {
		const a = acc()
		const body = a.selection ? { invoiceIds: a.selection.ids, reason: a.selection.reason || '' } : { from: a.form.from, to: a.form.to, includeLate: a.form.includeLate }
		a.preview = await api(`${base}/preview`, { method: 'POST', feedback: false, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body), loadingLabel: 'Prüfliste wird erstellt …' })
	}

	function bindAccounting() {
		const root = ctx.root
		root.querySelectorAll('[data-accounting-tab]').forEach((button) => button.addEventListener('click', async () => {
			acc().tab = button.dataset.accountingTab
			try { if (acc().tab === 'debtors') await loadDebtors() } catch (error) { notifyError(error.message) }
			render()
		}))
		const form = root.querySelector('#accounting-form')
		form?.addEventListener('submit', async (event) => {
			event.preventDefault()
			const a = acc()
			if (!a.selection) a.form = { from: form.from.value, to: form.to.value, includeLate: form.includeLate.checked }
			try { await runPreview() } catch (error) { notifyError(error.message) }
			render()
		})
		root.querySelector('#accounting-preview-close')?.addEventListener('click', () => { acc().preview = null; render() })
		root.querySelector('#accounting-selection-clear')?.addEventListener('click', () => { acc().selection = null; acc().preview = null; render() })
		root.querySelector('#accounting-reason')?.addEventListener('change', async (event) => {
			acc().selection.reason = event.target.value.trim()
			try { await runPreview() } catch (error) { notifyError(error.message) }
			render()
		})
		root.querySelector('#accounting-export')?.addEventListener('click', async () => {
			const a = acc()
			const reasonInput = root.querySelector('#accounting-reason')
			if (reasonInput) a.selection.reason = reasonInput.value.trim()
			const s = a.preview?.summary || {}
			if (!await confirmAction(`${s.invoiceCount} Beleg(e) mit ${s.lineCount} Buchungszeile(n) exportieren? Die Belege gelten danach als exportiert; ${s.newDebtors ? `${s.newDebtors} neue Debitorennummer(n) werden vergeben.` : 'Debitorennummern bleiben unverändert.'}`, { title: 'DATEV-Export erstellen', confirmLabel: 'Exportieren' })) return
			try {
				const body = a.selection ? { invoiceIds: a.selection.ids, reason: a.selection.reason || '' } : { from: a.form.from, to: a.form.to, includeLate: a.form.includeLate }
				const result = await api(`${base}/exports`, { method: 'POST', feedback: false, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body), loadingLabel: 'Export wird erstellt …' })
				notifySuccess(`${result.number} erstellt: ${result.invoiceCount} Beleg(e), ${result.lineCount} Buchungszeile(n).${result.storedAt ? ` Abgelegt unter „${result.storedAt}“.` : ''}`)
				await download(`${base}/exports/${result.id}/download`, result.fileName)
				a.preview = null; a.selection = null
				await loadAccounting()
			} catch (error) { notifyError(error.message) }
			render()
		})
		root.querySelectorAll('[data-accounting-download]').forEach((button) => button.addEventListener('click', () => download(`${base}/exports/${button.dataset.accountingDownload}/download`, button.dataset.fileName).catch((error) => notifyError(error.message))))
		root.querySelectorAll('[data-accounting-details]').forEach((button) => button.addEventListener('click', async () => {
			const id = button.dataset.accountingDetails
			try { acc().details[id] = acc().details[id] ? null : await api(`${base}/exports/${id}`, { feedback: false }) } catch (error) { notifyError(error.message) }
			render()
		}))
		root.querySelectorAll('[data-reexport-start]').forEach((button) => button.addEventListener('click', async () => {
			const checked = [...button.closest('td').querySelectorAll('[data-reexport]:checked')]
			if (!checked.length) { notifyError('Bitte mindestens einen Beleg auswählen.'); return }
			acc().selection = { ids: checked.map((input) => Number(input.dataset.reexport)), numbers: checked.map((input) => input.dataset.reexportNumber), reason: '' }
			acc().tab = 'export'
			try { await runPreview() } catch (error) { notifyError(error.message) }
			render()
		}))
		root.querySelector('#accounting-debtors-csv')?.addEventListener('click', () => download(`${base}/debtors.csv`, 'Debitoren.csv').catch((error) => notifyError(error.message)))
		const settingsForm = root.querySelector('#accounting-settings')
		if (settingsForm) {
			const keepDraft = () => { acc().settingsDraft = readSettingsForm(settingsForm) }
			settingsForm.addEventListener('change', keepDraft)
			settingsForm.querySelector('[name="chart"]')?.addEventListener('change', () => { keepDraft(); render() })
			settingsForm.querySelector('#accounting-rule-add')?.addEventListener('click', () => {
				const template = settingsForm.querySelector('#accounting-rule-template')
				settingsForm.querySelector('#accounting-rule-rows').insertAdjacentHTML('beforeend', template.innerHTML)
				keepDraft(); render()
			})
			settingsForm.querySelectorAll('[data-rule-remove]').forEach((button) => button.addEventListener('click', () => { button.closest('tr').remove(); keepDraft(); render() }))
			settingsForm.querySelector('#accounting-suggest')?.addEventListener('click', async () => {
				const draft = readSettingsForm(settingsForm)
				const suggestion = acc().overview?.configuration?.suggestions?.[draft.chart]
				if (!suggestion) return
				if (draft.rules.length && !await confirmAction(`Die vorhandenen ${draft.rules.length} Kontenzuordnung(en) durch den Vorschlag für ${draft.chart} ersetzen?`, { title: 'Vorschlag eintragen', confirmLabel: 'Ersetzen' })) return
				acc().settingsDraft = { ...draft, passThroughAccount: suggestion.passThroughAccount, rules: suggestion.rules.map((rule) => ({ ...rule })) }
				render()
			})
			settingsForm.addEventListener('submit', async (event) => {
				event.preventDefault()
				try {
					const result = await api(`${base}/settings`, { method: 'PUT', feedback: 'Einstellungen gespeichert.', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ settings: readSettingsForm(settingsForm) }) })
					acc().settingsDraft = result.settings
					await loadAccounting()
				} catch (error) { notifyError(error.message) }
				render()
			})
		}
	}

	return { accountingView, bindAccounting, loadAccounting }
}
