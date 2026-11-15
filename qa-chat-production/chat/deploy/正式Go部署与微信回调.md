# 正式站 Go 部署与微信回调

正式 PHP 继续使用 `/www/wwwroot/worker`，测试 PHP 继续使用 `/www/wwwroot/workers-test/server`。正式 Go 运行目录为 /www/wwwroot/worker/chat，运行状态统一为 /www/wwwroot/worker/chat/bin/state（www:www、0700），宝塔以 www 运行，正式聊天优先使用正式站数据库的专用低权限 MySQL 账号和独立的 Redis 命名空间；当前托管配置生成器也允许回退复用 PHP 数据库连接，正式启用前应明确选择并审计权限。正式监听 `127.0.0.1:9501` / `127.0.0.1:9502`，测试保持 19501 / 19502。无需更改 PHP 的 open_basedir。

> **2026-11-15 正式首次启用实现：** 正式站非 Git 发布、测试站 Git 部署；验收脚本已支持 text/text-images/full profile，正式文字启动自动生成 SHA/epoch 绑定的短效放行文件，Go 状态目录统一为 /www/wwwroot/worker/chat/bin/state。L1 不要求媒体回调，但仍要求真实微信文字内容安全、身份/订单授权和恢复门禁。代码提交不代表隔离测试或正式站启用已完成，操作顺序见[正式聊天首次启用与分级验收](../../docs/订单聊天/正式聊天首次启用与分级验收.md)。


## 通过宝塔部署正式 Go

1. 在正式 PHP 后台确认已完成对应版本升级，AppID 和微信文字内容安全凭证正常。L1 文字聊天不要求预先配置微信音视频媒体审核回调；只有启用音视频审核时才配置 Token 和 EncodingAESKey。
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

3. 在宝塔文件管理器把预编译包解压至 `/www/wwwroot/worker/chat`，在面板终端运行包内首次安装步骤。**当前代码要求目录属主为 `root:www` 或 `www:www`，权限 `0750`；启动器要求正式 Go 进程用户为 `www`，不是 `bxchat_prod`。** PHP CLI 是启动器运行后台 PHP 脚本用的命令行程序，启动器自动查找 PHP 8.3.36 或更高版本。只有日志报 `CHAT_PRODUCTION_PHP_CLI_NOT_FOUND` 时，才在宝塔 Go 项目环境变量填写实际 `CHAT_PHP_BIN`。
4. 在「网站 → Go 项目」添加正式项目 `chat`，程序路径 `/www/wwwroot/worker/chat/bin/chatd`，启动命令 `/bin/bash /www/wwwroot/worker/chat/deploy/bt-run.sh --production`，**运行用户 `www`**，端口 9501，关闭外网映射。
5. 正式启动器从 PHP 后台读取 AppID、身份签名、数据库/Redis、文字安全和恢复配置；L1 将媒体回调 relay 保持关闭。日志出现 `CHAT_PRODUCTION_RUNTIME_CONFIG_READY` 与 `CHAT_RUN_STARTED` 后，后台继续显示文字收发待放行，直到超级管理员初始化并通过 Go 业务就绪检查。状态文件和配置文件必须由 Nginx 拒绝外部访问；修改配置后从宝塔重启正式 Go 项目。

已有手工 `chat.json` 的项目继续按原配置启动。正式自动模式可生成文字收发配置。启用及检查方法见下文。

## 微信后台填写正式地址

到小程序后台「开发 → 开发管理 → 消息推送配置」填写 `https://worker.20m.cc/chat/api/v1/av/callback`。选择安全模式，Token 和 EncodingAESKey 使用正式后台保存的同一组值，数据格式支持 JSON / XML。URL 验证通过说明微信能访问签名验证入口，处理媒体审核事件需完成下一节。

同一个 AppID 使用正式站这一条推送地址。测试站「聊天设置 → Go 服务配置 → 微信媒体审核回调」选择「由正式站分发」，在宝塔文件管理器查看正式站当前 Go 状态目录 `/www/wwwroot/worker/chat/bin/state/wechat-relay.key`，填写其中的分发密钥，核对后保存并应用。测试站仍需要自己的媒体处理、存储及微信 access token 相关配置。测试站无需填写正式站的微信推送 Token 或 EncodingAESKey。

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

看到 `CHAT_DEPLOY_BUILT` 后启动 `chat`。升级包沿用已有启动命令和回执地址，保留 `/www/wwwroot/worker/chat/bin/state` 中的回执、分发密钥和正式配置。2.7.6 数据库无需再升级。脚本将安装备份保存在 `chat/update-backup/previous`，失败时仅保留最近一次失败快照。成功后清理旧工具在 `/var/lib` 下创建的升级备份。

