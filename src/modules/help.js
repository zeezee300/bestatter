/** Documentation-based, read-only help. Independent of case capture and action intents. */
export function createHelpModule(ctx) {
	const {state, root, apiBase, api, esc, render} = ctx
	const help = () => state.help
	const url = `${apiBase}/assistant/help`
	const sources = (entry) => (entry.sources || []).length ? `<small class="bp-help-sources">Quelle: ${(entry.sources || []).map((source) => `${esc(source.file)} / ${esc(source.heading)}`).join(' · ')}</small>` : ''

	function helpView() {
		if (!help().available) return ''
		return `<aside class="bp-help-panel ${help().open ? 'open' : ''}" id="bestatter-help" aria-label="Bedienhilfe" aria-hidden="${help().open ? 'false' : 'true'}">
			<header><div><p class="bp-eyebrow">Dokumentierte Bedienung</p><h2>Hilfe-Assistent</h2></div><button type="button" class="bp-icon-button" id="help-close" aria-label="Hilfe schließen">×</button></header>
			<p class="bp-muted">Antworten werden automatisch aus freigegebenen Bedienhinweisen erzeugt. Maßgeblich sind die Dokumentation und im Zweifel der Support. Der administrativ eingerichtete Chat-Anbieter kann extern sein; bitte keine personenbezogenen Falldaten eingeben.</p>
			<div class="bp-help-history" role="log" aria-live="polite">${help().entries.map((entry, index) => `<article class="bp-help-entry"><b>${esc(entry.question)}</b><p>${esc(entry.answer || (entry.status === 'SCHEDULED' || entry.status === 'RUNNING' ? 'Antwort wird erstellt …' : entry.message || 'Noch keine Antwort.'))}</p>${sources(entry)}${entry.status === 'DELAYED' ? `<button type="button" class="bp-secondary" data-help-check="${index}">Status erneut prüfen</button>` : ''}</article>`).join('') || '<p class="bp-muted">Zum Beispiel: Wie lege ich einen neuen Fall an?</p>'}</div>
			<form id="help-question"><label><span>Frage zur Bedienung</span><textarea name="question" rows="3" maxlength="500" required placeholder="Wie lege ich einen neuen Fall an?" ${help().pending ? 'disabled' : ''}>${esc(help().draft)}</textarea></label><button type="submit" class="bp-primary" ${help().pending ? 'disabled' : ''}>Frage stellen</button></form>
		</aside>`
	}

	async function poll(entry, attempts = 0) {
		if (attempts >= 40) { entry.status = 'DELAYED'; entry.message = 'Die Verarbeitung dauert länger. Bitte später den Status erneut prüfen.'; help().pending = false; render(); return }
		try {
			const result = await api(`${url}/status/${Number(entry.taskId)}`, {feedback:false})
			entry.status = result.status
			if (['SUCCESSFUL','FAILED','CANCELLED'].includes(result.status)) {
				entry.answer = result.answer || ''
				entry.message = result.message || ''
				entry.sources = result.sources || []
				help().pending = false; render(); return
			}
			render()
			await new Promise((resolve) => setTimeout(resolve, 2500))
			return poll(entry, attempts + 1)
		} catch (error) {
			entry.status = 'DELAYED'; entry.message = error.message; help().pending = false; render()
		}
	}

	function bindHelp() {
		root.querySelector('#help-toggle')?.addEventListener('click', () => { help().open = true; if (state.assistantSidebar) state.assistantSidebar.open = false; render(); root.querySelector('#help-question textarea')?.focus() })
		root.querySelector('#help-close')?.addEventListener('click', () => { help().open = false; render(); root.querySelector('#help-toggle')?.focus() })
		root.querySelector('#bestatter-help')?.addEventListener('keydown', (event) => { if (event.key === 'Escape') { help().open = false; render(); root.querySelector('#help-toggle')?.focus() } })
		root.querySelector('#help-question textarea')?.addEventListener('input', (event) => { help().draft = event.target.value })
		root.querySelector('#help-question')?.addEventListener('submit', async (event) => {
			event.preventDefault()
			if (help().pending) return
			const question = String(help().draft || '').trim()
			if (!question) return
			const entry = {question, status:'SCHEDULED', answer:'', sources:[]}
			help().entries.push(entry); help().entries = help().entries.slice(-10); help().pending = true; help().draft = ''; render()
			try {
				const result = await api(url, {method:'POST', feedback:false, body:new URLSearchParams({question})})
				entry.status = result.status; entry.sources = result.sources || []
				if (result.status === 'NO_SOURCE') { entry.answer = result.answer; help().pending = false; render(); return }
				entry.taskId = Number(result.taskId); render(); await poll(entry)
			} catch (error) { entry.status = 'FAILED'; entry.message = error.message; help().pending = false; render() }
		})
		root.querySelectorAll('[data-help-check]').forEach((button) => button.addEventListener('click', () => {
			const entry = help().entries[Number(button.dataset.helpCheck)]
			if (!entry?.taskId || help().pending) return
			help().pending = true; entry.status = 'SCHEDULED'; render(); void poll(entry)
		}))
	}
	return { helpView, bindHelp }
}
