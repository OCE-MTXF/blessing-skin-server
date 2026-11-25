import React, { useState, useEffect, useMemo } from 'react'
import { t } from '@/scripts/i18n'
import * as fetch from '@/scripts/net'
import Loading from '@/components/Loading'
import Pagination from '@/components/Pagination'
import type { Plugin } from './types'
import Row from './Row'

const pageSize = 10

const PluginsMarket: React.FC = () => {
  const [plugins, setPlugins] = useState<Plugin[] | null>(null)
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)

  const searchedPlugins = useMemo(
    () =>
      plugins?.filter(
        (plugin) =>
          plugin.name.includes(search) || plugin.title.includes(search),
      ) ?? [],
    [plugins, search],
  )

  useEffect(() => {
    let active = true
    const getPlugins = async () => {
      const data = await fetch
        .get<Plugin[] | undefined>('/admin/plugins/market/list')
        .catch(() => undefined)
      if (active) {
        setPlugins(Array.isArray(data) ? data : [])
      }
    }
    void getPlugins()
    return () => {
      active = false
    }
  }, [])

  const handleSearchChange = (event: React.ChangeEvent<HTMLInputElement>) => {
    setSearch(event.target.value)
    setPage(1)
  }

  const totalPages = Math.ceil(searchedPlugins.length / pageSize)
  const pagedPlugins = searchedPlugins.slice(
    (page - 1) * pageSize,
    page * pageSize,
  )

  return (
    <div className="card">
      <div className="card-header">
        <input
          type="search"
          className="form-control"
          aria-label={t('vendor.datatable.search')}
          placeholder={t('vendor.datatable.search')}
          value={search}
          onChange={handleSearchChange}
        />
      </div>
      {plugins === null ? (
        <div className="card-body">
          <Loading />
        </div>
      ) : searchedPlugins.length === 0 ? (
        <div className="card-body text-center">{t('general.noResult')}</div>
      ) : (
        <div className="card-body table-responsive p-0">
          <table className="table table-striped">
            <thead>
              <tr>
                <th>{t('admin.pluginTitle')}</th>
                <th>{t('admin.pluginDescription')}</th>
                <th>{t('admin.pluginAuthor')}</th>
                <th>{t('admin.pluginVersion')}</th>
                <th>{t('admin.pluginDependencies')}</th>
              </tr>
            </thead>
            <tbody>
              {pagedPlugins.map((plugin) => (
                <Row key={plugin.name} plugin={plugin} />
              ))}
            </tbody>
          </table>
        </div>
      )}
      {totalPages > 1 && (
        <div className="card-footer">
          <div className="float-right">
            <Pagination
              page={page}
              totalPages={totalPages}
              onChange={setPage}
            />
          </div>
        </div>
      )}
    </div>
  )
}

export default PluginsMarket
