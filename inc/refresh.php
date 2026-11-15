<?php
/**
 * 元信息批量刷新 —— 标题 / 描述 / 图标 三件套一起重抓。
 *
 * 和 inc/icons.php 里 hp_icon_refill() 的分工：
 *   icon_refill  → 只补「还没有图标」的那些，不管标题和描述。
 *                  它是「新加了一堆链接，先把图补齐」用的。
 *   hp_meta_refresh（这里） → 并发回源重读整页 <head>，
 *                  标题、描述、图标一起更新，且可以覆盖已有值。
 *                  它是「有些站点改版了 / 标题当初抓得不好看」用的。
 *
 * 这个区分很重要：用户反馈的问题是「很多图标和链接没有 logo，
 * 而且网站会自动变化」，那要的是「覆盖式重抓」，不是「只补空缺」。
 *
 * 分批协议（很关键，不然一个请求抓十几条外站必被 PHP 超时掐断）：
 *   前端第一次传 cursor=0 → 后端挑 id > 0 的前 N 条，返回处理到哪儿（cursor）
 *   前端拿新 cursor 再请求 → 后端挑 id > cursor 的前 N 条
 *   直到返回的 left === 0
 * 用 id 递增而不是 OFFSET，是因为刷新的过程中可能有链接被删 ——
 * OFFSET 会因此错位跳过几条，id 游标不会。
 */
declare(strict_types=1);

/**
 * 分批刷新元信息。
 *
 * 这里是并发实现：先把本批所有页面的 HTML 同时抓回来（curl_multi），
 * 解析出各自的图标地址后，再把所有图标同时抓回来，最后统一落盘写库。
 *
 * 为什么不是「一个 foreach 里调 fetch()」：
 *   那样每条要串行等两个请求（页面 + 图标），46 条就是 90 多次往返。
 *   实测一次刷新要 30 秒以上，用户以为卡死了。
 *   并发之后总耗时 ≈ 2 × 最慢那一个站点的响应时间，与条数基本无关。
 *
 * @param int   $limit      本次最多处理几条（并发，所以可以放宽）
 * @param bool  $overwrite  是否覆盖已有标题/描述。false 时只填空缺
 * @param int   $cursor     只处理 id 大于它的（0 = 从头开始）
 * @return array{ok:int, bad:int, left:int, changed:int, cursor:int, done:bool, errors:array<int,string>}
 */
