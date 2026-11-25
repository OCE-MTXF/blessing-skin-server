import React from 'react'
import { fireEvent, render, screen } from '@testing-library/react'
import { t } from '@/scripts/i18n'
import * as fetch from '@/scripts/net'
import PluginsManagement from '@/views/admin/PluginsManagement'
import type { Plugin } from '@/views/admin/PluginsManagement/types'

jest.mock('@/scripts/net')

const fixture: Plugin = {
  name: 'local-plugin',
  title: 'Local plugin',
  description: 'Installed offline',
  version: '1.0.0',
  approved: true,
  can_manage: true,
  enabled: true,
  readme: true,
  config: true,
  icon: { fa: 'plug', faType: 'fas', bg: 'navy' },
}

const originalBaseUrl = blessing.base_url

afterEach(() => {
  blessing.base_url = originalBaseUrl
})

test('loads installed plugins from the local list', async () => {
  fetch.get.mockResolvedValue([])

  render(<PluginsManagement />)

  expect(screen.getByTitle('Loading...')).toBeInTheDocument()
  expect(await screen.findByText(t('general.noResult'))).toBeInTheDocument()
  expect(fetch.get).toHaveBeenCalledTimes(1)
  expect(fetch.get).toHaveBeenCalledWith('/admin/plugins/data')
})

test('shows plain text and only the encoded local links and allowed toggle', async () => {
  blessing.base_url = `${window.location.origin}/skin-site/`
  const title = '<b>Local plugin</b>'
  const description = '<em>Installed offline</em>'
  fetch.get.mockResolvedValue([
    { ...fixture, name: 'local plugin #1', title, description },
  ])

  const { container } = render(<PluginsManagement />)

  expect(await screen.findByText(title)).toBeInTheDocument()
  expect(screen.getByText(description)).toBeInTheDocument()
  expect(screen.getByText('v1.0.0')).toBeInTheDocument()
  expect(container.querySelector('b, em')).toBeNull()
  expect(screen.getByRole('checkbox')).toBeChecked()
  expect(
    screen.getByRole('link', { name: t('admin.pluginReadme') }),
  ).toHaveAttribute(
    'href',
    `${window.location.origin}/skin-site/admin/plugins/readme/local%20plugin%20%231`,
  )
  expect(
    screen.getByRole('link', { name: t('admin.configurePlugin') }),
  ).toHaveAttribute(
    'href',
    `${window.location.origin}/skin-site/admin/plugins/config/local%20plugin%20%231`,
  )
  expect(screen.getAllByRole('link')).toHaveLength(2)
  expect(screen.queryByRole('button')).not.toBeInTheDocument()
  expect(container.querySelectorAll('input')).toHaveLength(1)
  expect(screen.queryByTitle(t('admin.deletePlugin'))).not.toBeInTheDocument()
  expect(fetch.post).not.toHaveBeenCalled()
})

test.each([
  ['unapproved', { approved: false }],
  ['lacking management permission', { can_manage: false }],
  ['missing approval', { approved: undefined }],
  ['missing management permission', { can_manage: undefined }],
  ['non-boolean approval', { approved: 'true' }],
  ['non-boolean management permission', { can_manage: 1 }],
])('hides management and configuration when %s', async (_, permissions) => {
  fetch.get.mockResolvedValue([{ ...fixture, ...permissions }])

  render(<PluginsManagement />)

  expect(await screen.findByText(fixture.title)).toBeInTheDocument()
  expect(screen.queryByRole('checkbox')).not.toBeInTheDocument()
  expect(
    screen.queryByTitle(t('admin.configurePlugin')),
  ).not.toBeInTheDocument()
  if ('approved' in permissions) {
    expect(screen.queryByTitle(t('admin.pluginReadme'))).not.toBeInTheDocument()
  }
  expect(fetch.post).not.toHaveBeenCalled()
})

test('retains the README for approved plugins without management permission', async () => {
  fetch.get.mockResolvedValue([
    { ...fixture, can_manage: false, enabled: false },
  ])

  render(<PluginsManagement />)

  expect(
    await screen.findByRole('link', { name: t('admin.pluginReadme') }),
  ).toBeInTheDocument()
  expect(screen.queryByRole('checkbox')).not.toBeInTheDocument()
})

