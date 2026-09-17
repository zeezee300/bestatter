import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const read=(name)=>readFileSync(new URL(`../${name}`,import.meta.url),'utf8')
const migration=read('lib/Migration/Version3700Date20260916000000.php')
for(const name of ['parent_item_id','metadata','burial_variant_code','with_funeral_ceremony','pickup_time','body_height_cm','body_weight_kg','bestatter_surcharge_rules','bestatter_burial_variant_rules','bestatter_case_automation']) assert(migration.includes(name),`Schemafeld ${name} fehlt`)
assert(migration.includes("'notnull' => false"))
assert(!migration.includes('addForeignKeyConstraint'),'Selbstreferenzierender Fremdschlüssel darf MariaDB-Upgrade nicht blockieren')
const custom=read('lib/Service/CustomizingService.php')
assert(custom.includes('treeForKey'))
assert(custom.includes('beginTransaction()'))
const caseService=read('lib/Service/CaseService.php')
assert(caseService.includes('normalizeBurialChoices'))
assert(caseService.includes('ensureCeremonyTask'))
assert(caseService.includes('burial_deferred_rule_ids'))
for(const field of ['surcharge_pickup_rule_key','surcharge_height_rule_key','surcharge_weight_rule_key','surcharge_other_rule_key']) {
	assert(caseService.includes(field),`Servervalidierung ${field} fehlt`)
	assert(read('src/main.js').includes(field),`Feldzuordnung ${field} fehlt`)
}
assert(read('src/modules/cases.js').includes('Keine Staffel / noch offen'))
assert(caseService.includes('Zuschläge nach Beauftragung bitte als begründeten Vertragsnachtrag'))
const surcharges=read('lib/Service/SurchargeRuleService.php')
for(const key of ['PICKUP_NIGHT','PICKUP_WEEKEND','PICKUP_HOLIDAY','HEIGHT_OVER_195','WEIGHT_101_150','WEIGHT_151_200','WEIGHT_OVER_200']) assert(surcharges.includes(key))
assert(!surcharges.includes('saveCaseServices('),'Zuschlag darf keine Position automatisch speichern')
assert(read('src/modules/commercial.js').includes('burialGuidance(state)'))
console.log('0.61.1 Migrations-, Feld- und Regelpflegevertrag: OK')
