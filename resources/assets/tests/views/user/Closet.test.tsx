import React from 'react'
import { act, render, fireEvent, waitFor } from '@testing-library/react'
import { createPaginator } from '../../utils'
import $ from 'jquery'
import { SkinViewer } from 'skinview3d'
import { t } from '@/scripts/i18n'
import * as fetch from '@/scripts/net'
import { ClosetItem, Player, TextureType } from '@/scripts/types'
import urls from '@/scripts/urls'
import Closet from '@/views/user/Closet'

jest.mock('@/scripts/net')

const fixtureSkin: Readonly<ClosetItem> = Object.freeze<ClosetItem>({
  tid: 1,
  name: 'skin',
  type: TextureType.Steve,
  hash: 'abc',
  size: 2,
  uploader: 1,
  public: true,
  upload_at: new Date().toString(),
  likes: 1,
  pivot: {
    user_uid: 1,
    texture_tid: 1,
    item_name: 'closet_skin',
  },
})

const fixtureCape: Readonly<ClosetItem> = Object.freeze<ClosetItem>({
  tid: 2,
  name: 'cape',
  type: TextureType.Cape,
  hash: 'def',
  size: 2,
  uploader: 1,
  public: true,
  upload_at: new Date().toString(),
  likes: 1,
  pivot: {
    user_uid: 1,
    texture_tid: 2,
    item_name: 'closet_cape',
  },
})

const fixturePlayer: Readonly<Player> = Object.freeze<Player>({
  pid: 1,
  name: 'kumiko',
  uid: 1,
  tid_skin: 1,
  tid_cape: 2,
  last_modified: new Date().toString(),
})

beforeAll(async () => {
  // Warm the real lazy component (with the mocked WebGL backend) independently
  // of individual test timeouts. The first import triggers ts-jest transforms.
  await import('@/components/Viewer')
}, 30_000)

beforeEach(() => {
  const container = document.createElement('div')
  container.id = 'previewer'
  document.body.appendChild(container)
})

afterEach(() => {
  jest.restoreAllMocks()
  document.querySelector('#previewer')!.remove()
})

test('empty closet links to the skin library', async () => {
  fetch.get.mockResolvedValue(createPaginator([]))

  const { getByText } = render(<Closet />)
  await act(async () => {
    // The mocked paginator resolves immediately; flush React's updates, not a polling timer.
  })
  expect(getByText(t('user.closet.empty'))).toBeInTheDocument()
  expect(getByText(t('user.viewInSkinlib'), { selector: 'a' })).toHaveAttribute(
    'href',
    `${urls.skinlib.home()}?filter=skin`,
  )
})

describe('debounced search', () => {
  beforeEach(() => jest.useFakeTimers())
  afterEach(() => {
    jest.restoreAllMocks()
    jest.useRealTimers()
  })

  test('loads immediately and searches only the latest input after 350ms', async () => {
    fetch.get
      .mockResolvedValueOnce(createPaginator([fixtureSkin]))
      .mockResolvedValueOnce(createPaginator([]))

    const { getByPlaceholderText, getByText } = render(<Closet />)
    expect(fetch.get).toHaveBeenCalledTimes(1)
    expect(fetch.get).toHaveBeenCalledWith(
      urls.user.closet.list(),
      expect.objectContaining({ q: '' }),
    )

    const input = getByPlaceholderText(t('user.typeToSearch'))
    fireEvent.input(input, { target: { value: 'ab' } })
    await act(async () => jest.advanceTimersByTime(200))
    fireEvent.input(input, { target: { value: 'abc' } })

    await act(async () => jest.advanceTimersByTime(349))
    expect(fetch.get).toHaveBeenCalledTimes(1)

    await act(async () => jest.advanceTimersByTime(1))
    expect(fetch.get).toHaveBeenCalledTimes(2)
    expect(fetch.get).toHaveBeenLastCalledWith(
      urls.user.closet.list(),
      expect.objectContaining({ q: 'abc' }),
    )
    expect(getByText(t('general.noResult'))).toBeInTheDocument()
  })

  test('cancels a pending search on unmount', async () => {
    fetch.get.mockResolvedValue(createPaginator([]))
    const setTimeout = jest.spyOn(window, 'setTimeout')
    const clearTimeout = jest.spyOn(window, 'clearTimeout')
    const { getByPlaceholderText, unmount } = render(<Closet />)

    fireEvent.input(getByPlaceholderText(t('user.typeToSearch')), {
      target: { value: 'abc' },
    })
    const pendingSearch = setTimeout.mock.results.slice(-1)[0]?.value
    expect(setTimeout).toHaveBeenLastCalledWith(expect.any(Function), 350)
    expect(pendingSearch).toBeDefined()

    unmount()
    expect(clearTimeout).toHaveBeenCalledWith(pendingSearch)
    await act(async () => jest.advanceTimersByTime(350))
    expect(fetch.get).toHaveBeenCalledTimes(1)
  })
})

