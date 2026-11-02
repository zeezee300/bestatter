import { handbookAnchor, handbookHelpLink, handbookLink, handbookUrl } from './handbook.js'

let f1Bound = false

/**
 * Documentation-based, read-only help. Independent of case capture and action intents.
 * 0.89.6: immer verfügbar (ohne Chat-Anbieter mit den gefundenen Hilfetexten als Antwort), „Hilfe zu dieser Seite“
 * beim Öffnen (Navigation „Bedienhilfe“ oder F1), „Was bedeutet das?“ an Hinweisen ([data-help-open]),
 * Rückmeldung „Hat das geholfen?“ und die Liste „Offene Hilfefragen“ für die Bestatter-Administration.
 */
export function createHelpModule(ctx) {
	const { state, root, apiBase, api, esc, render } = ctx
	const help = () => state.help
	const url = `${apiBase}/assistant/help`
	const FINAL = ['SUCCESSFUL', 'FOUND', 'NO_SOURCE']
	const sourceHref = (source) => source.kind === 'section' && source.id ? `${handbookUrl()}#${source.id}` : handbookHelpLink(source.heading)
	const sources = (entry) => (entry.sources || []).length && entry.status !== 'FOUND' ? `<small class="bp-help-sources">Quelle im Handbuch: ${(entry.sources || []).map((source) => `<a href="${esc(sourceHref(source))}" target="_blank" rel="noopener">${esc(source.heading)}</a>`).join(', ')}</small>` : ''
	const topicBlock = (topic, open) => `<details class="bp-help-topic" ${open ? 'open' : ''}><summary>${esc(topic.title)}</summary><p>${esc(topic.text)}</p><a href="${esc(handbookHelpLink(topic.title))}" target="_blank" rel="noopener">Im Handbuch öffnen</a></details>`
	const foundBlock = (entry) => entry.status === 'FOUND' ? `<p class="bp-muted">Passende Stellen aus dem Handbuch:</p>${(entry.sources || []).map((source, index) => `<details class="bp-help-topic" ${index === 0 ? 'open' : ''}><summary>${esc(source.heading)}</summary><p>${esc(source.excerpt || '')}</p><a href="${esc(sourceHref(source))}" target="_blank" rel="noopener">Im Handbuch öffnen</a></details>`).join('')}` : ''
	const feedbackBlock = (entry, index) => {
		if (!FINAL.includes(entry.status)) return ''
		if (entry.feedback === 'YES') return '<p class="bp-help-feedback bp-muted">Danke für die Rückmeldung.</p>'
		if (entry.feedback === 'NO') return '<p class="bp-help-feedback bp-muted">Danke – die Frage geht ohne Ihren Namen an die Bestatter-Administration (Offene Hilfefragen).</p>'
		return `<p class="bp-help-feedback"><span>Hat das geholfen?</span> <button type="button" class="bp-link-button" data-help-feedback="${index}" data-value="YES">Ja</button> <button type="button" class="bp-link-button" data-help-feedback="${index}" data-value="NO">Nein</button></p>`
	}

	function topicsView() {
		const data = help().topics
		const focus = help().focusTopic
		const list = (data?.topics || []).filter((topic) => topic.id !== focus?.id)
		if (!focus && !list.length) return `<section class="bp-help-page"><p class="bp-muted"><a href="${esc(handbookLink(state))}" target="_blank" rel="noopener">Diesen Bereich im Handbuch öffnen</a></p></section>`
		return `<section class="bp-help-page" data-help-page="${esc(data?.context || '')}">
			${focus ? `<h3>Was bedeutet das?</h3>${topicBlock(focus, true)}` : ''}
			${list.length ? `<h3>Hilfe zu dieser Seite${data?.title ? `: ${esc(data.title)}` : ''}</h3>${list.map((topic, index) => topicBlock(topic, !focus && index === 0 && list.length === 1)).join('')}` : ''}
			<p class="bp-muted"><a href="${esc(handbookLink(state))}" target="_blank" rel="noopener">Diesen Bereich im Handbuch öffnen</a></p>
		</section>`
	}

	function helpView() {
		if (!help().available) return ''
		const note = help().chat
			? 'Antworten werden automatisch aus freigegebenen Bedienhinweisen erzeugt. Maßgeblich sind die Dokumentation und im Zweifel der Support. Der administrativ eingerichtete Chat-Anbieter kann extern sein; bitte keine personenbezogenen Falldaten eingeben.'
			: 'Die Antworten zeigen die passenden Stellen aus dem Handbuch. Bitte keine personenbezogenen Falldaten eingeben.'
		return `<aside class="bp-help-panel ${help().open ? 'open' : ''}" id="bestatter-help" aria-label="Bedienhilfe" aria-hidden="${help().open ? 'false' : 'true'}">
			<header><div><p class="bp-eyebrow">Dokumentierte Bedienung</p><h2>Hilfe-Assistent</h2></div><button type="button" class="bp-icon-button" id="help-close" aria-label="Hilfe schließen">×</button></header>
			${help().open ? topicsView() : ''}
			<p class="bp-muted"><a href="${esc(handbookUrl())}" target="_blank" rel="noopener">Vollständiges Handbuch öffnen</a></p>
			<p class="bp-muted">${note}</p>
			<div class="bp-help-history" role="log" aria-live="polite">${help().entries.map((entry, index) => `<article class="bp-help-entry"><b>${esc(entry.question)}</b>${entry.status === 'FOUND' ? '' : `<p>${esc(entry.answer || (entry.status === 'SCHEDULED' || entry.status === 'RUNNING' ? 'Antwort wird erstellt …' : entry.message || 'Noch keine Antwort.'))}</p>`}${foundBlock(entry)}${sources(entry)}${entry.status === 'DELAYED' ? `<button type="button" class="bp-secondary" data-help-check="${index}">Status erneut prüfen</button>` : ''}${feedbackBlock(entry, index)}</article>`).join('') || '<p class="bp-muted">Zum Beispiel: Wie lege ich einen neuen Fall an?</p>'}</div>
			<form id="help-question"><label><span>Frage zur Bedienung</span><textarea name="question" rows="3" maxlength="500" required placeholder="Wie lege ich einen neuen Fall an?" ${help().pending ? 'disabled' : ''}>${esc(help().draft)}</textarea></label><button type="submit" class="bp-primary" ${help().pending ? 'disabled' : ''}>Frage stellen</button></form>
		</aside>`
	}

	/** Öffnet die Bedienhilfe mit den Hilfetexten zur aktuellen Seite; focusId zeigt zusätzlich einen bestimmten Hilfetext oben. */
	async function openHelp(focusId = '') {
		if (!help().available) return
		help().open = true
		if (state.assistantSidebar) state.assistantSidebar.open = false
		const context = handbookAnchor(state)
		help().focusTopic = null
		if (focusId) { try { help().focusTopic = await api(`${url}/topic?id=${encodeURIComponent(focusId)}`, { feedback: false, loadingLabel: false }) } catch (_) { help().focusTopic = null } }
		if (help().topics?.context !== context) {
			try { help().topics = await api(`${url}/topics?context=${encodeURIComponent(context)}`, { feedback: false, loadingLabel: false }) } catch (_) { help().topics = { context, title: '', topics: [] } }
			if (!Array.isArray(help().topics?.topics)) help().topics = { context, title: '', topics: [] }
		}
		render()
		if (!focusId) root.querySelector('#help-question textarea')?.focus()
	}

	async function poll(entry, attempts = 0) {
		if (attempts >= 40) { entry.status = 'DELAYED'; entry.message = 'Die Verarbeitung dauert länger. Bitte später den Status erneut prüfen.'; help().pending = false; render(); return }
		try {
			const result = await api(`${url}/status/${Number(entry.taskId)}`, { feedback: false })
			entry.status = result.status
			if (['SUCCESSFUL', 'FAILED', 'CANCELLED'].includes(result.status)) {
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
		if (!f1Bound && typeof document !== 'undefined') {
			f1Bound = true
			document.addEventListener('keydown', (event) => { if (event.key === 'F1' && state.help?.available) { event.preventDefault(); void openHelp() } })
		}
		root.querySelector('#help-toggle')?.addEventListener('click', () => { void openHelp() })
		root.querySelectorAll('[data-help-open]').forEach((button) => button.addEventListener('click', (event) => { event.preventDefault(); void openHelp(button.dataset.helpOpen || '') }))
		root.querySelector('#help-close')?.addEventListener('click', () => { help().open = false; render(); root.querySelector('#help-toggle')?.focus() })
		root.querySelector('#bestatter-help')?.addEventListener('keydown', (event) => { if (event.key === 'Escape') { help().open = false; render(); root.querySelector('#help-toggle')?.focus() } })
		root.querySelector('#help-question textarea')?.addEventListener('input', (event) => { help().draft = event.target.value })
		root.querySelector('#help-question')?.addEventListener('submit', async (event) => {
			event.preventDefault()
			if (help().pending) return
			const question = String(help().draft || '').trim()
			if (!question) return
			const context = handbookAnchor(state)
			const entry = { question, context, status: 'SCHEDULED', answer: '', sources: [] }
			help().entries.push(entry); help().entries = help().entries.slice(-10); help().pending = true; help().draft = ''; render()
			try {
				const result = await api(url, { method: 'POST', feedback: false, body: new URLSearchParams({ question, context }) })
				entry.status = result.status; entry.sources = result.sources || []
				if (result.status === 'NO_SOURCE' || result.status === 'FOUND') { entry.answer = result.answer || ''; help().pending = false; render(); return }
				entry.taskId = Number(result.taskId); render(); await poll(entry)
			} catch (error) { entry.status = 'FAILED'; entry.message = error.message; help().pending = false; render() }
		})
		root.querySelectorAll('[data-help-check]').forEach((button) => button.addEventListener('click', () => {
			const entry = help().entries[Number(button.dataset.helpCheck)]
			if (!entry?.taskId || help().pending) return
			help().pending = true; entry.status = 'SCHEDULED'; render(); void poll(entry)
		}))
		root.querySelectorAll('[data-help-feedback]').forEach((button) => button.addEventListener('click', async () => {
			const entry = help().entries[Number(button.dataset.helpFeedback)]
			if (!entry || entry.feedback) return
			entry.feedback = button.dataset.value === 'NO' ? 'NO' : 'YES'
			render()
			if (entry.feedback !== 'NO') return
			try {
				await api(`${url}/feedback`, { method: 'POST', feedback: false, body: new URLSearchParams({ question: entry.question, page: entry.context || '', pageTitle: help().topics?.context === entry.context ? help().topics?.title || '' : '', sources: JSON.stringify((entry.sources || []).map((source) => source.heading)) }) })
				state.helpFeedback = undefined
			} catch (error) { entry.feedback = ''; render(); ctx.notifyError?.(error.message) }
		}))
	}

	// ------------------------------------------------------------------ Administration → Assistenz: Offene Hilfefragen
	function helpFeedbackView() {
		if (state.helpFeedback === undefined) {
			state.helpFeedback = null
			api(`${url}/feedback`, { feedback: false, loadingLabel: false }).then((result) => { state.helpFeedback = result; render() }).catch((error) => { state.helpFeedback = { entries: [], error: error.message }; render() })
		}
		const data = state.helpFeedback
		const date = (value) => { const parsed = new Date(value); return Number.isNaN(parsed.getTime()) ? '' : parsed.toLocaleDateString('de-DE') }
		const rows = (data?.entries || []).map((entry) => `<tr data-help-feedback-entry="${esc(entry.id)}"><td>${esc(date(entry.createdAt))}</td><td><b>${esc(entry.question)}</b>${(entry.sources || []).length ? `<small class="bp-muted">Gefunden: ${esc(entry.sources.join(' · '))}</small>` : '<small class="bp-muted">Kein passender Hilfetext gefunden</small>'}</td><td>${esc(entry.pageTitle || entry.page || '–')}</td><td><button type="button" class="bp-secondary" data-help-feedback-resolve="${esc(entry.id)}">Erledigt</button></td></tr>`).join('')
		return `<section class="bp-panel bp-help-feedback-admin" data-help-feedback-admin><div class="bp-panel-head"><div><h3>Offene Hilfefragen</h3><p class="bp-muted">Fragen, bei denen die Bedienhilfe nach Angabe der Mitarbeitenden nicht geholfen hat – ohne Namen, mit der Seite, auf der gefragt wurde. Daraus lässt sich ablesen, wo Handbuch oder Anwendung nachgebessert werden sollten. „Erledigt“ entfernt den Eintrag; Einträge älter als ${Number(data?.maxAgeDays || 180)} Tage entfallen.</p></div><span class="bp-status">${(data?.entries || []).length}</span></div>
			${data?.error ? `<p class="bp-warning">${esc(data.error)}</p>` : ''}
			${data === null ? '<p class="bp-muted">Wird geladen …</p>' : `<div class="bp-table-scroll"><table><thead><tr><th>Datum</th><th>Frage</th><th>Seite</th><th></th></tr></thead><tbody>${rows || '<tr><td colspan="4" class="bp-empty">Keine offenen Hilfefragen.</td></tr>'}</tbody></table></div>`}
		</section>`
	}

	function bindHelpFeedback() {
		root.querySelectorAll('[data-help-feedback-resolve]').forEach((button) => button.addEventListener('click', async () => {
			try { state.helpFeedback = await api(`${url}/feedback/${encodeURIComponent(button.dataset.helpFeedbackResolve)}`, { method: 'DELETE', feedback: false }); render() } catch (error) { ctx.notifyError?.(error.message) }
		}))
	}

	return { helpView, bindHelp, openHelp, helpFeedbackView, bindHelpFeedback }
}
