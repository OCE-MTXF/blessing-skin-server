import React from 'react'
import { act, render, fireEvent } from '@testing-library/react'
import { t } from '@/scripts/i18n'
import Captcha from '@/components/Captcha'

test('renders a same-origin CAPTCHA and retrieves the code', async () => {
  const ref = React.createRef<Captcha>()
  const { getByPlaceholderText, getByAltText } = render(<Captcha ref={ref} />)
  const image = getByAltText(t('auth.captcha')) as HTMLImageElement
  expect(new URL(image.src).origin).toBe(location.origin)
  expect(new URL(image.src).pathname).toBe('/auth/captcha')
  fireEvent.input(getByPlaceholderText(t('auth.captcha')), { target: { value: 'abc' } })
  await expect(ref.current?.execute()).resolves.toBe('abc')
})

test('manual and programmatic refresh replace the challenge and clear the old answer', async () => {
  const ref = React.createRef<Captcha>()
  const { getByAltText, getByPlaceholderText } = render(<Captcha ref={ref} />)
  const image = getByAltText(t('auth.captcha'))
  const field = getByPlaceholderText(t('auth.captcha'))
  const first = image.getAttribute('src')
  fireEvent.input(field, { target: { value: 'abc' } })
  fireEvent.click(image)
  const second = image.getAttribute('src')
  expect(second).not.toBe(first)
  await expect(ref.current?.execute()).resolves.toBe('')
  fireEvent.input(field, { target: { value: 'def' } })
  act(() => ref.current?.reset())
  expect(image.getAttribute('src')).not.toBe(second)
  expect(field).toHaveValue('')
})
