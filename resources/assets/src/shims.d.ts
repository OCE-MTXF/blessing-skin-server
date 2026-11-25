import { ModalOptions, ModalResult } from './components/Modal'
import { Toast } from './scripts/toast'

declare global {
  // eslint-disable-next-line no-redeclare
  let blessing: {
    base_url: string
    debug: boolean
    env: string
    locale: string
    site_name: string
    version: string
    route: string
    extra: any
    i18n: object

    fetch: {
      get(url: string, params?: object): Promise<object>
      post(url: string, data?: object): Promise<object>
      put(url: string, data?: object): Promise<object>
      del(url: string, data?: object): Promise<object>
    }

    event: {
      on(eventName: string | symbol, listener: (payload: unknown) => unknown): () => void
      emit(eventName: string | symbol, payload?: unknown): void
    }

    notify: {
      showModal(options?: ModalOptions): Promise<ModalResult>
      toast: Toast
    }
  }
}
