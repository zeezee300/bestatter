// 0.90.3: Ressourcen je Terminart (Matrix im Customizing → Terminplanung, Auswahl im Terminformular).
// Gleiche Regeln wie lib/Service/SchedulingRules.php: Eine Terminart bietet nur ihre zulässigen Ressourcen an,
// „vorbelegt“ ist immer auch zulässig, ohne Terminart gibt es keine Ressourcen. Mitarbeitende sind keine Ressource.
// 0.92.0: Ressourcentypen kommen aus der Werteliste „Ressourcentypen“ (Stammdaten → Wertelisten, im Katalog als
// scheduling.resourceTypes mit Bezeichnung, Mehrzahl und Symbol); Ressourcen können auf Niederlassungen beschränkt sein.
import { resourceIconSvg } from './resource-icons.js'

export const ASSIGNABLE_TYPES = ['CHAPEL', 'ROOM', 'VEHICLE', 'EQUIPMENT']
export const TYPE_LABELS = { CHAPEL: 'Kapelle', ROOM: 'Raum', VEHICLE: 'Fahrzeug', EQUIPMENT: 'Ausstattung', STAFF: 'Mitarbeiter' }
export const TYPE_LABELS_PLURAL = { CHAPEL: 'Kapellen', ROOM: 'Räume', VEHICLE: 'Fahrzeuge', EQUIPMENT: 'Ausstattung', STAFF: 'Mitarbeitende' }
/** Frühere Einzelfelder des Pflichtbedarfs (requiredChapels …); neu ist requiredResources {TYP: Anzahl}. */
export const REQUIREMENT_FIELDS = { CHAPEL: 'requiredChapels', ROOM: 'requiredRooms', VEHICLE: 'requiredVehicles', EQUIPMENT: 'requiredEquipment' }
export const DEFAULT_RESOURCE_TYPES = [
	{ key: 'CHAPEL', label: 'Kapelle', plural: 'Kapellen', icon: 'building-church', system: true },
	{ key: 'ROOM', label: 'Raum', plural: 'Räume', icon: 'door', system: true },
	{ key: 'VEHICLE', label: 'Fahrzeug', plural: 'Fahrzeuge', icon: 'car', system: true },
	{ key: 'EQUIPMENT', label: 'Ausstattung', plural: 'Ausstattung', icon: 'armchair', system: true },
]
export const SUGGESTED_TYPES = {
	TRAUERFEIER_1: ['CHAPEL', 'VEHICLE', 'EQUIPMENT'],
	TRAUERFEIER_2: ['CHAPEL', 'VEHICLE', 'EQUIPMENT'],
	BEISETZUNG: ['VEHICLE', 'EQUIPMENT'],
	AUFBAHRUNG: ['ROOM', 'EQUIPMENT'],
	ABSCHIEDNAHME: ['ROOM', 'EQUIPMENT'],
	UEBERFUEHRUNG: ['VEHICLE', 'EQUIPMENT'],
	BERATUNG: ['ROOM'],
	BEHOERDENTERMIN: ['VEHICLE'],
	INTERNE_TAETIGKEIT: ['CHAPEL', 'ROOM', 'VEHICLE', 'EQUIPMENT'],
}

/** 0.92.0: Ressourcentypen in der Reihenfolge der Werteliste; ohne Angabe die vier Standardtypen. */
export function resourceTypeList(resourceTypes) {
	const list = Array.isArray(resourceTypes) && resourceTypes.length ? resourceTypes : DEFAULT_RESOURCE_TYPES
	return list.filter((type) => type && type.key && type.key !== 'STAFF')
}

/** 0.92.0: Angaben zu einem Ressourcentyp (unbekannte Typen mit ihrem Schlüssel und dem Ersatzsymbol). */
export function resourceTypeInfo(key, resourceTypes) {
	const found = resourceTypeList(resourceTypes).find((type) => type.key === key)
	if (found) return { plural: found.label, icon: 'box', ...found }
	return { key, label: TYPE_LABELS[key] || String(key || ''), plural: TYPE_LABELS_PLURAL[key] || String(key || ''), icon: 'box', system: false }
}

export const resourceTypeLabel = (type, resourceTypes) => resourceTypeInfo(type, resourceTypes).label

/** 0.92.0: Kleines Symbol des Ressourcentyps (mit Bezeichnung als Tooltip und für Screenreader). */
export function resourceTypeIcon(type, resourceTypes, esc = (value) => String(value)) {
	const info = resourceTypeInfo(type, resourceTypes)
	return `<span class="bp-resource-icon" role="img" title="${esc(info.label)}" aria-label="${esc(info.label)}">${resourceIconSvg(info.icon)}</span>`
}

