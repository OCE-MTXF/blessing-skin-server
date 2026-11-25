import * as net from '@/scripts/net'
import { on } from '@/scripts/event'
import { t } from '@/scripts/i18n'
import { showModal } from '@/scripts/notify'

jest.mock('@/scripts/notify')

const request = () => new Request('/abc', {})
const json = (status: number, value: unknown, contentType = 'application/json; charset=UTF-8') => ({
  ok: status >= 200 && status < 300,
  status,
  headers: new Headers({ 'Content-Type': contentType }),
  json: jest.fn().mockResolvedValue(value),
})

beforeEach(() => {
  document.head.innerHTML = ''
  window.fetch = jest.fn().mockResolvedValue(json(200, {}))
})

test('GET appends query parameters, not an empty delimiter', async () => {
  const stub = jest.fn()
  const stop = on('beforeFetch', stub)
  await net.get('/abc', { a: 'b' })
  await net.get('/abc')
  await net.get('/abc?existing=1', { a: 'b' })
  expect(stub).toHaveBeenCalledWith({ method: 'GET', url: '/abc', data: { a: 'b' } })
  expect(window.fetch.mock.calls.map(([sent]) => sent.url)).toEqual(['/abc?a=b', '/abc', '/abc?existing=1&a=b'])
  stop()
})

test('POST sends CSRF, supports FormData/JSON, and redacts mutation events', async () => {
  const meta = document.createElement('meta')
  meta.name = 'csrf-token'
  meta.content = 'test-csrf-token'
  document.head.appendChild(meta)

  const stub = jest.fn()
  const stop = on('beforeFetch', stub)
  const formData = new FormData()
  await net.post('/abc', formData)
  await net.post('/abc?token=opaque-test', { password: 'test-password' })
  await net.post('/abc')

  expect(stub.mock.calls).toEqual(Array(3).fill([{ method: 'POST', url: '/abc' }]))
  const [multipart, reset, empty] = window.fetch.mock.calls.map(([sent]) => sent)
  expect(multipart!.body).toBe(formData)
  expect(multipart!.headers.has('Content-Type')).toBe(false)
  expect(reset!.url).toBe('/abc?token=opaque-test')
  expect(reset!.headers.get('X-CSRF-TOKEN')).toBe('test-csrf-token')
  expect(reset!.headers.get('Content-Type')).toBe('application/json')
  expect(reset!.body).toBe(JSON.stringify({ password: 'test-password' }))
  expect(empty!.body).toBeUndefined()
  stop()
})

test.each([['PUT', net.put], ['DELETE', net.del]])('%s sends the matching method', async (method, send) => {
  const stub = jest.fn()
  const stop = on('beforeFetch', stub)
  await send('/abc', { confirmation: 'fixture' })
  expect(window.fetch.mock.calls[0]![0].method).toBe(method)
  expect(stub).toHaveBeenCalledWith({ method, url: '/abc' })
  stop()
})

test('reads legal JSON MIME parameters and +json; keeps non-JSON success as text', async () => {
  window.fetch.mockResolvedValueOnce(json(200, ['first']))
    .mockResolvedValueOnce(json(200, ['second'], 'application/problem+json'))
    .mockResolvedValueOnce({ ok: true, status: 200, headers: new Headers(), text: async () => 'text' })
  await expect(net.walkFetch(request())).resolves.toEqual(['first'])
  await expect(net.walkFetch(request())).resolves.toEqual(['second'])
  await expect(net.walkFetch(request())).resolves.toBe('text')
})

test('validation, CSRF, forbidden and throttled responses expose safe text and result shapes', async () => {
  window.fetch.mockResolvedValueOnce(json(422, { errors: { name: ['Required.'] } }))
    .mockResolvedValueOnce(json(419, { message: 'Private server wording' }))
    .mockResolvedValueOnce(json(403, { message: 'Forbidden.' }))
    .mockResolvedValueOnce(json(429, { message: 'Try later.' }))
  await expect(net.walkFetch(request())).resolves.toEqual({ code: 1, message: 'Required.' })
  await expect(net.walkFetch(request())).resolves.toEqual({ code: -1, message: t('general.csrf') })
  expect(showModal).toHaveBeenCalledWith({ mode: 'alert', text: t('general.csrf'), type: 'warning' })
  await expect(net.walkFetch(request())).resolves.toEqual({ code: -1, message: 'Forbidden.' })
  expect(showModal).toHaveBeenCalledWith({ mode: 'alert', text: 'Forbidden.', type: 'warning' })
  await expect(net.walkFetch(request())).resolves.toEqual({ code: 1, message: 'Try later.' })
})

test('never parses fatal server bodies or exposes private exception responses', async () => {
  const server = json(500, { message: 'Private exception', trace: ['local-path'] })
  const notFoundText = jest.fn().mockResolvedValue('<p>Private error page</p>')
  window.fetch.mockResolvedValueOnce(server)
    .mockResolvedValueOnce({ ok: false, status: 404, headers: new Headers(), text: notFoundText })
    .mockRejectedValueOnce(new Error('Private socket error'))
  const stub = jest.fn()
  const stop = on('fetchError', stub)
  const message = t('general.fatalError')
  for (let i = 0; i < 3; i++) {
    await expect(net.walkFetch(request())).resolves.toEqual({ code: -1, message })
  }
  expect(server.json).not.toHaveBeenCalled()
  expect(notFoundText).not.toHaveBeenCalled()
  expect(showModal).toHaveBeenCalledWith({ mode: 'alert', title: message, text: message, type: 'danger', okButtonType: 'outline-light' })
  expect(stub.mock.calls.map(([error]) => error.message)).toEqual(['HTTP 500', 'HTTP 404', message])
  expect(stub.mock.calls[0]![0]).not.toHaveProperty('response')
  stop()
})
