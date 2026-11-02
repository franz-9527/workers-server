# 复用宝塔项目版 Python 3.13

聊天主服务是 Go，Python 只用于本地图片编解码、二维码检查和音视频处理辅助脚本。无需创建 Python 网站。可以复用“网站 → Python项目 → Python环境管理”中已安装的项目版 3.13.15，不必另装 3.11。

## 1. 版本与系统条件

使用面板安装的项目解释器，不使用 /www/server/panel/pyenv 中的面板自身运行环境，也不改 /usr/bin/python3。不要将依赖直接安装进其他网站（例如 grow）正在使用的虚拟环境。

本安装锁文件对应普通 CPython 3.13（非 free-threaded）、Linux x86_64、glibc 2.27 或更高。先在服务器检查：

```bash
uname -m
getconf GNU_LIBC_VERSION
```

CentOS 7 等 glibc 2.17 系统不能直接安装这些 wheel；仅升级 Python 不会升级 glibc。遇到不匹配时停止安装，记录系统与解释器信息，安排兼容构建或独立容器，不覆盖系统 glibc，也不去掉哈希检查后临时编译。

## 2. 创建独立环境并填写路径

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

## 3. 检查依赖

固定目录方案可用以下命令检查实际 JPEG/PNG/WebP 编解码、二维码库和媒体工具；若面板创建环境或工具不在 /usr/bin，换成后台实际配置的对应路径：

```bash
/www/wwwroot/workers-test/chat/.venv313/bin/python --version
/www/wwwroot/workers-test/chat/.venv313/bin/python /www/wwwroot/workers-test/chat/helpers/preflight.py /usr/bin/ffmpeg /usr/bin/ffprobe
```

pillow、zxing 为 true 才说明图片 Python 依赖可用；ffmpeg、ffprobe 分别显示媒体工具实际结果。Python 就绪不会代替 PHP CLI GD、七牛凭据、微信 ASR 与回调等其他能力条件。服务器 www 用户还需能执行解释器、读取环境及辅助脚本。

## 4. 更新范围与验证

只增加 3.13 依赖锁文件与部署说明；无需数据库迁移、Go重新编译或小程序发布。更新测试站文件后，仍需按上文安装依赖、修改后台路径并应用配置。本说明不代表已操作服务器或实际供应商验收。

供应商发布文件：[Pillow 13.0.0](https://pypi.org/project/pillow/13.0.0/)、[zxing-cpp 3.1.1](https://pypi.org/project/zxing-cpp/3.1.1/)。[Python 虚拟环境说明](https://docs.python.org/3.13/library/venv.html)。
