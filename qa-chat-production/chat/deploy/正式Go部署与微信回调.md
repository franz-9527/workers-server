# 正式站 Go 部署与微信回调

正式 PHP 继续使用 `/www/wwwroot/worker`，测试 PHP 继续使用 `/www/wwwroot/workers-test/server`。正式 Go 运行目录为 /www/wwwroot/worker/chat，宝塔以 www 运行，正式聊天使用正式站数据库的专用 MySQL 账号和独立的 Redis 命名空间。正式监听 `127.0.0.1:9501` / `127.0.0.1:9502`，测试保持 19501 / 19502。无需更改 PHP 的 open_basedir。

## 通过宝塔部署正式 Go

1. 在正式 PHP 后台确认已完成 2.7.6 升级。到「聊天管理 → 聊天设置 → Go 服务配置 → 微信媒体审核回调」保存微信消息推送 Token 和 EncodingAESKey。小程序 AppID 沿用正式后台的微信小程序设置。首次保存可先不勾选回调已核对。
2. 在宝塔「网站 → worker.20m.cc → 配置文件」中合并以下规则。HTTP 入口跳转 HTTPS 或同样阻断静态目录。保存并重载 Nginx。

   ```nginx
   location = /chat { return 404; }
   location ^~ /chat/ { return 404; }
   location = /chat/api/v1/av/callback {
       proxy_pass http://127.0.0.1:9501;
       client_max_body_size 64k;
       proxy_connect_timeout 3s;
       proxy_read_timeout 30s;
   }
   ```

3. 在宝塔文件管理器把预编译包解压至 `/www/wwwroot/worker/chat`，在面板终端运行包内首次安装步骤。目录归 `root:bxchat_prod`，权限 `0750`。PHP CLI 是启动器运行后台 PHP 脚本用的命令行程序，启动器自动查找 PHP 8.3.36 或更高版本。只有日志报 `CHAT_PRODUCTION_PHP_CLI_NOT_FOUND` 时，才在宝塔 Go 项目环境变量填写实际 `CHAT_PHP_BIN`。
4. 在「网站 → Go 项目」添加正式项目 `chat`，程序路径 `/www/wwwroot/worker/chat/bin/chatd`，启动命令 `/bin/bash /www/wwwroot/worker/chat/deploy/bt-run.sh --production`，运行用户 `bxchat_prod`，端口 9501，关闭外网映射。
5. 正式启动器从正式 PHP 后台读取 AppID、Token 和 EncodingAESKey，在私有状态目录生成回调配置和分发密钥。日志出现 `CHAT_PRODUCTION_CALLBACK_CONFIG_READY` 与 `CHAT_RUN_STARTED` 后，无签名回调 GET 应返回 403，配置文件和状态文件应返回 404。后台修改回调配置后，从宝塔重启正式 Go 项目。

已有手工 `chat.json` 的项目继续按原配置启动。正式自动模式可生成文字收发配置。启用及检查方法见下文。

## 微信后台填写正式地址

到小程序后台「开发 → 开发管理 → 消息推送配置」填写 `https://worker.20m.cc/chat/api/v1/av/callback`。选择安全模式，Token 和 EncodingAESKey 使用正式后台保存的同一组值，数据格式支持 JSON / XML。URL 验证通过说明微信能访问签名验证入口，处理媒体审核事件需完成下一节。

同一个 AppID 使用正式站这一条推送地址。测试站「聊天设置 → Go 服务配置 → 微信媒体审核回调」选择「由正式站分发」，在宝塔文件管理器查看正式站 `chat/bin/state/wechat-relay.key`，填写其中的分发密钥，核对后保存并应用。测试站仍需要自己的媒体处理、存储及微信 access token 相关配置。测试站无需填写正式站的微信推送 Token 或 EncodingAESKey。

