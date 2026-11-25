export type Plugin = {
  name: string
  version: string
  title: string
  description: string
  author: string
  dependencies: {
    all: Record<string, string>
    unsatisfied: Record<string, unknown>
  }
}
