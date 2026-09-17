/* global OC */

function fileUrl(fileId) {
	return OC.generateUrl(`/f/${Number(fileId)}`)
}

function authenticatedUrl(url) {
	const token = String(OC.requestToken || '')
	if (!token) return url
	return `${url}${url.includes('?') ? '&' : '?'}requesttoken=${encodeURIComponent(token)}`
}

function removeLater(node, delay = 1500) {
	globalThis.setTimeout(() => node.remove(), delay)
}

export function printNextcloudPdf(button, notifyWarning) {
	const url = authenticatedUrl(button.dataset.printUrl || fileUrl(button.dataset.fileId))
	const fallbackUrl = fileUrl(button.dataset.fileId)
	const frame = document.createElement('iframe')
	frame.className = 'bp-print-frame'
	frame.title = 'PDF-Druckansicht'
	let finished = false
	const fallback = () => {
		if (finished) return
		finished = true
		frame.remove()
		window.open(fallbackUrl, '_blank', 'noopener')
		notifyWarning('Der direkte Druckdialog konnte nicht geöffnet werden. Die PDF wurde geöffnet; bitte dort Strg+P beziehungsweise Cmd+P verwenden.')
	}
	const timeout = globalThis.setTimeout(fallback, 8000)
	frame.addEventListener('load', () => {
		if (finished) return
		try {
			frame.contentWindow.focus()
			frame.contentWindow.print()
			finished = true
			globalThis.clearTimeout(timeout)
			removeLater(frame)
		} catch (_) {
			globalThis.clearTimeout(timeout)
			fallback()
		}
	}, { once: true })
	frame.addEventListener('error', () => {
		globalThis.clearTimeout(timeout)
		fallback()
	}, { once: true })
	frame.src = url
	document.body.append(frame)
}

export function documentMailto() {
	return 'mailto:'
}

function openMailtoFallback(button, notifyWarning, reason = '') {
	const fileId = Number(button.dataset.fileId || 0)
	if (!fileId) return
	window.open(fileUrl(fileId), '_blank', 'noopener')
	const link = document.createElement('a')
	link.href = documentMailto()
	link.hidden = true
	document.body.append(link)
	link.click()
	link.remove()
	notifyWarning(`${reason ? `${reason} ` : ''}Die Datei wurde geöffnet und eine leere E-Mail vorbereitet. Bitte hängen Sie das Dokument vor dem Versand manuell an.`)
}

export async function prepareDocumentMail(button, notifyWarning, notifySuccess = () => {}) {
	const fileId = Number(button.dataset.fileId || 0)
	if (!fileId) return
	if (typeof navigator.share !== 'function' || typeof navigator.canShare !== 'function') {
		openMailtoFallback(button, notifyWarning, 'Dieser Browser unterstützt die Übergabe von Dateianhängen an lokale Programme nicht.')
		return
	}
	try {
		const response = await fetch(button.dataset.shareUrl, { credentials: 'same-origin', headers: { requesttoken: String(OC.requestToken || '') } })
		if (!response.ok) throw new Error(`HTTP ${response.status}`)
		const blob = await response.blob()
		const type = button.dataset.mimeType || blob.type || 'application/octet-stream'
		const file = new File([blob], button.dataset.fileName || button.dataset.documentTitle || 'Dokument', { type })
		if (!navigator.canShare({ files: [file] })) {
			openMailtoFallback(button, notifyWarning, 'Das Betriebssystem kann diesen Dateityp nicht an ein lokales Mailprogramm übergeben.')
			return
		}
		await navigator.share({ files: [file] })
		notifySuccess('Das Dokument wurde an den Systemdialog übergeben. Empfänger, Betreff und Text können im gewählten Mailprogramm ergänzt werden; ein Versand wurde nicht protokolliert.')
	} catch (error) {
		if (error?.name === 'AbortError') return
		openMailtoFallback(button, notifyWarning, 'Die Übergabe mit Dateianhang war technisch nicht möglich.')
	}
}