正式站验签后先把审核回执保存到私有暂存目录。正式任务优先由正式 Go 认领，其他回执通过带签名的本机接口交给测试 Go。两个站点只写入自己数据库已登记的审核流水号，图片、音视频原文件、订单和身份信息均留在原站。两套 Go 必须在同一服务器，测试内部地址固定为 `127.0.0.1:19502`。回执先于任务入库或测试服务短时不可用时会补投，暂存最长一小时。过期记录见正式 Go 日志 `chat_media_relay_expired`；过期不会当作审核通过。

若现有微信入口还承担客服等其他事件，继续用原统一入口为这些事件提供处理，只将微信媒体审核事件交给本服务。聊天的图片、订阅通知、短信和通话分别沿用各站现有配置。本次统一入口分发的是微信异步语音／视频审核回执。

### 切换与验收

1. 两站先升级到包含分发功能的代码，启动正式 Go，核对测试内部端口保持 19502。若需要短暂停用测试音视频，先取消测试回调确认，避免切换窗口继续提交新审核。
2. 在微信后台验证并保存正式地址后，应用测试站的分发配置。用测试身份发送语音及带音轨的视频，检查回执、审核和发布。纯视频或语音是否进入微信异步审核取决于原有的处理流程，验收时核对确有审核流水号的样例。
3. 正式聊天放行后，再在正式站重复验证。测试停止时，正式站已登记的审核回执应继续处理。若两站分开部署，当前回环分发方式无法使用，需改为有独立认证的私网链路后再切换。

## 单独开放正式聊天能力

