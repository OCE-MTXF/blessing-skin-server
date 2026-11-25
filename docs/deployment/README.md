# Blessing Skin 6.0.3：离线部署与升级

本版本没有 Web 安装向导，也不会从 Web 下载、解压或覆盖核心代码。`/setup` 和 `/admin/update` 已停用。操作人员应使用可信渠道获取签名的发布记录及归档校验值，在独立目录验证后再发布。**与 ZIP 同源下载的 SHA-256 只能发现损坏，不能代替发布者身份认证。** 不要把新 ZIP 覆盖解压到旧站点。

## 运行条件与目录权限

- PHP 8.6（验证版本 8.6.0）、Argon2id、GD PNG/WebP；核对 `ctype`、`curl`、`dom`、`fileinfo`、`filter`、`hash`、`iconv`、`intl`、`json`、`libxml`、`mbstring`、`openssl`、`pcre`、`PDO`、`session`、`sodium`、`tokenizer`、`xml`、`xmlwriter`，另启用所用数据库的 PDO 驱动。模块化发行版要特别确认 CLI **和** FPM 均加载 `iconv`；生产依赖检查会拒绝不满足的 SAPI。ZIP **构建器**还需 `zip`；无需 Imagick。运行 `composer check-platform-reqs --no-dev`，并用目标 PHP-FPM 实际启动验证扩展；CLI 通过不能替代 FPM。
- 只有 `public/` 是 Web 根；不对代码、`.env`、Passport PEM、SQLite 文件、日志、插件目录或 `storage/` 建立公开别名。Web 账户不可写代码、依赖、引导清单或静态资源；运行时状态和模板缓存由指定 PHP 身份写入 Web 根之外的私有目录。将 `LARAVEL_STORAGE_PATH` 的绝对路径直接注入 **FPM、CLI 与 Worker 的进程环境**（Laravel 在加载 `.env` 前就绑定存储路径），`TEXTURES_DIR` 指向私有持久存储（`FS_DRIVER=local`）。不要带入旧 `options.php` 或运行时代码缓存。对于 ZIP，保留 `bootstrap/cache` 内附的构建时清单，只读即可；不要从上一版本复制。引导或缓存重建如有必要，由部署账户在离线阶段执行，不由 Web 账户执行。
- `APP_ENV=production`、`APP_DEBUG=false`、`APP_URL=https://实际站点`（单一规范 HTTPS 源，不能附路径）。终止 TLS 在应用前的代理时，隔离应用内网端口，边缘拒绝非规范 Host，传递已验证的 Host，并**覆盖**而非追加 `X-Forwarded-Proto`，清除客户端转发头，在 `APP_TRUSTED_PROXIES` 仅填写实际代理的 IP 或 CIDR。直接 TLS 留空。生产 cookie 以 HTTPS 发放。
- 准备独立数据库。MySQL/MariaDB 使用 InnoDB、直连数据库（不得通过事务池或读写拆分连接升级）；生产数据库优先采用受当前 DB 供应商安全维护的版本。SQLite 仅单机本地文件，预先创建持久数据库文件，目录允许同一应用身份创建 SQLite journal 和 `.lifecycle.lock`；不支持网络文件系统、多节点共享 SQLite、临时盘或 `:memory:` 部署。PostgreSQL 代码路径未完成本地端到端验证；SQL Server 不在本次支持范围。
- 插件默认关闭。需启用时使用受部署身份保护且不在 `public/` 或可写状态树内的绝对 `PLUGINS_DIR` 与 `PLUGINS_APPROVALS_FILE`，以及审批清单原字节的 `PLUGINS_APPROVALS_SHA256`；应用进程不可写。可选 `PLUGINS_CATALOG_FILE` 只指向预先审阅的本地文件。不能将已下载的第三方 PHP 插件当作隔离代码运行。参阅 [离线插件审批和旧设置迁移](../plugin-policy.md)。

## 邮件与队列（生产必配）

- 生产使用 `QUEUE_CONNECTION=database`，并独立运行受监控、自动重启的低权限 CLI Worker（例如 Linux 下 `php artisan queue:work database --queue=default --timeout=30 --tries=1`，CLI 启用 `pcntl` 以执行 Worker 超时；容器从同一经过验证的运行镜像以 `php -d extension=pcntl artisan queue:work database --queue=default --timeout=30 --tries=1` 运行独立的 Worker，将 Worker 容器停止信号设为 `SIGTERM`，宽限至少 35 秒）。Worker 与 Web 使用相同的已发布代码、实例 DB 前缀、规范 `APP_URL`、私有状态目录和 `APP_KEY`；安装/升级完成且 `/healthz` 就绪后再启动。切换版本、回退或恢复备份前停止旧 Worker，避免跨版本消费队列或重复寄送一次性链接。
- 使用受信任、验证 TLS 证书的 SMTP 中继：`MAIL_MAILER=smtp`，优先使用 `MAIL_PORT=465`（隐式 TLS），或经过验证且必须成功协商 STARTTLS 的 587。用 `MAIL_HOST` 配置与证书匹配的中继主机名，通过私有配置提供专用账户 `MAIL_USERNAME`、`MAIL_PASSWORD` 及已批准的 `MAIL_FROM_ADDRESS`，限制出站目的地。不要使用 `log` 或明文 SMTP 替代中继；不要以 `sync` 在 HTTP 请求内执行恢复作业。监控 Worker 活性、队列积压、失败数量及通用发送异常；应用、边缘访问日志和异常采集都不得记录一次性链接、查询参数、邮件正文、队列负载或 SMTP 凭据。
- 上线前用专用测试邮箱验证实际投递、链接域名、令牌单次使用和到期行为。Windows 回环实验设为数据库队列但禁用实际邮件投递；它不证明生产队列 Worker、SMTP 可用性或邮件路径的响应时延。

