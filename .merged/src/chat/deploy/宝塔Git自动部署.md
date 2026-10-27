# 宝塔 Git 管理自动部署聊天（测试站）

用户已确认：完整仓库同步至 `/www/wwwroot/workers-test`，网站运行目录仍为 `/server`，准备从原分支切到 `chat`。本流程保留完整检出，不设置或改写 sparse-checkout。旧 server-only/main 文档不是当前服务器配置。

## 一次设置

1. 宝塔安装与 `chat/go.mod` 一致的 Go 工具链（当前 Go 1.27.3）；确认部署脚本实际使用的 Git ≥2.24.0，并已有 PHP 8.3 CLI、Bash、tar、flock、sha256sum。服务器自行编译，无需手动上传 chatd，二进制不提交 Git。
2. 宝塔「网站 → Git 管理」：原仓库地址不变，项目目录保持 `/www/wwwroot/workers-test`，分支改成 **chat**，运行目录保持 **/server**。
3. 在现有「部署后脚本」末尾追加以下内容；保留原 PHP 部署步骤、配置与数据。既有脚本若失败，应立即停止，不应继续编译：
   ```bash
   set -e
   cd /www/wwwroot/workers-test
   /bin/bash chat/deploy/bt-deploy.sh
   ```
4. 点击一次「部署／同步」。成功显示 `CHAT_DEPLOY_BUILT`，生成 `chat/bin/chatd`。首次没有 `chat/chat.json` 时自动生成 test 配置，所有业务开关保持关闭；原有文件不覆盖。PHP 安装配置必须为 testing。
5. 「网站 → Go 项目 → 添加项目」填写下表。只建这一个项目；脚本以前台方式统一管理 serve/worker 两个 Go 子进程，宝塔保存、查看日志及停止此项目即可，不另开 Supervisor 或第二个项目竞争同一端口。

| 字段 | 值 |
| --- | --- |
| 项目名 | `bixuan_chat_test` |
| 执行文件 | `/www/wwwroot/workers-test/chat/bin/chatd` |
| 执行命令 | `/bin/bash /www/wwwroot/workers-test/chat/deploy/bt-run.sh` |
| 项目端口 | `19501`，与 chat.json 一致 |
| 运行用户 | `www`；自定义用户时在私有环境文件设置 CHAT_RUN_USER |
| 开机启动 | 勾选 |
| 绑定域名 | 留空；沿用现有测试站 HTTPS |

6. 现有测试站「设置 → 配置文件」加入同目录 `nginx.conf.example` 的聊天 location，并把 9501 换为 19501；保留 PHP 和 /server 运行目录。19502 是本机内部端口，不加入公网代理或防火墙开放项。检查配置并重载 Nginx。
7. Git 管理启用原有自动部署，把触发分支一起改为 `chat`；若原 Webhook 脚本只接受 main，也要同步修改。配置好后先手动触发一次，再核对 GitHub 最近投递和宝塔部署日志。本仓库脚本不创建公网 Webhook 接口或保存面板 API 密钥。

之后流程：chat 分支更新 → 原 Git 管理同步 → 自动编译已提交源码 → 校验配置 → 原子切换版本 → 统一启动器优雅更新两个子进程。Git 同步本身不能证明两个进程已更新，核对下文版本标记。

## CentOS 7.9 x86_64 兼容核对（2026-10-27）

用户报告服务器为 CentOS 7.9.2009 x86_64，宝塔显示 Py3.13.14，Go 已安装成功。这是现场提供的信息；尚未连接服务器读取实际内核、Git 和 Go 路径，不能将 Ubuntu 隔离回归记为 CentOS 实机验收。

