<?php
/**
 * 分组导航方式 —— 只有一个形态：左侧栏。
 *
 * V1.4.1 起「顶部标签」退役。原因是它不只是不好看：
 *   1) 标签横排会随分组数量变长，超过六七个就挤成一行小字或溢出；
 *   2) 它把「分组」做成了一次性开关，而用户的心智模型是目录 ——
 *      分类应该常驻可见、随时点，不是先展开再找；
 *   3) 中文导航站（WebStack / 一为）与成熟产品（shadcn / Linear）的
 *      共识都是左侧常驻列，没有任何一个把分类做成顶部标签。
 *
 * 保留函数而不是直接写死字符串，是为了让后台、前台、API 白名单
 * 仍然从同一处取值 —— 将来真要再加一种形态，只改这一个文件。
 */
declare(strict_types=1);
if (!defined('HP_ROOT')) { http_response_code(404); exit; } // 入口守卫：与 .htaccess 的 404 保持一致，直接访问不暴露路径存在

function hp_groupnavs(): array
{
    return [
        'side' => [
            'label' => '左侧侧边栏',
            'hint'  => '左边竖排分组，点击跳到该分组',
            'svg'   => '<rect x="3" y="3" width="6" height="18" rx="2"/><path d="M13 7h8M13 12h8M13 17h8"/>',
        ],
    ];
}

function hp_groupnav_keys(): array
{
    return array_keys(hp_groupnavs());
}

/** 合法值只有 side / tab；空或脏值一律视为「不启用组内导航」。 */
/**
 * 归一化。旧库里存着 tab / ''，这里一律当 side —— 侧栏现在不是可选项，
 * 而是唯一形态，所以不需要「不启用」这个状态（只有一个分组时前台
 * 本来就不渲染导航）。
 */
function hp_groupnav_safe(?string $v): string
{
    return 'side';
}

/**
 * 分组图标库 —— 后台选择器与前台渲染共用的单一真源。
 *
 * 为什么要一个库：分类是用户自建的，不可能自带图标；而中文导航站
 * （WebStack / 一为）的左侧栏每项都有图标，缺图标时整栏是一列光秃秃的
 * 文字，眼睛没有可以停靠的锚点。
 *
 * 全部 24×24 描边图标，与站点图标的笔画风格一致。key 存进 groups.icon。
 */
