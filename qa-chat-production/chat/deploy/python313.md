# 复用宝塔项目版 Python 3.13

聊天主服务是 Go，Python 只用于本地图片编解码、二维码检查和音视频处理辅助脚本。无需创建 Python 网站。可以复用“网站 → Python项目 → Python环境管理”中已安装的项目版 3.13.15，不必另装 3.11。

## 1. 版本与系统条件

使用面板安装的项目解释器，不使用 /www/server/panel/pyenv 中的面板自身运行环境，也不改 /usr/bin/python3。不要将依赖直接安装进其他网站（例如 grow）正在使用的虚拟环境。

本安装锁文件对应普通 CPython 3.13（非 free-threaded）、Linux x86_64、glibc 2.27 或更高。先在服务器检查：

```bash
uname -m
getconf GNU_LIBC_VERSION
```

CentOS 7 等 glibc 2.17 系统不能直接安装上面的供应商 wheel；仅升级 Python 不会升级 glibc。请使用下面的 CentOS 7 专用构建包，不覆盖系统 glibc，不去掉哈希检查后临时编译。

## 2. CentOS 7.9 / 项目版 3.13.15 的安装方式

已确认的基础解释器目录为 `/www/server/pyporject_evn/versions/3.13.15/bin`。先检查其中 `python3` 文件；本节按该文件存在编写，若只有 `python3.13` 则第一条使用实际文件名。

在独立测试仓库使用 manylinux2014 / glibc 2.17 构建相同版本的 Pillow 13.0.0、zxing-cpp 3.1.1，经 auditwheel 检查和依赖打包并验证；不将供应商 glibc 2.27 安装包直接改名。源码摘要锁定在 `requirements-py313-sources.lock`；仓库 `chat/wheels313/requirements.lock` 锁定实际 wheel 摘要，两者用途不同。

兼容依赖包、SHA-256清单和许可证已直接提交到仓库 `chat/wheels313/`。仓库同步到测试站后，对应目录就是 `/www/wwwroot/workers-test/chat/wheels313/`，无需下载附件、上传或解压。站点运行目录仍为 `server`，该目录不作为静态下载目录。同步文件不会自动创建虚拟环境或安装依赖；首次同步后在服务器执行：

```bash
/www/server/pyporject_evn/versions/3.13.15/bin/python3 --version
/www/server/pyporject_evn/versions/3.13.15/bin/python3 -m venv /www/wwwroot/workers-test/chat/.venv313
/www/wwwroot/workers-test/chat/.venv313/bin/python -m pip install --no-index --find-links=/www/wwwroot/workers-test/chat/wheels313 --require-hashes -r /www/wwwroot/workers-test/chat/wheels313/requirements.lock
```

构建来源和验证记录见本文末尾。离线安装不能误用 glibc 2.27 的 `requirements-py313.lock`，也不使用源码摘要来验证编译后的 wheel。如果安装提示运行库或架构不匹配，保留错误信息排查，不更改系统默认解释器。

后台 Python 填写 `/www/wwwroot/workers-test/chat/.venv313/bin/python`，保存后点击“应用已保存配置 / 重新检查”。不需要创建新的 Python 网站或改 Go 项目运行命令。

## 3. 其他系统或通过面板创建环境

点击 Python 环境管理中 3.13.15 行的“解释器位置”，取得真实解释器完整路径。截图中的名称不是路径，不猜测安装目录。

在环境管理中点击“创建虚拟环境”，选择该项目版 3.13.15，名称可用 workers-chat-test。创建后复制新环境的解释器完整路径（应以 /bin/python 或 /bin/python3 结尾）。虚拟环境复用已有 Python，依赖单独保存。

也可用真实基础解释器路径创建到固定位置。以下第一条须把占位路径替换为刚复制的真实路径；不要直接执行占位路径：

```bash
/替换为实际项目解释器路径/bin/python3 -m venv /www/wwwroot/workers-test/chat/.venv313
/www/wwwroot/workers-test/chat/.venv313/bin/python -m pip install --only-binary=:all: --require-hashes -r /www/wwwroot/workers-test/chat/helpers/images/requirements-py313.lock
```

如果通过面板创建虚拟环境，则安装命令中的 Python 改为面板返回的该环境解释器路径。requirements.lock 仍对应原 3.11；3.13 使用 requirements-py313.lock，不能混用两者的 wheel 哈希。安装失败不改用无版本限制的升级。

“聊天管理 → 聊天设置 → Go服务配置 → Python”填写新环境解释器完整路径。上面的固定目录方案填：

```text
/www/wwwroot/workers-test/chat/.venv313/bin/python
```

不填目录，不填 /usr/bin/python3，不填 grow 的运行环境，不填面板 pyenv。保存后点击“应用已保存配置 / 重新检查”，等待启动器读取，再刷新能力状态；只安装依赖但不重新检查可能继续显示旧检查结果。

## 4. 检查依赖

固定目录方案可用以下命令检查实际 JPEG/PNG/WebP 编解码、二维码库和媒体工具；若面板创建环境或工具不在 /usr/bin，换成后台实际配置的对应路径：

```bash
/www/wwwroot/workers-test/chat/.venv313/bin/python --version
/www/wwwroot/workers-test/chat/.venv313/bin/python /www/wwwroot/workers-test/chat/helpers/preflight.py /usr/bin/ffmpeg /usr/bin/ffprobe
```

pillow、zxing 为 true 才说明图片 Python 依赖可用；ffmpeg、ffprobe 分别显示媒体工具实际结果。Python 就绪不会代替 PHP CLI GD、七牛凭据、微信 ASR 与回调等其他能力条件。服务器 www 用户还需能执行解释器、读取环境及辅助脚本。

## 5. 更新范围与验证

仓库包含 3.13 依赖锁文件、CentOS 7离线安装包及部署说明；无需数据库迁移、Go重新编译或小程序发布。更新测试站文件后，仍需按上文安装依赖、修改后台路径并应用配置。本说明不代表已操作服务器或实际供应商验收。

供应商发布文件：[Pillow 13.0.0](https://pypi.org/project/pillow/13.0.0/)、[zxing-cpp 3.1.1](https://pypi.org/project/zxing-cpp/3.1.1/)。[Python 虚拟环境说明](https://docs.python.org/3.13/library/venv.html)。

兼容构建与离线媒体验证：[独立测试任务](https://github.com/franz-9527/workers-server/actions/runs/41462682243)。安装使用已随仓库保存的 `chat/wheels313/`，不依赖测试附件的有效期。后续重建可参考[构建脚本](https://github.com/franz-9527/workers-server/blob/85dd35ec8f86512b1bb573e9a6947c3c95b767bb/tests/build-centos7.sh)，重新生成 wheel 后必须同时更新校验清单。构建镜像按摘要固定，源码包和WebP 1.6.0源码均校验SHA-256；构建包不包含 Python 解释器，复用服务器已有的项目版 3.13.15。

本次验证：Ubuntu 24.04 / CPython 3.13.15 执行6项离线媒体测试（JPEG/PNG/WebP、元信息与方向、二维码规则、语音、带音轨视频、无效输入）；CentOS 7基线 glibc 2.17 / CPython 3.13.18 执行2项兼容测试，含6组真实图片二维码组合。兼容包为普通CPython cp313 ABI，可用于同一3.13系列的3.13.15；不将构建环境的3.13.18描述为服务器实际版本。未操作服务器，安装后仍需用服务器解释器运行第4节检查。
