import React from 'react'
import { render, fireEvent, waitFor } from '@testing-library/react'
import * as fetch from '@/scripts/net'
import { t } from '@/scripts/i18n'
import OAuth from '@/views/user/OAuth'
import type { App } from '@/views/user/OAuth/types'

jest.mock('@/scripts/net')

const redirect = 'https://url.test/callback'
const fixture = Object.freeze({
  id: 42,
  name: 'My App',
  redirect_uris: [redirect],
} satisfies App)

test('loading data', () => {
  fetch.get.mockResolvedValue([])
  const { queryByTitle } = render(<OAuth />)
  expect(queryByTitle('Loading...')).toBeInTheDocument()
})

describe('create app', () => {
  beforeEach(() => {
    fetch.get.mockResolvedValue([])
  })

  it('succeeded', async () => {
    fetch.post.mockResolvedValue({ ...fixture, plain_secret: 'one-time-secret' })
    const { getByLabelText, getByText, queryByText } = render(<OAuth />)
    await waitFor(() => expect(fetch.get).toHaveBeenCalledTimes(1))

    fireEvent.click(getByText(t('user.oauth.create')))
    fireEvent.input(getByLabelText(t('user.oauth.name')), {
      target: { value: 'My App' },
    })
    fireEvent.input(getByLabelText(t('user.oauth.redirect')), {
      target: { value: redirect },
    })
    fireEvent.click(getByText(t('general.confirm')))
    await waitFor(() =>
      expect(fetch.post).toHaveBeenCalledWith('/oauth/clients', {
        name: 'My App',
        redirect,
      }),
    )
    expect(queryByText(fixture.id.toString())).toBeInTheDocument()
    expect(queryByText(fixture.name)).toBeInTheDocument()
    expect(queryByText(redirect)).toBeInTheDocument()
    expect(queryByText('one-time-secret')).toBeInTheDocument()
  })

  it('failed', async () => {
    fetch.post.mockResolvedValue({ message: 'exception' })
    const { getByLabelText, getByText, getByRole, queryByText } = render(
      <OAuth />,
    )
    await waitFor(() => expect(fetch.get).toHaveBeenCalledTimes(1))

    fireEvent.click(getByText(t('user.oauth.create')))
    fireEvent.input(getByLabelText(t('user.oauth.name')), {
      target: { value: 'My App' },
    })
    fireEvent.input(getByLabelText(t('user.oauth.redirect')), {
      target: { value: redirect },
    })
    fireEvent.click(getByText(t('general.confirm')))
    await waitFor(() =>
      expect(fetch.post).toHaveBeenCalledWith('/oauth/clients', {
        name: 'My App',
        redirect,
      }),
    )
    expect(queryByText(fixture.name)).not.toBeInTheDocument()
    expect(queryByText(redirect)).not.toBeInTheDocument()
    expect(queryByText('exception')).toBeInTheDocument()
    expect(getByRole('alert')).toHaveClass('alert-danger')
  })

  it('cancel dialog', async () => {
    const { getByLabelText, getByText } = render(<OAuth />)
    await waitFor(() => expect(fetch.get).toHaveBeenCalledTimes(1))

    fireEvent.click(getByText(t('user.oauth.create')))
    fireEvent.input(getByLabelText(t('user.oauth.name')), {
      target: { value: 'My App' },
    })
    fireEvent.input(getByLabelText(t('user.oauth.redirect')), {
      target: { value: redirect },
    })
    fireEvent.click(getByText(t('general.cancel')))

    await waitFor(() => expect(fetch.post).not.toHaveBeenCalled())

    fireEvent.click(getByText(t('user.oauth.create')))
    expect(getByLabelText(t('user.oauth.name'))).toHaveValue('')
    expect(getByLabelText(t('user.oauth.redirect'))).toHaveValue('')
  })
})

