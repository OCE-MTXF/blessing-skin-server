export function readJsonObject(id: string): Record<string, unknown> {
  const element = document.getElementById(id)
  if (element?.tagName !== 'SCRIPT' || element.getAttribute('type') !== 'application/json') {
    return {}
  }
  try {
    const value: unknown = JSON.parse(element.textContent ?? '{}')
    return value !== null && typeof value === 'object' && !Array.isArray(value)
      ? (value as Record<string, unknown>)
      : {}
  } catch {
    return {}
  }
}

function localBase(value: unknown): string {
  if (typeof value === 'string') {
    try {
      const url = new URL(value, location.origin)
      if (url.protocol === location.protocol && url.origin === location.origin && !url.username && !url.password) {
        return `${url.origin}${url.pathname.replace(/\/+$/, '')}`
      }
    } catch {
      // Missing or invalid configuration never selects a remote origin.
    }
  }
  return location.origin
}

export function localReturnUrl(target: unknown): string {
  const base = new URL(localBase(blessing.base_url))
  const prefix = base.pathname.replace(/\/+$/, '')
  const fallback = `${base.origin}${prefix}/user`
  if (typeof target === 'string') {
    try {
      const destination = new URL(target, base.origin)
      if (
        destination.protocol === base.protocol &&
        destination.origin === base.origin &&
        !destination.username && !destination.password &&
        (destination.pathname === prefix || destination.pathname.startsWith(`${prefix}/`))
      ) {
        return destination.href
      }
    } catch {
      // Invalid return paths fall back to the local dashboard.
    }
  }
  return fallback
}

if (process.env.NODE_ENV !== 'test') {
  const config = readJsonObject('blessing-config')
  function stringValue(key: string): string {
    return typeof config[key] === 'string' ? config[key] : ''
  }
  Object.assign(window, {
    blessing: {
      base_url: localBase(config.base_url),
      debug: config.debug === true,
      env: stringValue('env'),
      locale: stringValue('locale'),
      site_name: stringValue('site_name'),
      version: stringValue('version'),
      route: stringValue('route'),
      i18n: readJsonObject('blessing-i18n'),
      extra: readJsonObject('blessing-extra'),
    },
  })
}
