# 壁选师傅订单管理系统 · 服务器部署说明

当前聊天测试站采用**全仓 Git 同步**：仓库目录 `/www/wwwroot/workers-test`，网站运行目录 `/server`。下文 `/www/wwwroot/worker` 代表 PHP 服务目录，套用到该测试站时替换为 `/www/wwwroot/workers-test/server`；聊天 Go 代码仍在仓库的 `chat/`。

当前安装包面向以下环境：

- 站点域名：`worker.20m.cc`
- 站点根目录：`/www/wwwroot/worker`
- PHP：`8.3.36`（本分支运行基线；FPM 与计划任务 CLI 均须核对，安装/升级预检会检查扩展和 GD 格式能力）
- MySQL：`5.7.44`
- 数据库字符集：`utf8mb4`
- 管理方式：宝塔面板、Nginx、phpMyAdmin 4.4

本项目不批量迁移原有云开发数据。用户首次打开订单功能时只在 MySQL 建立身份关联；师傅完整资料、公开图片和审核状态继续保存在云开发 `worker` 集合，PHP 仅保留订单、实名和结算需要的轻量投影。

## 版本与文档边界

代码目标读取 VERSION，数据库迁移读取 database/upgrades/manifest.json；部署现场以安装配置和迁移台账为准。升级步骤见 [UPGRADE.md](UPGRADE.md)，历史变更见 CHANGELOG.md，不能把旧版记录当成当前待开发项。

### 小程序 API 路由

所有接口都通过同一个 `https://worker.20m.cc/api/?route=...` 入口，不新增 PHP 暴露文件。除健康检查和带时效签名的图片外均要求小程序登录令牌；用户身份、师傅审核状态、订单访问权和完整联系方式权限都由服务器判断。

- `auth.wechat`、`auth.phone`：微信 code 登录、登录手机号和旧云记录按需同步；
- `forms.order`、`orders.create`、`orders.finalize`：动态表单、草稿、压缩图片和进入待付预约费；
- `orders.center`、`hall.orders`、`orders.detail`：独立订单中心、接单大厅和订单详情；
- `quotes.create`、`quotes.select`、`assignments.accept`、`invites.claim`：报价、选价、指派与受邀接单；
- `payments.create`、`payments.status`：预约费和师傅服务费的微信支付下单与查单；
- `appointments.save`、`arrivals.report`、`changes.create`、`changes.decide`：可选预约、异常到场和加价协商；
- `media.upload`、`media.delete`、`fulfillment.complete`、`fulfillment.accept`、`reviews.create`、`reviews.media.upload`：图片上传、尾款审批前的凭证重传、完工、验收和带图评价；
- `orders.cancel.request`、微信支付/退款通知路由：取消退款及真实资金状态推进；
- `finance.summary`、`finance.realname.*`、`commission.payment.create`、`finance.account.save`、`finance.withdraw`：实名认证、服务费、余额、支付宝账号和提现；
- `admin.dashboard`、`admin.orders`、`admin.order.action`、`admin.order.share`：仅配置的管理员 OpenID 可使用的小程序基础工作台与分享码。

上线前还要在微信公众平台把 `https://worker.20m.cc` 加入 request、uploadFile、downloadFile 合法域名，并在后台“基础设置 → 小程序绑定”填写当前小程序的 AppID、AppSecret 和云环境 ID。PHP 8.3.36 需启用 `curl`、`gd`（JPEG/PNG/WebP 读写）、`fileinfo`、`pdo_mysql`、`mbstring`、`openssl`、`simplexml`、`ctype`、`sodium`、`session` 与 `filter`。

## 一、上线后的地址

所有功能共用 `worker.20m.cc`，不需要增加第二个域名：

| 用途 | 地址 |
| --- | --- |
| 安装向导 | `https://worker.20m.cc/install/` |
| 管理后台 | `https://worker.20m.cc/admin` |
| 小程序 API | `https://worker.20m.cc/api` |
| 在线升级 | `https://worker.20m.cc/adminapi/upgrade` |
| 公共静态资源 | `https://worker.20m.cc/assets/` |

`app`、`config`、`database`、`storage`、`views`、`bin` 以及安装令牌都不是公开资源。Nginx 示例已经禁止浏览器直接访问这些路径；不要为了排错临时开放它们。

## 二、宝塔首次部署

### 1. 创建运行环境

