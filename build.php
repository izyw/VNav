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

$root = __DIR__;
$ver  = trim((string)@file_get_contents($root . '/VERSION'));
if ($ver === '') {
    fwrite(STDERR, "读不到 VERSION\n");
    exit(1);
}

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
$skipDirs = ['.git', '.workbuddy', 'data', 'node_modules', '__pycache__'];
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
    if (in_array($top, $skipDirs, true)) {
        continue;
    }
    if (in_array($rel, $skipFiles, true)) {
        continue;
    }
    $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
    if (in_array($ext, $skipExt, true)) {
        continue;
    }
    // 隐藏文件（.htaccess 要留，它是部署必需）
    if (str_starts_with(basename($rel), '.') && $rel !== '.htaccess') {
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
$hasInstall = false;
for ($i = 0; $i < $check->numFiles; $i++) {
    $n = (string)$check->getNameIndex($i);
    if (str_contains($n, 'inc/config.php') || str_contains($n, '/data/')) {
        $bad[] = $n;
    }
    if (preg_match('~^vnav/install\.php$~', $n)) $hasInstall = true;
}
$check->close();
if ($bad) {
    fwrite(STDERR, "❌ 自检失败，包里含不该有的文件：\n  " . implode("\n  ", $bad) . "\n");
    exit(2);
}
if (!$hasInstall) {
    fwrite(STDERR, "❌ 自检失败：包里缺 install.php，新装用户装不了\n");
    exit(2);
}
echo "  自检：通过（无 config.php / data/，含 install.php）\n";