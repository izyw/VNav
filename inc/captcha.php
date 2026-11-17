<?php
/**
 * 登录验证码 —— 纯 PHP + GD 自绘，无第三方依赖、无外部请求。
 *
 * 为什么不用现成的开源库（securimage / captcha composer 包）：
 *   1. 它们绝大多数依赖 session 文件 + 额外字体文件，且默认输出 PNG，
 *      在本站「零外呼、单文件部署」的前提里都是负担；
 *   2. 引入一个包意味着用户部署时还要 composer install —— 与
 *      「解压即用」冲突；
 *   3. 逻辑本身只有几十行，自己写反而更容易审计。
 *   这里借用的是同类开源库通行的做法（扭曲字形 + 干扰线 + 噪点 +
 *   一次性 token + 时效），实现部分是自写的。
 *
 * 存储：SQLite 表 captcha（不写 session）。
 *   原因：验证码必须能跨请求校验，而本站游客态刻意不开 session。
 *   落库 + 一次性消费 + 过期清理，比往 session 里塞更干净，
 *   也不会因为 session 被清掉而让用户看到「验证码已失效」。
 */
declare(strict_types=1);
if (!defined('HP_ROOT')) { http_response_code(404); exit; } // 入口守卫：与 .htaccess 的 404 保持一致，直接访问不暴露路径存在

/** 验证码存活秒数。够慢手输完，又不至于挂着不用。 */
const HP_CAPTCHA_TTL = 300;

/** 字符集：去掉 0/O、1/I/l 这些容易认错的，减少「明明输对了却不通过」 */
const HP_CAPTCHA_CHARS = 'ABCDEFGHJKLMNPQRSTUVWXY3456789';

/**
 * GD 是否可用。
 *
 * 很多发行版 / 容器镜像默认不带 gd（1Panel 的 1panel-php-fpm 就是其中之一，
 * 它的 PHP_EXTENSIONS 里写着 gd，实际镜像里并没有这个 so）。
 * 没有 GD 时验证码画不出来，调用方必须据此整体降级成「不要求验证码」，
 * 否则用户会卡在「图片是个白框、输什么都不对」的死循环里。
 */
function hp_captcha_available(): bool
{
    return function_exists('imagecreatetruecolor') && function_exists('imagepng');
}

/** 无 GD 时输出这张 1×1 透明 PNG，避免前端出现裂图。 */
const HP_CAPTCHA_BLANK_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAC0lEQVQImWNgAAIAAAUAAWJVMogAAAAASUVORK5CYII=';


/** 生成一张新验证码，返回 [token, 明文]（明文只用来画图，不回传前端） */
function hp_captcha_new(): array
{
    hp_captcha_gc();

    $len = 4;
    $plain = '';
    $max = strlen(HP_CAPTCHA_CHARS) - 1;
    for ($i = 0; $i < $len; $i++) {
        $plain .= HP_CAPTCHA_CHARS[random_int(0, $max)];
    }

    $token = bin2hex(random_bytes(16));
    $st = db()->prepare('INSERT INTO captcha(token, code, at) VALUES(?, ?, ?)');
    $st->execute([$token, $plain, time()]);

    return [$token, $plain];
}

/**
 * 校验并消费。token 一次性：无论对错，用过即删。
 * 这样即使被人反复猜，每题也只能猜一次。
 */
function hp_captcha_check(string $token, string $input): bool
{
    $token = preg_replace('~[^a-f0-9]~', '', $token) ?? '';
    if ($token === '') {
        return false;
    }
    $st = db()->prepare('SELECT code, at FROM captcha WHERE token = ?');
    $st->execute([$token]);
    $row = $st->fetch();

    // 无论结果如何都删掉这一个 token
    db()->prepare('DELETE FROM captcha WHERE token = ?')->execute([$token]);

    if (!$row) {
        return false;
    }
    if (time() - (int)$row['at'] > HP_CAPTCHA_TTL) {
        return false;
    }
    $input = strtoupper(preg_replace('~[^A-Za-z0-9]~', '', $input) ?? '');
    return hash_equals((string)$row['code'], $input);
}

