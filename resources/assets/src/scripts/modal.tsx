import { flushSync } from 'react-dom'
import { createRoot } from 'react-dom/client'
import Modal, { type ModalOptions, type ModalResult } from '../components/Modal'

export function showModal(options: ModalOptions = {}): Promise<ModalResult> {
  return new Promise((resolve, reject) => {
    const container = document.createElement('div')
    document.body.appendChild(container)
    const root = createRoot(container)
    let answer: ModalResult | undefined

    const handleClose = () => {
      root.unmount()
      container.remove()
      if (answer || options.mode === 'alert') resolve(answer ?? { value: '' })
      else reject()
    }

    // Finish closing before any chained prompt opens, so Bootstrap's backdrop
    // and scroll lock are cleaned up in order.
    flushSync(() => {
      root.render(
        <Modal
          {...options}
          show
          center
          onConfirm={(value) => { answer = value }}
          onClose={handleClose}
        />,
      )
    })
  })
}
