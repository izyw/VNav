<?php
/**
 * 打一个 Vnav 交付包。
 *
 * 一包三用 → 同一个 zip 既是安装包、也是升级包、也是恢复包。
 * 因此包里**绝不能**带这几样：
 *   inc/config.php    管理员密码哈希与密钥，跟着包走等于把密码公开
 *   data/             数据库、图标缓存、会话，全是本机数据
 *   install.php       装完就该删的东西，留着是个后门
 *   .workbuddy/       编辑器与本会话的私货
 *
 * 用法：php build.php            → 输出到上级目录
 *       php build.php --out=DIR  → 指定输出目录
 */
declare(strict_types=1);

/* 只能命令行跑。放在网站根下时，浏览器访问它就是「把整个源码打个包给你」——
   包里虽然没有 config.php 与 data/，但全部业务代码都在，等于源码公开。
   （release.php 早就有同样的守卫，这里之前漏了。） */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = __DIR__;
$ver  = trim((string)@file_get_contents($root . '/VERSION'));
if ($ver === '') {
    fwrite(STDERR, "读不到 VERSION\n");
    exit(1);
}

/* JS 语法硬闸门。
   V1.1.0 的 app.js:395 写成 `+ , 'ok'`，浏览器一加载就抛 SyntaxError，
   整个前台脚本一行都不执行（编辑/批量/拖拽/设引擎全瘫），
   本地源码、线上、GitHub 三处同时中招，肉眼 review 三次都漏掉。
   出包前用 node --check 过一遍 assets/*.js，过不了直接停 —— 绝不产出坏包。 */
$nodeProbe = [];
$nodeCode  = 0;
@exec('node --version 2>&1', $nodeProbe, $nodeCode);
if ($nodeCode !== 0) {
    fwrite(STDERR, "❌ 打包前置检查失败：找不到 node，无法校验 assets/*.js 语法\n");
    exit(1);
}
foreach (glob($root . '/assets/*.js') as $jsFile) {
    $jsOut  = [];
    $jsCode = 0;
    @exec('node --check ' . escapeshellarg(str_replace('/', '\\', $jsFile)) . ' 2>&1', $jsOut, $jsCode);
    if ($jsCode !== 0) {
        fwrite(STDERR, "❌ JS 语法错误，拒绝打包：" . basename($jsFile) . "\n  "
            . implode("\n  ", $jsOut) . "\n");
        exit(1);
    }
}
echo "JS 语法检查：通过（assets/*.js）\n";

$out = dirname($root);
foreach ($argv as $a) {
    if (str_starts_with($a, '--out=')) {
        $out = rtrim(substr($a, 6), '/\\');
    }
}
if (!is_dir($out) && !@mkdir($out, 0777, true)) {
    fwrite(STDERR, "输出目录建不出来：$out\n");
    exit(1);
}

$zipPath = $out . '/vnav-' . $ver . '.zip';
@unlink($zipPath);

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "打不开 zip\n");
    exit(1);
}

/* 排除规则。用「相对路径前缀 + 文件名」双判，任一命中就跳过。
   注意这里不能只判 basename：data/ 下任何东西都要挡，
   但 assets/fonts/*.woff2 要留 —— 所以按目录前缀判更可靠。 */
/* docs/ 是仓库里的文档（三张界面截图就占 500 KB），装到服务器上没有任何用处，
   放进包里只会让安装包大一倍。README 与部署说明留着 —— 解压的人会看。 */
$skipDirs = ['.git', '.workbuddy', 'data', 'node_modules', '__pycache__', 'docs'];
/* .gitattributes / release.php 是「仓库专用」的：前者管换行符策略，
   后者是往 GitHub 发版用的。装到别人服务器上这两样没有任何用处，
   留着只会让人误以为站点里有什么需要跑一下。 */
$skipFiles = ['inc/config.php', '.DS_Store', 'Thumbs.db', 'build.php', '安装说明.txt',
              '.gitattributes', '.gitignore', 'release.php'];
$skipExt = ['log', 'tmp', 'bak', 'db', 'db-wal', 'db-shm',
            /* zip 绝对不能进包：交付物本身就是 zip，源码目录里一旦留下
               上一次的打包产物（vnav.zip / vnav-V1.x.x.zip），下一包就会
               把它整个塞进去 —— 体积直接翻倍，解压后站点根还多出一个
               装着旧代码的压缩包，用户根本分不清该用哪个。 */
            'zip'];

