import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { burialGuidance } from '../src/modules/burial-guidance.js'

const seed = JSON.parse(readFileSync(new URL('../resources/initial-customizing.json', import.meta.url), 'utf8'))
const variants = seed.lists.find((entry) => entry.key === 'BURIAL_VARIANT').items
assert.equal(variants.length, 19)
assert(variants.some((entry) => entry.value === 'TREE' && entry.label === 'Baumbestattung' && !entry.parent))
assert(variants.some((entry) => entry.value === 'FOREST' && entry.label === 'Waldbestattung' && !entry.parent))
assert(variants.some((entry) => entry.value === 'FOREST_BASIC' && entry.parent === 'TREE'))
assert(variants.some((entry) => entry.value === 'FOREST_ANONYMOUS' && entry.parent === 'FOREST'))
assert(variants.some((entry) => entry.value === 'SEA_NORTH_SHIP_1' && entry.parent === 'SEA_NORTH_FAMILY'))
assert(variants.some((entry) => entry.value === 'SEA_NORTH_SHIP_2' && entry.parent === 'SEA_NORTH_FAMILY'))
for (const code of ['ALPINE_MEADOW', 'CRYSTAL', 'MEADOW']) {
	const item = variants.find((entry) => entry.value === code)
	assert.equal(item.metadata.classificationPending, true)
	assert.equal(item.metadata.funeralScope, undefined)
}
const items = variants.map((entry, index) => ({ ...entry, id:index+1, parentItemId:entry.parent ? variants.findIndex((parent)=>parent.value===entry.parent)+1 : null }))
const state = {currentCase:{masterData:{burial_variant_code:'FIRE_URN_FAMILY'}},customizing:[{key:'BURIAL_VARIANT',items}],burialVariantRules:[{id:1,active:true,variantCode:'FIRE',ruleType:'MANDATORY_ARTICLE_GROUP_WITH_DEFAULT',articleGroup:'Urne',defaultArticleId:31,note:'Urne noch final festlegen'}],caseServices:{items:[]},articles:[]}
assert.match(burialGuidance(state).find((entry)=>entry.kind==='mandatory').text,/Urne noch final/)
state.caseServices.items = [{articleId:31,articleGroup:'Urne',serviceStatus:'BEAUFTRAGT'}]
assert.equal(burialGuidance(state).find((entry)=>entry.kind==='placeholder').ruleId,1)
state.currentCase.masterData.burial_deferred_rule_ids=[1]
assert(!burialGuidance(state).some((entry)=>entry.ruleId===1))
state.currentCase.masterData={burial_variant_code:'SEA_NORTH_FAMILY'}
assert(burialGuidance(state).some((entry)=>entry.kind==='incomplete'))
state.currentCase.masterData={burial_variant_code:'CRYSTAL'}
assert(burialGuidance(state).some((entry)=>entry.kind==='classification'))
state.activeSideOrderId=7
assert.deepEqual(burialGuidance(state),[],'Nebenaufträge dürfen keine Hinweise aus den Hauptauftragspositionen übernehmen')
console.log('0.61.1 Variantenbaum, ungeklärte Zuordnung und interne Hinweise: OK')
