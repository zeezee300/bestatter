import { Window } from 'happy-dom'
import { createCommercialModule } from '../src/modules/commercial.js'

const window = new Window()
Object.assign(globalThis, { window, document: window.document, Event: window.Event, KeyboardEvent: window.KeyboardEvent })

const articles = [
	{ id: 1, itemType: 'SINGLE', articleNumber: '12345', shortName: 'Überführung', longText: 'Abholung im Stadtgebiet', category: 'Dienstleistung', articleGroup: 'Überführung', costType: 'INTERNAL', funeralScope: 'ALL', vatRate: 19, unit: 'KM', quantityDecimals: 1, salesPriceCents: 3000, active: true, components: [] },
	{ id: 2, itemType: 'SINGLE', articleNumber: 'AU-GRAB', shortName: 'Grabarbeiten', longText: 'Arbeiten am Grab', category: 'Dienstleistung', articleGroup: 'Friedhof', costType: 'THIRD_PARTY', funeralScope: 'ALL', vatRate: 19, unit: 'STK', quantityDecimals: 0, salesPriceCents: 25000, active: true, components: [] },
	...Array.from({ length: 9 }, (_, index) => ({ id: index + 10, itemType: 'SINGLE', articleNumber: `X${index}`, shortName: `Extra ${index}`, longText: '', category: 'Dienstleistung', articleGroup: 'Sonstiges', costType: 'INTERNAL', funeralScope: 'ALL', vatRate: 19, unit: 'STK', quantityDecimals: 0, salesPriceCents: 1000, active: true, components: [] })),
]
const state = {
	articles, articleGroupRules: [], currentCase: { id: 6, caseNumber: '2026-0006', masterData: { order_status: 'Entwurf' } }, commercial: { documents: [] },
	caseServices: { items: [], totals: { netCents: 0, vatCents: 0, grossCents: 0 }, contractProtection: { active: false, editable: true } },
	positionTypes: [{ value: 'EL', label: 'eigene Leistung' }, { value: 'FK', label: 'Fremdkosten/Fremdleistung' }, { value: 'DP', label: 'echter durchlaufender Posten' }],
	quantityUnits: [{ value: 'STK', label: 'Stück' }, { value: 'KM', label: 'Kilometer' }], allowedVatRates: [0, 7, 19],
	serviceLinesDraft: [], serviceDraft: {}, serviceQuery: '', serviceCostType: 'ALL', serviceArticleGroup: 'ALL', serviceItemType: 'ALL', serviceSelectedOnly: false, serviceCatalogOpen: false, servicePage: 1, servicePageSize: 20,
}
const root = window.document.createElement('div')
window.document.body.append(root)
const esc = (value) => String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;')
let module
const render = () => { root.innerHTML = module.serviceSelectionPanel(); module.bindServiceSelection() }
module = createCommercialModule({ root, state, esc, api: async () => state.caseServices, caseServicesUrl: () => '', commercialVersionPanel: () => '', field: () => '', render, title: () => {}, promptAction: async () => '' })
render()

const material = root.querySelector('[data-article-input]')
material.value = '1'
material.dispatchEvent(new window.Event('input', { bubbles: true }))
if (!window.document.querySelector('[data-article-suggestion="1"]')) throw new Error('Eine Artikelnummer ohne Bindestrich wird nicht ab dem ersten Zeichen gefunden.')
material.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }))
if (!window.document.querySelector('[data-article-suggestion].active') || !material.getAttribute('aria-activedescendant')) throw new Error('Pfeiltaste markiert keinen Treffer bei Fokus im Materialfeld.')
material.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
if (!root.querySelector('[data-article-suggestions]').hidden || material.getAttribute('aria-expanded') !== 'false') throw new Error('Escape schließt die Trefferliste nicht.')

material.value = 'grab'
material.dispatchEvent(new window.Event('input', { bubbles: true }))
if (!window.document.querySelector('[data-article-suggestion="2"]') || !window.document.querySelector('.bp-match-type') || !window.document.querySelector('mark')) throw new Error('Textsuche, Trefferart oder Hervorhebung fehlt.')
material.value = 'x'
material.dispatchEvent(new window.Event('input', { bubbles: true }))
if (!window.document.querySelector('.bp-more-matches')?.textContent.includes('weitere Treffer')) throw new Error('Mehr als acht Treffer werden still abgeschnitten.')

