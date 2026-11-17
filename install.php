<?php
/**
 * 一次性安装向导。装完请删除本文件或改名。
 */
declare(strict_types=1);
define('HP_ROOT', __DIR__);

// 装完就别再暴露安装向导：已安装直接 404，连页面都不渲染。
// 包里仍保留本文件供首次安装；生产环境也可直接删掉它。
if (is_file(__DIR__ . '/inc/config.php')) {
    http_response_code(404);
    exit;
}

require __DIR__ . '/inc/compress.php';
require __DIR__ . '/inc/schema.php';

hp_compress_start(6);

$cfgFile = __DIR__ . '/inc/config.php';
$exists  = is_file($cfgFile);
$msg = '';
$err = '';

// 已安装则硬拒绝：否则任何人重跑本页面都能重置管理员账号密码。
// 光靠页面提示「建议先备份」拦不住真正的滥用，必须是服务端拦截。
$locked = $exists;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $locked) {
    $err = '检测到已安装（inc/config.php 存在），安装程序已锁定。'
         . '如需重装，请先手动删除 inc/config.php 与 data/home.db。';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$locked) {
    $user  = trim($_POST['user'] ?? 'admin');
    $pass  = (string)($_POST['pass'] ?? '');
    $title = trim($_POST['site_title'] ?? 'Vnav');
    // 单人 / 多人模式：安装时就得选，默认多人（与老版本行为一致）。
    $mode  = (string)($_POST['site_mode'] ?? 'multi');
    if ($mode !== 'single' && $mode !== 'multi') {
        $mode = 'multi';
    }

    if ($user === '' || strlen($pass) < 8) {
        $err = '账号不能为空，密码至少 8 位。';
    } else {
        $dbPath = __DIR__ . '/data/home.db';
        if (!is_dir(__DIR__ . '/data')) {
            @mkdir(__DIR__ . '/data', 0775, true);
        }
        // icons 提前建好：压缩包里带的是空目录，某些解压工具会把它丢掉，
        // 这里兜一层。MetaFetcher 自己也会在写入时补建，双保险。
        if (!is_dir(__DIR__ . '/data/icons')) {
            @mkdir(__DIR__ . '/data/icons', 0775, true);
        }
        try {
            $pdo = new PDO('sqlite:' . $dbPath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $pdo->exec('PRAGMA journal_mode=WAL');
            hp_install_schema($pdo);

            /* 默认链接库：9 个分组、67 条站点（见 inc/seed.php）。
               只在 links 为空时灌 —— 带着自己的 home.db 来装的人不会被覆盖。
               失败不算安装失败：没有默认数据只是首页空着，站点本身是好的。 */
            $seedMsg = '';
            try {
                require_once __DIR__ . '/inc/seed.php';
                $seed = hp_seed_apply($pdo);
                if (!$seed['skipped']) {
                    $seedMsg = '已导入 ' . $seed['groups'] . ' 个分组、' . $seed['links']
                             . ' 条常用站点（图标请在后台「图标」页点一次「补图标」抓取）。';
                }
            } catch (Throwable $e) {
                $seedMsg = '默认数据导入失败（不影响使用）：' . $e->getMessage();
            }

            if ($title !== '') {
                $st = $pdo->prepare('UPDATE settings SET v = ? WHERE k = ?');
                $st->execute([$title, 'site_title']);
            }
            // 模式选择覆盖 hp_seed_settings 写入的默认值。
            // 用 INSERT OR REPLACE 而非只 UPDATE：老库升级路径可能没有这一行。
            $pdo->prepare('INSERT INTO settings(user_id, k, v) VALUES(0, ?, ?)
                           ON CONFLICT(user_id, k) DO UPDATE SET v = excluded.v')
                ->execute(['site_mode', $mode]);

            $tpl = file_get_contents(__DIR__ . '/inc/config.sample.php');
            $tpl = str_replace(
                ["'secret'   => '',", "'admin_user' => 'admin',", "'admin_pass' => '',"],
                [
                    "'secret'   => '" . bin2hex(random_bytes(24)) . "',",
                    "'admin_user' => '" . addslashes($user) . "',",
                    "'admin_pass' => '" . addslashes(password_hash($pass, PASSWORD_BCRYPT, ['cost' => 11])) . "',",
                ],
                $tpl
            );
            // 生产默认关掉 debug
            $tpl = str_replace("'debug'    => false,", "'debug'    => false,", $tpl);
            file_put_contents($cfgFile, $tpl);

            /* 登录只认 users 表（见 login.php 的 password_verify），
               config.php 里那对 admin_user / admin_pass 从 v6 起就不参与校验，
               只留给「老库升级时导入首个管理员」用。以前这里只写配置不写 users，
               而 hp_install_schema 又只建表 —— 新建的库 users 是空的，
               装完拿什么密码都进不去。这里把表单填的账号真正落到 users；
               已存在同名账号（比如带了自己本机的 home.db）就更新密码，
               免得用户以为填了新密码却只能用旧密码登。 */
            $pdo->prepare(
                'INSERT INTO users(username, pass_hash, role, created_at) VALUES(?, ?, ?, ?)
                 ON CONFLICT(username) DO UPDATE SET pass_hash = excluded.pass_hash, role = excluded.role'
            )->execute([
                $user,
                password_hash($pass, PASSWORD_BCRYPT, ['cost' => 11]),
                'admin',
                date('Y-m-d H:i:s'),
            ]);

            $msg = '安装完成！当前为<strong>' . ($mode === 'single' ? '单人模式' : '多人模式')
                 . '</strong>（可在后台「模式切换」里随时改）。请立刻删除 install.php，然后进入主页。'
                 . ($seedMsg !== '' ? ' ' . $seedMsg : '');
        } catch (Throwable $e) {
            $err = '安装失败：' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>安装 · Vnav</title>
<style>
:root{--bg:#14111c;--panel:#1e1a28;--line:#332c44;--text:#f2eee7;--dim:#a9a1b8;--accent:#f0b429}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;
  background:radial-gradient(120% 90% at 15% 0%,#2a2140 0%,#14111c 55%,#0e0b14 100%);
  color:var(--text);font:16px/1.6 "Instrument Sans","PingFang SC","Microsoft YaHei",sans-serif}
.card{width:100%;max-width:460px;background:var(--panel);border:1px solid var(--line);
  border-radius:20px;padding:32px;box-shadow:0 24px 60px -20px #000}
h1{font-size:26px;margin:0 0 6px;letter-spacing:-.02em}
p.sub{color:var(--dim);margin:0 0 24px;font-size:14px}
label{display:block;font-size:13px;color:var(--dim);margin:16px 0 6px;font-weight:600;letter-spacing:.02em}
input{width:100%;min-height:46px;padding:10px 14px;border-radius:11px;border:1px solid var(--line);
  background:#171320;color:var(--text);font-size:16px}
input:focus{outline:2px solid var(--accent);outline-offset:1px;border-color:transparent}
button{width:100%;min-height:48px;margin-top:26px;border:0;border-radius:12px;cursor:pointer;
  background:linear-gradient(180deg,var(--accent),#d99a10);color:#2a1c00;font-size:16px;font-weight:700}
button:hover{filter:brightness(1.06)}
.ok,.bad{padding:12px 14px;border-radius:11px;font-size:14px;margin-bottom:16px}
.ok{background:#12351f;color:#8fe6ab;border:1px solid #1f5c33}
.bad{background:#3a1420;color:#ff9db0;border:1px solid #6b2334}
code{background:#0e0b14;padding:2px 6px;border-radius:5px;font-size:13px}
a{color:var(--accent)}
.mode-opts{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:6px}
.mode-opt{position:relative;display:block;padding:12px 12px 12px 14px;border:1px solid var(--line);
  border-radius:12px;cursor:pointer;background:#171320;transition:border-color .15s,background .15s}
.mode-opt:hover{border-color:#6b5c8f}
.mode-opt input{position:absolute;opacity:0;width:1px;min-height:1px}
.mode-opt:has(input:checked){border-color:var(--accent);background:#241d12}
.mode-opt b{display:block;font-size:14px;color:var(--text);margin-bottom:3px}
.mode-opt small{display:block;font-size:11.5px;line-height:1.45;color:var(--dim)}
.mode-opt input:focus-visible~b{outline:2px solid var(--accent);outline-offset:2px}
</style>
</head>
<body>
<div class="card">
  <h1>安装 vnav</h1>
  <p class="sub">创建数据库与管理员账号，只需一次。</p>
  <?php if ($msg): ?>
    <div class="ok"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div>
    <p><a href="index.php">→ 打开主页</a>　<a href="login.php">→ 后台登录</a></p>
  <?php elseif ($locked): ?>
    <?php if ($err): ?><div class="bad"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <div class="bad">检测到 <code>inc/config.php</code> 已存在，安装程序已锁定。</div>
    <p>站点已经在运行，直接进入即可：</p>
    <p><a href="index.php">→ 打开主页</a>　<a href="login.php">→ 后台登录</a></p>
    <p class="sub" style="margin-top:22px">确实要重装，请先删除这两个：
      <code>inc/config.php</code>、<code>data/home.db</code>。</p>
  <?php else: ?>
    <?php if ($err): ?><div class="bad"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <form method="post">
      <label>使用模式</label>
      <div class="mode-opts" role="radiogroup" aria-label="使用模式">
        <label class="mode-opt">
          <input type="radio" name="site_mode" value="multi" checked>
          <b>多人模式</b>
          <small>开放注册，每人一个账号，链接各自独立</small>
        </label>
        <label class="mode-opt">
          <input type="radio" name="site_mode" value="single">
          <b>单人模式</b>
          <small>自己用：不开放注册、链接免登录可见</small>
        </label>
      </div>
      <label for="site_title">主页标题</label>
      <input id="site_title" name="site_title" value="Vnav">
      <label for="user">管理员账号</label>
      <input id="user" name="user" value="admin" autocomplete="username" required>
      <label for="pass">密码（至少 6 位）</label>
      <input id="pass" name="pass" type="password" autocomplete="new-password" minlength="6" required>
      <button type="submit">开始安装</button>
    </form>
  <?php endif; ?>
</div>
</body>
</html>