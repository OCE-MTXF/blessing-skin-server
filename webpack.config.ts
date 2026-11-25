import * as path from 'node:path'
import type { Configuration } from 'webpack'
import MiniCssExtractPlugin from 'mini-css-extract-plugin'
import CssMinimizerPlugin from 'css-minimizer-webpack-plugin'
import HtmlWebpackEnhancementPlugin from './tools/HtmlWebpackEnhancementPlugin.ts'

const root = import.meta.dirname

export default function (_env: unknown, argv: { mode?: string }): Configuration {
  const isDev = argv.mode === 'development'

  return {
    name: 'app',
    mode: isDev ? 'development' : 'production',
    entry: {
      app: '@/index.tsx',
      style: [
        '@/styles/common.css',
        '@/styles/vendor/adminlte/adminlte.components.min.css',
        '@/styles/vendor/adminlte/adminlte.core.min.css',
        '@/styles/vendor/adminlte/adminlte.pages.min.css',
        '@/styles/vendor/adminlte/adminlte.light.min.css',
        '@fortawesome/fontawesome-free/css/all.min.css',
      ],
      home: '@/scripts/homePage.ts',
      'home-css': '@/styles/home.css',
      spectre: [
        'spectre.css/dist/spectre.min.css',
        '@/fonts/minecraft.css',
        '@/styles/spectre.css',
      ],
    },
    output: {
      path: path.resolve(root, 'public/app'),
      publicPath: 'auto',
      // GlobalThis is available in the current browser target; do not emit a
      // Function constructor fallback requiring unsafe-eval under CSP.
      environment: { globalThis: true },
      clean: true,
      filename: isDev ? '[name].js' : '[name].[contenthash:12].js',
      chunkFilename: isDev ? '[id].js' : '[id].[contenthash:12].js',
      assetModuleFilename: '[contenthash:12][ext]',
    },
    module: {
      rules: [
        {
          test: /\.tsx?$/,
          loader: 'ts-loader',
          options: { configFile: 'tsconfig.build.json', transpileOnly: true },
        },
        {
          test: /\.css$/,
          use: [
            { loader: MiniCssExtractPlugin.loader, options: { publicPath: '' } },
            { loader: 'css-loader', options: { importLoaders: 1 } },
            'postcss-loader',
          ],
        },
        {
          test: /\.(png|webp|svg|woff2?|eot|ttf)$/,
          type: 'asset/resource',
        },
      ],
    },
    plugins: [
      new MiniCssExtractPlugin({
        filename: isDev ? '[name].css' : '[name].[contenthash:12].css',
        chunkFilename: isDev ? '[id].css' : '[id].[contenthash:12].css',
      }),
      new HtmlWebpackEnhancementPlugin(root),
    ],
    resolve: {
      extensions: ['.js', '.ts', '.tsx'],
      alias: { '@': path.resolve(root, 'resources/assets/src') },
    },
    optimization: {
      minimizer: [new CssMinimizerPlugin(), '...'],
    },
    devtool: isDev ? 'source-map' : false,
    stats: 'errors-warnings',
    performance: false,
  }
}