/** 清掉过期 token。每次生成新码顺手跑一次，不需要定时任务。 */
function hp_captcha_gc(): void
{
    db()->prepare('DELETE FROM captcha WHERE at < ?')
        ->execute([time() - HP_CAPTCHA_TTL * 2]);
}

/**
 * 画出验证码 PNG 并直接输出。
 * 视觉上刻意做得「有点难但看得清」：字符各自旋转、基线抖动、
 * 两道浅弧线干扰、稀疏噪点。不做过度的波浪扭曲 ——
 * 那会让正常人也要试两次，得不偿失。
 */
function hp_captcha_render(string $code): never
{
    $w = 132;
    $h = 46;

    if (!headers_sent()) {
        header('Content-Type: image/png');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
    }

    /* GD 缺失时输出一张 1×1 透明 PNG，绝不 500 —— 少一个功能不该让登录页崩。
       注意这里**不能**再调 imagecreate / imagestring：那些同样是 GD 函数，
       没装 gd 的时候调它们照样致命，「兜底的兜底」等于没有兜底。
       真正的降级发生在 login.php：hp_captcha_available() 为 false 时
       直接不要求验证码，前端也不会渲染这个 img。 */
    if (!hp_captcha_available()) {
        echo base64_decode(HP_CAPTCHA_BLANK_PNG);
        exit;
    }

    $im = imagecreatetruecolor($w, $h);

    // 底色：和站点皮肤同调的深紫灰，不是纯白 —— 纯白在深色登录页上太刺眼
    $bg = imagecolorallocate($im, 26, 23, 36);
    imagefilledrectangle($im, 0, 0, $w, $h, $bg);

    // 背景细斜纹，让二值化识别更难，又不干扰人眼
    for ($i = 0; $i < 26; $i++) {
        $c = imagecolorallocate($im, random_int(38, 56), random_int(34, 50), random_int(52, 74));
        $x = random_int(-20, $w);
        imagefilledpolygon($im, [$x, 0, $x + 12, 0, $x - 8, $h, $x - 20, $h], $c);
    }

    $ink = [
        [240, 180, 41],   // 琥珀
        [124, 211, 173],  // 薄荷
        [140, 170, 240],  // 天青
        [236, 150, 160],  // 珊瑚
    ];

    $n = strlen($code);
    /* 均匀切片。132 宽、4 个字 → 每格 26px，字模放大 1.9 倍后约 17px 宽，
       左右各留 4px 余量，旋转到 ±22° 也压不到隔壁。
       注意 $cx/$cy 是**字符中心**，不是左上角 —— hp_captcha_draw_char
       以中心为原点做旋转，传边角会导致字整体偏出去。 */
    $padL = 13;
    $slot = (int)(($w - $padL * 2) / $n);
    $cy   = (int)($h / 2);

    for ($i = 0; $i < $n; $i++) {
        $rgb = $ink[$i % count($ink)];
        $col = imagecolorallocate($im, $rgb[0], $rgb[1], $rgb[2]);

        /* 字号随位置轻微起伏，且每个字符独立旋转。
           用 GD 内置字体（imagestring）会被 OCR 轻易切分，
           所以这里把内置字模放大再旋转 —— 不用 imagettftext，
           是为了不依赖任何 ttf 文件，部署时不用额外传字体。 */
        $cx = $padL + (int)($slot * $i) + (int)($slot / 2);
        hp_captcha_draw_char($im, $code[$i], $cx, $cy + random_int(-3, 3), -22 + random_int(0, 44), $col, $w, $h);
    }

    // 两道浅弧干扰线（不穿过字符主体，压在上下边缘）
    for ($k = 0; $k < 2; $k++) {
        $c = imagecolorallocate($im, random_int(70, 100), random_int(64, 92), random_int(90, 120));
        $y0 = $k === 0 ? random_int(6, 14) : random_int($h - 14, $h - 6);
        $pts = [];
        for ($x = 0; $x <= $w; $x += 6) {
            $pts[] = $x;
            $pts[] = (int)($y0 + sin($x / 18 + $k * 2) * 5);
        }
        for ($j = 0; $j + 3 < count($pts); $j += 2) {
            imageline($im, $pts[$j], $pts[$j + 1], $pts[$j + 2], $pts[$j + 3], $c);
        }
    }

    // 稀疏噪点
    for ($i = 0; $i < 90; $i++) {
        $c = imagecolorallocate($im, random_int(60, 110), random_int(55, 100), random_int(80, 130));
        imagesetpixel($im, random_int(0, $w - 1), random_int(0, $h - 1), $c);
    }

    // 1px 描边，和登录卡片的边框呼应
    $line = imagecolorallocate($im, 62, 54, 82);
    imagerectangle($im, 0, 0, $w - 1, $h - 1, $line);

    imagepng($im);
    exit;
}

