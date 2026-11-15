<?php
/**
 * 出包 + 发布到 GitHub Release。
 *
 * 用法：
 *   php release.php                 打包当前 VERSION 并发布正式版
 *   php release.php --draft         只建草稿，回网页确认后再点「发布」
 *   php release.php --dry-run       只打包、只打印要发的内容，不联网
 *   php release.php --repo=a/b      覆盖默认仓库
 *   php release.php --notes=FILE.md 用文件内容代替自动生成的发布说明
 *
 * Token 读取顺序：
 *   1. 环境变量 GITHUB_TOKEN
 *   2. 项目根目录的 .release-token（已在 .gitignore 里，不会进仓库）
 *
 * Token 权限：classic PAT 要 repo；fine-grained 要该仓库的 Contents: Read and write。
 *
 * 发布说明默认从 inc/changelog.php 里取当前 VERSION 对应的那一条 ——
 * 也就是后台「更新日志」页展示的同一份内容，不需要另写一份。
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "只能在命令行运行\n");
    exit(1);
}

$root  = __DIR__;
$owner = 'izyw';
$repo  = 'vnav';
$draft = false;
$dryRun = false;
$notesFile = '';

foreach ($argv as $a) {
    if ($a === '--draft')          $draft = true;
    elseif ($a === '--dry-run')    $dryRun = true;
    elseif (str_starts_with($a, '--repo=')) {
        $p = explode('/', substr($a, 7), 2);
        if (count($p) === 2 && $p[0] !== '' && $p[1] !== '') {
            $owner = $p[0];
            $repo  = $p[1];
        }
    } elseif (str_starts_with($a, '--notes=')) {
        $notesFile = substr($a, 8);
    }
}

$ver = trim((string)@file_get_contents($root . '/VERSION'));
if ($ver === '') {
    fwrite(STDERR, "读不到 VERSION\n");
    exit(1);
}
$tag = 'v' . ltrim($ver, 'vV');

/* ---------- 1. 打包 ---------- */
echo "== 打包 ==\n";
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/build.php');
passthru($cmd, $rc);
if ($rc !== 0) {
    fwrite(STDERR, "打包失败，退出码 $rc\n");
    exit($rc);
}

$zipPath = dirname($root) . '/vnav-' . $ver . '.zip';
if (!is_file($zipPath)) {
    fwrite(STDERR, "找不到产物：$zipPath\n");
    exit(1);
}
$zipSize = (int)filesize($zipPath);

/* ---------- 2. 生成发布说明 ---------- */
$notes = '';
if ($notesFile !== '') {
    $notes = (string)@file_get_contents($notesFile);
    if ($notes === '') {
        fwrite(STDERR, "读不到发布说明文件：$notesFile\n");
        exit(1);
    }
} else {
    require $root . '/inc/changelog.php';
    $entry = null;
    foreach (hp_changelog() as $e) {
        if (strcasecmp((string)$e['ver'], $ver) === 0) {
            $entry = $e;
            break;
        }
    }
    if ($entry === null) {
        $notes = "Vnav $ver\n\n详细改动见 docs/开发记录.md。\n";
    } else {
        $notes = '**' . $entry['lead'] . "**\n\n";
        foreach ($entry['sections'] as $sec) {
            $notes .= '### ' . $sec['title'] . "\n\n";
            foreach ($sec['items'] as $it) {
                $notes .= '- ' . $it . "\n";
            }
            $notes .= "\n";
        }
        $notes .= "---\n\n";
    }
    $notes .= "**安装 / 升级 / 恢复都是下面这一个 zip。**\n"
        . "新装解压后打开 `install.php`；升级在后台「升级」页直接上传。\n"
        . "包里不含 `inc/config.php` 与 `data/`，覆盖不会丢数据。\n";
}

/* ---------- 3. 预演或发布 ---------- */
$apiBase = 'https://api.github.com';
$releaseName = "Vnav $ver";

