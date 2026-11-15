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
    ];
}

/** 合法的图标键（后台提交时的白名单） */
function hp_group_icon_keys(): array
{
    return array_keys(hp_group_icon_set());
}

/** 图标的中文名，给后台选择器做 aria-label 与 title */
function hp_group_icon_labels(): array
{
    return [
        'star' => '星标', 'code' => '代码', 'pen' => '画笔', 'news' => '资讯',
        'play' => '播放', 'zap' => '闪电', 'ai' => '智能', 'cloud' => '云',
        'book' => '书籍', 'bag' => '购物', 'users' => '人群', 'home' => '首页',
        'music' => '音乐', 'game' => '游戏', 'grid' => '网格',
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
    if ($custom !== '' && isset($set[$custom])) {
        return $set[$custom];
    }

    // AI 要排在「开发」前面，否则「AI 工具」里的「工具」会被效率规则抢走；
    // 「常用」放最后，避免它吃掉别的词
    $rules = [
        'ai'    => ['ai', '智能', '模型', 'gpt', 'claude'],
        'play'  => ['影音', '视频', '音乐', '娱乐', '影视', '播客', 'media'],
        'code'  => ['开发', '代码', '编程', '技术', '工程', 'dev', 'code'],
        'pen'   => ['设计', '素材', '字体', 'icon'],
        'news'  => ['资讯', '新闻', '阅读', '博客', '媒体', '日报'],
        'cloud' => ['云', '服务', '存储', '服务器', '主机'],
        'book'  => ['学习', '教育', '文档', '教程', '读书'],
        'bag'   => ['购物', '生活', '商城', '淘宝', '买'],
        'users' => ['社交', '社区', '论坛', '群'],
        'game'  => ['游戏', 'game'],
        'music' => ['歌', '专辑'],
        'home'  => ['首页', '主页'],
        'zap'   => ['效率', '工具', '办公'],
        'star'  => ['常用', '收藏', '推荐', '热门'],
    ];

    $lower = mb_strtolower($name, 'UTF-8');
    foreach ($rules as $key => $words) {
        foreach ($words as $w) {
            if (mb_strpos($lower, $w) !== false) {
                return $set[$key];
            }
        }
    }
    return $set['grid'];
}
