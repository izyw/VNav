<?php
/**
 * 登录页。三层防护，逐级加码：
 *
 *   第 0 层（默认）  账号 + 密码，不需要验证码。天天登录的人不该被拦。
 *   第 1 层（错 1 次）出现图形验证码。挡住「只差一个字符」的暴力试探。
 *   第 2 层（错 5 次）锁定 15 分钟。IP 与账号双维度，换 IP 也躲不掉账号那一半。
 *
 * 计数与锁分两张表：
 *   login_attempts —— 短窗口计数（15 分钟内错几次）
 *   login_locks    —— 封锁记录（到几点解封）
 * 分开的好处：解封不必清零计数，看清「为什么被锁」也不必翻日志。
 */
declare(strict_types=1);
define('HP_ROOT', __DIR__);
require __DIR__ . '/inc/boot.php';
require __DIR__ . '/inc/captcha.php';

hp_compress_start(6);
session_boot();

if (is_logged_in()) {
    header('Location: admin.php');
    exit;
}

/* ---------- 验证码图片输出 ----------
   放在最前面，且必须在任何 HTML 之前。
   token 走 GET，图片本身不带状态，靠 token 去库里换答案。

   token 无效（过期/已消费）时不偷偷给新码 —— 那样用户看到的图
   和库里的答案对不上，会陷入「明明输对了也不通过」的死循环。
   这时给一张写着「已过期」的图，前端换图会走 api.php 拿新 token。 */
if (isset($_GET['captcha'])) {
    $tok = preg_replace('~[^a-f0-9]~', '', (string)$_GET['captcha']) ?? '';
    if ($tok !== '') {
        $st = db()->prepare('SELECT code FROM captcha WHERE token = ?');
        $st->execute([$tok]);
        $code = (string)($st->fetchColumn() ?: '');
        if ($code !== '') {
            hp_captcha_render($code);
        }
    }
    hp_captcha_render_expired();
}

$err = '';
$ip  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$user0 = trim((string)($_POST['user'] ?? ''));

/* 锁定键：IP 与账号各一把。
   账号那把用 hash 而不是明文 —— 这张表可能被人看到，
   不该白送一份「这个站的管理员账号叫 admin」。 */
$lockIp  = 'ip:' . $ip;
$lockUsr = 'usr:' . hash('sha256', mb_strtolower($user0 !== '' ? $user0 : '-'));

$LOCK_WINDOW = 900;   // 15 分钟统计窗口
$LOCK_LIMIT  = 5;     // 5 次失败
$LOCK_TIME   = 900;   // 锁 15 分钟（用户明确要求 15 分钟）

$pdo = db();

/** 取某个键的封锁剩余秒数；0 表示没锁 */
$lockLeft = static function (string $k) use ($pdo): int {
    $st = $pdo->prepare('SELECT until FROM login_locks WHERE k = ?');
    $st->execute([$k]);
    $until = (int)($st->fetchColumn() ?: 0);
    return max(0, $until - time());
};

/** 写封锁 */
$lockSet = static function (string $k, int $sec, string $why) use ($pdo): void {
    $pdo->prepare(
        'INSERT INTO login_locks(k, until, reason, at) VALUES(?, ?, ?, ?)
         ON CONFLICT(k) DO UPDATE SET until = excluded.until, reason = excluded.reason, at = excluded.at'
    )->execute([$k, time() + $sec, $why, time()]);
};

$waitIp  = $lockLeft($lockIp);
$waitUsr = $lockLeft($lockUsr);
$wait    = max($waitIp, $waitUsr);
if ($wait > 0) {
    $min = (int)ceil($wait / 60);
    $err = "尝试次数过多，请 {$min} 分钟后再试。";
}

// 当前 IP 的失败次数 —— 决定要不要出验证码
$st = $pdo->prepare('SELECT fails, last_at FROM login_attempts WHERE ip = ?');
$st->execute([$ip]);
$row = $st->fetch() ?: ['fails' => 0, 'last_at' => 0];
$fails = ((time() - (int)$row['last_at']) > $LOCK_WINDOW) ? 0 : (int)$row['fails'];

