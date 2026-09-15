import { readdir } from 'node:fs/promises'
import { spawn } from 'node:child_process'
import path from 'node:path'
import process from 'node:process'

const root = path.resolve(process.argv[2] || '.')
const listOnly = process.argv.includes('--list')
const testDirectory = path.join(root, 'tests')
const ownPath = path.resolve(testDirectory, 'run-all.mjs')

async function discoverTests(directory) {
	const files = []
	for (const entry of await readdir(directory, { withFileTypes: true })) {
		const entryPath = path.join(directory, entry.name)
		if (entry.isDirectory()) {
			if (entry.name !== 'helpers') files.push(...await discoverTests(entryPath))
			continue
		}
		if (entry.name.endsWith('.mjs') && path.resolve(entryPath) !== ownPath) {
			files.push(entryPath)
		}
	}
	return files
}

const files = (await discoverTests(testDirectory))
	.sort((left, right) => left.localeCompare(right, 'en'))

if (listOnly) {
	console.log(files.map((file) => path.relative(root, file)).join('\n'))
	process.exit(0)
}

for (const file of files) {
	const displayName = path.relative(testDirectory, file)
	console.log(`\n--- ${displayName} ---`)
	const exitCode = await new Promise((resolve, reject) => {
		const child = spawn(process.execPath, [file, root], {
			cwd: root,
			stdio: 'inherit',
		})
		child.once('error', reject)
		child.once('exit', (code, signal) => {
			if (signal !== null) reject(new Error(`${displayName} wurde durch ${signal} beendet.`))
			else resolve(code ?? 1)
		})
	})
	if (exitCode !== 0) {
		console.error(`\nTest fehlgeschlagen: ${displayName}`)
		process.exit(exitCode)
	}
}

console.log(`\n${files.length} MJS-Testdateien erfolgreich ausgeführt.`)
