import { useState, useEffect } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { flushSync } from 'react-dom'
import * as emitter from './event'
import ToastBox, { ToastType } from '../components/Toast'

type QueueElement = { id: number; type: ToastType; message: string }
const TOAST_EVENT = Symbol('toast')
const CLEAR_EVENT = Symbol('clear')
let nextId = 0

export function ToastContainer() {
  const [queue, setQueue] = useState<QueueElement[]>([])

  const handleClose = (id: number) => {
    setQueue((current) => current.filter((el) => el.id !== id))
  }

  useEffect(() => {
    const timers = new Set<ReturnType<typeof setTimeout>>()
    const clearTimers = () => {
      timers.forEach(clearTimeout)
      timers.clear()
    }
    const stopToasts = emitter.on(TOAST_EVENT, (toast: QueueElement) => {
      setQueue((current) => [...current, toast])
      const timer = setTimeout(() => {
        timers.delete(timer)
        handleClose(toast.id)
      }, 3100)
      timers.add(timer)
    })
    const stopClear = emitter.on(CLEAR_EVENT, () => {
      clearTimers()
      setQueue([])
    })

    return () => {
      stopToasts()
      stopClear()
      clearTimers()
    }
  }, [])

  return (
    <>
      {queue.map((el, i) => (
        <ToastBox
          key={el.id}
          type={el.type}
          distance={50 + i * 70}
          onClose={() => handleClose(el.id)}
        >
          {el.message}
        </ToastBox>
      ))}
    </>
  )
}

export class Toast {
  private container?: HTMLDivElement
  private root?: Root

  constructor(render?: (element: JSX.Element) => void) {
    if (render) {
      render(<ToastContainer />)
    } else {
      this.container = document.createElement('div')
      document.body.appendChild(this.container)
      this.root = createRoot(this.container)
      // Subscribe before the first notification can be sent.
      flushSync(() => this.root?.render(<ToastContainer />))
    }
  }

  private push(type: ToastType, message: string) {
    emitter.emit(TOAST_EVENT, { id: nextId++, type, message })
  }

  success(message: string) {
    this.push('success', message)
  }

  info(message: string) {
    this.push('info', message)
  }

  warning(message: string) {
    this.push('warning', message)
  }

  error(message: string) {
    this.push('error', message)
  }

  clear() {
    emitter.emit(CLEAR_EVENT)
  }

  dispose() {
    this.root?.unmount()
    this.container?.remove()
  }
}