### 在后台更新 Go

安装一次带有发布公钥的启动器，宝塔启动项目后，打开「聊天设置 → Go 版本更新」，上传签名的 `.chatgo` 包并输入当前后台密码。启动器先检查现有配置，再切换，健康检查失败时恢复旧版。此入口更新 Go 二进制。包含 PHP 或数据库改动的发布需先按 PHP 发布流程完成。业务收发已开启时，签名放行文件也须覆盖目标 Go 版本，版本更新不会让业务降为仅回执。

发布人离线保管 Ed25519 私钥，使用 `CHAT_WEB_SIGNING_KEY_FILE` 指定文件并运行 `node chat/deploy/pack-web-update.cjs <chatd> <version.json> <本站PHP版本> <输出.chatgo>`。公钥随首次启用包登记，后续包使用同一发布密钥。私钥只留在发布端。

### 首次建立正式恢复基线

已确认正式聊天从未启用、当前数据库为可信主库时，停正式 Go 后使用 `bash /www/wwwroot/worker/chat/update/deploy/update-production.sh --init-recovery`。按提示输入具备当前库读取和读取 binlog 坐标权限的 MySQL 维护账号，密码不回显。建议在低峰执行。首次初始化使用具备 MySQL RELOAD 权限的维护账号，短暂加全局读锁对齐 InnoDB 一致性快照和 binlog 坐标，随后立即解锁。工具在一致性快照中核对正式聊天历史、生成指纹，校验归档覆盖该快照后建立恢复门禁。看到 `CHAT_RECOVERY_BASELINE_READY` 表示基线已建立。出现 `CHAT_RECOVERY_HISTORY_EXISTS` 时走已有数据的常规核验流程。

先运行 `bash /www/wwwroot/worker/chat/update/deploy/update-production.sh --check-recovery` 可在 Go 运行时只读核对 MySQL 登录、binlog 读取权限及表结构。失败时请保留 `CHAT_RECOVERY_` 错误行及其阶段、MySQL 错误号。

若宝塔为 PHP 启用了 open_basedir，在正式站现有允许列表中追加 `/www/backup-control/bixuan-chat/production/` 并重载 PHP 8.3，使 PHP 与 Go 能读取同一个门禁。保留其他允许目录。控制目录、基线及恢复密钥应另行备份，后续继续归档 binlog。维护密钥存于控制目录的 root 专用 `initial-identity.json`，正常运行不读取该密钥。

### 放行正式非音视频聊天

正式站不再承担业务验收。先在 workers-server 隔离测试仓与 test.20m.cc 对准确 SHA 完成非音视频完整验收（订单聊天、协作群、文字、图片、治理、免费订阅提醒、授权/撤权、风控、恢复与回退），最终发布证据使用既有 `--profile full` 的基础 capability：`text + images + governance + reminders`。语音消息、ASR、视频消息、实时通话和付费短信不包含在该集合内。

验收通过后，在正式站「聊天管理 → 聊天设置 → 正式站首次启用」点击 **初始化非音视频聊天并应用**。后台自动生成并加密保存正式 release HMAC 与内部提醒 HMAC；启动器按当前二进制 SHA、正式环境、恢复 epoch 和上述 capability 生成短效签名文件，并自动托管免费提醒进程。无需手工创建 `production.local.env`，也无需填写 `CHAT_PROD_TEXT`、`CHAT_PROD_RELEASE_FILE`、`CHAT_RELEASE_KEY` 或 `CHAT_INTERNAL_KEY`。

图片复用后台七牛配置；公开 HTTP 回源模式仍通过本站 HTTPS 媒体代理提供给小程序，不要求为了聊天单独购买七牛 HTTPS。正式放行前会检查图片表、PHP CLI/GD、Pillow、zxing-cpp、图片辅助脚本和私有临时目录，缺项时 fail closed。免费订阅提醒走内部 HMAC，并允许同机 `127.0.0.1:9502` 回环 HTTP；非回环 HTTP 仍拒绝。

看到 `CHAT_PRODUCTION_NON_AV_READY` 后只做无副作用检查：核对 deployed/running/current SHA、内部 `/readyz` HTTP 200 和后台“恢复核验已通过 / 正式收发已就绪”。**不再在正式站创建测试用户、测试订单、测试会话或发送测试消息。**

可选 `CHAT_PROD_MYSQL_USER` 与 `CHAT_MYSQL_PASSWORD` 仅用于明确选择独立 Go MySQL 账号；两者均不填写时复用 PHP 已安装连接。

