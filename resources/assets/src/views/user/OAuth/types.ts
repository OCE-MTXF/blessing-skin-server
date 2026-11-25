export type App = {
  id: number | string
  name: string
  // Passport 13 only returns the unencrypted secret when the client is created.
  plain_secret?: string | null
  redirect?: string
  redirect_uris?: string[]
}

export function redirectText(app: App) {
  return app.redirect_uris?.join(',') ?? app.redirect ?? ''
}
