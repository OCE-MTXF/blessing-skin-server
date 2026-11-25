# Compatibility wrapper: the pinned npm build emits and cleans all assets.
param([switch] $Simple)

npm run build
exit $LASTEXITCODE