test('hides unavailable configuration and README links', async () => {
  fetch.get.mockResolvedValue([{ ...fixture, config: false, readme: false }])

  render(<PluginsManagement />)

  expect(await screen.findByRole('checkbox')).toBeChecked()
  expect(screen.queryByRole('link')).not.toBeInTheDocument()
})

test('does not use an off-origin application base for plugin navigation', async () => {
  blessing.base_url = 'https://example.invalid/skin-site'
  fetch.get.mockResolvedValue([fixture])

  render(<PluginsManagement />)

  expect(await screen.findByText(fixture.title)).toBeInTheDocument()
  expect(screen.queryByRole('link')).not.toBeInTheDocument()
})

test('refreshes configuration and dependent state after approved toggles', async () => {
  const disabled = { ...fixture, enabled: false, config: false }
  const dependent = { ...fixture, name: 'dependent', title: 'Dependent plugin' }
  const disabledDependent = { ...dependent, enabled: false, config: false }
  fetch.get
    .mockResolvedValueOnce([fixture, dependent])
    .mockResolvedValueOnce([disabled, disabledDependent])
    .mockResolvedValueOnce([fixture, disabledDependent])
  fetch.post
    .mockResolvedValueOnce({ code: 0, message: 'Disabled successfully' })
    .mockResolvedValueOnce({ code: 0, message: 'Enabled successfully' })

  render(<PluginsManagement />)
  const [toggle, dependentToggle] = await screen.findAllByRole('checkbox')

  expect(toggle).toBeChecked()
  expect(dependentToggle).toBeChecked()
  expect(screen.getAllByTitle(t('admin.configurePlugin'))).toHaveLength(2)

  fireEvent.click(toggle)
  expect(await screen.findByText('Disabled successfully')).toBeInTheDocument()
  expect(toggle).not.toBeChecked()
  expect(dependentToggle).not.toBeChecked()
  expect(
    screen.queryByTitle(t('admin.configurePlugin')),
  ).not.toBeInTheDocument()
  expect(fetch.post).toHaveBeenNthCalledWith(1, '/admin/plugins/manage', {
    action: 'disable',
    name: fixture.name,
  })

  fireEvent.click(toggle)
  expect(await screen.findByText('Enabled successfully')).toBeInTheDocument()
  expect(toggle).toBeChecked()
  expect(dependentToggle).not.toBeChecked()
  expect(screen.getByTitle(t('admin.configurePlugin'))).toBeInTheDocument()
  expect(fetch.post).toHaveBeenNthCalledWith(2, '/admin/plugins/manage', {
    action: 'enable',
    name: fixture.name,
  })
  expect(fetch.post).toHaveBeenCalledTimes(2)
  expect(fetch.get.mock.calls).toEqual([
    ['/admin/plugins/data'],
    ['/admin/plugins/data'],
    ['/admin/plugins/data'],
  ])
})

test('shows enable failures as literal text without changing plugin state', async () => {
  fetch.get.mockResolvedValue([{ ...fixture, enabled: false }])
  const message = '<strong>Requirements not met</strong>'
  const reason = '<em>Requires a newer version</em>'
  fetch.post.mockResolvedValue({ code: 1, message, data: { reason: [reason] } })

  render(<PluginsManagement />)
  const toggle = await screen.findByRole('checkbox')
  expect(
    screen.queryByRole('link', { name: t('admin.configurePlugin') }),
  ).not.toBeInTheDocument()
  fireEvent.click(toggle)

  expect(await screen.findByText(message)).toBeInTheDocument()
  expect(screen.getByText(reason)).toBeInTheDocument()
  expect(
    document.querySelector('.modal-body strong, .modal-body em'),
  ).toBeNull()
  expect(toggle).not.toBeChecked()
  fireEvent.click(screen.getByText(t('general.confirm')))
})

test('leaves configuration and enabled state unchanged when disabling fails', async () => {
  fetch.get.mockResolvedValue([fixture])
  fetch.post.mockResolvedValue({ code: 1, message: 'Cannot disable plugin' })

  render(<PluginsManagement />)
  const toggle = await screen.findByRole('checkbox')
  fireEvent.click(toggle)

  expect(await screen.findByText('Cannot disable plugin')).toBeInTheDocument()
  expect(toggle).toBeChecked()
  expect(screen.getByTitle(t('admin.configurePlugin'))).toBeInTheDocument()
})
