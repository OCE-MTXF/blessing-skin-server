import { act, render, fireEvent, screen } from '@testing-library/react'
import { Toast } from '@/scripts/toast'

test('"Toast" class', () => {
  const toast = new Toast(render)

  act(() => toast.success('success'))
  expect(document.querySelector('.alert-success')!.textContent).toContain(
    'success',
  )

  act(() => toast.info('info'))
  expect(document.querySelector('.alert-info')!.textContent).toContain('info')

  act(() => toast.warning('warning'))
  expect(document.querySelector('.alert-warning')!.textContent).toContain(
    'warning',
  )

  act(() => toast.error('error'))
  expect(document.querySelector('.alert-danger')!.textContent).toContain(
    'error',
  )

  act(() => jest.runAllTimers())
  toast.dispose()
})

test('clear toasts', () => {
  const toast = new Toast(render)

  act(() => toast.success('success'))
  act(() => toast.info('info'))

  act(() => toast.clear())
  expect(document.querySelectorAll('.alert')).toHaveLength(0)

  toast.dispose()
})

test('close toast manually', () => {
  const toast = new Toast(render)
  act(() => toast.success('success'))

  fireEvent.click(screen.getByText('×'))
  expect(document.querySelectorAll('.alert')).toHaveLength(0)

  toast.dispose()
})
