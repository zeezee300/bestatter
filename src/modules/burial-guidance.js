/** Internal guidance only; never passed to document or invoice templates. */
export function burialGuidance(state) {
	// Variant rules refer to the main funeral order, not an unrelated side order.
	if (Number(state.activeSideOrderId || 0) > 0) return []
	const data = state.currentCase?.masterData || {}
	const code = String(data.burial_variant_code || state.currentCase?.burialVariantCode || '')
	if (!code) return []
	const variants = state.customizing?.find((list) => list.key === 'BURIAL_VARIANT')?.items || []
	const byId = new Map(variants.map((item) => [Number(item.id), item]))
	const current = variants.find((item) => item.value === code)
	if (!current) return [{kind: 'unknown', text: 'Die gespeicherte Bestattungsvariante ist nicht mehr im Katalog vorhanden.'}]
	const path = []
	let item = current
	while (item) { path.push(item.value); item = byId.get(Number(item.parentItemId)) }
	const messages = []
	if (current.metadata?.classificationPending) messages.push({kind: 'classification', text: `${current.label}: fachliche Katalogzuordnung noch offen; keine automatische Leistungsableitung.`})
	const hasChildren = variants.some((entry) => Number(entry.parentItemId) === Number(current.id))
	if (hasChildren) messages.push({kind: 'incomplete', text: `${current.label}: Untervariante noch nicht abschließend gewählt.`})
	const lines = (state.caseServices?.items || []).filter((line) => line.serviceStatus !== 'STORNIERT')
	const deferred = Array.isArray(data.burial_deferred_rule_ids) ? data.burial_deferred_rule_ids.map(Number) : []
	for (const rule of state.burialVariantRules || []) {
		if (!rule.active || !path.includes(rule.variantCode)) continue
		if (rule.ruleType === 'SUGGESTED_ARTICLE') {
			const article = state.articles?.find((entry) => Number(entry.id) === Number(rule.articleId))
			if (article && !lines.some((line) => Number(line.articleId) === Number(rule.articleId))) messages.push({kind:'suggestion', text: rule.note || `${article.shortName} wird für diese Variante vorgeschlagen.`, articleId: article.id})
			continue
		}
		// Variantenregeln verweisen seit 0.69.0 auf den Warengruppen-Schlüssel (z. B. 310).
		const group = (state.catalogGroups || []).find((entry) => entry.key === rule.articleGroup)
		const groupLabel = group ? `${group.key} ${group.name}` : rule.articleGroup
		const hasGroup = lines.some((line) => !line.isPackageHeader && (line.articleGroupKey === rule.articleGroup || line.articleGroup === rule.articleGroup))
		if (rule.ruleType === 'EXCLUDED_ARTICLE_GROUP') {
			if (hasGroup) messages.push({kind:'conflict', text: rule.note || `Warengruppe „${groupLabel}“ passt nicht zur gewählten Bestattungsvariante.`})
			continue
		}
		if (deferred.includes(Number(rule.id))) continue
		if (!hasGroup) messages.push({kind:'mandatory', ruleId: rule.id, articleId: rule.defaultArticleId || null, text: rule.note || `Auswahl aus Warengruppe „${groupLabel}“ noch offen.`})
		else if (rule.defaultArticleId && lines.some((line) => Number(line.articleId) === Number(rule.defaultArticleId))) messages.push({kind:'placeholder', ruleId:rule.id, text: rule.note || `Standardposition aus „${groupLabel}“ hinterlegt; endgültige Auswahl noch offen.`})
	}
	return messages
}
