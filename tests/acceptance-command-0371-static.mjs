import fs from 'node:fs'
import path from 'node:path'

const root = path.resolve(process.argv[2] || '.')
const source = fs.readFileSync(path.join(root, 'lib/Command/AcceptanceCheck.php'), 'utf8')
const expect = (condition, message) => { if (!condition) throw new Error(message) }

expect(source.includes('IUserSession'), 'Benutzerkontext fehlt im Abnahmebefehl')
expect(source.includes('$this->userSession->setUser($contextUser)'), 'Temporärer OCC-Prüfkontext fehlt')
expect(source.includes('finally'), 'Prüfkontext wird nicht ausfallsicher wiederhergestellt')
expect(source.includes('$this->userSession->setUser($previousUser)'), 'Vorheriger Benutzerkontext wird nicht wiederhergestellt')

console.log('acceptance-command-0371-static: ok')