- x86_64 对应 Go 的 linux/amd64。按 [Go 官方最低要求](https://go.dev/wiki/MinimumRequirements)，Go1.24及以后要求 Linux 内核≥3.2；部署前核对实际内核满足要求即可，不能只凭发行版名称判断。
- 本脚本显式 `CGO_ENABLED=0`，当前聊天 Go 依赖采用纯 Go 构建，减少旧系统 glibc／C 编译器带来的限制。媒体检查的 FFmpeg、图片库及 Python 环境仍是独立依赖，须分别核对。
- Go 官方对1.25及以后要求 Git≥2.24.0。CentOS7上若仍是旧 Git，需要先升级供宝塔 Git 管理和部署钩子实际调用的 Git；只在另一个目录安装新版而钩子继续调用旧版不算完成。
- 宝塔系统信息里的 Py3.13.14 不会决定 Go 工具链或 Go 二进制的兼容性；音视频／图片助手使用各自配置的 Python 路径和库，不能据此断言助手已安装完整。
- 可在宝塔终端只读查看 `uname -r`、`git --version` 和实际 Go 路径的 `go version`。安装成功后，以首次部署日志、二进制 version、两个实际进程及聊天健康检查为运行证据。
- [CentOS 官方](https://www.centos.org/centos-linux/)说明 CentOS Linux7已于2024-06-30结束维护。兼容性允许继续测试；长期生产应另行规划受维护系统，脚本不自动升级操作系统。

## 私有配置只填一次

`chat/chat.json`、`chat/deploy.local.env` 和 `chat/bin/` 均被 Git 忽略；脚本不会删除它们。禁止 git clean -fdx 或将仓库顶层设成网站运行目录。保持运行目录 /server，防止 chat 配置与产物被静态下载。

若 Go/PHP 路径未自动识别，在宝塔文件管理器新建 `chat/deploy.local.env`，内容按实际路径填写，例如：

```sh
CHAT_GO_BIN='/实际Go目录/bin/go'
CHAT_PHP_BIN='/www/server/php/83/bin/php'
CHAT_RUN_USER='www'
# 按 chat.json 引用的变量名填写实际密钥，例如：
# CHAT_MYSQL_PASSWORD='测试数据库密码'
```

该文件是管理员维护的本机 shell 环境文件，填写者有服务器管理权限；只允许管理员修改、运行账号读取（如 root:www、0640），不提交 Git、不填入 Go 项目公开命令行。两个 Go 子进程和编译校验使用同一环境。修改私有配置后在 Go 项目点击重启。

首次生成的是关闭业务的基础配置。完整聊天还需填入测试库只读业务权限／Go 自有表权限、Redis、身份密钥、安全审核、私有媒体、恢复门禁和测试组合；短信与音视频分别配置真实渠道并独立验收。自动构建不自动开启任何业务、购买服务、执行数据库升级或发布小程序。

## 更新和故障核对

- 编译、工具链或配置校验失败：不切换现有 Go 程序；按部署日志修复后重新同步。
- 编译只使用此 Git 提交的归档，含准确 SHA／clean 来源信息；服务器未提交文件不混入二进制。构建结束前复查 checkout 的分支与 SHA，并发部署用文件锁串行拒绝重入。
- 当前启动器的两子进程稳定运行后，写 `chat/bin/state/running-sha`；`chat/bin/deployed-sha` 为切换目标，`chat/bin/releases/<SHA>/version.json` 为二进制来源。三者应与实际 checkout SHA 一致。这个标记只证明启动与版本，不代替 /readyz、真实聊天或渠道联调。
- 新版本启动60秒未获确认时，部署脚本恢复上一 Go 版本指针并报失败。它不会回退已经同步的 PHP、数据库或配置；跨版本兼容须在测试站验证。首次没有上一版时按日志修复。
- 启动器保持前台，更新时先 TERM 两子进程，最多等45秒后才强制结束并启动新版；任一子进程异常退出会重启两进程。更新有短时断线，客户端按既有游标恢复，非零停机发布。
- 宝塔「停止」应向前台启动器发送 TERM 并给足停止时间。强制杀死管理进程／整机故障需检查残留进程与端口，不能把面板绿色状态当聊天正常。不要重复直接执行 chatd serve/worker。
- 历史二进制保存在 `chat/bin/releases`，日后在确认无运行／回退需要后由管理员清理；不自动删除版本和聊天数据。
- 现有 PHP 调度、短信调度、数据库升级及小程序发布保持原流程。首次升级到2.7.0仍走后台预检与受控升级，Git 自动部署不导入 SQL。

隔离回归 [40525600063](https://github.com/franz-9527/workers-server/actions/runs/40525600063) 已通过 ShellCheck、Bash语法及真实 Linux Go1.27.3／PHP8.3.36构建和生命周期检查，覆盖首次生成、重现构建、双进程、重复启动、并发锁、正式环境拒绝、提交归档与脏工作树隔离、编译失败保留旧版、崩溃重启、启动失败回退及TERM清理。123份输入逐Git blob核对；测试只在workers-server临时隔离目录，无真实供应商／生产数据，完成后恢复通用四文件基础树。服务器是否收到push、原Git管理如何停止前台进程、实际业务和渠道健康仍须宝塔现场核对；仓库提交不等于已部署。