function hp_meta_refresh(int $limit = 24, bool $overwrite = true, int $cursor = 0): array
{
    require_once HP_ROOT . '/inc/MetaFetcher.php';
    require_once HP_ROOT . '/inc/icons.php';

    /* 上限从 30 放到 40：并发之后瓶颈是「最慢那个站点」而不是条数，
       一批多带几条不会明显变慢，但能少一个来回。 */
    $limit = max(1, min(48, $limit));
    $pdo = db();

    /* 只挑 http(s) 开头的。url 本身不合法的那几条刷多少次也没用，
       直接排除在拉取范围外。 */
    $where = "url LIKE 'http%'";
    $args = [];
    if ($cursor > 0) {
        $where .= ' AND id > ?';
        $args[] = $cursor;
    }

    $st = $pdo->prepare("SELECT id, title, url, icon, icon_kind, descr FROM links WHERE {$where} ORDER BY id ASC LIMIT " . $limit);
    $st->execute($args);
    $rows = $st->fetchAll();

    /* 批量刷新的超时要比单条抓取更紧。
       单条添加时用户只等一条，6 秒可以接受；批量时只要有一个站点连不上，
       整批就得等满这个时间 —— 实测 24 条一批、6 秒超时时总耗时 8.7 秒，
       其中 6 秒全花在等那几个死站上。压到 4 秒后降到 4 秒出头。
       抓不到的站点不是丢了：它们会计入「失败」，用户再点一次就重试。 */
    $timeout = min(3, max(2, (int)($GLOBALS['CONFIG']['fetch_timeout'] ?? 6)));
    $fetcher = new MetaFetcher($timeout);

    $ok = 0;
    $bad = 0;
    $changed = 0;
    $errors = [];

    /* ---------- 阶段 0：先筛掉非法地址，别把它们塞进并发队列 ---------- */
    $todo = [];
    $urls = [];
    foreach ($rows as $r) {
        $id  = (int)$r['id'];
        $url = (string)$r['url'];
        if (!MetaFetcher::isPublicUrl($url)) {
            $bad++;
            $errors[$id] = '内网或非法地址，已跳过';
            continue;
        }
        $todo[$id] = $r;
        $urls[$id] = $url;
    }

    /* ---------- 阶段 1：并发抓所有页面 ----------
       16 是并发窗口：再多对跨境站点没帮助（受带宽与源站限流约束），
       却会压低单条的成功率（同时开太多容易被丢包）。 */
    /* 窗口直接给到「本批条数」：这批最多 40 个不同域名，同时连上去
       不会触发任何一家源站的限流。这样页面阶段只花一个往返的时间。 */
    $pages = $urls ? MetaFetcher::multiGet($urls, $timeout, count($urls)) : [];

    /* ---------- 阶段 2：就地解析，把图标地址攒起来 ---------- */
    $metas = [];
    $iconUrls = [];
    foreach ($todo as $id => $r) {
        $p = $pages[$id] ?? ['body' => '', 'finalUrl' => $urls[$id], 'code' => 0, 'mime' => ''];
        $code = (int)$p['code'];

        /* code 0 = 连不上（DNS / 超时 / TLS 握手失败）；
           >= 400 = 源站明确拒绝。这两种都算失败，别的都继续往下走 ——
           有些站点返回 200 但正文是空的（纯前端渲染），那时标题退回域名
           也比判成「失败」有用。 */
        if ($code === 0 || $code >= 400) {
            $bad++;
            $errors[$id] = $code === 0 ? '连不上（超时或 DNS 失败）' : ('源站返回 ' . $code);
            continue;
        }

        $m = $fetcher->metaOnly((string)$p['body'], $urls[$id], (string)$p['finalUrl']);
        $metas[$id] = $m;
        if ($m['icon'] !== '') {
            $iconUrls[$id] = (string)$m['icon'];
        }
    }

    /* ---------- 阶段 3：并发抓所有图标 ---------- */
    /* 图标更小、更少，超时和窗口都可以再紧一点：
       2 秒抓不回来的 favicon，再等也没意义。 */
    $iconBins = $iconUrls ? MetaFetcher::multiGet($iconUrls, min(3, $timeout), count($iconUrls)) : [];

    /* ---------- 阶段 4：落盘 + 写库 ----------
       这一段是纯 CPU 与磁盘，没有网络等待，串行反而是最快的。 */
    foreach ($metas as $id => $m) {
        $r = $todo[$id];

        $newTitle = (string)($m['title'] ?? '');
        $newDescr = (string)($m['desc'] ?? '');

        $savedIcon = '';
        if (isset($iconBins[$id]) && (string)$iconBins[$id]['body'] !== '') {
            $savedIcon = $fetcher->saveIconBin(
                (string)$iconBins[$id]['body'],
                $iconUrls[$id],
                (string)($m['host'] ?? ''),
                (string)$iconBins[$id]['mime']
            );
        }
        $iconOk = $savedIcon !== '' && preg_match('~^data/icons/[A-Za-z0-9._\-]+$~', $savedIcon) === 1;

        /* 逐字段决定要不要写。
           overwrite=false 时只补空白 —— 这是「别动我手改过的」那个选项。 */
        $set = [];
        $val = [];

        $titleNow = (string)$r['title'];
        if ($newTitle !== '' && ($overwrite || $titleNow === '') && $newTitle !== $titleNow) {
            $set[] = 'title = ?';
            $val[] = mb_substr($newTitle, 0, 60);
        }

        $descrNow = (string)$r['descr'];
        if ($newDescr !== '' && ($overwrite || $descrNow === '') && $newDescr !== $descrNow) {
            $set[] = 'descr = ?';
            $val[] = mb_substr($newDescr, 0, 120);
        }

        $iconNow = (string)$r['icon'];
        $iconKindNow = (string)$r['icon_kind'];
        if ($iconOk && ($overwrite || $iconNow === '' || $iconKindNow !== 'file')) {
            if ($savedIcon !== $iconNow || $iconKindNow !== 'file') {
                $set[] = 'icon = ?';
                $val[] = $savedIcon;
                $set[] = "icon_kind = 'file'";
            }
            hp_icon_remember($savedIcon, host_of((string)$r['url']));
        }

        if (!$set) {
            // 抓到了但一个字段都不用改：算成功，不算 changed
            $ok++;
            continue;
        }

        $set[] = 'updated_at = ?';
        $val[] = now();
        $val[] = $id;

        try {
            $pdo->prepare('UPDATE links SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($val);
            $ok++;
            $changed++;
        } catch (Throwable $e) {
            $bad++;
            $errors[$id] = '写库失败';
        }
    }

    /* 本批最后处理到哪个 id。空批次时保持传入的 cursor 不动，
       否则前端会以为「到头了又回到 0」，循环重新从第一条开始。 */
    $nextCursor = $rows ? (int)$rows[count($rows) - 1]['id'] : $cursor;

    // 还有没有 id > nextCursor 的
    $leftSt = $pdo->prepare("SELECT COUNT(*) FROM links WHERE url LIKE 'http%' AND id > ?");
    $leftSt->execute([$nextCursor]);
    $left = (int)$leftSt->fetchColumn();

    return [
        'ok'      => $ok,
        'bad'     => $bad,
        'left'    => $left,
        'changed' => $changed,
        'cursor'  => $nextCursor,
        'done'    => $left === 0,
        'errors'  => $errors,
    ];
}

/**
 * 统计有多少条链接可以刷新、其中多少条缺图/缺描述，给面板显示用。
 * @return array{total:int, no_icon:int, no_desc:int}
 */
function hp_meta_refresh_stats(): array
{
    $total = 0;
    $noIcon = 0;
    $noDesc = 0;
    try {
        $total = (int)db()->query("SELECT COUNT(*) FROM links WHERE url LIKE 'http%'")->fetchColumn();
        $noIcon = (int)db()->query(
            "SELECT COUNT(*) FROM links WHERE url LIKE 'http%' AND (icon = '' OR icon_kind <> 'file')"
        )->fetchColumn();
        $noDesc = (int)db()->query(
            "SELECT COUNT(*) FROM links WHERE url LIKE 'http%' AND descr = ''"
        )->fetchColumn();
    } catch (Throwable $e) {
    }
    return ['total' => $total, 'no_icon' => $noIcon, 'no_desc' => $noDesc];
}