test('switch page', async () => {
  fetch.get
    .mockResolvedValueOnce({ ...createPaginator([]), last_page: 2 })
    .mockResolvedValueOnce({ ...createPaginator([fixtureSkin]), last_page: 2 })

  const { getByText, findByText } = render(<Closet />)
  await waitFor(() => expect(fetch.get).toHaveBeenCalledTimes(1))
  fireEvent.click(getByText('2'))
  expect(await findByText(fixtureSkin.pivot.item_name)).toBeInTheDocument()
})

describe('switch category', () => {
  it('click tab', async () => {
    fetch.get
      .mockResolvedValueOnce(createPaginator([fixtureSkin]))
      .mockResolvedValueOnce(createPaginator([fixtureCape]))
      .mockResolvedValueOnce(createPaginator([fixtureSkin]))

    const { getByText, queryByText } = render(<Closet />)
    await waitFor(() => expect(fetch.get).toHaveBeenCalled())
    expect(queryByText(fixtureSkin.pivot.item_name)).toBeInTheDocument()
    expect(queryByText(fixtureCape.pivot.item_name)).not.toBeInTheDocument()

    fireEvent.click(getByText(t('general.cape')))
    await waitFor(() => expect(fetch.get).toHaveBeenCalled())
    expect(queryByText(fixtureSkin.pivot.item_name)).not.toBeInTheDocument()
    expect(queryByText(fixtureCape.pivot.item_name)).toBeInTheDocument()

    fireEvent.click(getByText(t('general.skin')))
    await waitFor(() => expect(fetch.get).toHaveBeenCalled())
    expect(queryByText(fixtureSkin.pivot.item_name)).toBeInTheDocument()
    expect(queryByText(fixtureCape.pivot.item_name)).not.toBeInTheDocument()
  })

  it('click current tab should not switch category', async () => {
    fetch.get.mockResolvedValue(createPaginator([fixtureSkin]))

    const { getByText } = render(<Closet />)
    await waitFor(() => expect(fetch.get).toHaveBeenCalled())

    fireEvent.click(getByText(t('general.skin')))
    await waitFor(() => expect(fetch.get).toHaveBeenCalledTimes(1))
  })

  it('reset page', async () => {
    fetch.get
      .mockResolvedValueOnce(
        createPaginator(
          Array.from({ length: 30 }).map((_, i) => ({
            ...fixtureSkin,
            tid: i + 1,
          })),
        ),
      )
      .mockResolvedValueOnce(
        createPaginator(
          Array.from({ length: 30 }).map((_, i) => ({
            ...fixtureSkin,
            tid: i + 1,
          })),
        ),
      )
      .mockResolvedValueOnce(createPaginator([fixtureCape]))

    const { getByText } = render(<Closet />)
    await waitFor(() =>
      expect(fetch.get).toHaveBeenCalledWith(urls.user.closet.list(), {
        category: 'skin',
        q: '',
        page: 1,
        perPage: 6,
      }),
    )

    fireEvent.click(getByText('3'))
    await waitFor(() =>
      expect(fetch.get).toHaveBeenCalledWith(urls.user.closet.list(), {
        category: 'skin',
        q: '',
        page: 3,
        perPage: 6,
      }),
    )

    fireEvent.click(getByText(t('general.cape')))
    await waitFor(() =>
      expect(fetch.get).toHaveBeenCalledWith(urls.user.closet.list(), {
        category: 'cape',
        q: '',
        page: 1,
        perPage: 6,
      }),
    )
  })
})

