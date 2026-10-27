# 服务端升级与发布指南

适用运行基线：PHP 7.4 / MySQL 5.7.44。代码目标读取 VERSION，迁移目标与前置依赖读取 database/upgrades/manifest.json；现场版本读取升级页和迁移台账。文档不证明某版已经发行。

## 1. 先判断安装方式

| 场景 | 操作 |
| --- | --- |
| 全新安装 | 按 README.md 使用完整安装包和 database.sql |
| 代码版本高于现场且有待执行迁移 | 覆盖正确增量/完整包后，用网页升级器执行待执行版本 |
| 同版本代码修复且无待执行迁移 | 使用 `bash tools/package-release.sh --server-hotfix <输出目录>` 生成同版本全量后端热更新包；覆盖并清 OPcache，验证即可，不重跑成功 SQL |
| 现场为 failed / 陈旧 running | 保留现场、备份和日志，核对锁与失败原因后按专门恢复方案处理 |

## 本次 2.6.0 订单修复（2026-10-27）

- **需要更新后台代码和小程序。** 后台更新包括 API、订单权限/状态处理、展示读模型、Web 模板及新中文辅助类，不能只复制前端或个别模板。按交付 SHA 生成全量后端热更新包，保留现场配置与数据，覆盖后刷新站点实际 PHP-FPM / OPcache。
- **本次没有新增数据库更新。** 从本批修复前 `0b73ac2c356643e4c8353123fea5097a98842e65` 到 `bbc6c6afc60112d5c80a66eba627c179ab064a3f`，数据库目录、迁移清单和 VERSION 均未改；服务端仍为 2.6.0。已有正确 2.6.0 成功台账时，不重跑 SQL。
- 如果现场尚未升级至 2.6.0，仍须通过升级器完成已有迁移；failed/running 或 checksum 不符时继续按原恢复规则处理。本次未检查现场库，不能以仓库版本代替现场状态。
- 云函数无本批改动；不因本轮修复调整 PHP 运行版本或覆盖现场密钥、配置、图片和存储。小程序应在服务端就绪后编译、体验验收并发布。
- 已有三轮独立仓库定向回归，最新[运行 40355698491](https://github.com/franz-9527/workers-server/actions/runs/40355698491) 通过。其验证环境为 PHP 8.3.36 / MySQL 5.7.44 / Node 22；不表示实际站点已部署、旧 PHP 版本已在本轮复测或微信真机已验收。
- 当前修复仅在 `dev/2.6.0`，未自动同步 main/chat 分支。部署前应核对明确交付提交；本文档更新也不表示已经生成升级包或执行部署。

详细更新矩阵见[数据库升级与发行手册第 13 节](../docs/数据库升级与发行手册.md)。

## 2. 标准升级顺序

1. 记录现场数据库版本与交付提交，备份数据库、代码、.env、config/installed.php、.user.ini、storage 和私有图片。
2. 暂停调度和普通写入流量；支付回调在维护期间的重试/恢复须有受控方案。
3. ZIP 直接解压到站点根目录，第一层应为 VERSION、app、database、api/adminapi 等，不能套 server 或版本目录。正式后端包不得包含 `.env`、`config/installed.php`、`.user.ini`、`storage/`；其中 `.user.ini` 可能由宝塔设置不可变属性，始终保留服务器现场文件。
4. 发行脚本必须在压缩前把后端 staging 目录统一为目录 `0755`、普通文件 `0644`，并在 ZIP 生成后重新检查 Unix mode；发现 `0600`、异常可执行位或符号链接时直接阻止发行。不能依赖打包机工作区原始权限，也不能用 `cp -a` 后不做权限归一化。同版本后台覆盖禁止再使用临时 `cp/zip` 流程，统一走 `tools/package-release.sh --server-hotfix`。
5. 覆盖后检查关键代码至少对 PHP-FPM 运行用户可读，例如 `app/Support/functions.php` 不得出现 `root:root + 0600`。如果使用 root 解压，新文件属主可能是 root；应按服务器部署规范恢复网站代码属主/组，或至少确认目录可进入、代码可读。不要使用 `chmod -R 777`。
6. 核对站点根 VERSION 已覆盖；保留现场配置和运行数据，重载 PHP / OPcache。
7. 打开 /adminapi/upgrade/，核对代码目标、现场版本、待执行迁移、checksum、PHP/MySQL 和备份标识。
8. 只通过升级器执行待执行 SQL；不要在 phpMyAdmin 拆 SQL 或手改 schema_migrations。
9. 成功后核对安装配置、迁移台账、后台/API、订单/资金/消息/任务；恢复调度与流量。
10. 云函数部署及运行配置同步按混合架构部署文档执行，之后验证并发布对应小程序。

### 部署后权限最低校验

宝塔默认 PHP-FPM 用户为 `www` 时，可用以下方式确认关键文件已经可读：

```bash
ls -l /www/wwwroot/worker/app/Support/functions.php
namei -l /www/wwwroot/worker/app/Support/functions.php
runuser -u www -- test -r /www/wwwroot/worker/app/Support/functions.php && echo "functions.php readable: OK"
```

正常代码文件应至少是 `0644`，代码目录至少是 `0755`。如 `.user.ini` 因不可变属性导致 `chown/chmod` 返回 `Operation not permitted`，不要强行覆盖或删除；它不属于发行包覆盖对象。

## 3. 中断、维护锁与回退

- DDL 可能隐式提交，恢复不能只靠事务回滚；先保存失败 SQL、SQLSTATE、台账、命名锁和 maintenance.lock。
- running 不等于进程仍活着，也不等于可以重跑。failed 不得删除或伪造 success。
- idempotent 与 retry_failed 是不同门槛；不因为脚本可幂等就允许所有中断自动重试。
- PDO 2014/unbuffered 的历史专项恢复只对代码中限定版本、checksum、证据与锁条件有效，不能套用到任意版本。
- SQL 开始后不要先删除维护锁；确认无活动请求、现场与备份齐全、恢复方案明确后才处理单个锁。
- 不删除 storage、install.lock、config/installed.php 或真实媒体。回退代码必须匹配数据库结构，必要时从升级前备份恢复。

## 4. 数据与配置保护

- 未运营测试数据重置须明确范围；历史 2.2.9 业务清空和 2.4.0 表单/FAQ 重建不是日常升级策略。
- 业务订单、支付退款、余额台账、系统设置、用户/师傅/商家主档不可为整理文档或补配置而清空。
- 演示身份与种子版本独立；生产调度、微信支付/退款与短信不得处理模拟资金记录。
- 服务端唯一长期部署文件为 README.md / UPGRADE.md；CHANGELOG.md 仅为历史变更记录。

## 5. 发行门禁

按交付提交验证 PHP 7.4、MySQL 5.7.44 增量迁移/全新安装/幂等、包 CRC/SHA-256、ZIP 顶层结构和 Unix 权限（目录 `0755`、普通文件 `0644`），并确认 `.user.ini`、`.env`、`config/installed.php`、`storage/` 未进入覆盖包。
新增分层类必须全部打包；业务、资金并发与外部联调结果分别记录，旧版 CI 成功不自动覆盖新提交。
版本升级包须包含交付提交的全部正式运行文件，不能假设线上上一版本与开发分支的文件集相同；差异清单只用于审计，不能据此省略已有依赖类。
详细规则见仓库 docs/数据库升级与发行手册.md；具体变更及已通过验证写 Release notes，不在此累加 RC 修复记录。
