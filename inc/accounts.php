<?php
/**
 * 账号 —— 注册、默认数据复制、权限查询。
 *
 * 角色只有两个：
 *   admin  站点所有者。能进后台、改系统级设置、看统计与访问控制。
 *   user   普通账号。只能用（增删改自己的链接与分组、改自己的外观偏好）。
 *
 * 「只能用」这条必须由**服务端**落实：前端把后台入口藏起来只是体验，
 * 绕过入口直接请求 api.php 一样能改。所以每个写接口都要自己判归属。
 */
declare(strict_types=1);

/** 用户名规则：3~24 位，字母 / 数字 / 下划线 / 中文 */
function hp_username_valid(string $u): bool
{
    return (bool)preg_match('/^[A-Za-z0-9_\x{4e00}-\x{9fa5}]{3,24}$/u', $u);
}

/** 用户名是否已被占用（大小写不敏感，避免 Admin / admin 并存） */
function hp_username_taken(string $u): bool
{
    $st = db()->prepare('SELECT 1 FROM users WHERE lower(username) = lower(?) LIMIT 1');
    $st->execute([$u]);
    return (bool)$st->fetchColumn();
}

/**
 * 把站点默认数据（user_id = 0）复制一份给新用户。
 *
 * 为什么复制而不是让新账号从空白开始：空白首页没有任何参照，
 * 用户不知道该建什么分组。复制一份之后，看到的是「一个能用的主页」，
 * 改与不改都行 —— 而且因为复制到了自己名下，怎么改都不影响别人。
 *
 * @return array{groups:int, links:int}
 */
function hp_clone_default_data(PDO $pdo, int $uid): array
{
    if ($uid <= 0) {
        return ['groups' => 0, 'links' => 0];
    }

    $gCount = 0;
    $lCount = 0;

    // 组：旧 id → 新 id，链接要靠它重新挂靠
    $map = [];
    $gs = $pdo->query('SELECT id, name, sort, icon FROM groups WHERE user_id = 0 ORDER BY sort ASC, id ASC')->fetchAll();
    $insG = $pdo->prepare('INSERT INTO groups(name, sort, icon, user_id, created_at) VALUES(?, ?, ?, ?, ?)');
    foreach ($gs as $g) {
        $insG->execute([$g['name'], (int)$g['sort'], (string)($g['icon'] ?? ''), $uid, now()]);
        $map[(int)$g['id']] = (int)$pdo->lastInsertId();
        $gCount++;
    }

    $ls = $pdo->query('SELECT group_id, title, url, icon, icon_kind, descr, sort, hidden FROM links WHERE user_id = 0')->fetchAll();
    $insL = $pdo->prepare(
        'INSERT INTO links(user_id, group_id, title, url, icon, icon_kind, descr, sort, hidden, created_at, updated_at)
         VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($ls as $l) {
        $oldG = (int)($l['group_id'] ?? 0);
        $newG = $map[$oldG] ?? 0;
        try {
            $insL->execute([
                $uid,
                $newG > 0 ? $newG : null,
                (string)$l['title'],
                (string)$l['url'],
                (string)$l['icon'],
                (string)$l['icon_kind'],
                (string)$l['descr'],
                (int)$l['sort'],
                (int)($l['hidden'] ?? 0),
                now(),
                now(),
            ]);
            $lCount++;
        } catch (Throwable $e) {
            /* 单条失败不能中断整个注册 —— 最常见的原因是
               (user_id, url) 唯一索引撞了，跳过这一条继续。 */
        }
    }

    return ['groups' => $gCount, 'links' => $lCount];
}

/**
 * 把游客在本地攒下的数据搬到账号里。
 *
 * 只做「追加」：云端已有的 URL 跳过，本地独有的插入。这样合并是
 * 幂等的，重复调用不会产生重复条目；也符合用户对「合并」的直觉 ——
 * 两边都保留，重复的只留一份。
 *
 * @param array{groups?:array, links?:array} $payload
 * @return array{groups:int, links:int, skipped:int}
 */
function hp_merge_guest_data(PDO $pdo, int $uid, array $payload): array
{
    $out = ['groups' => 0, 'links' => 0, 'skipped' => 0];
    if ($uid <= 0) {
        return $out;
    }

    // 现有组：按名字对齐，同名视为同一组
    $byName = [];
    $st = $pdo->prepare('SELECT id, name FROM groups WHERE user_id = ?');
    $st->execute([$uid]);
    foreach ($st as $r) {
        $byName[(string)$r['name']] = (int)$r['id'];
    }

    $sortBase = (int)$pdo->query('SELECT COALESCE(MAX(sort), 0) FROM groups WHERE user_id = ' . $uid)->fetchColumn();
    $insG = $pdo->prepare('INSERT INTO groups(name, sort, icon, user_id, created_at) VALUES(?, ?, ?, ?, ?)');

    $groups = $payload['groups'] ?? [];
    foreach ($groups as $g) {
        $name = trim(mb_substr((string)($g['name'] ?? ''), 0, 24));
        if ($name === '' || isset($byName[$name])) {
            continue;
        }
        $sortBase += 10;
        $insG->execute([$name, $sortBase, (string)($g['icon'] ?? ''), $uid, now()]);
        $byName[$name] = (int)$pdo->lastInsertId();
        $out['groups']++;
    }

    // 现有 URL，用来去重
    $have = [];
    $st = $pdo->prepare('SELECT url FROM links WHERE user_id = ?');
    $st->execute([$uid]);
    foreach ($st as $r) {
        $have[(string)$r['url']] = true;
    }

    $sortMax = (int)$pdo->query('SELECT COALESCE(MAX(sort), 0) FROM links WHERE user_id = ' . $uid)->fetchColumn();
    $insL = $pdo->prepare(
        'INSERT INTO links(user_id, group_id, title, url, icon, icon_kind, descr, sort, hidden, created_at, updated_at)
         VALUES(?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)'
    );

    $links = $payload['links'] ?? [];
    foreach ($links as $l) {
        $url = trim((string)($l['url'] ?? ''));
        if ($url === '' || !preg_match('~^https?://~i', $url)) {
            continue;
        }
        if (isset($have[$url])) {
            $out['skipped']++;
            continue;
        }
        $gname = trim((string)($l['group'] ?? ''));
        $gid = $gname !== '' && isset($byName[$gname]) ? $byName[$gname] : null;
        $sortMax += 10;
        try {
            $insL->execute([
                $uid,
                $gid,
                mb_substr((string)($l['title'] ?? $url), 0, 60),
                $url,
                (string)($l['icon'] ?? ''),
                'auto',
                mb_substr((string)($l['descr'] ?? ''), 0, 120),
                $sortMax,
                now(),
                now(),
            ]);
            $have[$url] = true;
            $out['links']++;
        } catch (Throwable $e) {
            $out['skipped']++;
        }
    }

    return $out;
}
