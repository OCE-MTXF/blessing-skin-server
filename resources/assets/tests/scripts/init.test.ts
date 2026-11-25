import { localReturnUrl, readJsonObject } from '@/scripts/init'

it('reads configuration from an inert JSON object only', () => {
  const script = document.createElement('script')
  script.id = 'test-config'
  script.type = 'application/json'
  script.textContent = '{"site_name":"<characters displayed as text>"}'
  document.body.append(script)
  try {
    expect(readJsonObject(script.id)).toEqual({
      site_name: '<characters displayed as text>',
    })
    script.textContent = '[]'
    expect(readJsonObject(script.id)).toEqual({})
    script.textContent = 'invalid JSON'
    expect(readJsonObject(script.id)).toEqual({})
    script.type = 'text/plain'
    script.textContent = '{"site_name":"ignored"}'
    expect(readJsonObject(script.id)).toEqual({})
  } finally {
    script.remove()
  }
})

it('keeps login return URLs on the configured site and preserves local queries', () => {
  const previous = blessing.base_url
  blessing.base_url = `${location.origin}/site`
  try {
    expect(localReturnUrl('/site/oauth/authorize?state=test')).toBe(
      `${location.origin}/site/oauth/authorize?state=test`,
    )
    const fallback = `${location.origin}/site/user`
    expect(localReturnUrl('https://example.test/account')).toBe(fallback)
    expect(localReturnUrl('/site-other/account')).toBe(fallback)
    expect(localReturnUrl(undefined)).toBe(fallback)
  } finally {
    blessing.base_url = previous
  }
})