describe('rename item', () => {
  beforeEach(() => {
    fetch.get.mockResolvedValue(createPaginator([fixtureSkin]))
  })

  it('succeeded', async () => {
    fetch.put.mockResolvedValue({ code: 0, message: 'success' })

    const { getByText, getByDisplayValue, getByRole, queryByText } = render(
      <Closet />,
    )
    await waitFor(() => expect(fetch.get).toHaveBeenCalledTimes(1))

    fireEvent.click(getByText(t('user.renameItem')))
    fireEvent.input(getByDisplayValue(fixtureSkin.pivot.item_name), {
      target: { value: 'my skin' },
    })
    fireEvent.click(getByText(t('general.confirm')))
    await waitFor(() =>
      expect(fetch.put).toHaveBeenCalledWith(
        urls.user.closet.rename(fixtureSkin.tid),
        { name: 'my skin' },
      ),
    )
    expect(queryByText('my skin')).toBeInTheDocument()
    expect(queryByText('success')).toBeInTheDocument()
    expect(getByRole('status')).toHaveClass('alert-success')
  })

  it('empty name', async () => {
    const { getByText, getByDisplayValue, queryByText } = render(<Closet />)
    await waitFor(() => expect(fetch.get).toHaveBeenCalledTimes(1))

    fireEvent.click(getByText(t('user.renameItem')))
    fireEvent.input(getByDisplayValue(fixtureSkin.pivot.item_name), {
      target: { value: '' },
    })
    fireEvent.click(getByText(t('general.confirm')))
    await waitFor(() => expect(fetch.put).not.toHaveBeenCalled())
    expect(queryByText(t('skinlib.emptyNewTextureName'))).toBeInTheDocument()

    fireEvent.click(getByText(t('general.cancel')))
  })

  it('failed', async () => {
    fetch.put.mockResolvedValue({ code: 1, message: 'failed' })

    const { getByText, getByDisplayValue, getByRole, queryByText } = render(
      <Closet />,
    )
    await waitFor(() => expect(fetch.get).toHaveBeenCalledTimes(1))

    fireEvent.click(getByText(t('user.renameItem')))
    fireEvent.input(getByDisplayValue(fixtureSkin.pivot.item_name), {
      target: { value: 'my skin' },
    })
    fireEvent.click(getByText(t('general.confirm')))
    await waitFor(() =>
      expect(fetch.put).toHaveBeenCalledWith(
        urls.user.closet.rename(fixtureSkin.tid),
        { name: 'my skin' },
      ),
    )
    expect(queryByText(fixtureSkin.pivot.item_name)).toBeInTheDocument()
    expect(queryByText('failed')).toBeInTheDocument()
    expect(getByRole('alert')).toHaveClass('alert-danger')
  })
})

describe('remove item', () => {
  beforeEach(() => {
    fetch.get.mockResolvedValue(createPaginator([fixtureSkin]))
  })

  it('succeeded', async () => {
    fetch.del.mockResolvedValue({ code: 0, message: 'success' })

    const { getByText, getByRole, queryByText } = render(<Closet />)
    await waitFor(() => expect(fetch.get).toHaveBeenCalledTimes(1))

    fireEvent.click(getByText(t('user.removeItem')))
    fireEvent.click(getByText(t('general.confirm')))
    await waitFor(() =>
      expect(fetch.del).toHaveBeenCalledWith(
        urls.user.closet.remove(fixtureSkin.tid),
      ),
    )
    expect(queryByText(/skin library/i)).toBeInTheDocument()
    expect(queryByText('success')).toBeInTheDocument()
    expect(getByRole('status')).toHaveClass('alert-success')
  })

  it('failed', async () => {
    fetch.del.mockResolvedValue({ code: 1, message: 'failed' })

    const { getByText, getByRole, queryByText } = render(<Closet />)
    await waitFor(() => expect(fetch.get).toHaveBeenCalledTimes(1))

    fireEvent.click(getByText(t('user.removeItem')))
    fireEvent.click(getByText(t('general.confirm')))
    await waitFor(() =>
      expect(fetch.del).toHaveBeenCalledWith(
        urls.user.closet.remove(fixtureSkin.tid),
      ),
    )
    expect(queryByText(fixtureSkin.pivot.item_name)).toBeInTheDocument()
    expect(queryByText('failed')).toBeInTheDocument()
    expect(getByRole('alert')).toHaveClass('alert-danger')
  })

  it('cancelled', async () => {
    const { getByText, queryByText } = render(<Closet />)
    await waitFor(() => expect(fetch.get).toHaveBeenCalledTimes(1))

    fireEvent.click(getByText(t('user.removeItem')))
    fireEvent.click(getByText(t('general.cancel')))
    await waitFor(() => expect(fetch.del).not.toHaveBeenCalled())
    expect(queryByText(fixtureSkin.pivot.item_name)).toBeInTheDocument()
  })
})

