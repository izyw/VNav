<div align="center">
  <img src="docs/logo.svg" width="88" height="88" alt="Vnav">
  <h1>Vnav</h1>
  <p><strong>自托管的浏览器起始页。传到虚拟主机就能跑，不需要 Docker、Node 或数据库服务。</strong></p>
  <p>
    <img alt="Version" src="https://img.shields.io/badge/version-1.0.0-blue">
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

**装完就有东西可点。** 自带 9 个分组、67 条常用站点（搜索 / AI / 开发 / 设计 / 资讯 / 影音……），不是打开一个空页面从零加起。想全删了换成自己的，后台一键清理。

**粘贴即收藏。** 登录后在主页按 `Ctrl+V`，自动抓标题、描述和站点图标，生成简称入库——不用一张张填表。

**批量整理，不用逐个点。** 编辑模式下每张卡片左上角有勾选框，勾一批就能一次**移到分组、拉取图标、拉取信息、删除**。排序直接拖，能跨组，也能拖到侧栏组名上整张归过去。

**依赖全在本地。** 站点图标抓一次落盘，六个搜索引擎用各家官方 logo 的本地文件，字体自托管。页面本身**不向任何第三方发起请求**（你主动点的搜索除外）。不向 Google Fonts 要字体，也不向 CDN 要图标。

**一包三用。** 同一个 zip 既是安装包、也是升级包、也是恢复包。升级页上传即完成，版本号倒退也允许——改坏了能原地滚回去。

**摸得着的尺寸。** 首页 HTML 未压缩 108 KB（316 条站点），gzip 后 **16 KB**。服务端渲染，关掉 JS 也能看。

## 界面

两套皮肤。**Atlas** 是 Apple 语言：系统灰底、白卡柔影、胶囊搜索框，三态（亮 / 暗 / 跟随系统）。**工作台**（Frame）把整个应用装进一个带边框的框里，三区并列。

![深色](docs/home-dark.png)

![工作台皮肤](docs/home-frame.png)

布局有卡片 / 横排 / 紧凑 / 网格四选；分组默认在左侧一整列，可收起成 68px 的图标条。每个分组有固定颜色（颜色跟着分组走，不跟着排序走），侧栏、组标题、卡片悬停都是同一个色相——扫一眼就知道自己在哪一区。

## 快速开始

需要 PHP **8.1+** 与扩展 `pdo_sqlite` `mbstring`：

```bash
php -m | grep -E 'pdo_sqlite|mbstring'
```

`curl` 用于抓标题与图标，`gd` 用于登录验证码——两者都缺也能跑，只是会降级：抓不到元信息就手动填，验证码画不出来会自动关掉该策略。

```bash
git clone https://github.com/izyw/VNav.git
cd VNav
php -S 127.0.0.1:8848 -t .
```

打开 `http://127.0.0.1:8848/install.php` 走安装向导，设管理员账号，装完删掉 `install.php`。默认数据会自动导入，**图标需要自己拉一次**：登录后进后台「图标」页点「补图标」，或在主页编辑态勾选全部再点「拉取图标」。

自动抓取要访问外部站点，PHP 需要 CA 证书包（`curl.cainfo` / `openssl.cafile`），否则 HTTPS 抓取会报 `unable to get local issuer certificate`。证书从 <https://curl.se/ca/cacert.pem> 取。

生产部署（Nginx / Apache 完整配置、目录权限、安全检查）见 **[部署说明.md](部署说明.md)**。

## 目录结构

```
VNav/
├── index.php        主页，服务端渲染
├── login.php        登录（验证码、失败锁定）
├── admin.php        后台：链接 / 分组 / 外观 / 统计 / 安全 / 升级 / 日志 / 说明
├── api.php          JSON 写接口
├── install.php      一次性安装向导，装完请删除
├── inc/             引导、建表、抓取、压缩、地理库、升级、默认数据种子
├── assets/          原生 CSS / JS，无构建步骤，无框架
└── data/            SQLite 数据库与图标缓存（不入库）
```

## 几个必须知道的事

`inc/config.php` 存着站点密钥与管理员密码散列，**已在 `.gitignore` 里**。安装向导从 `inc/config.sample.php` 生成它，别提交。

`data/` 是你的全部数据——数据库、图标缓存、会话。同样不入库。备份和迁移就是这一个目录。

**默认数据不带图标。** `inc/seed.php` 里只有分组、标题、网址和描述。图标是各家站点的 favicon，商标归各站点所有，不适合跟着 MIT 一起分发；而且它本来就是抓一次就有的东西。

## 文档

- **[部署说明.md](部署说明.md)** —— 上传、赋权、跑向导、安全检查，按步骤走
- **[docs/开发记录.md](docs/开发记录.md)** —— 设计取舍与踩过的坑（含 V1.0.0 之前的内部迭代记录，编号不对外）

## 许可

代码以 [MIT](LICENSE) 授权。文档中出现的站点名称与图标归各自所有者，仅作示例。
