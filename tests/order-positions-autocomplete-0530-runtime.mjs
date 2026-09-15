import { Window } from 'happy-dom'
import { createCommercialModule } from '../src/modules/commercial.js'

const window = new Window()
Object.assign(globalThis, { window, document: window.document, Event: window.Event })

const articles = [
	{ id: 1, itemType: 'SINGLE', articleNumber: 'AU-GRABARBEITEN', shortName: 'Grabarbeiten', longText: 'Vorbereitung und Durchführung der Grabarbeiten.', category: 'Dienstleistung', articleGroup: 'Friedhof', costType: 'INTERNAL', funeralScope: 'ALL', vatRate: 19, unit: 'STK', quantityDecimals: 0, salesPriceCents: 25000, active: true, components: [] },
	{ id: 2, itemType: 'SINGLE', articleNumber: 'AU-ARZT-TOTENSCHEIN', shortName: 'Ärztlicher Totenschein', longText: 'Organisation des ärztlichen Totenscheins.', category: 'Dienstleistung', articleGroup: 'Behörden', costType: 'THIRD_PARTY', funeralScope: 'ALL', vatRate: 19, unit: 'STK', quantityDecimals: 0, salesPriceCents: 8500, active: true, components: [] },
	{ id: 3, itemType: 'SINGLE', articleNumber: 'LA-URNE-STANDARD', shortName: 'Urne für Auftrag', longText: 'Abweichende Materialnummer mit Suchwort Auftrag.', category: 'Ware', articleGroup: 'Urnen', costType: 'INTERNAL', funeralScope: 'ALL', vatRate: 19, unit: 'STK', quantityDecimals: 0, salesPriceCents: 19000, active: true, components: [] },
	{ id: 4, itemType: 'SINGLE', articleNumber: 'DL-LAUTSPRECHER', shortName: 'Lautsprecher', longText: 'Ausführung für Außenbereich.', category: 'Dienstleistung', articleGroup: 'Trauerfeier', costType: 'THIRD_PARTY', funeralScope: 'ALL', vatRate: 19, unit: 'STD', quantityDecimals: 1, salesPriceCents: 7500, active: true, components: [] },
]
const state = {
	articles, articleGroupRules: [], currentCase: { id: 6, caseNumber: '2026-0006', masterData: { order_status: 'Entwurf' } }, commercial: { documents: [] },
	caseServices: { items: [], totals: { netCents: 0, vatCents: 0, grossCents: 0 }, contractProtection: { active: false, editable: true, amendmentsAllowed: false, effectiveOrderStatus: 'Entwurf' } },
	positionTypes: [{ value: 'EL', label: 'eigene Leistung' }, { value: 'FK', label: 'Fremdkosten/Fremdleistung' }, { value: 'DP', label: 'echter durchlaufender Posten' }],
	quantityUnits: [{ value: 'STK', label: 'Stück' }, { value: 'STD', label: 'Stunden' }],
	serviceLinesDraft: [{ id: -1, articleId: 0, articleNumber: '', title: '', quantity: 1, quantityDecimals: 3, unit: 'STK', unitPriceCents: 0, vatRate: 19, positionType: 'EL', origin: 'FREE_TEXT', position: 10 }],
	serviceDraft: {}, serviceQuery: '', serviceCostType: 'ALL', serviceArticleGroup: 'ALL', serviceItemType: 'ALL', serviceSelectedOnly: false, serviceCatalogOpen: false, servicePage: 1, servicePageSize: 20,
}
const root = window.document.createElement('div')
const esc = (value) => String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
const module = createCommercialModule({ root, state, esc, api: async () => state.caseServices, caseServicesUrl: () => '', commercialVersionPanel: () => '', field: () => '', render: () => {}, title: () => {}, promptAction: async () => '' })
root.innerHTML = module.serviceSelectionPanel()
window.document.body.append(root)
module.bindServiceSelection()

