<?php
/**
 * 访问控制 —— 按国家/地区放行或拦截。
 *
 * 三种模式（setting: acl_mode）：
 *   off    完全不拦。默认值，避免一装就把自己锁在门外。
 *   deny   黑名单：只拦 acl_countries 里列出的国家，其余放行。
 *   allow  白名单：只放行 acl_countries 里列出的国家，其余拦截。
 *
 * 未知归属（查不到国家，比如 IPv6、库还没导入、内网）单独一档
 * （setting: acl_unknown）—— 这一档必须可配，否则一导入半个库
 * 就把自己锁在外面，谁都进不来。
 *
 * 关键安全约束：
 *  1. 已登录管理员永远放行。否则配错一次就得去改数据库才能进后台。
 *  2. 拦截页返回 403 且不带任何站点内容，避免白名单用户窥探站点。
 *  3. 只在 acl_mode=on 时才查库，off 时零成本。
 */
declare(strict_types=1);

/** 国家码 → 中文名。只列常见国家/地区，其余按代码显示。 */
function hp_country_name(string $cc): string
{
    static $map = [
        'CN' => '中国', 'HK' => '中国香港', 'MO' => '中国澳门', 'TW' => '中国台湾',
        'US' => '美国', 'JP' => '日本', 'KR' => '韩国', 'SG' => '新加坡', 'MY' => '马来西亚',
        'TH' => '泰国', 'VN' => '越南', 'PH' => '菲律宾', 'ID' => '印度尼西亚', 'IN' => '印度',
        'PK' => '巴基斯坦', 'BD' => '孟加拉国', 'LK' => '斯里兰卡', 'NP' => '尼泊尔',
        'AU' => '澳大利亚', 'NZ' => '新西兰', 'CA' => '加拿大', 'MX' => '墨西哥',
        'BR' => '巴西', 'AR' => '阿根廷', 'CL' => '智利', 'CO' => '哥伦比亚', 'PE' => '秘鲁',
        'GB' => '英国', 'IE' => '爱尔兰', 'FR' => '法国', 'DE' => '德国', 'NL' => '荷兰',
        'BE' => '比利时', 'LU' => '卢森堡', 'CH' => '瑞士', 'AT' => '奥地利', 'IT' => '意大利',
        'ES' => '西班牙', 'PT' => '葡萄牙', 'GR' => '希腊', 'SE' => '瑞典', 'NO' => '挪威',
        'DK' => '丹麦', 'FI' => '芬兰', 'IS' => '冰岛', 'PL' => '波兰', 'CZ' => '捷克',
        'SK' => '斯洛伐克', 'HU' => '匈牙利', 'RO' => '罗马尼亚', 'BG' => '保加利亚',
        'UA' => '乌克兰', 'RU' => '俄罗斯', 'BY' => '白俄罗斯', 'LT' => '立陶宛',
        'LV' => '拉脱维亚', 'EE' => '爱沙尼亚', 'TR' => '土耳其', 'IL' => '以色列',
        'AE' => '阿联酋', 'SA' => '沙特阿拉伯', 'QA' => '卡塔尔', 'KW' => '科威特',
        'EG' => '埃及', 'ZA' => '南非', 'NG' => '尼日利亚', 'KE' => '肯尼亚', 'MA' => '摩洛哥',
        'IR' => '伊朗', 'IQ' => '伊拉克', 'KZ' => '哈萨克斯坦', 'UZ' => '乌兹别克斯坦',
        'MN' => '蒙古', 'KH' => '柬埔寨', 'LA' => '老挝', 'MM' => '缅甸',
    ];
    $cc = strtoupper(trim($cc));
    if ($cc === '') return '未知';
    return $map[$cc] ?? $cc;
}

/** @return array<string,string> 国家码 => 中文名，供后台下拉用 */
function hp_country_choices(): array
{
    $out = [];
    // 从地理库已导入的实际数据里取，只列真实出现过的国家，
    // 免得下拉里一堆永远匹配不到空国家
    try {
        $rows = db()->query(
            "SELECT cc, COUNT(*) c FROM geo_country WHERE cc <> '' GROUP BY cc ORDER BY c DESC LIMIT 250"
        )->fetchAll();
        foreach ($rows as $r) {
            $out[(string)$r['cc']] = hp_country_name((string)$r['cc']);
        }
    } catch (Throwable $e) {
    }
    if (!$out) {
        // 库没导入时给一份常见清单，至少能先配起来
        foreach (['CN', 'HK', 'MO', 'TW', 'US', 'JP', 'KR', 'SG', 'GB', 'DE', 'FR', 'RU', 'IN', 'BR'] as $cc) {
            $out[$cc] = hp_country_name($cc);
        }
    }
    asort($out, SORT_LOCALE_STRING);
    return $out;
}

