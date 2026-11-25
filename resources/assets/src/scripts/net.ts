import { emit } from './event'
import { showModal } from './notify'
import { t } from './i18n'

export interface ResponseBody<T = null> {
  code: number
  message: string
  data: T extends null ? never : T
}

class HTTPError extends Error {
  constructor(status: number) {
    super(`HTTP ${status}`)
  }
}

const empty = Object.create(null)
export const init: RequestInit = {
  credentials: 'same-origin',
  headers: new Headers({ Accept: 'application/json' }),
}

function retrieveToken() {
  return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || ''
}

async function alertError(text: string, type = 'warning') {
  try {
    await showModal({ mode: 'alert', text, type })
  } catch {
    // The alert may be dismissed with Escape or by clicking its backdrop.
  }
}

export async function walkFetch(request: Request): Promise<any> {
  request.headers.set('X-CSRF-TOKEN', retrieveToken())
  try {
    const response = await fetch(request)
    const contentType = response.headers.get('Content-Type')?.split(';', 1)[0]?.trim().toLowerCase()
    const json = contentType === 'application/json' || contentType?.endsWith('+json')
    if (response.ok) return json ? await response.json() : await response.text()

    if (response.status >= 400 && response.status < 500) {
      const body = json ? await response.json().catch(() => null) : null
      const message = typeof body?.message === 'string' && body.message
        ? body.message
        : t('general.fatalError')
      if (response.status === 422) {
        const details = body?.errors
        const first = details && typeof details === 'object'
          ? Object.values(details).find((value) => Array.isArray(value) && typeof value[0] === 'string')
          : undefined
        return { code: 1, message: Array.isArray(first) ? first[0] : message }
      }
      if (response.status === 419 || response.status === 400 || response.status === 403) {
        const text = response.status === 419 ? t('general.csrf') : message
        await alertError(text)
        return { code: -1, message: text }
      }
      if (response.status === 429) return { code: 1, message }
    }

    // Never display exception pages or expose raw responses to browser extensions.
    throw new HTTPError(response.status)
  } catch (error: unknown) {
    const message = t('general.fatalError')
    emit('fetchError', error instanceof HTTPError ? error : new Error(message))
    try {
      await showModal({ mode: 'alert', title: message, text: message, type: 'danger', okButtonType: 'outline-light' })
    } catch {
      // Preserve the established result shape even if the user dismisses the dialog.
    }
    return { code: -1, message }
  }
}

export function get<T = any>(url: string, params = empty): Promise<T> {
  emit('beforeFetch', { method: 'GET', url, data: params })
  const qs = new URLSearchParams(params).toString()
  const suffix = qs ? `${url.includes('?') ? '&' : '?'}${qs}` : ''
  return walkFetch(new Request(`${blessing.base_url}${url}${suffix}`, init))
}

function nonGet<T = any>(
  method: string,
  url: string,
  data?: FormData | Record<string, unknown>,
): Promise<T> {
  // Request payloads can contain passwords, reset tokens, CAPTCHA answers or
  // personal data. Extensions can observe the action, not its body or query.
  emit('beforeFetch', { method, url: url.split(/[?#]/, 1)[0] })
  const request = new Request(`${blessing.base_url}${url}`, {
    ...init,
    body: data instanceof FormData ? data : JSON.stringify(data),
    method,
  })
  if (!(data instanceof FormData)) request.headers.set('Content-Type', 'application/json')
  return walkFetch(request)
}

export function post<T = any>(url: string, data?: FormData | Record<string, unknown>): Promise<T> {
  return nonGet<T>('POST', url, data)
}

export function put<T = any>(url: string, data?: FormData | Record<string, unknown>): Promise<T> {
  return nonGet<T>('PUT', url, data)
}

export function del<T = any>(url: string, data?: FormData | Record<string, unknown>): Promise<T> {
  return nonGet<T>('DELETE', url, data)
}

blessing.fetch = { get, post, put, del }