const input = root.querySelector('[data-article-input]')
if (input.disabled) throw new Error('Neuer Testfall 2026-0006 ist trotz konsistentem Entwurfsstatus gesperrt.')
const headers = [...root.querySelectorAll('thead th')].map((entry) => entry.textContent.trim())
if (headers.slice(0, 4).join('|') !== 'Pos.|Materialnummer|Quelle|Bezeichnung') throw new Error('SAP-Spaltenfolge beginnt nicht mit Position, Materialnummer und Herkunft.')
const initialRows = root.querySelectorAll('tbody tr')
if (initialRows.length !== 1 || initialRows[0].querySelector('.bp-position-number')?.textContent.trim() !== '10') throw new Error('Die erste leere Erfassungsposition 10 fehlt.')
if (root.querySelector('#add-free-text-service')) throw new Error('Die Positionsanlage verlangt weiterhin einen unnötigen Zusatzbutton.')
const tabFields = [...initialRows[0].querySelectorAll('input:not([tabindex="-1"]), select:not([tabindex="-1"])')].map((entry) => entry.getAttribute('aria-label') || entry.dataset.lineField)
if (tabFields.join('|') !== 'Material-/Leistungsnummer|Bezeichnung|Menge|Mengeneinheit|Einzelpreis netto|Mehrwertsteuer|Positionstyp') throw new Error('Die schnelle Tabulator-Reihenfolge ist nicht korrekt.')
const unitSelect = initialRows[0].querySelector('[data-line-field="unit"]')
const typeSelect = initialRows[0].querySelector('[data-line-field="positionType"]')
if (unitSelect.selectedOptions[0].textContent !== 'STK' || typeSelect.selectedOptions[0].textContent !== 'EL') throw new Error('Einheit und Typ zeigen im geschlossenen Zustand nicht nur das Kürzel.')
if (!unitSelect.title.includes('STK – Stück') || !typeSelect.title.includes('EL – eigene Leistung')) throw new Error('Die Bezeichnungen sind nicht als Feldhilfe verfügbar.')
unitSelect.dispatchEvent(new window.Event('focus'))
if (!unitSelect.selectedOptions[0].textContent.includes('STK – Stück')) throw new Error('Die Einheitenbezeichnung erscheint beim Öffnen/Fokussieren nicht.')
unitSelect.dispatchEvent(new window.Event('blur'))
if (unitSelect.selectedOptions[0].textContent !== 'STK') throw new Error('Die Einheit bleibt nach Verlassen unnötig lang dargestellt.')

