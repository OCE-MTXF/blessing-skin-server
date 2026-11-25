export default function textureAssetUrl(tid?: number): string {
  if (tid === undefined || !Number.isSafeInteger(tid) || tid <= 0) {
    return ''
  }
  return `${blessing.base_url}/asset/${tid}`
}
