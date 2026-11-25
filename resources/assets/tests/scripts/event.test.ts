import * as emitter from '@/scripts/event'

test('add listener and emit event', () => {
  const mockA1 = jest.fn()
  const mockA2 = jest.fn()
  const mockB = jest.fn()

  emitter.on('a', mockA1)
  emitter.on('a', mockA2)
  emitter.on('b', mockB)

  emitter.emit('a')

  expect(mockA1).toHaveBeenCalledTimes(1)
  expect(mockA2).toHaveBeenCalledTimes(1)
  expect(mockB).not.toHaveBeenCalled()
})

test('not throw for un-existed event', () => {
  emitter.emit('c')
})

test('unsubscribe event', () => {
  const mock = jest.fn()

  const off = emitter.on('c', mock)
  off()

  expect(mock).not.toHaveBeenCalled()
})
