// 0.89.5: Preisart einer Position (Festpreis, Schätzbetrag, nach Bescheid) – gleiche Begriffe wie im Auftragsdokument.
export const PRICE_BASES = [
	['FIXED', 'Festpreis', 'Fest'],
	['ESTIMATE', 'Schätzbetrag (ca.)', 'ca.'],
	['ON_NOTICE', 'nach Bescheid', 'Bescheid'],
]
export const PRICE_BASIS_HELP = 'Festpreis: verbindlich vereinbart. Schätzbetrag: Erfahrungswert, im Auftrag mit „ca.“ gedruckt und laut Rechnung abgerechnet. Nach Bescheid: Gebühr, deren Betrag erst mit dem Bescheid feststeht; darf beim Festschreiben offen bleiben.'
export const priceBasisLabel = (value) => (PRICE_BASES.find(([key]) => key === value) || PRICE_BASES[0])[1]
/** Vorbelegung wie im Server (CatalogRules::defaultPriceBasis). */
export const defaultPriceBasis = (articleType, priceOnEntry = false, guidePriceCents = null) => articleType === 'FREMDLEISTUNG' ? 'ESTIMATE' : articleType === 'AUSLAGE' ? (priceOnEntry && !(Number(guidePriceCents) > 0) ? 'ON_NOTICE' : 'ESTIMATE') : 'FIXED'
export const priceBasisOptions = (selected) => PRICE_BASES.map(([value, label]) => `<option value="${value}" ${value === (selected || 'FIXED') ? 'selected' : ''}>${label}</option>`).join('')