$files = [];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($it as $f) {
    /** @var SplFileInfo $f */
    $abs = str_replace('\\', '/', $f->getPathname());
    $rel = ltrim(substr($abs, strlen(str_replace('\\', '/', $root))), '/');

    // 目录本身不进包，由文件自带路径
    if ($f->isDir()) {
        continue;
    }

    $top = explode('/', $rel)[0];
    /* 两份 .htaccess 是部署必需，即使所在目录被整体跳过也要进包：
         data/.htaccess —— 挡住 home.db 与目录列举
         inc/.htaccess  —— 挡住 config.php 源码
       少了它们，装完就是「数据库可下载 + 源码可读」，而这两件事
       恰恰是这套代码最不该发生的（见 .htaccess 第一节的注释）。 */
    $forceInclude = ['data/.htaccess', 'inc/.htaccess'];
    if (in_array($top, $skipDirs, true) && !in_array($rel, $forceInclude, true)) {
        continue;
    }
    if (in_array($rel, $skipFiles, true)) {
        continue;
    }
    $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
    if (in_array($ext, $skipExt, true) && !in_array($rel, $forceInclude, true)) {
        continue;
    }
    // 隐藏文件一律跳过，但**所有层级**的 .htaccess 都要留（不只是根目录那份）
    $base = basename($rel);
    if (str_starts_with($base, '.') && $base !== '.htaccess') {
        continue;
    }

    $files[] = $rel;
}

sort($files);

/* 包内统一套一层 vnav/。
   理由：解压到虚拟主机时不会把 index.php 直接泼在网站根上，
   用户多一步移动，但少了「覆盖掉别人的东西」这种事故。
   升级解析器已经能自动识别这层前缀（见 inc/upgrade.php）。 */
foreach ($files as $rel) {
    $zip->addFile($root . '/' . $rel, 'vnav/' . $rel);
}

/* 附带一份说明，解压的人第一眼能看到怎么装 */
$readme = "Vnav $ver\n"
    . str_repeat('=', 40) . "\n\n"
    . "这个包一包三用：\n"
    . "  1) 第一次装  → 解压到网站目录，浏览器打开 install.php\n"
    . "  2) 升级      → 后台「升级」页直接上传本 zip\n"
    . "  3) 恢复      → 改坏了就用本 zip 再传一次（版本号倒退也允许）\n\n"
    . "包里刻意不含：\n"
    . "  inc/config.php   管理员账号与密码哈希（首次安装时生成）\n"
    . "  data/            数据库与图标缓存（你的全部数据）\n\n"
    . "升级时程序会跳过 inc/config.php 与 data/，所以覆盖不会丢数据。\n"
    . "install.php 本身也有硬拦截：只要 inc/config.php 已存在就拒绝运行，\n"
    . "所以升级后它留在服务器上也不会被人重跑一遍重置密码。\n"
    . "当然，装完之后手动删掉它仍然是最干净的做法。\n";
$zip->addFromString('vnav/安装说明.txt', $readme);

$zip->close();

$size = filesize($zipPath);
printf("打包完成\n  文件：%s\n  条目：%d\n  体积：%s（%d B）\n", $zipPath, count($files) + 1, round($size / 1024, 1) . ' KB', $size);

// 自检：不该在包里的东西必须在外面
/* 注意 install.php 是**该在包里**的 —— 一包三用里的「安装包」就靠它。
   它本身有服务端硬拦截：只要 inc/config.php 存在就直接拒绝运行，
   所以升级场景下传到线上也不会被人重跑一遍重置密码。
   （早先把它排除掉，导致包里没有安装向导，新装用户按说明打开
     install.php 会 404，「一包三用」当场变成两用。） */
$check = new ZipArchive();
$check->open($zipPath);
$bad = [];
$names = [];
$hasInstall = false;
for ($i = 0; $i < $check->numFiles; $i++) {
    $n = (string)$check->getNameIndex($i);
    $names[] = $n;
    /* data/ 下只允许 data/.htaccess —— 它是部署必需的守卫文件，
       其余（home.db / icons / dns-cache.json）一律不许进包。 */
    $isData = str_contains($n, '/data/') && !str_ends_with($n, '/data/.htaccess');
    if (str_contains($n, 'inc/config.php') || $isData) {
        $bad[] = $n;
    }
    if (preg_match('~^vnav/install\.php$~', $n)) $hasInstall = true;
}
$check->close();
if ($bad) {
    fwrite(STDERR, "❌ 自检失败，包里含不该有的文件：\n  " . implode("\n  ", $bad) . "\n");
    exit(2);
}
/* 三份 .htaccess 缺一不可：根那份挡 inc/ 与 data/，data/ 那份挡数据库，
   inc/ 那份挡 config.php。少任何一份，装完就是一个可被直接下载的站点。 */
$need = ['vnav/.htaccess', 'vnav/data/.htaccess', 'vnav/inc/.htaccess'];
$missing = array_values(array_diff($need, $names));
if ($missing) {
    fwrite(STDERR, "❌ 自检失败，包里缺 .htaccess（部署必需）：\n  " . implode("\n  ", $missing) . "\n");
    exit(2);
}
if (!$hasInstall) {
    fwrite(STDERR, "❌ 自检失败：包里缺 install.php，新装用户装不了\n");
    exit(2);
}
echo "  自检：通过（无 config.php / data/，含 install.php）\n";