describe('select textures', () => {
  beforeEach(() => {
    fetch.get
      .mockResolvedValueOnce(createPaginator([fixtureSkin]))
      .mockResolvedValueOnce(createPaginator([fixtureCape]))
  })

  it('select skin', async () => {
    const { getByAltText, queryAllByText } = render(<Closet />)
    await waitFor(() => expect(fetch.get).toHaveBeenCalledTimes(1))

    fireEvent.click(getByAltText(fixtureSkin.pivot.item_name))
    expect(queryAllByText(t('general.skin'))).toHaveLength(2)
  })

  it('select cape', async () => {
    const { getByText, getByAltText, queryAllByText } = render(<Closet />)
    await waitFor(() => expect(fetch.get).toHaveBeenCalled())

    fireEvent.click(getByText(t('general.cape')))
    await waitFor(() => expect(fetch.get).toHaveBeenCalled())

    fireEvent.click(getByAltText(fixtureCape.pivot.item_name))
    expect(queryAllByText(t('general.cape'))).toHaveLength(2)
  })

  it('reset selected', async () => {
    const { getByText, getByAltText, queryByText } = render(<Closet />)
    await waitFor(() => expect(fetch.get).toHaveBeenCalled())

    fireEvent.click(getByAltText(fixtureSkin.pivot.item_name))
    fireEvent.click(getByText(t('general.cape')))
    await waitFor(() => expect(fetch.get).toHaveBeenCalled())
    fireEvent.click(getByAltText(fixtureCape.pivot.item_name))

    expect(
      queryByText(`${t('general.skin')} & ${t('general.cape')}`),
    ).toBeInTheDocument()

    fireEvent.click(getByText(t('user.resetSelected')))
    expect(
      queryByText(`${t('general.skin')} & ${t('general.cape')}`),
    ).not.toBeInTheDocument()
  })
})

test('previews private closet items but blocks applying or using them as an avatar', async () => {
  fetch.get.mockResolvedValue(
    createPaginator([{ ...fixtureSkin, public: false }]),
  )
  const loadSkin = jest.spyOn(SkinViewer.prototype, 'loadSkin')

  const { findByAltText, findByRole, getByRole, queryByText } = render(
    <Closet />,
  )
  const apply = await findByRole('button', { name: t('user.useAs') })
  fireEvent.click(await findByAltText(fixtureSkin.pivot.item_name))

  expect(loadSkin).toHaveBeenCalledWith(`/asset/${fixtureSkin.tid}`, {
    model: 'default',
  })
  expect(queryByText(t('user.setAsAvatar'))).not.toBeInTheDocument()
  expect(apply).toBeDisabled()
  fireEvent.click(apply)
  expect(queryByText(t('user.closet.use-as.title'))).not.toBeInTheDocument()
  expect(fetch.get).not.toHaveBeenCalledWith(urls.user.player.list())
  expect(fetch.put).not.toHaveBeenCalled()
  expect(fetch.post).not.toHaveBeenCalled()

  fireEvent.click(getByRole('button', { name: t('user.resetSelected') }))
  expect(apply).toBeEnabled()
})

describe('set avatar', () => {
  beforeEach(() => {
    fetch.get.mockResolvedValue(createPaginator([fixtureSkin]))

    const img = document.createElement('img')
    img.alt = 'User Image'
    document.body.appendChild(img)
  })

  afterEach(() => {
    document.querySelector('[alt="User Image"]')!.remove()
  })

  it('succeeded', async () => {
    fetch.post.mockResolvedValue({ code: 0, message: 'success' })

    const { getByText, getByRole, queryByText } = render(<Closet />)
    await waitFor(() => expect(fetch.get).toHaveBeenCalledTimes(1))

    fireEvent.click(getByText(t('user.setAsAvatar')))
    fireEvent.click(getByText(t('general.confirm')))
    await waitFor(() =>
      expect(fetch.post).toHaveBeenCalledWith(urls.user.profile.avatar(), {
        tid: fixtureSkin.tid,
      }),
    )
    expect(queryByText('success')).toBeInTheDocument()
    expect(getByRole('status')).toHaveClass('alert-success')
    expect(document.querySelector('[alt="User Image"]')).toHaveAttribute(
      'src',
      `/avatar/${fixtureSkin.tid}`,
    )
  })

  it('failed', async () => {
    fetch.post.mockResolvedValue({ code: 1, message: 'failed' })

    const { getByText, getByRole, queryByText } = render(<Closet />)
    await waitFor(() => expect(fetch.get).toHaveBeenCalledTimes(1))

    fireEvent.click(getByText(t('user.setAsAvatar')))
    fireEvent.click(getByText(t('general.confirm')))
    await waitFor(() =>
      expect(fetch.post).toHaveBeenCalledWith(urls.user.profile.avatar(), {
        tid: fixtureSkin.tid,
      }),
    )
    expect(queryByText('failed')).toBeInTheDocument()
    expect(getByRole('alert')).toHaveClass('alert-danger')
  })

  it('cancelled', async () => {
    const { getByText } = render(<Closet />)
    await waitFor(() => expect(fetch.get).toHaveBeenCalledTimes(1))

    fireEvent.click(getByText(t('user.setAsAvatar')))
    fireEvent.click(getByText(t('general.cancel')))
    await waitFor(() => expect(fetch.post).not.toHaveBeenCalled())
  })
})

