/** @jsxImportSource @emotion/react */
import * as React from 'react'
import { t } from '@/scripts/i18n'
import * as cssUtils from '@/styles/utils'

class Captcha extends React.Component<object, { value: string; revision: number }> {
  state = { value: '', revision: Date.now() }

  execute = async () => this.state.value

  reset = () => {
    this.setState(({ revision }) => ({ value: '', revision: revision + 1 }))
  }

  render() {
    return (
      <div className="d-flex">
        <div className="form-group mb-3 mr-2">
          <input
            type="text"
            className="form-control"
            placeholder={t('auth.captcha')}
            autoComplete="off"
            required
            value={this.state.value}
            onChange={(event) => this.setState({ value: event.target.value })}
          />
        </div>
        <img
          src={`${blessing.base_url}/auth/captcha?v=${this.state.revision}`}
          alt={t('auth.captcha')}
          css={cssUtils.pointerCursor}
          height={34}
          title={t('auth.change-captcha')}
          onClick={this.reset}
        />
      </div>
    )
  }
}

export default Captcha