state.serviceLinesDraft = [
	{ id: 71, articleId: 2, articleNumber: 'AU-GRAB', title: 'Grabarbeiten', longText: 'Arbeiten am Grab', note: '', category: 'Dienstleistung', articleGroup: 'Friedhof', quantity: 2, quantityDecimals: 0, unit: 'STK', unitPriceCents: 25000, vatRate: 19, positionType: 'FK', costType: 'THIRD_PARTY', origin: 'ORDER', position: 10 },
	{ id: 72, articleId: 0, articleNumber: '', title: 'Individuelle Leistung', longText: '', note: 'Kundenwunsch', category: 'Freitext', articleGroup: 'Freitext', quantity: 1, quantityDecimals: 3, unit: 'STK', unitPriceCents: 10000, vatRate: 19, positionType: 'EL', costType: 'INTERNAL', origin: 'FREE_TEXT', position: 20 },
]
state.openServiceDetailId = 72
render()
const headers = [...root.querySelectorAll('thead th')].map((entry) => entry.textContent.trim())
if (!headers.includes('Gesamt netto') || !headers.includes('MwSt.-Satz')) throw new Error('Netto-Gesamt oder eindeutiger Steuersatz fehlt.')
if (!root.querySelector('[data-service-detail-row="72"] textarea') || root.querySelector('[data-service-detail-row="72"] textarea').value !== 'Kundenwunsch') throw new Error('Zeilengebundene Bemerkung wird nicht angezeigt.')
if (!root.querySelector('.bp-type-totals')?.textContent.includes('FK')) throw new Error('Zwischensummen nach Positionstyp fehlen.')

state.servicePositionQuery = 'individuell'
render()
if (root.querySelector('[data-service-row="71"]') || !root.querySelector('[data-service-row="72"]')) throw new Error('Positionsfilter verändert oder filtert die sichtbaren Zeilen falsch.')
state.servicePositionQuery = ''
render()
if (root.querySelector('[data-duplicate-service-line]')) throw new Error('Die deaktivierte Funktion „Duplizieren“ ist weiterhin sichtbar.')

state.serviceCatalogOpen = true
render()
const browser = root.querySelector('.bp-service-catalog-dialog .bp-service-browser')
browser.scrollTop = 420
root.querySelector('[data-service-select="10"]').click()
await new Promise((resolve) => setTimeout(resolve, 650))
if (state.serviceCatalogScrollTop !== 420 || root.querySelector('.bp-service-catalog-dialog .bp-service-browser')?.scrollTop !== 420) throw new Error('Die Artikelliste verliert nach einer Auswahl ihre Scrollposition.')

state.serviceCatalogOpen = false
state.contractAmendmentReason = 'Zusätzlicher Kundenwunsch'
state.caseServices.contractProtection = { active: true, editable: false, amendmentsAllowed: true, hasInvoice: true, hasActiveFinalInvoice: false, documentType: 'ORDER', documentNumber: 'A-2026-0006' }
state.serviceLinesDraft = [
	{ id: 81, articleId: 2, articleNumber: 'AU-GRAB', title: 'Grabarbeiten', longText: 'Arbeiten am Grab', note: '', category: 'Dienstleistung', articleGroup: 'Friedhof', quantity: 2, quantityDecimals: 0, unit: 'STK', unitPriceCents: 25000, vatRate: 19, positionType: 'FK', costType: 'THIRD_PARTY', origin: 'ORDER', position: 10, invoicedQuantityMilli: 1000 },
	{ id: 82, articleId: 0, articleNumber: '', title: 'Nachträgliche Sonderleistung', longText: '', note: 'Kundenwunsch', category: 'Freitext', articleGroup: 'Freitext', quantity: 1, quantityDecimals: 3, unit: 'STK', unitPriceCents: 8000, vatRate: 19, positionType: 'EL', costType: 'INTERNAL', origin: 'NACHTRAG', position: 20, invoicedQuantityMilli: 0 },
]
render()
if (!root.textContent.includes('Nachtragsbearbeitung aktiv') || !root.querySelector('.bp-origin-code[title="Nachtragsposition"]') || root.querySelector('.bp-origin-code[title="Nachtragsposition"]').textContent !== 'NTR') throw new Error('Nachtragsfähigkeit oder NTR-Kennzeichnung nach Teilrechnung fehlt.')
const invoicedRow = root.querySelector('[data-service-row="81"]')
if ([...invoicedRow.querySelectorAll('[data-line-field], [data-remove-service-line]')].some((control) => !control.disabled)) throw new Error('Bereits fakturierte Position bleibt in der Nachtragsbearbeitung veränderbar.')
if (root.querySelector('[data-service-row="82"] [data-line-field="unitPriceCents"]').disabled) throw new Error('Nicht fakturierte Nachtragsposition ist nach Teilrechnung fälschlich gesperrt.')

console.log('0.54.0 Matchcode, Details, Filter, Zwischensummen und ausgeblendetes Duplizieren im Laufzeittest OK')
