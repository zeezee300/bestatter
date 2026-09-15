import fs from 'node:fs'
import path from 'node:path'

const root = process.argv[2] || '.'
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8')
const assert = (condition, message) => { if (!condition) throw new Error(message) }
const assistant = read('lib/Service/AssistantService.php')

assert(assistant.includes('foreach ($this->configuration->documents() as $template)'), 'Dokument-Intent prüft den aktiven Vorlagenkatalog nicht')
assert(assistant.includes("$this->templateMatchesRequest($template, $input)"), 'Vorlagenname wird nicht zur Intent-Erkennung verwendet')
assert(assistant.indexOf("preg_match('/\\b(druck|drucke") < assistant.indexOf('foreach ($this->configuration->documents() as $template)'), 'Ausgabeabsicht muss vor der Vorlagensuche geprüft werden')
assert(/<version>0\.(?:34\.[2-9]|3[5-9]\.\d+|[4-9]\d\.\d+)<\/version>/.test(read('appinfo/info.xml')), 'Releaseversion ab 0.34.2 fehlt')

console.log('0.34.2 active-template document intent detection passed')