/** 读规则表：cc => allow|deny */
function hp_acl_rules(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    try {
        foreach (db()->query('SELECT cc, mode FROM acl_countries') as $r) {
            $cache[strtoupper((string)$r['cc'])] = (string)$r['mode'];
        }
    } catch (Throwable $e) {
    }
    return $cache;
}

function hp_acl_set(string $cc, string $mode): void
{
    $cc = strtoupper(preg_replace('~[^A-Za-z]~', '', $cc) ?? '');
    if (strlen($cc) !== 2) return;
    if ($mode === '') {
        db()->prepare('DELETE FROM acl_countries WHERE cc = ?')->execute([$cc]);
        return;
    }
    $mode = $mode === 'allow' ? 'allow' : 'deny';
    db()->prepare(
        'INSERT INTO acl_countries(cc, mode) VALUES(?, ?)
         ON CONFLICT(cc) DO UPDATE SET mode = excluded.mode'
    )->execute([$cc, $mode]);
}

/**
 * 判定一次访问是否放行。
 * @return array{allow:bool, country:string, reason:string}
 *   reason: off | admin | listed | not_listed | unknown_allow | unknown_deny
 */
function hp_acl_check(string $ip, bool $isAdmin): array
{
    $mode = setting('acl_mode', 'off');
    if ($mode !== 'on') {
        return ['allow' => true, 'country' => '', 'reason' => 'off'];
    }
    // 管理员永远放行：否则配错一次就得进数据库才能救回来
    if ($isAdmin) {
        return ['allow' => true, 'country' => '', 'reason' => 'admin'];
    }

    $geo = hp_geo_lookup($ip);
    $cc  = strtoupper($geo['country']);

    if ($cc === '') {
        $unknown = setting('acl_unknown', 'allow') === 'deny' ? 'unknown_deny' : 'unknown_allow';
        return ['allow' => $unknown === 'unknown_allow', 'country' => '', 'reason' => $unknown];
    }

    $rules    = hp_acl_rules();
    $listType = setting('acl_list_type', 'deny');
    $listed   = isset($rules[$cc]);

    if ($listType === 'deny') {
        // 黑名单：列在 deny 名单里的才拦
        $allow = !($listed && $rules[$cc] === 'deny');
    } else {
        // 白名单：只有列在 allow 名单里的才放行
        $allow = $listed && $rules[$cc] === 'allow';
    }

    return [
        'allow'   => $allow,
        'country' => $cc,
        'reason'  => $allow ? ($listed ? 'listed' : 'not_listed') : 'blocked',
    ];
}

/** 被拦截时输出一个极简页并结束请求。不带任何站点信息。 */
function hp_acl_deny(string $cc = ''): never
{
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    $where = $cc !== '' ? hp_country_name($cc) : '未知地区';
    $msg   = '根据站点访问策略，来自「' . $where . '」的访问暂未开放。';
    echo <<<HTML
<!DOCTYPE html>
<html lang="zh-CN"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>403 · 访问受限</title>
<style>
:root{color-scheme:dark light}
body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;
  background:radial-gradient(120% 90% at 20% 0%,#241d33 0%,#12101a 55%,#0b0910 100%);
  color:#f2eee7;font:16px/1.65 "Instrument Sans","PingFang SC","Microsoft YaHei",sans-serif}
.box{max-width:420px;text-align:center}
.code{font-size:72px;font-weight:800;letter-spacing:-.05em;margin:0;line-height:1;
  background:linear-gradient(150deg,#ff9d80,#e0603f);-webkit-background-clip:text;background-clip:text;color:transparent}
p{color:#b6aebe;margin:14px 0 0;font-size:15px}
</style></head>
<body><div class="box">
<p class="code">403</p>
<p>{$msg}</p>
</div></body></html>
HTML;
    exit;
}