1. 在宝塔安装 Nginx、PHP 8.3.36、MySQL 5.7.44。不要把生产数据库升级到不兼容版本。
2. 在“软件商店 → PHP 8.3 → 安装扩展”确认启用 `pdo_mysql`、`mbstring`、`openssl`、`fileinfo`、`gd`（JPEG/PNG/WebP）、`curl`、`simplexml`、`ctype` 和 `sodium`；接单图片压缩与微信登录分别依赖 `gd`、`curl`。
3. 新建数据库 `worker`，字符集选择 `utf8mb4`。为站点创建独立数据库账号，只授予该数据库所需权限，不要在网页安装器中使用 MySQL 的 `root` 账号。
4. 创建站点 `worker.20m.cc`，PHP 版本选择 PHP 8.3（核对实际补丁为 8.3.36），根目录设为 `/www/wwwroot/worker`。

### 2. 上传文件

把本安装包 `server` 目录里面的全部内容上传到 `/www/wwwroot/worker`。上传完成后应直接存在：

```text
/www/wwwroot/worker/index.php
/www/wwwroot/worker/admin/index.php
/www/wwwroot/worker/install/index.php
/www/wwwroot/worker/database/database.sql
```

不要形成 `/www/wwwroot/worker/server/index.php` 这一层多余目录。

站点运行用户通常是 `www`。让 `config` 和 `storage` 对站点运行用户可写，其他程序目录只读即可。推荐目录权限 `750` 或 `770`、普通文件权限 `640` 或 `644`，不要使用 `777`。安装成功后仍需保持 `storage` 可写，以便保存安装锁、日志和私有图片。

### 3. 配置 Nginx 与 SSL

1. 先在宝塔为 `worker.20m.cc` 申请并部署有效 SSL 证书，开启 HTTP 跳转 HTTPS。
2. 备份宝塔当前站点配置。
3. 参考根目录的 `nginx.worker.conf.example` 替换站点配置，并在保存前核对证书路径、站点根目录和 PHP 8.3.36 Socket。
4. 示例已经使用 PHP 入口白名单。不要再保留宝塔自动生成的通用 `include enable-php-74.conf` 或另一段可执行任意 PHP 文件的规则，否则会绕过白名单。
5. 在宝塔测试配置并重载 Nginx。确认 `/assets/` 可以访问，而 `/config/`、`/database/database.sql`、`/storage/` 和 `/install/install-token.php` 均返回 404。

根目录的 `.htaccess` 是 Apache 兼容保护；Nginx 不读取 `.htaccess`，因此使用 Nginx 时必须配置站点规则。

### 4. 打开一键安装

1. 在宝塔文件管理器中读取 `/www/wwwroot/worker/install/install-token.php` 保存的安装安全令牌。该文件不得通过网址访问，也不要把令牌发到群聊或截图中。
2. 访问 `https://worker.20m.cc/install/`。
3. 根据页面填写数据库地址、端口、库名、独立数据库账号、站点地址和首位超级管理员。
4. 环境检查全部通过后执行安装。
5. 安装器会导入 `database/database.sql`、创建首位超级管理员、生成 `config/installed.php`，并写入 `storage/install.lock`。

安装完成后，只要安装配置或安装锁存在，安装器就会拒绝覆盖安装并只显示锁定状态。不要删除 `config/installed.php` 或 `storage/install.lock` 来尝试重装；确需重装时，必须先完整备份，再由服务器管理员人工处理数据库和文件。

### 5. 验收

依次检查：

- `https://worker.20m.cc/admin` 能登录，首次管理员不是安装包内置账号；
- `https://worker.20m.cc/api` 能返回 API 状态；
- 未登录不能执行 `https://worker.20m.cc/adminapi/upgrade`；
- 普通管理员不能执行升级，只有 `super_admin` 可以进入升级流程；
- 安装令牌、数据库脚本、运行日志和订单图片无法通过静态网址下载。

## 三、使用 phpMyAdmin 直接导入 SQL

网页安装器是推荐方式。如果数据库较大或服务器限制网页导入，可以先在宝塔或 phpMyAdmin 中导入：

1. 创建全新的空数据库 `worker`，字符集选择 `utf8mb4`。
2. 选中该数据库，导入 `/www/wwwroot/worker/database/database.sql`。
3. 确认导入过程没有跳过错误，也没有把 SQL 导入其他业务数据库。
4. 导入完成后，仍然必须访问 `https://worker.20m.cc/install/` 完成安装。

SQL 文件故意不包含固定后台密码。安装页面负责创建首位超级管理员，同时生成站点密钥、数据库配置和安装锁；只导入 SQL 并不等于系统已经安装。安装器能够识别同版本的本系统基线库，不要手工伪造 `schema_migrations` 记录。

