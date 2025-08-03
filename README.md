# 云阶快传 (YKC)

**极简・高效・可控**的小规模文件分享网盘系统


## 📦 项目简介

**云阶快传 (YKC)** 是一款基于 **Vue 3 + Element Plus + PHP** 架构的现代化轻量级私有网盘系统，搭载全新自研的 **StarUI v3 框架**。

采用**现代化前端技术栈**（Vue 3 Composition API、Element Plus、Chart.js等），定位**小规模文件分享与管理场景**，支持**本地存储**和**多用户协作**，适合个人开发者、小团队或企业搭建专属文件分发平台。

后端 PHP 代码 **100% 开源**（零混淆 / 加密，附带完整注释），技术细节完全透明。

##  ⚠ 提示
如果未启用https，则关闭网页/刷新网页自动退出登录（无法保持登录）
已知问题：管理员视角，删除文件之后，会多余提示一次：目录不存在（实际上操作成功，也返回成功提示），不影响功能。

## ✨ 核心特性

*   **极简交互**：基于 StarUI v3 框架的清爽界面，聚焦文件管理核心功能，学习成本极低

*   **数据可控**：文件完全存储在本地服务器，支持自定义存储路径，数据隐私有保障

*   **多用户体系**：完善的用户权限管理（管理员 / 普通用户），支持用户分组、存储空间配额

*   **后端开源**：后端代码无任何加密处理，注释覆盖率高，二次开发友好

## ⚙️ 技术架构

| 模块      | 技术栈                        | 说明                 |
| ------- | -------------------------- | ------------------ |
| **前端**  | Vue 3 + Element Plus + StarUI v3 | 响应式设计，支持 PC / 移动端  |
| **后端**  | PHP 8.0+                   | 原生 PHP ，无框架依赖（JWT鉴权模块）   |
| **数据库** | SQLite              | 轻量部署 |
| **存储层** | 本地文件系统                     | 支持扩展 OSS 存储（计划中）   |
| **部署**  | Docker/Nginx        | 提供 Docker 快速部署方案   |

## 🖥️ 界面预览
![分享设置](https://raw.githubusercontent.com/defeatedperson/ykc/refs/heads/main/photo/1.webp "分享设置")
![分享下载](https://raw.githubusercontent.com/defeatedperson/ykc/refs/heads/main/photo/2.webp "分享下载")
![分享管理](https://raw.githubusercontent.com/defeatedperson/ykc/refs/heads/main/photo/3.webp "分享管理")
![文件管理](https://raw.githubusercontent.com/defeatedperson/ykc/refs/heads/main/photo/4.webp "文件管理")
![文件上传](https://raw.githubusercontent.com/defeatedperson/ykc/refs/heads/main/photo/5.webp "文件上传")

## 🚀 快速部署

### 环境要求

**通用环境基础**
*   PHP ≥ 8.0（需开启  `mysqli`, `pdo_mysql` 扩展，推荐开启opcache）
*   SQLite 3.0+

**Web 服务器说明**
*   推荐：Nginx 1.18+
*   不推荐：Apache 环境（存在已知兼容问题，不建议使用）


### 部署方式推荐

#### Docker部署（强烈推荐）
Docker部署方式具有环境一致性高、更新便捷、数据持久化可靠等优势，是官方推荐的首选部署方式。

##### 一、首次部署
```bash
# 1. 拉取最新镜像
docker pull defeatedperson/ykc-app:latest

# 2. 启动容器
docker run -d \
  --name ykc-cloud-transfer \
  -p 8080:80 \
  -v $(pwd)/web/api/auth/data:/var/www/html/api/auth/data \
  -v $(pwd)/web/api/data:/var/www/html/api/data \
  -v $(pwd)/web/api/file/data:/var/www/html/api/file/data \
  -v $(pwd)/web/api/share/data:/var/www/html/api/share/data \
  defeatedperson/ykc-app:latest
```

**参数说明**：
- `-d`: 后台运行容器
- `--name`: 指定容器名称（此处为`ykc-cloud-transfer`）
- `-p`: 端口映射，将主机的 8080 端口映射到容器的 80 端口（可根据需求修改主机端口）
- `-v`: 挂载数据卷，确保用户数据、文件、配置等内容在容器重启/更新后不丢失


##### 二、访问应用
启动容器后，通过以下地址访问应用：  
`http://localhost:8080`  
（若修改了主机端口，将`8080`替换为实际端口）


##### 三、更新到最新版本
```bash
# 1. 停止并删除现有容器
docker stop ykc-cloud-transfer
docker rm ykc-cloud-transfer

# 2. 拉取最新镜像
docker pull defeatedperson/ykc-app:latest

# 3. 重新启动容器（使用与首次部署相同的参数）
docker run -d \
  --name ykc-cloud-transfer \
  -p 8080:80 \
  -v $(pwd)/web/api/auth/data:/var/www/html/api/auth/data \
  -v $(pwd)/web/api/data:/var/www/html/api/data \
  -v $(pwd)/web/api/file/data:/var/www/html/api/file/data \
  -v $(pwd)/web/api/share/data:/var/www/html/api/share/data \
  defeatedperson/ykc-app:latest
```


##### 四、其他常用命令
```bash
# 查看容器日志
docker logs -f ykc-cloud-transfer

# 进入容器终端
docker exec -it ykc-cloud-transfer /bin/sh

# 查看运行中的容器
docker ps

# 查看所有容器（包括停止的）
docker ps -a

# 查看本地镜像
docker images
```


#### 直接部署（不推荐Apache环境）
若需直接部署（非Docker方式），请确保满足上述环境要求，且**避免使用Apache服务器**以减少兼容问题。

📚 详细部署文档：[点击查看安装手册](https://re.xcdream.com/9390.html)


## 🌐 官网与社区

*   **官方网站**：[云阶快传官网](https://www.xcdream.com/ykc)&#x20;

*   **问题反馈**：[GitHub Issues](https://github.com/defeatedperson/ykc/issues)


## 📜 开源协议

本项目采用 **Apache License 2.0** 开源协议，允许商业使用，但是严禁用于任何违法违规业务。

**特别说明**：商业用途（特指二开/商用本软件，不含使用本软件分发自己的商用文件）需提前通过dp712@qq.com告知项目团队。

## 🤝 贡献指南

欢迎提交 PR 或 Issue 参与项目共建！

## 📧 联系我们

*   **商务合作**：dp712@qq.com

*   **交流群（二次元居多）**：https://qm.qq.com/q/a0Kywvgjhm

## 🙏 致谢

感谢以下开源项目对本项目的启发与支持：

### 核心框架
*   [Vue.js](https://vuejs.org/) - 渐进式 JavaScript 框架
*   [Element Plus](https://element-plus.org/) - 基于 Vue 3 的桌面端组件库
*   [Vue Router](https://router.vuejs.org/) - Vue.js 官方路由管理器

### 后端与工具
*   [PHP](https://www.php.net/) - PHP
*   [SQLite](https://www.sqlite.org/) - 轻量级嵌入式数据库

### 特别感谢
*   所有为本项目提交 Issue 和 PR 的开发者！
*   开源社区的无私贡献和技术分享精神
*   StarUI v3 设计框架的灵感来源于现代化设计趋势