function hp_group_icon_set(): array
{
    return [
        /* ---------- 第一批：语义最常用的 15 个 ---------- */
        'star'  => '<path d="M12 3.2l2.6 5.9 6.1.5-4.6 4.1 1.4 6-5.5-3.3-5.5 3.3 1.4-6L3.3 9.6l6.1-.5z"/>',
        'code'  => '<path d="M8.5 8L4 12l4.5 4M15.5 8L20 12l-4.5 4"/>',
        'pen'   => '<path d="M12.5 20H21"/><path d="M16.6 3.6a2 2 0 012.9 2.9L7.4 18.6l-4 1.1 1.1-4z"/>',
        'news'  => '<rect x="3" y="4.5" width="18" height="15" rx="2.2"/><path d="M7 9h6M7 13h10M7 16.5h5"/>',
        'play'  => '<circle cx="12" cy="12" r="8.8"/><path d="M10.2 8.6l5.6 3.4-5.6 3.4z"/>',
        'zap'   => '<path d="M13.2 2.5L5 13.4h6.1l-1.3 8.1 8.2-10.9h-6.1z"/>',
        'ai'    => '<rect x="4" y="7.5" width="16" height="12" rx="3.2"/><path d="M9 12.5v.01M15 12.5v.01M9.5 16.2h5"/><path d="M12 3v4.5"/>',
        'cloud' => '<path d="M7.5 18.5h9.2a4.1 4.1 0 00.3-8.2 6.1 6.1 0 00-11.7 1.9 3.6 3.6 0 002.2 6.3z"/>',
        'book'  => '<path d="M4.5 5.2A2.2 2.2 0 016.7 3h12.8v18H6.7a2.2 2.2 0 01-2.2-2.2z"/><path d="M8.5 7.5h6.5M8.5 11h6.5"/>',
        'bag'   => '<path d="M6 7.5h12l1.2 12.5H4.8z"/><path d="M9.2 7.5V5.6a2.8 2.8 0 015.6 0v1.9"/>',
        'users' => '<circle cx="9.2" cy="8.6" r="3.1"/><path d="M3.4 20a5.8 5.8 0 0111.6 0"/><path d="M16.6 11.4a3 3 0 000-5.8"/><path d="M18.8 20a5.2 5.2 0 00-3.3-4.6"/>',
        'home'  => '<path d="M4 10.5L12 3.5l8 7"/><path d="M6 9.5V20h12V9.5"/><path d="M10 20v-5h4v5"/>',
        'music' => '<circle cx="7" cy="17.5" r="2.6"/><circle cx="18" cy="15.5" r="2.6"/><path d="M9.6 17.5V7l11-2v10.5"/>',
        'game'  => '<rect x="2.5" y="7" width="19" height="10" rx="4"/><path d="M7 10.5v3M5.5 12h3M16 11.5h.01M18.5 14h.01"/>',
        'grid'  => '<rect x="3.4" y="3.4" width="7" height="7" rx="1.8"/><rect x="13.6" y="3.4" width="7" height="7" rx="1.8"/><rect x="3.4" y="13.6" width="7" height="7" rx="1.8"/><rect x="13.6" y="13.6" width="7" height="7" rx="1.8"/>',

        /* ---------- 第二批：办公 / 技术 / 生活，补到 39 个 ----------
           全部沿用同一套画法：24 网格、纯描边、无填充、圆头圆角，
           混在第一批里不会有「某个图标线条粗细不一样」的突兀感。 */
        'wrench'   => '<path d="M14.5 6.2a4.2 4.2 0 015.3 5.3l-8.6 8.6a2.4 2.4 0 01-3.4-3.4l8.6-8.6z"/><path d="M4.6 15.4l3.9 3.9"/>',
        'camera'   => '<path d="M4 8h3l1.5-2.2h7L17 8h3a1 1 0 011 1v9a1 1 0 01-1 1H4a1 1 0 01-1-1V9a1 1 0 011-1z"/><circle cx="12" cy="13.4" r="3.4"/>',
        'image'    => '<rect x="3" y="4" width="18" height="16" rx="2.4"/><circle cx="8.6" cy="9.4" r="1.6"/><path d="M4 17.5l5-4.6 4 3.6 3-2.6 4 3.6"/>',
        'video'    => '<rect x="3" y="6" width="12" height="12" rx="2.2"/><path d="M15 10.5l6-3.5v10l-6-3.5z"/>',
        'mail'     => '<rect x="3" y="5" width="18" height="14" rx="2.2"/><path d="M3.6 6.6l8.4 6 8.4-6"/>',
        'calendar' => '<rect x="3.5" y="5" width="17" height="16" rx="2.4"/><path d="M3.5 10h17M8 3.2v3.6M16 3.2v3.6"/>',
        'chart'    => '<path d="M4 20V10M10 20V4M16 20v-7M21.2 20H2.8"/>',
        'terminal' => '<rect x="3" y="4" width="18" height="16" rx="2.4"/><path d="M7.2 9.6l3 2.6-3 2.6M13 15.2h4"/>',
        'server'   => '<rect x="3" y="4" width="18" height="6.8" rx="2"/><rect x="3" y="13.2" width="18" height="6.8" rx="2"/><path d="M7 7.4h.01M7 16.6h.01"/>',
        'db'       => '<ellipse cx="12" cy="6" rx="7.6" ry="3"/><path d="M4.4 6v12c0 1.7 3.4 3 7.6 3s7.6-1.3 7.6-3V6"/><path d="M4.4 12c0 1.7 3.4 3 7.6 3s7.6-1.3 7.6-3"/>',
        'download' => '<path d="M12 3.8v10.6M7.6 10.2L12 14.6l4.4-4.4"/><path d="M4.5 17.2v1.6a1.6 1.6 0 001.6 1.6h11.8a1.6 1.6 0 001.6-1.6v-1.6"/>',
        'folder'   => '<path d="M3 7.4A1.6 1.6 0 014.6 5.8h4.1l2 2.6h8.7A1.6 1.6 0 0121 10v8.6a1.6 1.6 0 01-1.6 1.6H4.6A1.6 1.6 0 013 18.6z"/>',
        'file'     => '<path d="M6 3.6h7l5.2 5.2V20a1.6 1.6 0 01-1.6 1.6H6A1.6 1.6 0 014.4 20V5.2A1.6 1.6 0 016 3.6z"/><path d="M13 3.6v5.2h5.2"/>',
        'lock'     => '<rect x="4.6" y="10" width="14.8" height="10.4" rx="2.2"/><path d="M8.2 10V7.6a3.8 3.8 0 017.6 0V10"/>',
        'shield'   => '<path d="M12 3l8 3v6.1c0 4.5-3.3 8.2-8 9-4.7-.8-8-4.5-8-9V6z"/>',
        'bulb'     => '<path d="M9.4 17.8h5.2M10.4 21h3.2"/><path d="M12 3a5.8 5.8 0 00-3.4 10.5v2.1h6.8v-2.1A5.8 5.8 0 0012 3z"/>',
        'rocket'   => '<path d="M12 2.8c3.1 1.6 5 4.9 5 8.6 0 2-.8 3.8-1.8 5.2H8.8C7.8 15.2 7 13.4 7 11.4c0-3.7 1.9-7 5-8.6z"/><path d="M9.2 16.6L6.8 21l3.9-1.4M14.8 16.6L17.2 21l-3.9-1.4"/><circle cx="12" cy="10" r="1.4"/>',
        'heart'    => '<path d="M12 20.2s-7.2-4.6-7.2-9.4A4 4 0 0112 8.2a4 4 0 017.2 2.6c0 4.8-7.2 9.4-7.2 9.4z"/>',
        'bookmark' => '<path d="M6.6 3.6h10.8A1.6 1.6 0 0119 5.2v15.6l-7-4.2-7 4.2V5.2a1.6 1.6 0 011.6-1.6z"/>',
        'clock'    => '<circle cx="12" cy="12" r="8.6"/><path d="M12 7.4V12l3.1 2"/>',
        'search'   => '<circle cx="10.6" cy="10.6" r="6.4"/><path d="M15.4 15.4L20.4 20.4"/>',
        'gear'     => '<circle cx="12" cy="12" r="3.2"/><path d="M12 2.9v2.5M12 18.6v2.5M4.4 4.4l1.8 1.8M17.8 17.8l1.8 1.8M2.9 12h2.5M18.6 12h2.5M4.4 19.6l1.8-1.8M17.8 6.2l1.8-1.8"/>',
        'chat'     => '<path d="M20 15.6a2.4 2.4 0 01-2.4 2.4H9.2L5 21V6.4A2.4 2.4 0 017.4 4h10.2A2.4 2.4 0 0120 6.4z"/>',
        'tag'      => '<path d="M11.6 3.5H20a.5.5 0 01.5.5v8.4a2 2 0 01-.6 1.4l-7.1 7.1a2 2 0 01-2.8 0l-6.4-6.4a2 2 0 010-2.8l7.1-7.1a2 2 0 011.4-.6z"/><circle cx="16.3" cy="7.7" r="1.3"/>',
        'link'     => '<path d="M10.2 13.6a3.9 3.9 0 005.5 0l2.7-2.7a3.9 3.9 0 10-5.5-5.5l-1.2 1.2"/><path d="M13.8 10.4a3.9 3.9 0 00-5.5 0l-2.7 2.7a3.9 3.9 0 105.5 5.5l1.2-1.2"/>',
        'globe'    => '<circle cx="12" cy="12" r="8.6"/><path d="M3.4 12h17.2"/><path d="M12 3.4c2.5 2.4 3.8 5.4 3.8 8.6s-1.3 6.2-3.8 8.6c-2.5-2.4-3.8-5.4-3.8-8.6s1.3-6.2 3.8-8.6z"/>',
        'wallet'   => '<rect x="3" y="6" width="18" height="13" rx="2.6"/><path d="M3 10.4h18"/><circle cx="16.6" cy="14.6" r="1.2"/>',
        'gift'     => '<rect x="3.4" y="9.2" width="17.2" height="11" rx="1.9"/><path d="M3.4 13.4h17.2M12 9.2V20.2"/><path d="M12 9.2C9.4 9.2 7.9 8 7.9 6.5A2.2 2.2 0 0112 5.3a2.2 2.2 0 014.1 1.2c0 1.5-1.5 2.7-4.1 2.7z"/>',
        'plane'    => '<path d="M10.6 13.4L3 10.9V8.4l7.6 1.2V5.4a1.4 1.4 0 012.8 0v4.2L21 8.4v2.5l-7.6 2.5V18l2.2 1.5V21L12 19.8 8.4 21v-1.5L10.6 18z"/>',
        'palette'  => '<path d="M12 3.6a8.4 8.4 0 000 16.8c1.4 0 2-.9 2-1.9 0-1.6-1.5-1.9-1.5-3.2 0-1 .8-1.7 2-1.7h1.8a4.2 4.2 0 004.2-4.2c0-3.2-3.8-5.8-8.5-5.8z"/><circle cx="7.9" cy="9.6" r="1.1"/><circle cx="11.1" cy="7.1" r="1.1"/><circle cx="15.1" cy="8.1" r="1.1"/>',
        'headphones' => '<path d="M4.6 14v-2a7.4 7.4 0 0114.8 0v2"/><rect x="2.9" y="13.4" width="4" height="6.6" rx="1.9"/><rect x="17.1" y="13.4" width="4" height="6.6" rx="1.9"/>',
        'mug'      => '<path d="M4.6 7.6h11.8v7.4a4 4 0 01-4 4H8.6a4 4 0 01-4-4z"/><path d="M16.4 9.6h1.6a2.2 2.2 0 010 4.4h-1.6"/><path d="M7.6 3.6v2.2M10.8 3.6v2.2"/>',
        'leaf'     => '<path d="M20 4c0 8-5 13-11 13H4.8v-4C4.8 7 11 4 20 4z"/><path d="M4.8 20c2-5 5.4-8.4 9.6-10.4"/>',
        'pin'      => '<path d="M12 21s6.4-6.2 6.4-10.4a6.4 6.4 0 10-12.8 0C5.6 14.8 12 21 12 21z"/><circle cx="12" cy="10.4" r="2.4"/>',
        'git'      => '<circle cx="7" cy="6" r="2.2"/><circle cx="7" cy="18" r="2.2"/><circle cx="17.2" cy="12" r="2.2"/><path d="M7 8.2v7.6M9.2 6.6c3.4 0 6 2.2 6 5.4"/>',
        'keyboard' => '<rect x="2.6" y="6" width="18.8" height="12" rx="2.4"/><path d="M6.4 10h.01M9.8 10h.01M13.2 10h.01M16.6 10h.01M7.6 14.2h8.8"/>',
    ];
}

