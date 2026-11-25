import React from 'react'
import { render, waitFor, fireEvent } from '@testing-library/react'
import { t } from '@/scripts/i18n'
import * as fetch from '@/scripts/net'
import Reset from '@/views/auth/Reset'

jest.mock('@/scripts/net')

const resetPath = '/auth/reset/42?token=test-fixture'
beforeEach(() => {
  blessing.base_url = `${location.origin}/site`
  history.replaceState(null, '', `/site${resetPath}#discarded`)
})
afterEach(() => {
  blessing.base_url = ''
  history.replaceState(null, '', '/')
})

test('confirmation is not matched', () => {
  const { getByText, getByPlaceholderText, queryByText } = render(<Reset />)

  fireEvent.input(getByPlaceholderText(t('auth.password')), {
    target: { value: 'password' },
  })
  fireEvent.input(getByPlaceholderText(t('auth.repeat-pwd')), {
    target: { value: 'password1' },
  })
  fireEvent.click(getByText(t('auth.reset')))

  expect(queryByText(t('auth.invalidConfirmPwd'))).toBeInTheDocument()
  expect(fetch.post).not.toHaveBeenCalled()
})

test('succeeded', async () => {
  fetch.post.mockResolvedValue({ code: 0, message: 'ok' })
  const { getByText, getByPlaceholderText, getByRole, queryByText } = render(
    <Reset />,
  )

  fireEvent.input(getByPlaceholderText(t('auth.password')), {
    target: { value: 'password' },
  })
  fireEvent.input(getByPlaceholderText(t('auth.repeat-pwd')), {
    target: { value: 'password' },
  })
  fireEvent.click(getByText(t('auth.reset')))
  await waitFor(() =>
    expect(fetch.post).toHaveBeenCalledWith(
      resetPath,
      { password: 'password' },
    ),
  )
  expect(queryByText('ok')).toBeInTheDocument()
  expect(getByRole('status')).toHaveClass('alert-success')
  jest.runAllTimers()
})

test('failed', async () => {
  fetch.post.mockResolvedValue({ code: 1, message: 'failed' })
  const { getByText, getByPlaceholderText, queryByText } = render(<Reset />)

  fireEvent.input(getByPlaceholderText(t('auth.password')), {
    target: { value: 'password' },
  })
  fireEvent.input(getByPlaceholderText(t('auth.repeat-pwd')), {
    target: { value: 'password' },
  })
  fireEvent.click(getByText(t('auth.reset')))
  await waitFor(() =>
    expect(fetch.post).toHaveBeenCalledWith(
      resetPath,
      { password: 'password' },
    ),
  )
  expect(queryByText('failed')).toBeInTheDocument()
})
