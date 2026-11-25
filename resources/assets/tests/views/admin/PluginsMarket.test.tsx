import React from 'react'
import { fireEvent, render, screen } from '@testing-library/react'
import { t } from '@/scripts/i18n'
import * as fetch from '@/scripts/net'
import PluginsMarket from '@/views/admin/PluginsMarket'
import type { Plugin } from '@/views/admin/PluginsMarket/types'

jest.mock('@/scripts/net')

const fixture: Plugin = {
  name: 'yggdrasil-api',
  title: 'Yggdrasil API',
  description: 'Auth System',
  version: '1.0.0',
  author: 'Blessing Skin',
  dependencies: {
    all: { 'blessing-skin-server': '^5.0.0' },
    unsatisfied: {},
  },
}

test('loads catalog metadata and renders it as plain text without mutating controls', async () => {
  const metadata = {
    ...fixture,
    title: '<b>Catalog title</b>',
    author: '<em>Catalog author</em>',
    description: '<i>Catalog description</i>',
    version: '<small>1.0.0</small>',
    homepage: 'https://example.invalid/plugin',
    dist: { url: 'https://example.invalid/plugin.zip' },
    installed: '0.5.0',
    can_update: true,
  }
  fetch.get.mockResolvedValue([metadata])

  const { container } = render(<PluginsMarket />)

  expect(screen.getByTitle('Loading...')).toBeInTheDocument()
  expect(await screen.findByText(metadata.title)).toBeInTheDocument()
  expect(screen.getByText(metadata.name)).toBeInTheDocument()
  expect(screen.getByText(metadata.author)).toBeInTheDocument()
  expect(screen.getByText(metadata.description)).toBeInTheDocument()
  expect(screen.getByText(metadata.version)).toBeInTheDocument()
  expect(container.querySelector('td em, td i, td small, b b')).toBeNull()
  expect(screen.queryByRole('link')).not.toBeInTheDocument()
  expect(screen.queryByRole('button')).not.toBeInTheDocument()
  expect(container.querySelector('[src]')).toBeNull()
  expect(fetch.get).toHaveBeenCalledTimes(1)
  expect(fetch.get).toHaveBeenCalledWith('/admin/plugins/market/list')
  expect(fetch.post).not.toHaveBeenCalled()
})

test('paginates the catalog and resets to page one when filtering by title or name', async () => {
  const firstPage = Array.from({ length: 10 }, (_, i) => ({
    ...fixture,
    name: `catalog-module-${i + 1}`,
    title: `Catalog module ${i + 1}`,
  }))
  fetch.get.mockResolvedValue([...firstPage, fixture])

  render(<PluginsMarket />)

  expect(await screen.findByText('Catalog module 1')).toBeInTheDocument()
  expect(screen.queryByText(fixture.name)).not.toBeInTheDocument()

  fireEvent.click(screen.getByRole('link', { name: '2' }))
  expect(screen.getByText(fixture.name)).toBeInTheDocument()
  expect(screen.queryByText('Catalog module 1')).not.toBeInTheDocument()

  const search = screen.getByRole('searchbox')
  fireEvent.change(search, { target: { value: 'Catalog module 3' } })
  expect(screen.getByText('catalog-module-3')).toBeInTheDocument()
  expect(screen.queryByText(fixture.name)).not.toBeInTheDocument()
  expect(screen.queryByRole('link', { name: '2' })).not.toBeInTheDocument()

  fireEvent.change(search, { target: { value: fixture.name } })
  expect(screen.getByText(fixture.title)).toBeInTheDocument()
  expect(screen.queryByText('catalog-module-3')).not.toBeInTheDocument()

  fireEvent.change(search, { target: { value: 'no matching plugin' } })
  expect(screen.getByText(t('general.noResult'))).toBeInTheDocument()
  expect(screen.queryByRole('table')).not.toBeInTheDocument()
  expect(fetch.get).toHaveBeenCalledTimes(1)
  expect(fetch.post).not.toHaveBeenCalled()
})

test('displays requirements with their satisfaction status', async () => {
  fetch.get.mockResolvedValue([
    {
      ...fixture,
      dependencies: {
        all: { 'blessing-skin-server': '^5.0.0', 'local-dependency': '^1.0.0' },
        unsatisfied: {
          'local-dependency': { version: '0.9.0', constraint: '^1.0.0' },
        },
      },
    },
    {
      ...fixture,
      name: 'standalone-plugin',
      dependencies: { all: {}, unsatisfied: {} },
    },
  ])

  render(<PluginsMarket />)

  expect(await screen.findByText('blessing-skin-server: ^5.0.0')).toHaveClass(
    'bg-green',
  )
  expect(screen.getByText('local-dependency: ^1.0.0')).toHaveClass('bg-red')
  expect(screen.getByText(t('admin.noDependencies'))).toBeInTheDocument()
})

test('shows an empty read-only catalog when no entries are available', async () => {
  fetch.get.mockResolvedValue([])

  render(<PluginsMarket />)

  expect(await screen.findByText(t('general.noResult'))).toBeInTheDocument()
  expect(screen.queryByRole('table')).not.toBeInTheDocument()
  expect(screen.queryByRole('button')).not.toBeInTheDocument()
})
