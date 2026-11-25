import { useState, useEffect } from 'react'
import * as fetch from '../net'
import textureAssetUrl from '../textureAssetUrl'
import type { Texture } from '../types'
import urls from '../urls'

export default function useTexture(tid?: number) {
  const url = textureAssetUrl(tid)
  const [metadata, setMetadata] = useState<Pick<Texture, 'tid' | 'type'>>()

  useEffect(() => {
    if (tid === undefined || !url) {
      return
    }

    let current = true
    const getModel = async () => {
      const { type } = await fetch.get<Texture>(urls.skinlib.info(tid))
      if (current) {
        setMetadata({ tid, type })
      }
    }
    getModel()
    return () => {
      current = false
    }
  }, [tid, url])

  return {
    url,
    type: metadata && metadata.tid === tid ? metadata.type : undefined,
  }
}
