import { helpExplainButton } from './handbook.js'
/**
 * 0.89.5 – Verweisprüfung: Einstellungen mit Warengruppen-Schlüsseln oder Artikeln, die im Leistungskatalog kein Ziel haben
 * (Ankreuzfelder, typische Fremdkosten, Variantenregeln, Zuschlagsstaffeln). Anzeige im jeweiligen Customizing-Reiter und
 * nach dem Einspielen eines Einrichtungspakets; die Systemprüfung zeigt dieselbe Auswertung.
 */
export const REFERENCE_TABS = { 'order-choices': 'order-choices', 'typical-costs': 'typical-costs', 'burial-rules': 'variant-rules', surcharges: 'surcharges' }

/** Hinweis für einen Bereich (area) oder – ohne area – für alle Bereiche. */
export function referenceNotice(result, esc, area = '') {
	if (!result || result.error) return ''
	if (result.catalogEmpty) return `<aside class="bp-warning bp-reference-notice" data-catalog-references="empty"><b>Leerer Leistungskatalog</b> ${esc(result.message)}${helpExplainButton('h-neue-installation-leistungskatalog-ablaeufe-und-marker-fehlen', esc)}</aside>`
	const areas = area ? [result.areas?.[area]].filter(Boolean) : Object.values(result.areas || {})
	const findings = areas.flatMap((entry) => (entry.findings || []).map((finding) => area ? finding : `${entry.label}: ${finding}`))
	if (!findings.length) return ''
	return `<aside class="bp-warning bp-reference-notice" data-catalog-references="${findings.length}"><b>Verweise ohne Ziel im Leistungskatalog</b> Diese Einstellungen wirken nicht, bis es die Warengruppe bzw. den Artikel gibt – im Leistungskatalog anlegen oder hier anpassen.${helpExplainButton('h-verweise-ohne-ziel-im-leistungskatalog', esc)}<ul>${findings.map((finding) => `<li>${esc(finding)}</li>`).join('')}</ul></aside>`
}
