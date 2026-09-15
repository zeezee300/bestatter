import fs from 'node:fs'
import path from 'node:path'

export const readApiControllers = (root) => {
	const directory = path.join(root, 'lib', 'Controller')
	return fs.readdirSync(directory)
		.filter((file) => file.endsWith('ApiController.php'))
		.sort((left, right) => left.localeCompare(right, 'en'))
		.map((file) => fs.readFileSync(path.join(directory, file), 'utf8'))
		.join('\n')
}
