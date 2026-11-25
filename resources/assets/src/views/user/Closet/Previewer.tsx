import React from 'react'
import ReactDOM from 'react-dom'
import useMount from '@/scripts/hooks/useMount'
import textureAssetUrl from '@/scripts/textureAssetUrl'
import ViewerSkeleton from '@/components/ViewerSkeleton'

const Viewer = React.lazy(() => import('@/components/Viewer'))

interface Props {
  skin?: number
  cape?: number
  isAlex: boolean
  children?: React.ReactNode
}

const Previewer: React.FC<Props> = (props) => {
  const container = useMount('#previewer')

  const skin = textureAssetUrl(props.skin)
  const cape = textureAssetUrl(props.cape)

  return (
    container &&
    ReactDOM.createPortal(
      <React.Suspense fallback={<ViewerSkeleton />}>
        <Viewer skin={skin} cape={cape} isAlex={props.isAlex} showIndicator>
          {props.children}
        </Viewer>
      </React.Suspense>,
      container,
    )
  )
}

export default Previewer