正式收发、真实媒体审核和通话继续按[发布与签名门禁](../../docs/订单聊天/宝塔部署、容量与运维.md#195-联合放行与受控测试站)执行。对齐正式 PHP 签名及短期微信凭据、正式数据库权限、Redis、恢复代次和签名发布凭证。先完成指定能力的联合验收，再按 `chat/config.example.json` 配置实际依赖。正式媒体继续要求私有空间和对应发布凭证。

启用正式音视频消息时，保留 `av.callback_relay.mode=dispatch`，将 `safety.app_id` 设为与 `av.callback_app_id` 相同的 AppID，再配置 `av.enabled` 和所需的语音或视频能力。完整媒体链路继续使用同一 URL，只处理本环境已发起且经过验签的审核结果。重启正式项目核对 Go 日志和实际收发。对于仅调整私有配置的变更，使用宝塔重启应用；更新代码时安装选定 `main` 提交对应的正式运行包，核对版本和配置后切换 `chat/bin/current`。测试站继续从测试仓库检出目录独立构建。

正式端先提供 URL 验证和回执分发时，后台 PHP 的聊天总开关可以保持关闭，无需改动小程序入口。后续开放用户链路时，按 Nginx 示例补齐正式 `/chat/api/v1/` 和 `/chat/ws`，并核对小程序正式 request、socket、uploadFile 和 downloadFile 合法域名。


## 已安装正式站快速升级

在宝塔正式站聊天目录下创建专用升级暂存目录。暂存目录仅 root 可读写，Nginx 继续阻断普通 `/chat/` 文件访问。

```bash
install -d -m 0700 -o root -g root /www/wwwroot/worker/chat/update
```

将升级包上传并解压到该目录。停止正式 Go 项目 `chat` 后，在宝塔 root 终端运行：

```bash
bash /www/wwwroot/worker/chat/update/deploy/update-production.sh
```

看到 `CHAT_DEPLOY_BUILT` 后启动 `chat`。升级包沿用已有启动命令和回执地址，保留 `/var/lib/bixuan-chat-prod` 中的回执、分发密钥和正式配置。2.7.6 数据库无需再升级。脚本将安装备份保存在 `chat/update-backup/previous`，失败时仅保留最近一次失败快照。成功后清理旧工具在 `/var/lib` 下创建的升级备份。

### 在后台更新 Go

安装一次带有发布公钥的启动器，宝塔启动项目后，打开「聊天设置 → Go 版本更新」，上传签名的 `.chatgo` 包并输入当前后台密码。启动器先检查现有配置，再切换，健康检查失败时恢复旧版。此入口更新 Go 二进制。包含 PHP 或数据库改动的发布需先按 PHP 发布流程完成。业务收发已开启时，签名放行文件也须覆盖目标 Go 版本，版本更新不会让业务降为仅回执。

发布人离线保管 Ed25519 私钥，使用 `CHAT_WEB_SIGNING_KEY_FILE` 指定文件并运行 `node chat/deploy/pack-web-update.cjs <chatd> <version.json> <本站PHP版本> <输出.chatgo>`。公钥随首次启用包登记，后续包使用同一发布密钥。私钥只留在发布端。

### 首次建立正式恢复基线

已确认正式聊天从未启用、当前数据库为可信主库时，停正式 Go 后使用 `bash /www/wwwroot/worker/chat/update/deploy/update-production.sh --init-recovery`。按提示输入具备当前库读取和读取 binlog 坐标权限的 MySQL 维护账号，密码不回显。建议在低峰执行。首次初始化使用具备 MySQL RELOAD 权限的维护账号，短暂加全局读锁对齐 InnoDB 一致性快照和 binlog 坐标，随后立即解锁。工具在一致性快照中核对正式聊天历史、生成指纹，校验归档覆盖该快照后建立恢复门禁。看到 `CHAT_RECOVERY_BASELINE_READY` 表示基线已建立。出现 `CHAT_RECOVERY_HISTORY_EXISTS` 时走已有数据的常规核验流程。

先运行 `bash /www/wwwroot/worker/chat/update/deploy/update-production.sh --check-recovery` 可在 Go 运行时只读核对 MySQL 登录、binlog 读取权限及表结构。失败时请保留 `CHAT_RECOVERY_` 错误行及其阶段、MySQL 错误号。

若宝塔为 PHP 启用了 open_basedir，在正式站现有允许列表中追加 `/www/backup-control/bixuan-chat/production/` 并重载 PHP 8.3，使 PHP 与 Go 能读取同一个门禁。保留其他允许目录。控制目录、基线及恢复密钥应另行备份，后续继续归档 binlog。维护密钥存于控制目录的 root 专用 `initial-identity.json`，正常运行不读取该密钥。

### 放行正式文字收发

在 `/var/lib/bixuan-chat-prod/production.local.env` 配置以下变量，文件属主为 `root:www`、权限 `0640`，敏感值用 shell 单引号引用。

| 变量 | 用途 |
| --- | --- |
| `CHAT_PROD_TEXT=1` | 请求启用正式文字 |
| `CHAT_PROD_MYSQL_USER` | 可选独立 Go 账号。未填写账号和密码时复用 PHP 连接 |
| `CHAT_MYSQL_PASSWORD` | 可选独立 Go 账号密码 |
| `CHAT_PROD_RELEASE_FILE` | 与本次提交、正式环境和恢复代次匹配的签名放行文件绝对路径 |
| `CHAT_RELEASE_KEY` | 放行文件的签名密钥 |

启动器从正式 PHP 复用数据库地址、正式库名、Redis、签名公钥和微信配置。部署前按[恢复与联合放行](../../docs/订单聊天/宝塔部署、容量与运维.md#192-同环境恢复控制目录)完成恢复核验，PHP 配置启用 `chat.safety_credential_export_enabled`，正式站 Nginx 加入本目录示例中的业务和 WebSocket 路由。已有微信回执入口可以继续使用。若配置缺项，日志会输出 `CHAT_PRODUCTION_TEXT_PENDING` 和缺项。看到 `CHAT_PRODUCTION_TEXT_READY` 后核对内部 `/readyz`，再用正式小程序做两端收发。图片、语音、视频、通话和短信依原有发布流程逐项放行。

MySQL 5.7 网站账号查询 binlog 坐标遇到 1227 时，在可信终端执行 `bin/chat-recovery.php` 前，用环境变量 `CHAT_RECOVERY_DB_USER` 和 `CHAT_RECOVERY_DB_PASSWORD` 临时提供具备所需权限的维护账号。工具仍读取同一个正式数据库。运行完成后清除终端中的变量。