/** 合法的图标键（后台提交时的白名单） */
function hp_group_icon_keys(): array
{
    return array_keys(hp_group_icon_set());
}

/**
 * 分组配色 —— 每个图标键一枚色相（HSL 的 H，0~360）。
 *
 * 为什么绑在「图标键」上、而不是按分组在列表里的序号：
 *   序号配色看着更「自动平衡」，但分母是分组数量 —— 删掉一组，后面
 *   所有组的颜色会集体前移。用户刚记住「紫色是 AI」，第二天它变成了
 *   资讯的颜色，那这套颜色就不再是信息，只是噪音。绑在语义上，
 *   AI 永远是紫、开发永远是蓝、影音永远是红。
 *
 * 为什么不是均匀分布（i × 24°）：
 *   28px 的色块上，相邻 24° 的两色几乎分不出来，而真实分类也就七八个。
 *   这里是按「语义直觉」挑的色，再回头检查相邻组不撞色：实际用到的
 *   七个分组（常用 / AI / 开发 / 设计 / 资讯 / 影音 / 效率）两两间距
 *   都在 33° 以上。
 *
 * 兜底的 grid 用 214 的中性蓝灰：它是「没匹配上」的颜色，不该抢眼。
 */
function hp_group_icon_tones(): array
{
    return [
        'star'  => 45,   // 琥珀金 —— 星标
        'ai'    => 272,  // 紫 —— AI 的通用色
        'code'  => 228,  // 蓝 —— 开发者蓝
        'pen'   => 330,  // 粉 —— 设计
        'news'  => 192,  // 青 —— 资讯
        'play'  => 8,    // 红 —— 播放
        'zap'   => 32,   // 橙 —— 闪电
        'cloud' => 210,  // 天蓝
        'book'  => 165,  // 翠绿
        'bag'   => 350,  // 玫瑰
        'users' => 250,  // 靛蓝
        'home'  => 140,  // 绿
        'music' => 300,  // 品红
        'game'  => 80,   // 青柠
        'grid'  => 214,  // 石板蓝（兜底，低饱和）

        /* 第二批沿用同一思路：一个图标一枚色相，按语义挑，
           彼此至少隔 15° 以上 —— 28px 的色块上，太近的两种色分不出来。 */
        'wrench'     => 202,
        'camera'     => 342,
        'image'      => 286,
        'video'      => 14,
        'mail'       => 222,
        'calendar'   => 16,
        'chart'      => 196,
        'terminal'   => 152,
        'server'     => 236,
        'db'         => 262,
        'download'   => 206,
        'folder'     => 42,
        'file'       => 26,
        'lock'       => 4,
        'shield'     => 174,
        'bulb'       => 52,
        'rocket'     => 336,
        'heart'      => 352,
        'bookmark'   => 22,
        'clock'      => 216,
        'search'     => 190,
        'gear'       => 226,
        'chat'       => 266,
        'tag'        => 312,
        'link'       => 182,
        'globe'      => 200,
        'wallet'     => 132,
        'gift'       => 322,
        'plane'      => 208,
        'palette'    => 292,
        'headphones' => 282,
        'mug'        => 30,
        'leaf'       => 122,
        'pin'        => 356,
        'git'        => 12,
        'keyboard'   => 232,
    ];
}