if ($dryRun) {
    echo "\n== 预演（未联网）==\n";
    echo "  仓库：$owner/$repo\n";
    echo "  标签：$tag\n";
    echo "  标题：$releaseName\n";
    echo "  产物：{$zipPath}（" . round($zipSize / 1024, 1) . " KB）\n";
    echo "  草稿：" . ($draft ? '是' : '否') . "\n";
    echo "\n---- 发布说明 ----\n$notes\n";
    exit(0);
}

$token = getenv('GITHUB_TOKEN') ?: '';
if ($token === '') {
    $tf = $root . '/.release-token';
    if (is_file($tf)) {
        $token = trim((string)file_get_contents($tf));
    }
}
if ($token === '') {
    fwrite(STDERR, "缺少 token：设置环境变量 GITHUB_TOKEN，或把 token 写到 $root/.release-token\n");
    exit(1);
}

/** 统一的 curl 调用，返回 [状态码, 解析后的 body 或原始 body] */
function gh_request(string $method, string $url, string $token, ?array $json = null, ?string $uploadFile = null): array
{
    $ch = curl_init($url);
    $headers = [
        'Authorization: Bearer ' . $token,
        'Accept: application/vnd.github+json',
        'X-GitHub-Api-Version: 2022-11-28',
        'User-Agent: vnav-release',
    ];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 120,
    ]);
    if ($json !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_UNICODE));
    } elseif ($uploadFile !== null) {
        $headers[] = 'Content-Type: application/zip';
        curl_setopt($ch, CURLOPT_POSTFIELDS, (string)file_get_contents($uploadFile));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return [0, ['message' => $err]];
    }
    $parsed = json_decode((string)$body, true);
    return [$code, $parsed ?? $body];
}

echo "\n== 建 Release ==\n";
[$code, $res] = gh_request('POST', "$apiBase/repos/$owner/$repo/releases", $token, [
    'tag_name'         => $tag,
    'target_commitish' => 'main',
    'name'             => $releaseName,
    'body'             => $notes,
    'draft'            => $draft,
    'prerelease'       => false,
]);

if ($code === 422) {
    $msg = is_array($res) ? (string)($res['message'] ?? '') : '';
    fwrite(STDERR, "创建失败（422）：$msg\n");
    fwrite(STDERR, "多半是标签 $tag 已存在。先删掉旧 Release，或去网页手动处理。\n");
    exit(1);
}
if ($code < 200 || $code >= 300) {
    $msg = is_array($res) ? (string)($res['message'] ?? json_encode($res, JSON_UNESCAPED_UNICODE)) : (string)$res;
    fwrite(STDERR, "创建失败（$code）：$msg\n");
    exit(1);
}

$releaseId  = (int)($res['id'] ?? 0);
$uploadUrl  = (string)($res['upload_url'] ?? '');
$htmlUrl    = (string)($res['html_url'] ?? '');
if ($releaseId === 0 || $uploadUrl === '') {
    fwrite(STDERR, "响应里没有 release id 或 upload_url，无法继续\n");
    exit(1);
}
$uploadUrl = preg_replace('~\{.*\}$~', '', $uploadUrl);

echo "== 上传产物 ==\n";
$assetName = basename($zipPath);
[$code2, $res2] = gh_request(
    'POST',
    $uploadUrl . '?name=' . rawurlencode($assetName),
    $token,
    null,
    $zipPath
);

if ($code2 < 200 || $code2 >= 300) {
    $msg2 = is_array($res2) ? (string)($res2['message'] ?? json_encode($res2, JSON_UNESCAPED_UNICODE)) : (string)$res2;
    fwrite(STDERR, "上传失败（$code2）：$msg2\n");
    fwrite(STDERR, "Release 已创建但缺少产物，去网页补传：$htmlUrl\n");
    exit(1);
}

$assets = is_array($res2) ? ($res2['browser_download_url'] ?? '') : '';

echo "\n完成\n";
echo "  Release：$htmlUrl\n";
if ($assets) {
    echo "  下载：$assets\n";
}
echo "  体积：" . round($zipSize / 1024, 1) . " KB\n";
if ($draft) {
    echo "  状态：草稿（去网页点「Publish release」才对外可见）\n";
}
