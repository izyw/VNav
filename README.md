<div align="center">
  <img src="docs/logo.svg" width="88" height="88" alt="Vnav">
  <h1>Vnav</h1>
  <p><strong>自托管的浏览器起始页。传到虚拟主机就能跑，不需要 Docker、Node 或数据库服务。</strong></p>
  <p>
    <img alt="PHP" src="https://img.shields.io/badge/PHP-8.1%2B-777bb4">
    <img alt="SQLite" src="https://img.shields.io/badge/SQLite-单文件-003b57">
    <img alt="无构建" src="https://img.shields.io/badge/构建步骤-无-brightgreen">
    <img alt="License" src="https://img.shields.io/badge/License-MIT-blue">
  </p>
</div>

![Vnav 主页](docs/home-atlas.png)

Homepage、Dashy、Homarr 这些起始页项目都跑在 Docker 里。Vnav 走另一条路：**一个 PHP 目录 + 一个 SQLite 文件**。传上去、打开安装向导、设个密码，就能用。备份是把 `data/home.db` 拷走，迁移是把它拷回来。

## 它到底省了什么

**不用数据库服务。** SQLite 由 PHP 内置，没有 MySQL、没有容器编排、没有 `docker-compose.yml` 要维护。虚拟主机、NAS、树莓派、旧笔记本都能跑。

**粘贴即收藏。** 登录后在主页按 `Ctrl+V`，自动抓标题、描述和站点图标，生成简称入库——不用一张张填表。

**依赖全在本地。** 站点图标抓一次落盘，六个搜索引擎用各家官方 logo 的本地文件，字体自托管。页面本身**不向任何第三方发起请求**（你主动点的搜索除外）。不向 Google Fonts 要字体，也不向 CDN 要图标。

**一包三用。** 同一个 zip 既是安装包、也是升级包、也是恢复包。升级页上传即完成，版本号倒退也允许——改坏了能原地滚回去。

**摸得着的尺寸。** 首屏 HTML 未压缩 69 KB，gzip 后 **8.5 KB**（Brotli 环境下更小）。服务端渲染，关掉 JS 也能看。

## 界面

两套皮肤。**Atlas** 是 Apple 语言：系统灰底、白卡柔影、胶囊搜索框，三态（亮 / 暗 / 跟随系统）。**工作台**（Frame）把整个应用装进一个带边框的框里，三区并列。

![工作台皮肤](docs/home-frame.png)

布局有卡片 / 横排 / 紧凑 / 网格四选；分组默认在左侧一整列，可收起成 68px 的图标条；卡片支持跨组拖拽，也能直接拖到侧栏组名上。

## 快速开始

需要 PHP **8.1+**，扩展 `pdo_sqlite` `curl` `mbstring` `gd` `fileinfo`：

```bash
php -m | grep -E 'pdo_sqlite|curl|mbstring|gd|fileinfo'
```

本地试跑：

```bash
git clone https://github.com/izyw/vnav.git
cd vnav
php -S 127.0.0.1:8848 -t .
```

打开 `http://127.0.0.1:8848/install.php` 走安装向导，设管理员账号，装完删掉 `install.php`。

自动抓取要访问外部站点，PHP 需要 CA 证书包（`curl.cainfo` / `openssl.cafile`），否则 HTTPS 抓取会报 `unable to get local issuer certificate`。证书从 <https://curl.se/ca/cacert.pem> 取。

生产部署（Nginx / Apache 完整配置、目录权限、安全检查）见 **[部署说明.md](部署说明.md)**。

## 目录结构

```
vnav/
├── index.php        主页，服务端渲染
├── login.php        登录（验证码、失败锁定）
├── admin.php        后台：链接 / 分组 / 外观 / 统计 / 安全 / 升级 / 日志 / 说明
├── api.php          JSON 写接口
├── install.php      一次性安装向导，装完请删除
├── inc/             引导、建表、抓取、压缩、地理库、升级
├── assets/          原生 CSS / JS，无构建步骤，无框架
└── data/            SQLite 数据库与图标缓存（不入库）
```

## 两个必须知道的事

`inc/config.php` 存着站点密钥与管理员密码散列，**已在 `.gitignore` 里**。安装向导从 `inc/config.sample.php` 生成它，别提交。

`data/` 是你的全部数据——数据库、图标缓存、会话。同样不入库。备份和迁移就是这一个目录。

## 文档

- **[部署说明.md](部署说明.md)** —— 上传、赋权、跑向导、安全检查，按步骤走
- **[docs/开发记录.md](docs/开发记录.md)** —— 每个版本改了什么、为什么这么改，含设计取舍和踩过的坑

## 许可

[MIT](LICENSE)