describe('edit app', () => {
  beforeEach(() => {
    fetch.get.mockResolvedValue([fixture])
  })

  describe('edit name', () => {
    it('succeeded', async () => {
      fetch.put.mockResolvedValue({ ...fixture, name: 'new name' })

      const { getByTitle, getByText, getByDisplayValue, queryByText } = render(
        <OAuth />,
      )
      await waitFor(() => expect(fetch.get).toHaveBeenCalledTimes(1))

      fireEvent.click(getByTitle(t('user.oauth.modifyName')))
      fireEvent.input(getByDisplayValue(fixture.name), {
        target: { value: 'new name' },
      })
      fireEvent.click(getByText(t('general.confirm')))
      await waitFor(() =>
        expect(fetch.put).toHaveBeenCalledWith(`/oauth/clients/${fixture.id}`, {
          name: 'new name',
          redirect,
        }),
      )
      expect(queryByText('new name')).toBeInTheDocument()
    })

    it('failed', async () => {
      fetch.put.mockResolvedValue({ message: 'exception' })

      const {
        getByTitle,
        getByText,
        getByDisplayValue,
        getByRole,
        queryByText,
      } = render(<OAuth />)
      await waitFor(() => expect(fetch.get).toHaveBeenCalledTimes(1))

      fireEvent.click(getByTitle(t('user.oauth.modifyName')))
      fireEvent.input(getByDisplayValue(fixture.name), {
        target: { value: 'new name' },
      })
      fireEvent.click(getByText(t('general.confirm')))
      await waitFor(() =>
        expect(fetch.put).toHaveBeenCalledWith(`/oauth/clients/${fixture.id}`, {
          name: 'new name',
          redirect,
        }),
      )
      expect(queryByText(fixture.name)).toBeInTheDocument()
      expect(queryByText('exception')).toBeInTheDocument()
      expect(getByRole('alert')).toHaveClass('alert-danger')
    })

    it('cancel dialog', async () => {
      const { getByTitle, getByText, queryByText } = render(<OAuth />)
      await waitFor(() => expect(fetch.get).toHaveBeenCalledTimes(1))

      fireEvent.click(getByTitle(t('user.oauth.modifyName')))
      fireEvent.click(getByText(t('general.cancel')))
      await waitFor(() => expect(fetch.put).not.toHaveBeenCalled())
      expect(queryByText(fixture.name)).toBeInTheDocument()
    })
  })

  describe('edit redirect url', () => {
    it('succeeded', async () => {
      fetch.put.mockResolvedValue({ ...fixture, redirect_uris: ['https://new.test/callback'] })

      const { getByTitle, getByDisplayValue, getByText, queryByText } = render(
        <OAuth />,
      )
      await waitFor(() => expect(fetch.get).toHaveBeenCalledTimes(1))

      fireEvent.click(getByTitle(t('user.oauth.modifyUrl')))
      fireEvent.input(getByDisplayValue(redirect), {
        target: { value: 'https://new.test/callback' },
      })
      fireEvent.click(getByText(t('general.confirm')))
      await waitFor(() =>
        expect(fetch.put).toHaveBeenCalledWith(`/oauth/clients/${fixture.id}`, {
          name: fixture.name,
          redirect: 'https://new.test/callback',
        }),
      )
      expect(queryByText('https://new.test/callback')).toBeInTheDocument()
    })

    it('failed', async () => {
      fetch.put.mockResolvedValue({ message: 'exception' })

      const {
        getByTitle,
        getByDisplayValue,
        getByText,
        getByRole,
        queryByText,
      } = render(<OAuth />)
      await waitFor(() => expect(fetch.get).toHaveBeenCalledTimes(1))

      fireEvent.click(getByTitle(t('user.oauth.modifyUrl')))
      fireEvent.input(getByDisplayValue(redirect), {
        target: { value: 'https://new.test/callback' },
      })
      fireEvent.click(getByText(t('general.confirm')))
      await waitFor(() =>
        expect(fetch.put).toHaveBeenCalledWith(`/oauth/clients/${fixture.id}`, {
          name: fixture.name,
          redirect: 'https://new.test/callback',
        }),
      )
      expect(queryByText(redirect)).toBeInTheDocument()
      expect(queryByText('exception')).toBeInTheDocument()
      expect(getByRole('alert')).toHaveClass('alert-danger')
    })

    it('cancel dialog', async () => {
      const { getByTitle, getByText, queryByText } = render(<OAuth />)
      await waitFor(() => expect(fetch.get).toHaveBeenCalledTimes(1))

      fireEvent.click(getByTitle(t('user.oauth.modifyUrl')))
      fireEvent.click(getByText(t('general.cancel')))
      await waitFor(() => expect(fetch.put).not.toHaveBeenCalled())
      expect(queryByText(redirect)).toBeInTheDocument()
    })
  })
})

describe('delete app', () => {
  beforeEach(() => {
    fetch.get.mockResolvedValue([fixture])
  })

  it('succeeded on an empty 204 response', async () => {
    fetch.del.mockResolvedValue('')
    const { getByText, queryByText } = render(<OAuth />)
    await waitFor(() => expect(fetch.get).toHaveBeenCalledTimes(1))

    fireEvent.click(getByText(t('report.delete')))
    fireEvent.click(getByText(t('general.confirm')))
    await waitFor(() =>
      expect(fetch.del).toHaveBeenCalledWith(`/oauth/clients/${fixture.id}`),
    )
    expect(queryByText(fixture.name)).not.toBeInTheDocument()
    expect(queryByText(redirect)).not.toBeInTheDocument()
  })

  it('cancel dialog', async () => {
    const { getByText, queryByText } = render(<OAuth />)
    await waitFor(() => expect(fetch.get).toHaveBeenCalledTimes(1))

    fireEvent.click(getByText(t('report.delete')))
    fireEvent.click(getByText(t('general.cancel')))
    await waitFor(() => expect(fetch.del).not.toHaveBeenCalled())
    expect(queryByText(fixture.name)).toBeInTheDocument()
    expect(queryByText(redirect)).toBeInTheDocument()
  })
})