## 四、在线升级

升级入口是 `https://worker.20m.cc/adminapi/upgrade`。它会检查超级管理员身份、当前密码、数据库版本、升级顺序和脚本校验值，再执行安装包内受信任的升级 SQL。

### 升级前必须人工备份

必须在上传新版本文件之前完成以下操作：

1. 在“宝塔 → 数据库”中备份 `worker` 数据库，等待备份成功并记下备份文件名和时间。
2. 在“宝塔 → 文件”中把当前 `/www/wwwroot/worker` 打包备份，确保备份的是正在运行的旧代码，而不是上传后的新包。
3. 单独确认 `config/installed.php`、`storage/private`、`storage/uploads` 以及其他订单图片目录已经进入文件备份。
4. 把数据库备份名、旧代码压缩包名、私有文件备份名和时间整理成一个可追踪的“备份标识”，例如 `20260814-2300_db-worker_website-worker_private-media`。

上传新安装包本身不能备份已经被覆盖的旧代码。升级页面也不会替代宝塔生成数据库、旧代码或私有图片备份；页面要求填写备份标识，只是为了留下审计记录。

### 执行升级

1. 在宝塔暂停“壁选订单调度器”，并把站点临时停用或切到宝塔维护页，确认上传过程中没有普通后台、API 或小程序请求进入。
2. 在备份完成后上传新版本文件，保留服务器现有的 `config/installed.php` 和整个 `storage` 目录；不要手工创建 `storage/maintenance.lock`。
3. 完整上传后重载 PHP 8.3.36，确保新 PHP 文件和 OPcache 同步，再恢复站点访问。代码 `VERSION` 与安装配置不一致时，程序会自动暂停 API、普通后台页面和计划任务，只放行后台登录与升级入口。
4. 使用超级管理员登录，打开 `/adminapi/upgrade`，先执行升级预检，确认版本、数据库连接、目录权限和 SQL 校验全部通过。
5. 勾选已完成备份，填写上一步的备份标识，再输入当前管理员密码。执行升级并保存结果。

V1.0.1 起，普通 Web 请求会持有共享升级屏障，升级器取得独占屏障并等待在途请求结束后才执行数据库迁移；此保护面向单台宝塔服务器和本地磁盘。未来改成多台服务器或共享存储时，需要换成数据库或 Redis 分布式屏障。
6. 确认后台和 API 恢复、版本号一致后，重新启用宝塔计划任务。中途失败时不要反复点击；先根据请求编号和日志定位原因，再决定修复或从已记录备份恢复。

> 2026-08-14：文件覆盖不是原子发布。即使程序带有版本漂移保护，上传过程中仍必须先停普通流量和计划任务；版本保护用于完整上传后的第二道防线，不能替代宝塔停站。

后续升级 SQL 应按版本依次放入 `database/upgrades`，同时更新 manifest 和校验值。迁移脚本必须可安全重试，不能依赖 MySQL 8 专属语法，也不能从任意公网地址下载或执行 PHP 文件。

## 五、宝塔计划任务

系统需要每分钟扫描自动验收、服务费应收、自动评价和超期自动取消等到期任务。

在“宝塔 → 计划任务”中新增：

- 任务类型：Shell 脚本
- 任务名称：壁选订单调度器
- 执行周期：每 1 分钟
- 脚本内容：

```sh
/www/server/php/83/bin/php /www/wwwroot/worker/bin/scheduler.php all --limit=100
```

先在宝塔终端手工执行一次，确认输出 JSON 且退出正常，再启用计划任务。调度器只能由 PHP CLI 运行，Nginx 已禁止从网址访问 `bin`。同一分钟重复触发时，程序仍会根据订单状态、期限版本和任务抢占记录避免重复验收、评价或记账；升级维护、代码版本待同步时会输出 `skipped_maintenance` 或 `skipped_upgrade_required` 并安全跳过。

业务默认期限：完工提交满 72 小时自动验收；验收满 5 天仍未评价时生成自动评价；订单公开满 7 天仍没有被用户接受的有效报价时自动取消并对已实收预约费提交原路退款；服务费应收默认 3 天到期，逾期限制新的报价和接单。上线前必须填入真实商户资料并完成小额支付及退款联调。

## 六、图片上传与存储

小程序端先使用微信图片组件压缩，不上传原图；服务端只保留校验、重编码后的压缩成品。订单商品图、墙面图、施工前照片和完工照属于私有业务资料，不应放进 `/assets`。

