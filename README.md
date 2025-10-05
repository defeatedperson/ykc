# 云阶快传 (YKC)

**极简・高效・可控**的小规模文件分享系统

## 最近情况说明

通过开发cms项目，学会了很多新知识。为了提升代码质量和后续可维护性，决定重写这个项目，需要一段时间，如果您有其他功能建议/bug反馈，欢迎提issu，十分感谢您的支持。

## 📦 项目定位

基于 **Vue 3 + Element Plus + PHP** 的轻量级私有网盘，搭载自研 StarUI v3 框架，专注小规模文件管理与协作。后端代码 100% 开源（无混淆 / 加密，带完整注释），支持本地存储与多用户体系。

前端 Vue 代码暂未开源，后续将结合社区反馈与项目发展节奏评估开源计划。

## ✨ 核心优势



*   **交互极简**：StarUI v3 框架打造清爽界面，聚焦文件管理核心功能，零学习成本

*   **数据可控**：文件存储于本地服务器，支持自定义存储路径，隐私安全有保障

*   **权限完善**：支持管理员 / 普通用户分级管理，可配置用户分组与空间配额

*   **二次开发友好**：原生 PHP 后端无框架依赖，代码注释丰富，轻松扩展功能

*   **跨平台适配**：Nginx 环境（不推荐apache），推荐通过 Docker 快速部署

## 🚀 推荐部署方式（Docker）

[![通过雨云一键部署](https://rainyun-apps.cn-nb1.rains3.com/materials/deploy-on-rainyun-cn.svg)](https://app.rainyun.com/apps/rca/store/6854/dp712_)

### 环境准备

Docker环境或PHP8+环境（推荐docker部署）
源码安装包可以前往官网下载

### 部署步骤



1.  **首次安装**


    1. 拉取最新镜像：
       docker pull defeatedperson/ykc-app:latest
    2. 启动容器：
       docker run -d \
         --name ykc-cloud-transfer \
         -p 8080:80 \
         -v $(pwd)/web/api/auth/data:/var/www/html/api/auth/data \
         -v $(pwd)/web/api/data:/var/www/html/api/data \
         -v $(pwd)/web/api/file/data:/var/www/html/api/file/data \
         -v $(pwd)/web/api/share/data:/var/www/html/api/share/data \
         defeatedperson/ykc-app:latest

访问 `http://localhost:8080` 即可使用

2.  **更新版本**

    三、更新到最新版本
    1. 停止并删除现有容器：
       docker stop ykc-cloud-transfer
       docker rm ykc-cloud-transfer
    2. 拉取最新镜像（先删除，再拉取）：
       
       docker rmi defeatedperson/ykc-app:latest
       
       docker pull defeatedperson/ykc-app:latest
    4. 重新启动容器：
       docker run -d \
         --name ykc-cloud-transfer \
         -p 8080:80 \
         -v $(pwd)/web/api/auth/data:/var/www/html/api/auth/data \
         -v $(pwd)/web/api/data:/var/www/html/api/data \
         -v $(pwd)/web/api/file/data:/var/www/html/api/file/data \
         -v $(pwd)/web/api/share/data:/var/www/html/api/share/data \
         defeatedperson/ykc-app:latest









## 🖥️ 界面预览



![分享设置](https://raw.githubusercontent.com/defeatedperson/ykc/refs/heads/main/photo/1.webp)



![分享下载](https://raw.githubusercontent.com/defeatedperson/ykc/refs/heads/main/photo/2.webp)



![分享管理](https://raw.githubusercontent.com/defeatedperson/ykc/refs/heads/main/photo/3.webp)



![文件管理](https://raw.githubusercontent.com/defeatedperson/ykc/refs/heads/main/photo/4.webp)



![文件上传](https://raw.githubusercontent.com/defeatedperson/ykc/refs/heads/main/photo/5.webp)

## 🔗 关键链接



*   **官网**：[云阶快传官网](https://www.xcdream.com/ykc)

*   **问题反馈**：[GitHub Issues](https://github.com/defeatedperson/ykc/issues)

*   **商业合作**：dp712@qq.com

*   **交流群（二次元居多）**：[点击加入交流群](https://qm.qq.com/q/a0Kywvgjhm)

## 📜 开源协议

采用 **Apache License 2.0**，商业使用（特指转卖/二开，不含使用本程序分享商业文件）需通过 dp712@qq.com 告知项目团队。

欢迎提交 PR 参与开源共建，获取最新动态请关注项目仓库。
