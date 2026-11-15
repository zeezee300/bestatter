import { handbookMoreLink } from './handbook.js'
// 0.89.5: Customizing → Fremdkosten: Abweichungsschwelle für Schätzbeträge und Liste der typischen Fremdkosten
// (Artikel, Geltungsbereich, Bedingung, Menge). Im Fall übernimmt „Typische Fremdkosten übernehmen“ die fehlenden Positionen.
import { priceBasisLabel } from './price-basis.js'

export function createTypicalCostsModule(ctx) {
	const { root, apiBase, state } = ctx
	const api = (...args) => ctx.api(...args)
	const esc = (...args) => ctx.esc(...args)
	const render = (...args) => ctx.render(...args)
	const url = () => `${apiBase}/typical-costs`

	async function load() {
		try { state.typicalCostSettings = await api(url(), { feedback: false, loadingLabel: false }) } catch (error) { state.typicalCostSettings = { entries: [], error: error.message } }
		return state.typicalCostSettings
	}

	/** Vorschläge für den geöffneten Fall (Reiter Leistungen); Fehler bleiben still, der Knopf erscheint dann nicht. */
	async function loadForCase(caseId) {
		if (!caseId) { state.caseTypicalCosts = null; return null }
		try { state.caseTypicalCosts = { caseId: Number(caseId), ...(await api(`${apiBase}/cases/${Number(caseId)}/typical-costs`, { feedback: false, loadingLabel: false })) } } catch { state.caseTypicalCosts = null }
		return state.caseTypicalCosts
	}

	const options = (entries, selected) => entries.map(([value, label]) => `<option value="${esc(value)}" ${value === selected ? 'selected' : ''}>${esc(label)}</option>`).join('')

	function row(entry, settings, disabled) {
		const articles = settings.articles || []
		const articleOptions = articles.map((article) => [article.articleNumber, `${article.articleNumber} · ${article.title}${article.active ? '' : ' (inaktiv)'}`])
		if (entry.article && !articles.some((article) => article.articleNumber === entry.article)) articleOptions.unshift([entry.article, `${entry.article} · nicht im Leistungskatalog`])
		const hint = !entry.article ? '' : entry.found === false ? '<small class="bp-warning">Nicht im Leistungskatalog – ohne Wirkung.</small>' : entry.active === false ? '<small class="bp-warning">Artikel ist inaktiv – ohne Wirkung.</small>' : entry.priceBasis ? `<small class="bp-muted">${esc(priceBasisLabel(entry.priceBasis))}</small>` : ''
		return `<tr data-typical-cost-row>
			<td><select data-typical-field="article" aria-label="Artikel" ${disabled}><option value="">Artikel wählen</option>${options(articleOptions, entry.article || '')}</select>${hint}</td>
			<td><select data-typical-field="scope" aria-label="Gilt für" ${disabled}>${options(Object.entries(settings.scopes || {}), entry.scope || 'ALL')}</select></td>
			<td><select data-typical-field="condition" aria-label="Bedingung" ${disabled}>${options(Object.entries(settings.conditions || {}), entry.condition || 'ALWAYS')}</select></td>
			<td><select data-typical-field="quantity" aria-label="Menge" ${disabled}>${options(Object.entries(settings.quantities || {}), entry.quantity || 'ONE')}</select></td>
			<td>${disabled ? '' : '<button type="button" class="bp-secondary" data-typical-remove>Entfernen</button>'}</td>
		</tr>`
	}

	function view() {
		const settings = state.typicalCostSettings
		if (!settings) return '<section class="bp-panel"><p class="bp-muted">Fremdkosten werden geladen …</p></section>'
		if (settings.error) return `<section class="bp-panel"><p class="bp-warning">${esc(settings.error)}</p></section>`
		const disabled = settings.canEdit ? '' : 'disabled'
		return `<section class="bp-panel bp-typical-costs" data-typical-costs>
			<div class="bp-panel-head"><div><h3>Fremdkosten</h3><p class="bp-muted">Schätzbeträge und Gebühren nach Bescheid im Bestattungsauftrag sowie die Fremdkosten, die bei fast jedem Fall anfallen. Die Preisart eines Artikels (Festpreis, Schätzbetrag, nach Bescheid) steht im Leistungskatalog.${handbookMoreLink('customizing-fremdkosten', esc)}</p></div></div>
			${settings.canEdit ? '' : '<p class="bp-muted">Nur Bestatter-Administratoren können die Fremdkosten ändern.</p>'}
			<article class="bp-typical-threshold"><h4>Schätzbeträge im Auftrag</h4><p class="bp-muted">Abschnitt 2 des Auftrags nennt die Schwelle, ab der das Bestattungsunternehmen eine Überschreitung vorab mitteilt („Zeichnet sich ab, dass ein Schätzbetrag um mehr als … % überschritten wird …“). 0 % bedeutet: jede Überschreitung.</p>
				<label><span>Abweichungsschwelle in %</span><input type="number" min="0" max="100" step="1" data-typical-threshold value="${Number(settings.deviationPercent ?? 10)}" ${disabled}></label></article>
			<article><h4>Typische Fremdkosten</h4><p class="bp-muted">Im Reiter „Leistungen“ übernimmt „Typische Fremdkosten übernehmen“ alle passenden Positionen, die im Fall noch fehlen – mit Richtwert als Schätzbetrag bzw. „nach Bescheid“. Vor dem Festschreiben erscheint ein Hinweis, wenn eine fehlt. „Gilt für“ eine Bestattungsvariante schließt ihre Untervarianten ein.${settings.isDefault ? ' Noch nicht eingerichtet – die Liste kommt mit dem Einrichtungspaket oder wird hier angelegt.' : ''}</p>
				<div class="bp-table-scroll"><table class="bp-typical-table"><thead><tr><th>Artikel</th><th>Gilt für</th><th>Bedingung</th><th>Menge</th><th></th></tr></thead><tbody>${(settings.entries || []).map((entry) => row(entry, settings, disabled)).join('') || `<tr data-typical-empty><td colspan="5" class="bp-empty">Keine typischen Fremdkosten eingerichtet.</td></tr>`}</tbody></table></div>
				<p class="bp-card-state" data-typical-state aria-live="polite"></p>
				${settings.canEdit ? `<div class="bp-row-actions"><button type="button" class="bp-secondary" data-typical-add>Zeile hinzufügen</button><button type="button" class="bp-primary" data-typical-save>Speichern</button></div>` : ''}
			</article>
		</section>`
	}

	function bind() {
		if (state.view !== 'customizing' || state.customizingTab !== 'typical-costs') return
		if (!state.typicalCostSettings) { load().then(() => { if (state.view === 'customizing' && state.customizingTab === 'typical-costs') render() }); return }
		const panel = root.querySelector('[data-typical-costs]')
		if (!panel) return
		const message = panel.querySelector('[data-typical-state]')
		const collect = () => [...panel.querySelectorAll('[data-typical-cost-row]')].map((tr) => Object.fromEntries([...tr.querySelectorAll('[data-typical-field]')].map((field) => [field.dataset.typicalField, field.value])))
		const bindRemove = () => panel.querySelectorAll('[data-typical-remove]').forEach((button) => { button.onclick = () => button.closest('tr').remove() })
		bindRemove()
		panel.querySelector('[data-typical-add]')?.addEventListener('click', () => {
			panel.querySelector('[data-typical-empty]')?.remove()
			panel.querySelector('.bp-typical-table tbody').insertAdjacentHTML('beforeend', row({ article: '', scope: 'ALL', condition: 'ALWAYS', quantity: 'ONE' }, state.typicalCostSettings, ''))
			bindRemove()
		})
		const store = async (payload, success) => {
			try {
				state.typicalCostSettings = await api(url(), { method: 'PUT', feedback: false, body: new URLSearchParams({ settings: JSON.stringify(payload) }) })
				state.caseTypicalCosts = null; state.catalogReferences = undefined
				render(); ctx.notifySuccess(success)
			} catch (error) { message.textContent = error.message; message.dataset.state = 'error' }
		}
		const threshold = () => Number(panel.querySelector('[data-typical-threshold]')?.value || 0)
		panel.querySelector('[data-typical-save]')?.addEventListener('click', () => {
			const entries = collect().filter((entry) => entry.article)
			store({ deviationPercent: threshold(), entries }, 'Fremdkosten gespeichert.')
		})
	}

	return { view, bind, load, loadForCase }
}