## 全新安装

1. 以受保护的环境配置或私有 `.env` 设置 DB 等变量（见归档 `.env.example`）。使用 CSPRNG 生成每实例 32 字节 `APP_KEY`（Laravel `base64:` 格式），单独创建至少 2048 位的匹配 RSA Passport 私钥和公钥。可在私有状态目录提供 `oauth-private.key` / `oauth-public.key`，或通过 `PASSPORT_PRIVATE_KEY` / `PASSPORT_PUBLIC_KEY` 注入受保护的 PEM **内容**（不是路径）。POSIX 文件模式只接受 `0400`、`0440`、`0600` 或 `0640`，两把钥都要能被 PHP 身份读取；Windows 用 ACL。密钥文件及其挂载应对 Web/FPM 只读，不能替换。安装、升级、`/healthz` 都检查密钥配置；备份恢复后也应重验文件模式。不要记录、提交或再分发密钥材料。
2. 保持流量隔离。以部署账户使用与 Web 一致的配置和密钥，交互运行 `php artisan bs:install admin@example.test "站点管理员"`；命令提示隐藏密码并确认。非交互用 `--password-stdin` 从受保护的密钥来源提供单行，不把密码写在命令、参数、CI 脚本或日志中。
3. 检查 `/healthz` 由 503 变为 `200 ready`，然后验证安全 cookie、管理员登录和所需业务功能。`/healthz` 仅读取就绪条件，不运行迁移；保持接入限制，避免将就绪端点当管理接口。安装进度由数据库状态、迁移表、代码版本及密钥一致性决定，任何旧 `storage/install.lock` 都不是安装证明。已安装库拒绝再次创建管理员。

## 旧 6.0.2 迁移与恢复

1. **先演练**：停止旧站 Web、定时任务与队列提交，记录版本、DB 前缀和 `PWD_METHOD`，在停写后备份数据库和纹理/上传、私有配置、`APP_KEY`、Passport 密钥、使用的只读插件及审批，并保留原版本归档和配置。备份、恢复目录与生产严格分离；验证散列、加密/ACL、恢复后事务一致性。SQLite 停止所有连接后使用 SQLite 原生备份或确认 checkpoint 后复制单一数据库文件；不能依赖仅复制 WAL 模式主文件。MySQL/MariaDB 使用受验证的一致性备份（注意 DDL 与非事务表），测试还原和全部表前缀。
2. 私有环境中把同一份备份复制到独立 DB 和状态目录后，验证旧站数据规模、管理员、插件清单、纹理内容和认证读取。不替换保留的密钥；新旧站的 `APP_KEY` / Passport 对必须一致。先以 `php artisan bs:upgrade --adopt-legacy` 显式接纳完整的 6.0.2，随后版本使用 `php artisan bs:upgrade`。兼容旧密码时设置正确的 `PWD_METHOD`；新密码为 Argon2id。遗留重复身份、脏表或部分迁移应离线调查，不强制标记为安装完成，不以修改 migration ledger 规避失败。
3. 通过手动 CLI 执行升级；初始状态变更和写入不走 HTTP。锁拒绝并发操作；失败时状态留在非就绪，且数据库引擎的 DDL 可能已部分提交。**不要用 `migrate:rollback` 当备份恢复。** 可解释、可验证的临时错（例如锁冲突）消除后按相同 6.0.3 和密钥重试；发生部分 DDL、数据不明或密钥失配应保持断流，恢复全套协调备份，再重走验证。不能只回滚代码、只恢复密钥或利用当前 DB 降级。如果使用 Laravel `artisan down`，再次检查不要让 CLI/代码切换过程打开旧站流量。
4. 上线前检查 CLI 结果、`/healthz`、实际数据库版本/关键行数、登录与注销、API 和材质读取；重发放受影响的旧 OAuth 凭据可能是升级的一部分。同步迁移并验证实例后再恢复流量。在正式切换中若需回退：停写，恢复**同一时间点**数据库、纹理、配置与密钥，切回旧归档；比较数据并完成健康检查才解除隔离。

## 伺服拓扑与已知验证边界

- `deploy/windows/Lab.ps1` 与匹配配置仅用于 **Windows 单机回环实验**：Nginx HTTPS → PHP NTS `php-cgi` → 私有 SQLite；使用隔离目录和短期自签名 localhost 测试证书，不安装 Windows 服务或导入根证书。该 Windows 实验的浏览器上传曾遇到 FastCGI 超时，不是经完整验收的部署拓扑，也不应用作线上指南。见 [实验步骤](windows-lab.md)。
- Docker 使用锁定的官方 PHP Apache OCI 索引、日期固定的 Debian 签名快照和固定 Node/npm/Composer，镜像仅以 `www-data` 监听 `8080`；它需要外部 TLS 代理、私有持久化 `/var/lib/blessing`、明确配置和预部署步骤。镜像未在本轮 Windows 宿主上构建/运行（无 Docker/WSL）。首次正式使用前应在目标架构运行 CI、检查镜像实际内容和 TLS 转发，并使用备份副本复测安装/升级/恢复。
- 原生 Linux 可使用 [Apache + PHP-FPM 与直接 TLS](linux-apache.md) 的配置模板。已在隔离的 Linux x86-64 来宾上以本地 SQLite 验证 PHP 8.6 CLI/FPM、HTTPS 启动与重启、目录和 Unix 套接字权限；这不代替实际站点域名、数据、邮件和负载验收。
- ZIP 和本地源码构建方法见 [归档构建](release.md)。
