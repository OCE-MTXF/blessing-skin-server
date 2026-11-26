- **简体中文**
- [English](./README.md)

# Blessing Skin 6.0.3 · PHP 8.6

这是从 [Blessing Skin](https://github.com/bs-community/blessing-skin-server) 6.0.2 派生的 PHP 8.6 安全维护版本，不是上游官方发布。

Blessing Skin 是一款能让您上传、管理和分享您的 Minecraft 皮肤和披风的 Web 应用程序。与修改游戏材质包不同的是，所有人都能在游戏中看到各自的皮肤和披风（当然，前提是玩家们要使用同一个皮肤站）。

Blessing Skin 是一个开源的 PHP 项目，这意味着您可以自由地在您的服务器上部署它。

## 特性

- 完整实现了一个皮肤站该有的功能
- 支持单用户多个角色
- 通过皮肤库来分享您的皮肤和披风！
- 易于使用
  - 可视化的用户、角色、材质管理页面
  - 详细的站点配置页面
  - 多处 UI/UX 优化只为更好的用户体验
- 安全
  - 支持多种安全密码 Hash 算法
  - 注册可要求 Email 验证
  - 防止恶意请求的积分系统
- 强大的可扩展性
  - 多种多样的插件
  - 支持与 Authme/Discuz 等程序的用户数据对接（插件）
  - 支持自定义 Yggdrasil API 外置登录系统（插件）

## 环境要求

使用 PHP 8.6（已验证 8.6.0）、支持 PNG/WebP 的 GD、OpenSSL、Argon2id 和对应的 PDO 驱动。仅将 `public/` 设为 HTTPS Web 根。详见[部署指南](docs/deployment/README.md)；本版本无需 Imagick。

## 安装和升级

从可信渠道获取并验证本地发布包后，使用 `php artisan bs:install` 安装、`php artisan bs:upgrade` 升级。Web 安装向导及核心自动更新已移除。升级前需协调备份数据库、密钥、私有配置和材质文件；6.0.2 迁移与恢复方法见[部署指南](docs/deployment/README.md)。

## 插件系统

默认禁用插件。只允许部署人员在公开目录和可写状态目录以外部署经审阅且散列固定的审批清单与插件代码，不能从 Web 下载执行第三方代码。

## 自行构建

[锁定构建和打包方法](docs/deployment/release.md)会从干净临时目录生成只含生产依赖与允许列表文件的归档。

## 国际化（i18n）

Blessing Skin 可支持多种语言，当前支持英语、简体中文和西班牙语。

如果您愿意将您的翻译贡献出来，欢迎参与 [我们的 Crowdin 项目](https://crowdin.com/project/blessing-skin)。

## 文档

- [部署、升级与回滚](docs/deployment/README.md)
- [插件规约](docs/plugin-policy.md)
- [材质策略](docs/texture-policy.md)

报告问题时，提供版本、运行拓扑和已脱敏的最少错误信息；不要上传完整日志、配置、数据库、令牌或密钥。上游手册中的 Web 安装、在线更新及旧插件说明不适用于本版本。

## 版权

MIT License，完整文本见 [LICENSE](LICENSE)。

Copyright (c) 2016-present The Blessing Skin Team —— 上游 Blessing Skin Server。

Copyright (c) 2026 OCE_MTXF —— PHP 8.6 下游维护改动。

本版本是下游维护版，不是上游发行版，与上游团队无从属关系，也不代表上游团队的认可。

感谢Blessing skin server 团队。
感谢你们的源代码。

程序原作者为 [@printempw](https://printempw.github.io/)，转载请注明。