state.serviceLinesDraft[0].title = 'Individuelle Freitextleistung'
let priceRejected = false
try { await module.saveCaseServices() } catch (error) { priceRejected = error.message.includes('Preis größer') }
if (!priceRejected) throw new Error('Freitextposition ohne Preis wird nicht plausibilisiert.')
state.serviceLinesDraft[0].title = ''
input.value = 'AU-ARZT-TOTENSCHEIN'
input.dispatchEvent(new window.Event('input', { bubbles: true }))
input.dispatchEvent(new window.Event('blur'))
await new Promise((resolve) => setTimeout(resolve, 150))
if (state.serviceLinesDraft[0].articleId !== 2 || state.serviceLinesDraft[0].title !== 'Ärztlicher Totenschein') throw new Error('Eine vollständig eingegebene Materialnummer wird beim Verlassen nicht automatisch aufgefüllt.')
Object.assign(state.serviceLinesDraft[0], { articleId: 0, articleNumber: '', title: '', longText: '', category: 'Freitext', articleGroup: 'Freitext', unit: 'STK', quantityDecimals: 3, unitPriceCents: 0, vatRate: 19, costType: 'INTERNAL', origin: 'FREE_TEXT', positionType: 'EL' })
state.serviceDraft = {}
Object.defineProperty(window, 'innerWidth', { configurable: true, value: 1280 })
Object.defineProperty(window, 'innerHeight', { configurable: true, value: 720 })
input.getBoundingClientRect = () => ({ left: 120, right: 340, top: 650, bottom: 686, width: 220, height: 36, x: 120, y: 650 })
input.value = 'AU-'
input.dispatchEvent(new window.Event('input', { bubbles: true }))
let suggestions = [...window.document.querySelectorAll('[data-article-suggestion]')]
if (suggestions.length !== 2) throw new Error('Die Eingabe AU- liefert nicht beide passenden Katalogpositionen.')
if (!suggestions.some((entry) => entry.textContent.includes('AU-GRABARBEITEN'))) throw new Error('AU-GRABARBEITEN fehlt in der Trefferliste.')
if (suggestions.some((entry) => /LA-URNE-STANDARD|DL-LAUTSPRECHER/.test(entry.textContent))) throw new Error('Die Präfixsuche AU- zeigt fachlich falsche Artikelnummern.')
if (!suggestions.every((entry) => entry.textContent.includes('STK – Stück'))) throw new Error('Die Trefferliste zeigt die sprechende Mengeneinheit nicht an.')
const floatingSuggestions = window.document.querySelector('[data-article-suggestions]')
if (!floatingSuggestions || floatingSuggestions.parentElement !== window.document.body) throw new Error('Die Trefferliste wird nicht außerhalb des begrenzten Tabellen-Scrollcontainers dargestellt.')
if (!floatingSuggestions.style.width || !floatingSuggestions.style.maxHeight || (!floatingSuggestions.style.top && !floatingSuggestions.style.bottom)) throw new Error('Die Trefferliste erhält keine viewportabhängige Größe und Position.')
if (floatingSuggestions.dataset.placement !== 'above' || !floatingSuggestions.style.bottom) throw new Error('Die Trefferliste einer unteren Position öffnet sich nicht automatisch nach oben.')
input.dispatchEvent(new window.Event('blur'))
await new Promise((resolve) => setTimeout(resolve, 150))
if (state.serviceLinesDraft[0].articleNumber !== '' || state.serviceLinesDraft[0].title !== '') throw new Error('Ein verlassener Suchpräfix wird fälschlich als Freitextposition angelegt.')
input.value = 'AU-'
input.dispatchEvent(new window.Event('input', { bubbles: true }))
suggestions = [...window.document.querySelectorAll('[data-article-suggestion]')]
suggestions[0].dispatchEvent(new window.Event('mousedown', { bubbles: true }))
const line = state.serviceLinesDraft[0]
if (line.articleNumber !== 'AU-GRABARBEITEN' || line.title !== 'Grabarbeiten' || line.category !== 'Dienstleistung' || line.articleGroup !== 'Friedhof' || line.unit !== 'STK' || line.unitPriceCents !== 25000 || line.positionType !== 'EL') throw new Error('Die Katalogdaten wurden nicht vollständig in die Auftragserfassungszeile übernommen.')
root.innerHTML = module.serviceSelectionPanel()
const positions = [...root.querySelectorAll('.bp-position-number')].map((entry) => entry.textContent.trim())
if (positions.join('|') !== '10|20' || root.querySelectorAll('.bp-service-entry-row').length !== 1) throw new Error('Nach Position 10 wird nicht automatisch genau eine leere Position 20 angeboten.')
if (!root.textContent.includes('KAT') || !root.querySelector('.bp-origin-code')?.title.includes('Katalogposition')) throw new Error('Katalog- und Freitextpositionen sind nicht eindeutig gekennzeichnet.')
if (root.querySelector('[data-show-service-details]')?.parentElement?.classList.contains('bp-title-input-row') !== true) throw new Error('Der Detailzugang steht nicht in derselben Zeile wie die Bezeichnung.')
if (!root.querySelector('#open-service-catalog')) throw new Error('Die vollständige Artikellisten-/Leistungskatalogsuche ist nicht erreichbar.')
state.serviceCatalogOpen = true
root.innerHTML = module.serviceSelectionPanel()
if (!root.querySelector('#service-catalog-title') || !root.querySelector('#service-search') || !root.querySelector('[data-service-add="3"]')) throw new Error('Der vollständige Leistungskatalog öffnet nicht mit Suche und Hinzufügen-Aktion.')

state.serviceCatalogOpen = false
state.caseServices.contractProtection = { active: true, editable: false, amendmentsAllowed: false, documentType: 'ORDER', documentNumber: 'A-2026-0005', hasInvoice: true, effectiveOrderStatus: 'beauftragt' }
state.currentCase = { id: 5, caseNumber: '2026-0005', masterData: { order_status: 'Entwurf' } }
root.innerHTML = module.serviceSelectionPanel()
if (root.querySelector('.bp-service-entry-row') || [...root.querySelectorAll('[data-line-field]')].some((field) => !field.disabled)) throw new Error('Ein festgeschriebener Auftrag mit Rechnung bleibt fälschlich eingabebereit.')
root.innerHTML = module.orderPanel(state.currentCase.masterData)
if (!root.querySelector('[name="order_status"][type="hidden"]') || root.querySelector('[name="order_status"]').value !== 'beauftragt') throw new Error('Der effektive Auftragsstatus wird bei inkonsistenten Testdaten nicht angezeigt.')
console.log('0.53.2 Positions-Autovervollständigung, Katalogdialog und Statussperre OK')
