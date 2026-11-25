import { flushSync } from 'react-dom'
import { showModal } from './modal'
import { Toast } from './toast'

export const toast = new Toast()

/* istanbul ignore next */
if (process.env.NODE_ENV === 'test') {
  afterEach(() => {
    flushSync(() => toast.clear())
  })
}

Object.assign(blessing, { notify: { showModal, toast } })

export { showModal } from './modal'