describe('apply textures to player', () => {
  it('selected nothing', async () => {
    fetch.get.mockResolvedValue(createPaginator([]))

    const { getByText, getByRole, queryByText } = render(<Closet />)
    await waitFor(() => expect(fetch.get).toHaveBeenCalledTimes(1))

    fireEvent.click(getByText(t('user.useAs')))

    expect(queryByText(t('user.emptySelectedTexture'))).toBeInTheDocument()
    expect(getByRole('status')).toHaveClass('alert-info')
  })

  it('search players', async () => {
    fetch.get
      .mockResolvedValueOnce(createPaginator([fixtureSkin]))
      .mockResolvedValueOnce([fixturePlayer])

    const {
      getByText,
      getByAltText,
      getAllByPlaceholderText,
      queryByText,
      findByText,
    } = render(<Closet />)
    await waitFor(() => expect(fetch.get).toHaveBeenCalledTimes(1))

    fireEvent.click(getByAltText(fixtureSkin.pivot.item_name))
    fireEvent.click(getByText(t('user.useAs')))

    expect(await findByText(fixturePlayer.name)).toBeInTheDocument()

    fireEvent.input(getAllByPlaceholderText(t('user.typeToSearch'))[1]!, {
      target: { value: 'reina' },
    })
    expect(queryByText(fixturePlayer.name)).not.toBeInTheDocument()
  })

  it('succeeded', async () => {
    fetch.get
      .mockResolvedValueOnce(createPaginator([fixtureSkin]))
      .mockResolvedValueOnce([fixturePlayer])
    fetch.put.mockResolvedValue({ code: 0, message: 'success' })

    const { getByText, getByAltText, getByTitle, getByRole, queryByText } =
      render(<Closet />)
    await waitFor(() => expect(fetch.get).toHaveBeenCalled())

    fireEvent.click(getByAltText(fixtureSkin.pivot.item_name))
    fireEvent.click(getByText(t('user.useAs')))
    await waitFor(() => expect(fetch.get).toHaveBeenCalled())
    fireEvent.click(getByTitle(fixturePlayer.name))
    await waitFor(() =>
      expect(fetch.put).toHaveBeenCalledWith(
        urls.user.player.set(fixturePlayer.pid),
        { skin: fixtureSkin.tid },
      ),
    )
    expect(queryByText('success')).toBeInTheDocument()
    expect(getByRole('status')).toHaveClass('alert-success')
  })

  it('failed', async () => {
    fetch.get
      .mockResolvedValueOnce(createPaginator([fixtureSkin]))
      .mockResolvedValueOnce([fixturePlayer])
    fetch.put.mockResolvedValue({ code: 1, message: 'failed' })

    const { getByText, getByAltText, getByTitle, getByRole, queryByText } =
      render(<Closet />)
    await waitFor(() => expect(fetch.get).toHaveBeenCalled())

    fireEvent.click(getByAltText(fixtureSkin.pivot.item_name))
    fireEvent.click(getByText(t('user.useAs')))
    await waitFor(() => expect(fetch.get).toHaveBeenCalled())
    fireEvent.click(getByTitle(fixturePlayer.name))
    await waitFor(() =>
      expect(fetch.put).toHaveBeenCalledWith(
        urls.user.player.set(fixturePlayer.pid),
        { skin: fixtureSkin.tid },
      ),
    )
    expect(queryByText('failed')).toBeInTheDocument()
    expect(getByRole('alert')).toHaveClass('alert-danger')
  })

  it('close dialog', async () => {
    fetch.get
      .mockResolvedValueOnce(createPaginator([fixtureSkin]))
      .mockResolvedValueOnce([fixturePlayer])

    const { getByText, getByAltText } = render(<Closet />)
    await waitFor(() => expect(fetch.get).toHaveBeenCalled())

    fireEvent.click(getByAltText(fixtureSkin.pivot.item_name))
    fireEvent.click(getByText(t('user.useAs')))
    await waitFor(() => expect(fetch.get).toHaveBeenCalled())

    $('#modal-apply').modal('hide').trigger('hidden.bs.modal')

    expect(fetch.put).not.toHaveBeenCalled()
  })
})