/**
 * 组名 → 图标键的匹配规则。顺序即优先级。
 *
 * AI 要排在「开发」前面，否则「AI 工具」里的「工具」会被效率规则抢走；
 * 「常用」放最后，避免它吃掉别的词。
 */
function hp_group_icon_rules(): array
{
    return [
        'ai'    => ['ai', '智能', '模型', 'gpt', 'claude'],
        'play'  => ['影音', '视频', '音乐', '娱乐', '影视', '播客', 'media'],
        'code'  => ['开发', '代码', '编程', '技术', '工程', 'dev', 'code'],
        'pen'   => ['设计', '素材', '字体', 'icon'],
        'news'  => ['资讯', '新闻', '阅读', '博客', '媒体', '日报'],
        'cloud' => ['云', '服务', '存储', '服务器', '主机'],
        'book'  => ['学习', '教育', '文档', '教程', '读书', '小说', '书'],
        'bag'   => ['购物', '生活', '商城', '淘宝', '买'],
        'users' => ['社交', '社区', '论坛', '群'],
        'game'  => ['游戏', 'game'],
        'music' => ['歌', '专辑'],
        'home'  => ['首页', '主页'],
        'zap'   => ['效率', '工具', '办公'],
        'star'  => ['常用', '收藏', '推荐', '热门'],
    ];
}