/**
 * 「已过期」占位图。token 失效时输出它，而不是偷偷换一张新码 ——
 * 偷偷换码会让图和库里的答案错位，用户会陷入「明明输对了却不通过」
 * 的死循环。让前端明确知道要换 token 才是对的。
 */
function hp_captcha_render_expired(): never
{
    if (!headers_sent()) {
        header('Content-Type: image/png');
        header('Cache-Control: no-store');
    }
    $w = 132; $h = 46;
    if (!hp_captcha_available()) {
        echo base64_decode(HP_CAPTCHA_BLANK_PNG);
        exit;
    }
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 26, 23, 36));
    $c = imagecolorallocate($im, 150, 140, 165);
    imagestring($im, 3, 24, 17, 'click to renew', $c);
    imagerectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocate($im, 62, 54, 82));
    imagepng($im);
    exit;
}

/**
 * 用 GD 内置字模画一个「放大 + 旋转 + 抗锯齿」的字符。
 *
 * 做法：先在小画布上用 imagechar 画 1 倍字模（5×8 像素块），
 * 取出每个像素点，按旋转矩阵映射到大图上，每个源像素涂成一个
 * 2×2 的方块（等于 2 倍放大 + 一点点抗锯齿感）。
 * 好处：完全不依赖 ttf 字体文件，部署时不用额外上传字体。
 */
function hp_captcha_draw_char($im, string $ch, int $cx, int $cy, int $angleDeg, int $color, int $W, int $H): void
{
    // 1 倍字模画到临时画布
    $gw = 10;
    $gh = 16;
    $g = imagecreatetruecolor($gw, $gh);
    $black = imagecolorallocate($g, 0, 0, 0);
    $white = imagecolorallocate($g, 255, 255, 255);
    imagefilledrectangle($g, 0, 0, $gw, $gh, $black);
    imagestring($g, 5, 1, 1, $ch, $white); // font 5 = 内置最大字模 9×15

    $rad = deg2rad($angleDeg);
    $cos = cos($rad);
    $sin = sin($rad);
    $scale = 1.9;

    for ($y = 0; $y < $gh; $y++) {
        for ($x = 0; $x < $gw; $x++) {
            $rgb = imagecolorat($g, $x, $y);
            // 只取亮的像素（字模是白字黑底）
            if ((($rgb >> 16) & 0xFF) < 128) {
                continue;
            }
            // 以字符中心为原点做旋转
            $ox = ($x - $gw / 2) * $scale;
            $oy = ($y - $gh / 2) * $scale;
            $tx = $cx + $ox * $cos - $oy * $sin;
            $ty = $cy + $ox * $sin + $oy * $cos;

            // 2×2 的点块，边缘坐标取整避免出现裂缝
            $x0 = (int)round($tx);
            $y0 = (int)round($ty);
            imagefilledrectangle($im, $x0, $y0, $x0 + 1, $y0 + 1, $color);
        }
    }
    imagedestroy($g);
}