推荐设置：

- 宝塔 Nginx `client_max_body_size`：`12m`；
- PHP 8.3.36 `upload_max_filesize`：`12M`；
- PHP 8.3.36 `post_max_size`：`14M`；
- PHP 8.3.36 `max_file_uploads`：按前端单次上传数量设置，建议不低于 `20`；
- 应用单张成品：最长边不超过 1600 像素，JPEG 质量约 80，目标不超过 1 MiB。

修改 PHP 配置后要重载 PHP 8.3.36。即使小程序已经压缩，服务端仍要校验真实 MIME、扩展名、图片尺寸和文件大小，并重新编码后保存；不要信任客户端文件名。私有图片放在 `storage/private` 或 `storage/uploads`，由后续鉴权接口输出，禁止 Nginx 直接提供静态访问。

## 七、微信支付与回调准备

当前业务使用微信支付普通商户：用户在线支付后台配置的预约费，师傅通过独立微信支付单补缴服务费；剩余施工款由师傅线下收取。平台服务费比例与最低金额以“基础设置 → 平台服务费”为准，预约费按业务规则抵扣。发票申请暂不开发。

接入微信支付时注意：

1. 小程序后台的 request 合法域名和微信支付回调地址必须使用已备案、证书有效的 `https://worker.20m.cc`。
2. 支付通知、退款通知应进入 `/api` 下的专用回调路由，并最终由统一的 `/api/index.php` 处理；不要新增可被任意访问的 PHP 入口。
3. 回调 URL 不得启用宝塔 Basic Auth、验证码、人机验证、登录跳转或仅允许固定浏览器访问的规则；微信服务器必须可以直接发送 HTTPS POST。
4. 回调必须读取原始请求体，验证微信签名与平台证书，按微信支付订单号做幂等处理，并在成功落库后按协议响应。不能根据前端“支付成功”页面直接修改订单状态。
5. 按当前运营要求，支付与短信密钥通过后台明文保存在 `system_settings`，安装脚本和源码不预置真实密钥。必须限制数据库账号、phpMyAdmin、宝塔与备份文件访问权限，严禁把真实密钥放进 `/assets`、升级包说明或公开代码仓库。
6. 正式上线前分别测试支付成功、重复通知、通知延迟、验签失败、退款成功和退款查询。服务器时间应保持 NTP 同步。

## 八、安全与日常维护

- 强制 HTTPS，确认全站稳定后再启用 HSTS；不要让后台通过 HTTP 登录。
- 关闭目录浏览，隐藏 Nginx/PHP 版本，不对公网开放 MySQL 3306。
- 定期更新后台管理员强密码，限制不必要的超级管理员数量。
- `config/installed.php` 含数据库密码和站点密钥；`install/install-token.php` 含安装令牌；两者都只能由服务器管理员读取。
- 日志写入 `storage/logs`，排错后也不能把日志移动到 `/assets`。
- 备份要包含数据库、当前代码、安装配置和私有图片，并定期做恢复演练。仅看到“备份成功”不等于已经验证可恢复。
- Nginx 示例只允许五个受信任 PHP 入口。如果后续新增入口，优先接入现有 `/admin/index.php` 或 `/api/index.php` 路由；确需新增时同步评审并更新白名单。

## 九、常见问题

### 安装页提示必须使用 HTTPS

先在宝塔部署证书并使用 `https://` 打开。当前安装器只接受服务器实际 HTTPS 标记，或来自本机 `127.0.0.1/::1` 反向代理的 `X-Forwarded-Proto: https`；外部 CDN 的同名请求头不会直接作为安装授权依据。

### 安装页提示目录不可写

检查 `/www/wwwroot/worker/config` 和 `/www/wwwroot/worker/storage` 是否属于站点运行用户，并给予目录写权限。不要直接把整个站点改成 `777`。

### 导入 SQL 后仍显示未安装

这是正常设计。继续访问 `/install/` 创建首位管理员、生成 `config/installed.php` 和 `storage/install.lock`。

### 升级页要求备份标识

先在宝塔真实完成数据库、旧代码和私有文件备份，再填写这些备份的文件名或工单号。不要填写“已备份”之类无法追踪的文字。

### 上传图片返回 413 或 PHP 超限

同时核对 Nginx 的 `client_max_body_size`、PHP 的 `upload_max_filesize` 和 `post_max_size`。`post_max_size` 应大于单次请求内全部文件与表单数据之和。