/** 0.92.0: Anzeigename mit Nummer/Kennung, z. B. „Bestattungswagen · HB-RL 333“. */
export function resourceDisplayName(resource) {
	const name = String(resource?.name || resource?.key || '')
	const identifier = String(resource?.identifier || '').trim()
	return identifier && identifier !== name ? `${name} · ${identifier}` : name
}

/** 0.92.0: Pflichtbedarf einer Terminart je Ressourcentyp (neu requiredResources, sonst die früheren Einzelfelder). */
export function requiredResources(type) {
	if (!type) return {}
	const map = type.requiredResources
	if (map && typeof map === 'object' && !Array.isArray(map) && Object.keys(map).length) {
		return Object.fromEntries(Object.entries(map).map(([key, count]) => [String(key).toUpperCase(), Number(count) || 0]).filter(([key, count]) => key && key !== 'STAFF' && count > 0))
	}
	return Object.fromEntries(Object.entries(REQUIREMENT_FIELDS).map(([key, field]) => [key, Number(type[field] || 0)]).filter(([, count]) => count > 0))
}

export function normalizeKeys(keys) {
	const result = []
	for (const key of keys || []) {
		const value = String(key ?? '').trim().toUpperCase()
		if (value && !result.includes(value)) result.push(value)
	}
	return result
}

/** 0.92.0: Jede Ressource außer dem früheren Typ „Mitarbeiter“ ist zuordenbar, auch eigene Ressourcentypen. */
export const isAssignable = (resource) => Boolean(resource?.resourceType) && resource.resourceType !== 'STAFF'

/** 0.92.0: Steht die Ressource in der Niederlassung zur Verfügung? Leere Liste = alle Niederlassungen; ohne Niederlassung jede. */
export function availableInBranch(resource, branchKey = '') {
	const branch = String(branchKey || '').trim().toUpperCase()
	const keys = (resource?.branchKeys || []).map((key) => String(key).trim().toUpperCase()).filter(Boolean)
	return !branch || !keys.length || keys.includes(branch)
}

/** Zulässige Schlüssel einer Terminart (Vorbelegte zählen immer dazu); ohne Terminart keine. */
export function allowedKeys(type) {
	if (!type) return []
	return normalizeKeys([...(type.allowedResourceKeys || []), ...(type.defaultResourceKeys || [])])
}

/**
 * Ressourcen, die das Terminformular für eine Terminart anbietet: zulässig, zuordenbar, aktiv und (0.92.0) in der
 * Niederlassung des Falls verfügbar. Beim Ändern bleiben bisher zugeordnete Ressourcen sichtbar (sonst gingen sie still verloren).
 */
export function offeredResources(type, resources, previousKeys = [], branchKey = '') {
	const allowed = allowedKeys(type)
	const previous = normalizeKeys(previousKeys)
	return (resources || []).filter((resource) => isAssignable(resource) && allowed.includes(resource.key) && (previous.includes(resource.key) || (resource.active !== false && availableInBranch(resource, branchKey))))
}

/** Gruppiert nach Ressourcentyp in der Reihenfolge der Werteliste; unbekannte Typen am Ende. */
export function groupByType(resources, resourceTypes) {
	const types = resourceTypeList(resourceTypes)
	const known = types.map((type) => type.key)
	const extra = [...new Set((resources || []).map((resource) => resource.resourceType).filter((key) => key && key !== 'STAFF' && !known.includes(key)))]
	return [...types.map((type) => resourceTypeInfo(type.key, resourceTypes)), ...extra.map((key) => resourceTypeInfo(key, resourceTypes))]
		.map((info) => ({ type: info.key, label: info.plural || info.label, icon: info.icon, items: (resources || []).filter((resource) => resource.resourceType === info.key) }))
		.filter((group) => group.items.length > 0)
}

/** Bisher gewählte Ressourcen, die für die Terminart (bzw. die Niederlassung) nicht (mehr) vorgesehen sind. */
export function droppedKeys(type, selectedKeys, resources, previousKeys = [], branchKey = '') {
	const offered = offeredResources(type, resources, previousKeys, branchKey).map((resource) => resource.key)
	return normalizeKeys(selectedKeys).filter((key) => !offered.includes(key))
}

