import { promises as fs } from 'node:fs'
import * as path from 'node:path'
import type { Compiler } from 'webpack'

const plugin = 'HtmlWebpackEnhancementPlugin'
const styles = new Set(['style', 'home-css', 'spectre'])

class HtmlWebpackEnhancementPlugin {
  private readonly root: string

  constructor(root: string) {
    this.root = root
  }

  apply(compiler: Compiler) {
    // Package the static routes with exactly the same build as the hashed assets.
    compiler.hooks.thisCompilation.tap(plugin, (compilation) => {
      compilation.hooks.processAssets.tapPromise(
        { name: plugin, stage: compiler.webpack.Compilation.PROCESS_ASSETS_STAGE_ADDITIONAL },
        async () => {
          for (const name of ['bg.webp', 'favicon.ico']) {
            const source = path.join(this.root, 'resources/assets/src/images', name)
            compilation.fileDependencies.add(source)
            compilation.emitAsset(
              name,
              new compiler.webpack.sources.RawSource(await fs.readFile(source)),
            )
          }
        },
      )
    })

    compiler.hooks.afterEmit.tapPromise(plugin, async (compilation) => {
      const directory = path.join(this.root, 'resources/views/assets')
      await fs.mkdir(directory, { recursive: true })
      for (const [name, entry] of compilation.entrypoints) {
        const suffix = styles.has(name) ? '.css' : '.js'
        const html = entry
          .getFiles()
          .filter((file) => file.endsWith(suffix))
          .map((file) => {
            const url = `{{ url('/app') }}/${file}`
            return styles.has(name)
              ? `<link rel="stylesheet" href="${url}">`
              : `<script defer src="${url}"></script>`
          })
          .join('\n')
        await fs.writeFile(path.join(directory, `${name}.twig`), `${html}\n`)
      }
    })
  }
}

export default HtmlWebpackEnhancementPlugin
