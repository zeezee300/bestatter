import assert from 'node:assert/strict'
import fs from 'node:fs'

const read = (path) => fs.readFileSync(path, 'utf8')

assert(read('appinfo/info.xml').includes('<version>0.59.0</version>'))
assert(read('lib/AppInfo/Application.php').includes("VERSION = '0.59.0'"))

const migration = read('lib/Migration/Version3000Date20260907000000.php')
for (const token of ['bestatter_country_profiles', 'country_code', 'invoice_profile', 'payment_qr_standard', 'federal_state']) {
	assert(migration.includes(token), `Ländermigration enthält ${token}`)
}

const countries = read('lib/Service/CountryConfigurationService.php')
for (const code of ["'DE'", "'AT'", "'CH'", "'FR'"]) assert(countries.includes(code), `Länderprofil ${code} fehlt`)
for (const rate of ['[0, 7, 19]', '[0, 10, 13, 20]']) assert(countries.includes(rate), `Steuerprofil ${rate} fehlt`)
for (const state of ['WIEN', 'NIEDEROESTERREICH', 'OBEROESTERREICH', 'STEIERMARK', 'TIROL', 'KAERNTEN', 'SALZBURG', 'VORARLBERG', 'BURGENLAND']) {
	assert(countries.includes(`'${state}'`), `Österreichisches Bundesland ${state} fehlt`)
}

const article = read('lib/Service/ArticleService.php')
assert(article.includes('CountryConfigurationService'))
assert(article.includes('allowedVatRates()'))

const documents = read('lib/Service/DocumentService.php')
assert(documents.includes('REGIONAL_TEMPLATE_REQUIRED'))
assert(documents.includes('regionalTemplateRequired'))
assert(documents.includes("$invoiceProfile === 'ZUGFERD'"))
assert(documents.includes("$invoiceProfile === 'XRECHNUNG'"))
assert(documents.includes('paymentQrStandard'))

const controller = read('lib/Controller/CatalogApiController.php')
const routes = read('appinfo/routes.php')
assert(controller.includes('function countryProfiles'))
assert(controller.includes('function saveCountryProfile'))
assert(routes.includes('/api/country-profiles'))

const administration = read('src/modules/administration.js')
const customizing = read('src/modules/customizing.js')
for (const token of ['countryCode', 'invoiceProfile', 'paymentQrStandard', 'federalState']) {
	assert(administration.includes(token), `Administration enthält ${token}`)
	assert(customizing.includes(token), `Customizing enthält ${token}`)
}

for (const path of ['docs/LAENDERPROFILE.md', 'docs/ENTWICKLUNG.md', 'resources/templates/AT/README.md']) {
	assert(fs.existsSync(path), `${path} fehlt`)
}

console.log('country profiles 0.51 static checks passed')