/** Vorschlag „zulässig“: mitgelieferte Terminarten nach Ressourcentyp, eigene nach Pflichtbedarf. */
export function suggestAllowed(type, resources) {
	const categories = SUGGESTED_TYPES[String(type?.key || '').toUpperCase()] || Object.keys(requiredResources(type))
	const keys = (resources || []).filter((resource) => resource.active !== false && categories.includes(resource.resourceType)).map((resource) => resource.key)
	return normalizeKeys([...keys, ...(type?.defaultResourceKeys || [])])
}

/**
 * Hinweise: Pflichtbedarf einer aktiven Terminart ist mit den zulässigen, aktiven Ressourcen nicht erfüllbar.
 * 0.92.0: bei mehreren Niederlassungen zusätzlich je Niederlassung (Ressourcen mit „Alle Niederlassungen“ zählen überall).
 */
export function assignmentIssues(types, resources, resourceTypes, branches = []) {
	const byKey = Object.fromEntries((resources || []).map((resource) => [resource.key, resource]))
	const issues = []
	const activeBranches = (branches || []).filter((branch) => branch.active !== false)
	for (const type of types || []) {
		if (type.active === false) continue
		const required = requiredResources(type)
		const usable = allowedKeys(type).map((key) => byKey[key]).filter((resource) => resource && resource.active !== false && isAssignable(resource))
		const missingEverywhere = new Set()
		for (const [resourceType, count] of Object.entries(required)) {
			const available = usable.filter((resource) => resource.resourceType === resourceType).length
			if (count > available) {
				missingEverywhere.add(resourceType)
				issues.push({ typeKey: type.key, resourceType, text: `„${type.name || type.key}“ verlangt ${count} × ${resourceTypeLabel(resourceType, resourceTypes)}, zulässig ${available === 0 ? 'ist keine aktive Ressource dieser Art' : `sind nur ${available}`}.` })
			}
		}
		if (activeBranches.length < 2) continue
		for (const branch of activeBranches) {
			for (const [resourceType, count] of Object.entries(required)) {
				if (missingEverywhere.has(resourceType)) continue
				const available = usable.filter((resource) => resource.resourceType === resourceType && availableInBranch(resource, branch.key)).length
				if (count > available) issues.push({ typeKey: type.key, resourceType, branchKey: branch.key, text: `„${type.name || type.key}“ verlangt ${count} × ${resourceTypeLabel(resourceType, resourceTypes)}, in der Niederlassung „${branch.name || branch.key}“ ${available === 0 ? 'ist keine aktive Ressource dieser Art zulässig' : `sind nur ${available} zulässig`}.` })
			}
		}
	}
	return issues
}

/**
 * 0.92.0: Ressourcen eines Termins für Listen und Hinweise: aus der beim Speichern festgehaltenen Zusammenfassung
 * (data.resourceSummary) oder – bei älteren Terminen – aus dem Katalog.
 */
export function scheduleResources(data, catalogResources = []) {
	const summary = Array.isArray(data?.resourceSummary) ? data.resourceSummary : []
	const byKey = Object.fromEntries((catalogResources || []).map((resource) => [resource.key, resource]))
	return normalizeKeys(data?.resourceKeys || summary.map((entry) => entry.key)).map((key) => {
		const stored = summary.find((entry) => entry.key === key) || {}
		const current = byKey[key] || {}
		return { key, name: current.name || stored.name || key, identifier: current.identifier ?? stored.identifier ?? '', resourceType: current.resourceType || stored.resourceType || '' }
	})
}

/** 0.92.0: Ressourcen als kleine Chips mit Symbol (Terminlisten, Aufgaben mit Terminbezug, Übersicht); ab vier „+n“. */
export function resourceChips(resources, resourceTypes, esc = (value) => String(value), limit = 3) {
	const list = resources || []
	if (!list.length) return ''
	const shown = list.slice(0, limit)
	const rest = list.slice(limit)
	const chip = (resource) => `<span class="bp-resource-chip" data-resource-chip="${esc(resource.key)}">${resourceTypeIcon(resource.resourceType, resourceTypes, esc)}<span>${esc(resourceDisplayName(resource))}</span></span>`
	const more = rest.length ? `<span class="bp-resource-chip bp-resource-more" title="${esc(rest.map(resourceDisplayName).join(', '))}">+${rest.length}</span>` : ''
	return `<span class="bp-resource-chips" aria-label="Ressourcen">${shown.map(chip).join('')}${more}</span>`
}
