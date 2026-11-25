# Windows 回环 HTTPS 验收

仅用于独立验证，不能指向现有站点或状态数据。用可信的官方 PHP 8.6.0 Windows NTS 构建（需 `php-cgi.exe` 和配置好的 `php.ini`，启用 `gd` / `fileinfo` / `intl` / `curl` / `openssl` / `pdo_sqlite` / `sodium` / `zip` 等）、Nginx 1.30.8 Windows 发布 ZIP（核对 nginx.org PGP 签名）、OpenSSL 及 PowerShell。不要添加受信根证书、开放公网端口或创建服务。

验证发布者与 ZIP 校验值后，将 ZIP 解压到干净临时目录，再使用其中的脚本；对已验证的发布包使用 `-UseShippedManifests`，只复制随包构建的 `bootstrap/cache/packages.php` 与 `bootstrap/cache/services.php`，缺任何一个都拒绝。若测试源码，先构建依赖和静态资源并去掉此选项；不使用已部署站点的缓存。以下示例预期端口未被占用，选择不存在的纯 ASCII 实验目录（脚本拒绝目录已存在）：

```powershell
$archiveTree = 'C:\path\to\extracted-release'
$lab = Join-Path $env:USERPROFILE ('bs86-lab-' + [Guid]::NewGuid().ToString('N'))
$script = Join-Path $archiveTree 'deploy\windows\Lab.ps1'
& $script Prepare -RunRoot $lab -AppPath $archiveTree `
    -PhpPath 'C:\tools\php-8.6.0\php.exe' `
    -NginxPath 'C:\tools\nginx-1.30.8\nginx.exe' `
    -OpenSslPath 'C:\Program Files\Git\usr\bin\openssl.exe' -UseShippedManifests
& $script Start -RunRoot $lab
curl.exe --noproxy '*' --resolve 'localhost:18486:127.0.0.1' --cacert "$lab\edge\cert.pem" -i https://localhost:18486/healthz
& $script Install -RunRoot $lab -Email 'admin@example.test' -Nickname 'Lab Admin'
curl.exe --noproxy '*' --resolve 'localhost:18486:127.0.0.1' --cacert "$lab\edge\cert.pem" -i https://localhost:18486/healthz
& $script Upgrade -RunRoot $lab # Recheck current version; should not change data.
& $script Stop -RunRoot $lab
```

冷启动时扫描依赖、编译模板可能受 Windows 磁盘负载影响；仅此回环实验把 PHP CGI 最大执行时间设为 90 秒、Nginx FastCGI 读取超时设为 100 秒，不作为生产参数建议。`Start` 可使用 Prepare 指定的其他 `-HttpsPort/-FastCgiPort`。浏览器测试应固定使用上述本机证书及实验端口；curl 不需 `-k`。第一请求应为 503、安装后应为 200。自动化若已经从密钥来源读取单行测试密码，可用 `$password | & $script Install … -PasswordFromPipeline`（密码不在进程命令行，勿打印）。实验根目录包括 SQLite、独有密钥和日志，应像测试秘密一样保护，用完先停止对应实验进程，再清理经核对的实验目录。可将**停写后的**原生 SQLite 备份（如 `VACUUM INTO`）和同一时间点的身份与纹理目录通过受保护介质复原到另一个实验根；先核对数据库完整性、验收密钥不配对会返回 503，再加载正确身份并复测 200 与纹理散列。实验使用本地数据库队列并禁用真正的邮件投递，不复用生产数据、证书或 SMTP 凭据。
