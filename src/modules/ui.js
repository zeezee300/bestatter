import { trashIcon } from './icons.js'

export function createUi(root, esc) {
	let loadingCount = 0
	let loadingTimer = null
	let portal = document.getElementById('bestatter-ui-portal')
	if (!portal) {
		portal = document.createElement('div')
		portal.id = 'bestatter-ui-portal'
		portal.className = 'bp-ui-portal'
		portal.innerHTML = '<div class="bp-global-loading" role="status" aria-live="polite" aria-hidden="true"><span class="bp-loading-spinner" aria-hidden="true"></span><span class="bp-loading-copy"><b>Wird verarbeitet …</b><small>Bitte warten.</small></span><span class="bp-loading-progress" aria-hidden="true"><i></i></span></div><div class="bp-toast-region" role="region" aria-label="Systemmeldungen" aria-live="polite"></div><div class="bp-dialog-region"></div>'
		document.body.append(portal)
	}

	const toastRegion = portal.querySelector('.bp-toast-region')
	const dialogRegion = portal.querySelector('.bp-dialog-region')
	const loading = portal.querySelector('.bp-global-loading')

	function notify(message, type = 'success', timeout = type === 'error' ? 9000 : 4500) {
		const toast = document.createElement('div')
		toast.className = `bp-toast bp-toast-${type}`
		toast.setAttribute('role', type === 'error' ? 'alert' : 'status')
		toast.innerHTML = `<span>${esc(message)}</span><button type="button" aria-label="Meldung schließen">×</button>`
		const close = () => { toast.classList.add('leaving'); setTimeout(() => toast.remove(), 180) }
		toast.querySelector('button').addEventListener('click', close)
		toastRegion.append(toast)
		while (toastRegion.children.length > 4) toastRegion.firstElementChild?.remove()
		if (timeout > 0) setTimeout(close, timeout)
		return toast
	}

	function beginLoading(label = 'Wird verarbeitet …') {
		loadingCount++
		loading.querySelector('b').textContent = label
		const detail = loading.querySelector('small')
		const startedAt = Date.now()
		detail.textContent = 'Bitte warten.'
		clearTimeout(loadingTimer)
		loadingTimer = setTimeout(() => {
			if (loadingCount > 0) {
				loading.classList.add('visible')
				loading.setAttribute('aria-hidden', 'false')
			}
		}, 160)
		const elapsedTimer = setInterval(() => {
			if (loadingCount < 1) return
			const seconds = Math.max(1, Math.round((Date.now() - startedAt) / 1000))
			detail.textContent = seconds < 10
				? 'Anfrage wird vorbereitet …'
				: seconds < 30
					? `Verarbeitung läuft seit ${seconds} Sekunden …`
					: `Der Vorgang dauert länger, arbeitet aber weiter (${seconds} Sekunden).`
		}, 1000)
		let ended = false
		return () => {
			if (ended) return
			ended = true
			clearInterval(elapsedTimer)
			loadingCount = Math.max(0, loadingCount - 1)
			if (loadingCount === 0) {
				clearTimeout(loadingTimer)
				loading.classList.remove('visible')
				loading.setAttribute('aria-hidden', 'true')
			}
		}
	}

	async function download(url, fallbackFilename = 'download', requestToken = '', options = {}) {
		const endLoading = beginLoading(options.loadingLabel || 'Download wird vorbereitet …')
		try {
			const response = await fetch(url, { credentials: 'same-origin', headers: { requesttoken: requestToken } })
			if (!response.ok) {
				const text = await response.text()
				let message = ''
				try { message = JSON.parse(text)?.message || '' } catch (_) { message = text.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim() }
				throw new Error(message || `Download fehlgeschlagen (HTTP ${response.status}).`)
			}
			const disposition = response.headers.get('content-disposition') || ''
			const encodedName = disposition.match(/filename\*=UTF-8''([^;]+)/i)?.[1]
			const plainName = disposition.match(/filename="?([^";]+)"?/i)?.[1]
			let filename = fallbackFilename
			try { filename = encodedName ? decodeURIComponent(encodedName) : (plainName || fallbackFilename) } catch (_) { filename = plainName || fallbackFilename }
			const blob = await response.blob()
			if (options.saveAs && typeof window.showSaveFilePicker === 'function') {
				try {
					const extension = filename.includes('.') ? `.${filename.split('.').pop()}` : ''
					const handle = await window.showSaveFilePicker({ suggestedName: filename, types: [{ description: options.description || 'Sicherungsdatei', accept: { [blob.type || 'application/octet-stream']: extension ? [extension] : [] } }] })
					const writable = await handle.createWritable(); await writable.write(blob); await writable.close()
					return filename
				} catch (error) {
					if (error?.name === 'AbortError') return null
					// Nicht jeder Chromium-Build unterstützt alle Dateitypen des Pickers.
					// In diesem Fall übernimmt der normale Browser-Download.
				}
			}
			const blobUrl = URL.createObjectURL(blob)
			const anchor = document.createElement('a'); anchor.href = blobUrl; anchor.download = filename; anchor.hidden = true
			document.body.append(anchor); anchor.click(); anchor.remove(); setTimeout(() => URL.revokeObjectURL(blobUrl), 1000)
			return filename
		} finally { endLoading() }
	}

	function dialog({ title, message, confirmLabel = 'Bestätigen', cancelLabel = 'Abbrechen', danger = false, input = null }) {
		return new Promise((resolve) => {
			const previousFocus = document.activeElement
			const overlay = document.createElement('div')
			overlay.className = 'bp-ui-dialog-overlay'
			const inputHtml = input ? `<label class="bp-ui-dialog-field"><span>${esc(input.label || 'Eingabe')}</span>${input.multiline ? `<textarea rows="4" ${input.required ? 'required aria-required="true"' : ''}>${esc(input.value || '')}</textarea>` : `<input type="text" value="${esc(input.value || '')}" ${input.required ? 'required aria-required="true"' : ''}>`}<small id="bp-dialog-help">${esc(input.help || '')}</small></label>` : ''
			overlay.innerHTML = `<section class="bp-ui-dialog" role="dialog" aria-modal="true" aria-labelledby="bp-ui-dialog-title" aria-describedby="bp-ui-dialog-message"><h2 id="bp-ui-dialog-title">${esc(title)}</h2><p id="bp-ui-dialog-message">${esc(message)}</p>${inputHtml}<div class="bp-ui-dialog-actions"><button type="button" class="bp-secondary bp-dialog-cancel">${esc(cancelLabel)}</button><button type="button" class="${danger ? 'bp-danger' : 'bp-primary'} bp-dialog-confirm">${esc(confirmLabel)}</button></div></section>`
			dialogRegion.replaceChildren(overlay)
			const panel = overlay.querySelector('.bp-ui-dialog')
			const field = overlay.querySelector('input,textarea')
			const cancel = overlay.querySelector('.bp-dialog-cancel')
			const confirm = overlay.querySelector('.bp-dialog-confirm')
			const close = (value) => {
				document.removeEventListener('keydown', onKeyDown, true)
				overlay.remove()
				if (previousFocus instanceof HTMLElement && previousFocus.isConnected) previousFocus.focus()
				resolve(value)
			}
			const submit = () => {
				if (!field) return close(true)
				const value = field.value.trim()
				if (input.required && value === '') {
					field.setAttribute('aria-invalid', 'true')
					field.focus()
					return
				}
				close(value)
			}
			const onKeyDown = (event) => {
				if (event.key === 'Escape') { event.preventDefault(); close(input ? null : false); return }
				if (event.key === 'Enter' && !input?.multiline) { event.preventDefault(); submit(); return }
				if (event.key !== 'Tab') return
				const focusable = [...panel.querySelectorAll('button,input,textarea')]
				if (!focusable.length) return
				const first = focusable[0]; const last = focusable.at(-1)
				if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus() }
				else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus() }
			}
			document.addEventListener('keydown', onKeyDown, true)
			cancel.addEventListener('click', () => close(input ? null : false))
			confirm.addEventListener('click', submit)
			overlay.addEventListener('mousedown', (event) => { if (event.target === overlay) close(input ? null : false) })
			requestAnimationFrame(() => (field || cancel).focus())
		})
	}

	const confirmAction = (message, options = {}) => dialog({ title: options.title || 'Bitte bestätigen', message, confirmLabel: options.confirmLabel || 'Bestätigen', danger: Boolean(options.danger) })
	const promptAction = (message, value = '', options = {}) => dialog({ title: options.title || 'Angabe erforderlich', message, confirmLabel: options.confirmLabel || 'Übernehmen', input: { label: options.label || 'Eingabe', value, required: options.required !== false, multiline: Boolean(options.multiline), help: options.help || '' } })

	function enhanceAccessibility(scope = root) {
		const deleteButtons = [
			['#delete-case', 'Fall löschen'],
			['#cleanup-backups', 'Fristabgelaufene Sicherungen löschen'],
			['.delete-backup', 'Sicherung löschen'],
			['.delete-branch', 'Niederlassung löschen'],
			['.remove-incoming-item', 'Rechnungsposition löschen'],
			['.delete-article', 'Artikel löschen'],
			['.remove-component', 'Paketbestandteil löschen'],
			['.delete-document-template', 'Dokumentvorlage löschen'],
			['.delete-dereg-template', 'Abmeldevorgang löschen'],
			['.remove-workflow-action', 'Workflow-Aktion löschen'],
			['.delete-value', 'Listenwert löschen'],
			['.delete-checklist-item', 'Checklistenaufgabe löschen'],
			['.delete-checklist', 'Checkliste löschen'],
			['.delete-workflow', 'Workflow löschen'],
			['[data-remove-service-line]', 'Position löschen'],
		]
		deleteButtons.forEach(([selector, label]) => scope.querySelectorAll(selector).forEach((button) => {
			if (button.classList.contains('bp-delete-icon-button')) return
			const accessibleLabel = button.getAttribute('aria-label') || label
			button.setAttribute('aria-label', accessibleLabel)
			if (!button.hasAttribute('title')) button.setAttribute('title', accessibleLabel)
			button.classList.add('bp-icon-button', 'bp-delete-icon-button')
			button.innerHTML = trashIcon
		}))
		scope.querySelectorAll('[required]').forEach((field) => field.setAttribute('aria-required', 'true'))
		scope.querySelectorAll('[data-state],.bp-save-state,.bp-card-state').forEach((status) => { status.setAttribute('role', status.dataset.state === 'error' ? 'alert' : 'status'); status.setAttribute('aria-live', 'polite') })
		scope.querySelectorAll('input,select,textarea').forEach((field) => {
			field.addEventListener('invalid', () => field.setAttribute('aria-invalid', 'true'))
			field.addEventListener('input', () => { if (field.checkValidity()) field.removeAttribute('aria-invalid') })
		})
		scope.querySelectorAll('.bp-modal').forEach((modal) => {
			if (modal.dataset.a11yReady) return
			modal.dataset.a11yReady = 'true'; modal.setAttribute('role', 'dialog'); modal.setAttribute('aria-modal', 'true')
			requestAnimationFrame(() => modal.querySelector('input:not([type="hidden"]),select,textarea,button')?.focus())
		})
	}

	new window.MutationObserver(() => enhanceAccessibility(root)).observe(root, { childList: true, subtree: true })
	return { notify, notifySuccess: (message) => notify(message, 'success'), notifyError: (message) => notify(message, 'error'), notifyWarning: (message) => notify(message, 'warning', 7000), beginLoading, download, confirmAction, promptAction, enhanceAccessibility }
}