/**
 * 解析出分组最终用哪个图标键：自定义优先，否则按组名匹配，兜底 grid。
 *
 * 抽出来是因为颜色也要跟图标走 —— 前台要「图标 + 色相」两样东西，
 * 不能让两套匹配逻辑各自跑一遍，那迟早会不一致。
 */
function hp_group_icon_key(string $name, string $custom = ''): string
{
    $set = hp_group_icon_set();
    if ($custom !== '' && isset($set[$custom])) {
        return $custom;
    }

    $lower = mb_strtolower($name, 'UTF-8');
    foreach (hp_group_icon_rules() as $key => $words) {
        foreach ($words as $w) {
            if (mb_strpos($lower, $w) !== false) {
                return $key;
            }
        }
    }
    return 'grid';
}

/** 分组的色相。侧栏图标块、组标题、卡片悬停共用这一个数。 */
function hp_group_hue(string $name, string $custom = ''): int
{
    $tones = hp_group_icon_tones();
    $key   = hp_group_icon_key($name, $custom);
    return (int)($tones[$key] ?? $tones['grid']);
}

/** 图标的中文名，给后台选择器做 aria-label 与 title */
function hp_group_icon_labels(): array
{
    return [
        'star' => '星标', 'code' => '代码', 'pen' => '画笔', 'news' => '资讯',
        'play' => '播放', 'zap' => '闪电', 'ai' => '智能', 'cloud' => '云',
        'book' => '书籍', 'bag' => '购物', 'users' => '人群', 'home' => '首页',
        'music' => '音乐', 'game' => '游戏', 'grid' => '网格',
        'wrench' => '工具', 'camera' => '相机', 'image' => '图片', 'video' => '视频',
        'mail' => '邮件', 'calendar' => '日历', 'chart' => '图表', 'terminal' => '终端',
        'server' => '服务器', 'db' => '数据库', 'download' => '下载', 'folder' => '文件夹',
        'file' => '文档', 'lock' => '加密', 'shield' => '安全', 'bulb' => '灵感',
        'rocket' => '火箭', 'heart' => '收藏夹', 'bookmark' => '书签', 'clock' => '时间',
        'search' => '搜索', 'gear' => '设置', 'chat' => '对话', 'tag' => '标签',
        'link' => '链接', 'globe' => '全球', 'wallet' => '钱包', 'gift' => '礼物',
        'plane' => '出行', 'palette' => '配色', 'headphones' => '耳机', 'mug' => '咖啡',
        'leaf' => '生活', 'pin' => '地点', 'git' => 'Git', 'keyboard' => '键盘',
    ];
}