$captchaOn = hp_captcha_available() && setting('login_captcha', '1') === '1';
// 出验证码的条件：GD 可用 且 开关开启 且 已经错过至少一次。
// 没装 GD 时强制关掉 —— 图片画不出来，要求输入等于把用户永久锁在门外。
$needCaptcha = $captchaOn && $fails >= 1;

// 准备一张验证码给页面用（只在需要时生成，避免无谓写库）
$capToken = '';
if ($needCaptcha && $wait === 0) {
    [$capToken] = hp_captcha_new();
}

/* 注册与登录共用一个表单，靠 mode 区分。
   mode 写在隐藏字段里而不是只看 URL —— 表单 POST 之后 URL 参数就没了。 */
$mode = (($_POST['mode'] ?? $_GET['mode'] ?? '') === 'register') ? 'register' : 'login';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $wait === 0) {
    $u = $user0;
    $p = (string)($_POST['pass'] ?? '');
    $ok = false;
    $dest = './';
    $newUserId = 0;

    /* 验证码先验，注册也走这一套 —— 否则注册就是一个不需要任何门槛的
       写入口，被脚本刷一遍就是几千个空账号。 */
    $capOk = true;
    if ($needCaptcha) {
        $tok = (string)($_POST['captcha_token'] ?? '');
        $in  = (string)($_POST['captcha'] ?? '');
        $capOk = hp_captcha_check($tok, $in);
    }

    if (!$capOk) {
        $err = '验证码不正确。';
    } elseif ($mode === 'register') {
        /* ---------- 注册 ---------- */
        if (setting('allow_register', '1') !== '1') {
            $err = '本站已关闭注册，请联系管理员。';
        } elseif (!hp_username_valid($u)) {
            $err = '用户名 3~24 位，只能使用字母、数字、下划线或中文。';
        } elseif (mb_strlen($p) < 8) {
            $err = '密码至少 8 位。';
        } elseif ($p !== (string)($_POST['pass2'] ?? '')) {
            $err = '两次输入的密码不一致。';
        } elseif (hp_username_taken($u)) {
            $err = '这个用户名已经被占用了。';
        } else {
            try {
                $pdo->prepare('INSERT INTO users(username, pass_hash, role, created_at) VALUES(?, ?, ?, ?)')
                    ->execute([$u, password_hash($p, PASSWORD_BCRYPT, ['cost' => 11]), 'user', now()]);
                $newUserId = (int)$pdo->lastInsertId();
                // 新账号复制一份站点默认数据，免得进来是一片空白
                hp_clone_default_data($pdo, $newUserId);
                $ok = true;
            } catch (Throwable $e) {
                $err = '注册失败，换一个用户名再试。';
            }
        }
    } else {
        /* ---------- 登录 ---------- */
        $st = $pdo->prepare('SELECT id, username, pass_hash, role FROM users WHERE username = ?');
        $st->execute([$u]);
        $acc = $st->fetch();
        if ($acc && password_verify($p, (string)$acc['pass_hash'])) {
            $newUserId = (int)$acc['id'];
            // 管理员进后台，普通账号回首页 —— 后台对他们本来就是 403
            $dest = ($acc['role'] ?? 'user') === 'admin' ? 'admin.php' : './';
            $ok = true;
        } else {
            $err = '账号或密码不正确。';
        }
    }

    if ($ok && $newUserId > 0) {
        // 成功：清计数、解封锁、换 session id 防固定攻击
        $pdo->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ip]);
        $pdo->prepare('DELETE FROM login_locks WHERE k IN (?, ?)')->execute([$lockIp, $lockUsr]);
        $pdo->prepare('UPDATE users SET last_login = ? WHERE id = ?')->execute([now(), $newUserId]);
        session_regenerate_id(true);
        $_SESSION['uid']  = $newUserId;
        $_SESSION['csrf'] = bin2hex(random_bytes(20));
        $_SESSION['logged_at'] = time();
        current_user_flush();
        header('Location: ' . $dest);
        exit;
    }

    // 失败：计数 +1（窗口过期就重置成 1）
    $fails = ((time() - (int)$row['last_at']) > $LOCK_WINDOW) ? 1 : ($fails + 1);
    $pdo->prepare(
        'INSERT INTO login_attempts(ip, fails, last_at) VALUES(?, ?, ?)
         ON CONFLICT(ip) DO UPDATE SET fails = excluded.fails, last_at = excluded.last_at'
    )->execute([$ip, $fails, time()]);

    if ($fails >= $LOCK_LIMIT) {
        // 两个维度一起锁：换 IP 也还得面对账号那把锁
        $lockSet($lockIp, $LOCK_TIME, 'fails=' . $fails);
        $lockSet($lockUsr, $LOCK_TIME, 'fails=' . $fails);
        $err = '连续失败 ' . $LOCK_LIMIT . ' 次，已锁定 15 分钟。';
        $needCaptcha = false;
        $capToken = '';
    } else {
        $left = $LOCK_LIMIT - $fails;
        // 注册/登录各自的错误文案已经设好了，这里只在没设时补默认
        if ($err === '') {
            $err = '账号或密码不正确。';
        }
        if ($left > 0 && $left <= 3) {
            $err .= "（还可尝试 {$left} 次）";
        }
        // 下一次请求必然是「已错过 1 次」的状态，所以这里就要备好新码
        $needCaptcha = $captchaOn;
        if ($needCaptcha) {
            [$capToken] = hp_captcha_new();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="<?= h(hp_skin_safe(setting('theme', 'atlas'))) ?>" data-accent="<?= h(setting('accent', 'amber')) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="dark light">
<meta name="robots" content="noindex, nofollow">
<title>登录 · <?= h(setting('site_title', 'Vnav')) ?></title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='8' fill='%23F0B429'/><path d='M9 16.5l4.5 4.5L23 11' stroke='%23241500' stroke-width='3.2' fill='none' stroke-linecap='round' stroke-linejoin='round'/></svg>">
<link rel="preload" href="assets/fonts/bricolage.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="assets/fonts/instrument.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="assets/app.css?v=43">
<link rel="stylesheet" href="assets/skin-atlas.css?v=51">
<link rel="stylesheet" href="assets/skin-frame.css?v=51">
<link rel="stylesheet" href="assets/auth.css?v=39">
<script>
/* 抢先应用已保存的皮肤，避免登录页闪一下默认皮肤。
   白名单由 PHP 注入，新增皮肤不必回来改这段。 */
(function () {
  var keys = <?= json_encode(hp_skin_keys(), JSON_UNESCAPED_UNICODE) ?>;
  try {
    var t = localStorage.getItem('vnav.theme');
    if (t && keys.indexOf(t) !== -1) {
      document.documentElement.dataset.theme = t;
    }
  } catch (e) {}
})();
</script>
</head>
<body class="auth-body">

<div class="aurora" aria-hidden="true"></div>
<div class="grain" aria-hidden="true"></div>

<main class="auth-wrap">
  <div class="auth-aside">
    <p class="auth-kicker">Vnav</p>
    <h1 class="auth-big">回你的<br><em>主页</em>。</h1>
    <p class="auth-note">登录后你的分组、链接和外观会存到账号里，换一台设备打开还是同一份。</p>
  </div>

  <form class="auth-card" method="post" novalidate>
    <?php /* 登录与注册共用一张表单，靠这个隐藏字段区分 ——
             只看 URL 的话，POST 回来参数就没了。 */ ?>
    <input type="hidden" name="mode" value="<?= h($mode) ?>">
    <h2 class="auth-title"><?= $mode === 'register' ? '创建账号' : '登录' ?></h2>

    <?php if ($err !== ''): ?>
      <p class="auth-alert" role="alert"><?= h($err) ?></p>
    <?php endif; ?>

    <label class="f-label" for="user">账号</label>
    <input class="f-input" id="user" name="user" type="text" autocomplete="username"
           value="<?= h((string)($_POST['user'] ?? '')) ?>" required <?= $needCaptcha ? '' : 'autofocus' ?>>

    <label class="f-label" for="pass"><?= $mode === 'register' ? '密码（至少 8 位）' : '密码' ?></label>
    <div class="pw-wrap">
      <input class="f-input" id="pass" name="pass" type="password"
             autocomplete="<?= $mode === 'register' ? 'new-password' : 'current-password' ?>"
             required <?= $needCaptcha ? 'autofocus' : '' ?>>
      <button type="button" class="pw-toggle" id="pwToggle" aria-label="显示密码">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
      </button>
    </div>

    <?php if ($mode === 'register'): ?>
      <label class="f-label" for="pass2">确认密码</label>
      <input class="f-input" id="pass2" name="pass2" type="password" autocomplete="new-password" required>
    <?php endif; ?>

    <?php if ($needCaptcha && $capToken !== ''): ?>
      <?php /* 验证码在第 1 次失败后才出现。默认登录不打扰 ——
               天天登录的人不该每次都被要求认四个歪字。 */ ?>
      <div class="cap-row">
        <div class="cap-field">
          <label class="f-label" for="captcha">验证码</label>
          <input class="f-input" id="captcha" name="captcha" type="text" inputmode="latin"
                 autocomplete="off" autocapitalize="characters" spellcheck="false"
                 maxlength="6" placeholder="四位字符" required>
        </div>
        <input type="hidden" name="captcha_token" id="capToken" value="<?= h($capToken) ?>">
        <button type="button" class="cap-img" id="capRefresh" title="看不清？换一张" aria-label="换一张验证码">
          <img id="capImg" src="login.php?captcha=<?= h($capToken) ?>" alt="验证码图片" width="132" height="46">
        </button>
      </div>
      <p class="cap-hint">密码输错一次后需要验证码。连续错 5 次将锁定 15 分钟。</p>
    <?php endif; ?>

    <button class="btn-primary auth-submit" type="submit"<?= $wait > 0 ? ' disabled' : '' ?>>
      <?= $wait > 0 ? '已锁定' : ($mode === 'register' ? '注册并登录' : '登录') ?>
    </button>

    <p class="auth-switch">
      <?php if ($mode === 'register'): ?>
        已经有账号了？<a href="login.php">去登录</a>
      <?php elseif (setting('allow_register', '1') === '1'): ?>
        还没有账号？<a href="login.php?mode=register">注册一个</a>
      <?php endif; ?>
    </p>
    <a class="auth-back" href="./">← 返回主页</a>
  </form>
</main>

<script>
  const pw = document.getElementById('pass');
  const tg = document.getElementById('pwToggle');
  tg?.addEventListener('click', () => {
    const show = pw.type === 'password';
    pw.type = show ? 'text' : 'password';
    tg.setAttribute('aria-label', show ? '隐藏密码' : '显示密码');
    pw.focus();
  });

  /* 换一张验证码：不能只改 src 的 hash —— token 必须换成新的，
     否则服务端还认旧答案。所以换图要走一次「拿新 token」的请求。 */
  const capRefresh = document.getElementById('capRefresh');
  const capImg = document.getElementById('capImg');
  const capToken = document.getElementById('capToken');

  async function refreshCaptcha() {
    if (!capImg || !capToken) return;
    capRefresh.disabled = true;
    capImg.style.opacity = '.35';
    try {
      // 用一个已消费的假 token 请求：服务端拿不到答案会给新码，
      // 但前端并不知道新 token。所以这里直接重新加载登录页的
      // captcha 端点不现实 —— 改用 JSON 接口拿 token。
      const r = await fetch('api.php?action=captcha_new', { credentials: 'same-origin' });
      const d = await r.json();
      if (d.ok && d.token) {
        capToken.value = d.token;
        capImg.src = 'login.php?captcha=' + encodeURIComponent(d.token) + '&t=' + Date.now();
        const inp = document.getElementById('captcha');
        if (inp) { inp.value = ''; inp.focus(); }
      }
    } catch (e) {
      // 拿不到就退回「重新加载页面」，用户至少还有路可走
      location.reload();
    } finally {
      capRefresh.disabled = false;
      capImg.style.opacity = '1';
    }
  }
  capRefresh?.addEventListener('click', refreshCaptcha);

  // 验证码输入框：回车直接提交，不用去够按钮
  document.getElementById('captcha')?.addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); e.target.form.requestSubmit(); }
  });
</script>
</body>
</html>