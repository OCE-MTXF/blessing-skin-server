import { readJsonObject } from './init'

export function getExtraData(): Record<string, unknown> {
  return readJsonObject('blessing-extra')
}

if (process.env.NODE_ENV !== 'test') {
  blessing.extra = getExtraData()
}
