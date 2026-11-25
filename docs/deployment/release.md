# 本地离线发布包

锁定工具链：PHP **8.6.0**（含 `ext-zip`）、Composer **2.11.3**、Node **24.21.0**（含 npm **11.19.0**）。核对来源和散列：

- [官方 PHP 下载](https://www.php.net/downloads.php)、[PHP for Windows](https://windows.php.net/download/)。运行时平台依赖以 `composer.lock` 和 `composer check-platform-reqs --no-dev` 为准。
- [Composer 下载 2.11.3](https://getcomposer.org/download/2.11.3/composer.phar)，SHA-256 `04f8b003da4e3a2459056591fbfebd02b4dc3e5adeb158616b90cf55a65113ec`。
- [Node 24.21.0 校验值](https://nodejs.org/dist/v24.21.0/SHASUMS256.txt)。Docker 采用 [Docker Official Image 元数据](https://api.github.com/repos/docker-library/repo-info/git/blobs/6d5ffcc9c07adc244dcbd435bb069e940689e01d) 对应的 `php:8.6.0-apache-trixie` 索引 `sha256:7394e376d8dec30bfa474614d37bfce171e4fdf57a1398df63afca152d396b14`。源码 Dockerfile 锁定 Debian 已签名的 main 快照 `20261124T150911Z` 与 security 快照 `20261124T184346Z`；维护者升级基础镜像或快照时应复核安全公告并复跑验证。

在无实际部署密钥的受信任候选工作树运行（构建需要可访问锁定依赖仓库，构建后的站点安装/升级不需要网络）：

```sh
php tools/release.php build . ./target/release 6.0.3 /absolute/path/to/composer-2.11.3.phar
```

追加 `--validate-only` 只构建和检查负载、不生成归档。允许执行 PowerShell 脚本的 Windows 环境也可使用 `tools/release.ps1` 包装器；不需要修改宿主脚本执行策略。

构建器在临时新目录中校验 Composer 锁文件和 PHP 平台依赖，执行 `composer install --no-dev --no-plugins --no-scripts` 再显式生成包清单，用 `npm ci --registry=https://registry.npmjs.org --ignore-scripts` 后重建所需静态资源。ZIP 仅包含允许列表中的源码、生产依赖、静态资源、文档和空运行时目录；不包含私有 `.env`、插件安装、运行数据、node_modules、开发依赖、缓存/上传或 test fixtures。稳定排序、归档时间、权限元数据，生成同名 `.zip.sha256`。不同 OS 或压缩库版本的 ZIP 字节不保证相同；复现时固定同一工具链与平台，比较两次独立构建内容。对被审阅的同一提交签名并在可信渠道分发校验值；构建器和 CI 不上传到公开发布、推送更新清单或自动部署。

开发可用相同 `npm ci`、`npm run build`、`npm test -- --runInBand`、`php vendor/bin/phpunit`；`npm run dev` 仅原地监听和重建前端，不开放开发 HTTP 服务。任何过往指向 Web 安装/自动更新的指南不适用于 6.0.3。
