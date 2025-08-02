# 云阶快传 (YKC)

**极简・高效・可控**的小规模文件分享网盘系统


## 📦 项目简介

**云阶快传 (YKC)** 是一款基于 **Vue 3 + Element Plus + PHP** 架构的现代化轻量级私有网盘系统，搭载全新自研的 **StarUI v3 框架**。

采用**现代化前端技术栈**（Vue 3 Composition API、Element Plus、Chart.js等），定位**小规模文件分享与管理场景**，支持**本地存储**和**多用户协作**，适合个人开发者、小团队或企业搭建专属文件分发平台。

后端 PHP 代码 **100% 开源**（零混淆 / 加密，附带完整注释），技术细节完全透明。

## ✨ 核心特性



*   **极简交互**：基于 StarUI v3 框架的清爽界面，聚焦文件管理核心功能，学习成本极低

*   **数据可控**：文件完全存储在本地服务器，支持自定义存储路径，数据隐私有保障

*   **多用户体系**：完善的用户权限管理（管理员 / 普通用户），支持用户分组、存储空间配额

*   **后端开源**：后端代码无任何加密处理，注释覆盖率高，二次开发友好

*   **跨平台兼容**：适配 Apache/Nginx 环境

## ⚙️ 技术架构



| 模块      | 技术栈                        | 说明                 |
| ------- | -------------------------- | ------------------ |
| **前端**  | Vue 3 + Element Plus + StarUI v3 | 响应式设计，支持 PC / 移动端  |
| **后端**  | PHP 8.0+                   | 原生 PHP ，无框架依赖（JWT鉴权模块）   |
| **数据库** | SQLite              | 轻量部署 |
| **存储层** | 本地文件系统                     | 支持扩展 OSS 存储（计划中）   |
| **部署**  | Docker/Apache/Nginx        | 提供 Docker 快速部署方案   |

### 前端技术栈详细

- **核心框架**: Vue 3 (Composition API)
- **UI组件库**: Element Plus (现代化Vue 3组件库)
- **路由管理**: Vue Router 4 (官方路由解决方案)
- **图表组件**: Chart.js + Vue-Chart-3 (数据可视化)
- **图标库**: Font Awesome Free (丰富的图标资源)
- **二维码**: QRCode-Vue3 (二维码生成组件)
- **图片预览**: Vue Easy Lightbox (图片查看器)
- **视频播放**: Vue Plyr (媒体播放器组件)
- **HTTP客户端**: Axios (API请求处理)
- **自研框架**: StarUI v3 (现代化设计风格)

## 🖥️ 界面预览
![分享设置](https://raw.githubusercontent.com/defeatedperson/ykc/refs/heads/main/photo/1.webp "分享设置")
![分享下载](https://raw.githubusercontent.com/defeatedperson/ykc/refs/heads/main/photo/2.webp "分享下载")
![分享管理](https://raw.githubusercontent.com/defeatedperson/ykc/refs/heads/main/photo/3.webp "分享管理")
![文件管理](https://raw.githubusercontent.com/defeatedperson/ykc/refs/heads/main/photo/4.webp "文件管理")
![文件上传](https://raw.githubusercontent.com/defeatedperson/ykc/refs/heads/main/photo/5.webp "文件上传")
## 🚀 快速部署

### 环境要求

**环境**
*   PHP ≥ 8.0（需开启  `mysqli`, `pdo_mysql` 扩展，推荐开启opcache）
*   SQLite 3.0+
*   Web 服务器：Apache/Nginx（推荐 Nginx 1.18+）

### 部署步骤

📚 详细部署文档：[点击查看安装手册](https://re.xcdream.com/9390.html)&#x20;

## 🌐 官网与社区

*   **官方网站**：[云阶快传官网](https://www.xcdream.com/ykc)&#x20;

*   **问题反馈**：[GitHub Issues](https://github.com/defeatedperson/ykc/issues)


## 📜 开源协议

本项目采用 **Apache License 2.0** 开源协议，允许商业使用，但需遵守以下条款：



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

### UI 与交互
*   [Font Awesome](https://fontawesome.com/) - 世界上最受欢迎的图标库
*   [Vue Easy Lightbox](https://github.com/XiongAmao/vue-easy-lightbox) - 简洁易用的图片预览组件
*   [Vue Plyr](https://github.com/sampotts/plyr) - 现代化媒体播放器组件

### 功能组件
*   [Chart.js](https://www.chartjs.org/) - 简单而灵活的 JavaScript 图表库
*   [Vue-Chart-3](https://github.com/victorgarciaesgi/vue-chart-3) - Chart.js 的 Vue 3 封装
*   [QRCode-Vue3](https://github.com/scholtz/qrcode-vue3) - Vue 3 二维码生成组件
*   [Axios](https://axios-http.com/) - 基于 Promise 的 HTTP 客户端

### 后端与工具
*   [PHP](https://www.php.net/) - PHP
*   [SQLite](https://www.sqlite.org/) - 轻量级嵌入式数据库

### 特别感谢
*   所有为本项目提交 Issue 和 PR 的开发者！
*   开源社区的无私贡献和技术分享精神
*   StarUI v3 设计框架的灵感来源于现代化设计趋势
