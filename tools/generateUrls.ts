import { spawnSync } from 'node:child_process'
import { writeFileSync } from 'node:fs'
import { resolve } from 'node:path'
import prettier from 'prettier'

type Route = { uri: string; name: string | null }
type RouteNode = { uri?: string; children: Map<string, RouteNode> }
const supportedPrefixes = ['auth.', 'user.', 'skinlib.', 'texture.', 'admin.']
const numericParameters = new Set(['uid', 'pid', 'tid', 'texture', 'user', 'player', 'report'])

function routeFunction(uri: string): string {
  const parameters = [...new Set([...uri.matchAll(/\{([a-z_][a-z0-9_]*)\}/gi)].map((match) => match[1]!))]
  const path = JSON.stringify(`/${uri.replace(/^\/+/, '')}`)
  if (parameters.length === 0) return `() => ${path} as const`
  const types = parameters.map((parameter) => `${parameter}: ${numericParameters.has(parameter) ? 'number' : 'string'}`)
  const parts = `/${uri.replace(/^\/+/, '')}`.split(/\{([a-z_][a-z0-9_]*)\}/gi)
  return `(${types.join(', ')}) => ${parts.map((part, i) => i % 2 === 0 ? JSON.stringify(part) : `encodeURIComponent(${part})`).filter((part) => part !== '""').join(' + ')}`
}

function renderNode(node: RouteNode): string {
  if (node.children.size === 0 && node.uri !== undefined) return routeFunction(node.uri)
  const properties = [...node.children.entries()]
    .sort(([a], [b]) => a.localeCompare(b, 'en'))
    .map(([name, child]) => `${JSON.stringify(name)}: ${renderNode(child)}`)
  if (node.uri !== undefined) properties.unshift(`$path: ${routeFunction(node.uri)}`)
  return `{${properties.join(', ')}}`
}

const root = resolve(import.meta.dirname, '..')
const result = spawnSync('php', ['artisan', 'route:list', '--json'], {
  cwd: root,
  encoding: 'utf8',
})
if (result.error) throw result.error
if (result.status !== 0) throw new Error(result.stderr || result.stdout || 'php artisan route:list failed')
const routes = JSON.parse(result.stdout) as Route[]
const tree: RouteNode = { children: new Map() }
for (const { name, uri } of routes) {
  if (!name || !supportedPrefixes.some((prefix) => name.startsWith(prefix)) || name.endsWith('.') || /\{[^}]*\?\}/.test(uri)) continue
  let node = tree
  for (const key of name.split('.')) {
    let child = node.children.get(key)
    if (!child) {
      child = { children: new Map() }
      node.children.set(key, child)
    }
    node = child
  }
  if (node.uri !== undefined && node.uri !== uri) throw new Error(`Duplicate route name: ${name}`)
  node.uri = uri
}
const output = await prettier.format(`export default ${renderNode(tree)}\n`, {
  parser: 'typescript',
  semi: false,
  singleQuote: true,
  trailingComma: 'all',
})
const outputPath = process.argv[2] ?? 'resources/assets/src/scripts/urls.ts'
writeFileSync(resolve(root, outputPath), output, 'utf8')
