# Linux 原生部署：Apache + PHP-FPM

`deploy/apache/native-https.conf.example` 是**直接 TLS**的 Apache 2.4 + PHP 8.6-FPM 模板，和 Docker 内部 Apache 配置不同。已在隔离的 Alpine 3.24.4 x86-64 来宾验证 Apache 2.4.71、PHP 8.6.0 CLI/FPM、Unix 套接字、回环 TLS 与 SQLite 持久化。这个实验不指定生产发行版，也不证明 OCI 镜像或现网已部署。在目标 Linux 机器上用测试数据和符合目标环境的 TLS 证书重做健康检查、浏览器、备份恢复与负载验证，才可上线。

1. 从可信来源安装受维护的 Apache 2.4 和 PHP 8.6，CLI/FPM 用同一小版本及扩展；安装时准备独立无登录权限的 FPM pool 用户和受限的高权限部署账户。遵循 [数据库、密钥与备份流程](README.md)；面向公网的 HTTPS 证书不能用实验自签名证书。
2. 把已验证 ZIP 解压到版本化的只读代码目录，通过经审阅的部署流程切换 `/srv/blessing/current`。仅 `current/public` 是 Web 根，`deploy/` 和私有 `.env` 都不能跟随文件别名对外。预创建私有状态目录；运行账户只有该目录的写权限，只读配置和 Passport 密钥。同机 FPM Unix 套接字 `/run/php/blessing-8.6.sock` 的模式设为 `0660` 并给 Apache 所在组，不能让网络访问 FPM。
3. 渲染模板中的主机名（也改 Host 正则）、路径、实际证书和对应套接字，确认默认虚拟主机、全局 `Alias` 或其他 PHP 处理规则不会放宽限制。使用专用 FPM pool，设 `security.limit_extensions=.php`、`cgi.fix_pathinfo=0`、`display_errors=Off`、`expose_php=Off`、`variables_order=EGPCS`、`upload_max_filesize=6M`、`post_max_size=8M`，配置受控日志。保持 `clear_env=yes`，在 pool 中用如 `env[LARAVEL_STORAGE_PATH] = /srv/blessing/state` 显式传入私有路径，CLI/Worker 用相同的进程环境路径，不能只写在 `.env`。其余实例配置走显式进程环境或 Web 根外受限的 `.env`；不要在 Apache 中配置要交给 PHP 的秘密。直接 TLS 时不配可信代理，设置真实 HTTPS `APP_URL`。
4. 在目标环境先运行 `apache2ctl configtest` 与对应 PHP-FPM 配置测试。启动服务由运维控制；应用入口不会自动安装或迁移。验证未安装 `/healthz` 返回 503 并且 `Cache-Control` 不缓存，`/setup`、`/admin/update`、`/plugins/`、`/storage/`、`/.env` 返回不可访问，静态资源加载且不允许列目录；CLI 成功后 `/healthz` 返回 `200 ready`。正向验证管理员登录、会话安全属性、头像/上传和后台；反向验证错 Host 被拒绝、重启后就绪状态仍在和使用隔离备份的恢复。记录 TLS、配置、PHP 和数据库的实际版本。

### Passport 文件密钥

- 两把钥都由部署身份提供、由 FPM 和 CLI 读取。推荐部署所有者（如 `root`）持有文件、专用 PHP 组只读（例如 `0440` 或 `0640`），并将两个文件以只读方式挂载至私有状态路径，使 FPM 无法替换它们。若钥由同一专用身份管理，`0400` 或 `0600` 也受支持。用同一 FPM 用户验证两项均可读；Apache 不需要读。这里的文件模式和 Apache 到 FPM **套接字**的 `0660` 是不同设置。
- 不要沿用归档、备份工具或秘密卷默认的 `0644`，也不要给组写入权（`0660` 不符合本版本策略）。启动预检会拒绝不符合要求的文件，已安装时 `/healthz` 返回不可缓存的 503。隔离流量后修正同一对文件的权限并复查 200，不要盲目生成新钥或禁用 Passport 权限验证。随后应用一个实际 Bearer 令牌复测授权 API；只验证 token 交换不能确认公钥验签正常。用 [部署手册](README.md#旧-602-迁移与恢复) 恢复同一时间点的数据与密钥。

如果采用 Docker，当前 `deploy/apache/blessing.conf` 只用于隔离后端 8080，并不自行终止公网 TLS；使用运维的真实边缘代理。不要将后端端口直接发布到公网。