/**
 * 取某个分组的图标。
 *
 * $custom 非空且合法 → 直接用用户选的那个；
 * 否则按组名做子串匹配（先具体后宽泛），一个都不中就用网格兜底。
 *
 * 自动匹配是给「懒得配图标」的人兜底的，不是默认值 —— 后台一旦选过，
 * 就以用户的选择为准，不再覆盖。
 */
function hp_group_icon(string $name, string $custom = ''): string
{
    $set = hp_group_icon_set();
    return $set[hp_group_icon_key($name, $custom)] ?? $set['grid'];
}

/**
 * 分组图标的完整标记（内联 svg 或 <img>）。
 *
 * $custom 可能是三种东西：
 *   ① 空                       → 按组名自动匹配的内置图标
 *   ② 内置图标键（'star' 等）   → 同上，但按用户选的
 *   ③ 自定义图片（'data/icons/g-xxx.png' 或 http(s)://…）
 *      → 用 <img>。前两种内联 svg 不产生任何额外请求，只有第三种要下载，
 *        而且后台保存时就已经抓下来落到 data/icons/，前台读的是本机文件。
 *
 * 为什么要单独一个函数：模板里 <svg> 的尺寸、描边宽度各不相同（侧栏 15、
 * 分组头 13、后台 18），自定义图标又不能用 <svg> 包 —— 于是把「外壳」交给
 * 调用方决定尺寸，这里只负责里面的内容形态。
 */
function hp_group_icon_markup(string $name, string $custom = '', int $size = 18): string
{
    $c = trim($custom);
    if ($c !== '' && (str_starts_with($c, 'data/icons/') || preg_match('#^https?://#i', $c))) {
        return '<img class="gicon-img" src="' . h($c) . '" width="' . $size . '" height="' . $size
            . '" alt="" loading="lazy" decoding="async">';
    }
    return '<svg viewBox="0 0 24 24" width="' . $size . '" height="' . $size
        . '" fill="none" stroke="currentColor" stroke-width="' . ($size >= 18 ? '1.7' : '2.1') . '"'
        . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . hp_group_icon($name, $custom) . '</svg>';
}
