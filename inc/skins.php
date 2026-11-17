<?php
/**
 * 主题皮肤定义 —— PHP 与 JS 的单一真源。
 *
 * 为什么单独一个文件：主题按钮的图标、名称、顺序要在服务端渲染出来
 * （否则首屏 aria-pressed 会错位闪烁），而前端切换逻辑又需要同一份清单。
 * 两处各写一遍必然漂移，所以只在这里定义。
 *
 * V1.3.2 起只剩 Atlas 一个体系，但它不是一个皮肤，而是同一套设计语言的
 * 三种呈现：
 *   atlas       亮底。白纸黑字、发丝线、编号目录。
 *   atlas-dark  暗底。石墨而非纯黑，网格与发丝线一模一样，只换明暗。
 *   auto        跟随系统，在两者之间自动切。
 *
 * 之前那五套（纸白 / 深夜 / 护眼 / 赛博）已退役。原因是它们全都建立在
 * 「同一套结构换颜色」之上 —— 圆角、间距、字号、字体一字不动，
 * 只在底色和阴影上做文章，所以切换时看起来始终是「同一个页面换个色调」。
 * Atlas 换的是结构本身，一套就够覆盖明暗两种使用场景。
 *
 * 新增皮肤仍需在本文件加一条 + 在 skin-atlas.css 里补一组变量块，
 * 其它地方（菜单、api 白名单、首屏脚本、后台单选框）全部自动生效。
 */
declare(strict_types=1);
if (!defined('HP_ROOT')) { http_response_code(404); exit; } // 入口守卫：与 .htaccess 的 404 保持一致，直接访问不暴露路径存在

/**
 * @return array<string, array{label:string, hint:string, svg:string, tone:'light'|'dark'|'auto'}>
 */
function hp_skins(): array
{
    return [
        // 亮底：默认。一张被认真排版的纸。
        'atlas' => [
            'label' => 'Atlas',
            'hint'  => '亮 · 白底柔影，圆角与留白',
            'tone'  => 'light',
            'svg'   => '<rect x="3" y="3" width="18" height="18" rx="1.5"/><path d="M3 9h18M9 9v12"/>',
        ],
        // 暗底：同一套网格，换成石墨。夜里不刺眼，但不像「深夜」那样偏紫。
        'atlas-dark' => [
            'label' => 'Atlas 暗',
            'hint'  => '暗 · 深空石墨，层次不变',
            'tone'  => 'dark',
            'svg'   => '<rect x="3" y="3" width="18" height="18" rx="1.5"/><path d="M3 9h18M9 9v12"/><path d="M3.6 3.6h5.2v5.2H3.6z" fill="currentColor" stroke="none"/>',
        ],
        // 跟随系统：在 atlas / atlas-dark 之间自动切。
        'auto' => [
            'label' => '跟随系统',
            'hint'  => '按系统明暗自动切换',
            'tone'  => 'auto',
            'svg'   => '<circle cx="12" cy="12" r="8"/><path d="M12 4a8 8 0 0 1 0 16z" fill="currentColor" stroke="none"/>',
        ],
        /* 第二套并列的设计语言，不是 Atlas 的换色版：
           整个应用装进一张带边框的圆角框，框内顶栏 / 侧栏 / 主区三块并列。
           见 assets/skin-frame.css 的头部注释。 */
        'frame' => [
            'label' => '工作台',
            'hint'  => '框 · 一整块应用，三区并列',
            'tone'  => 'light',
            'svg'   => '<rect x="2.5" y="4" width="19" height="16" rx="2.4"/><path d="M2.5 9h19M8.5 9v11"/>',
        ],
        /* 工作台的暗色版。不是第四套设计语言，是同一副骨架换明暗：
           结构、圆角、间距、字号一字不动，只换颜色与阴影。
           取色照参考站 shadcn dashboard 的暗色主题，见 skin-frame.css 1b 节。
           图标在 frame 的线框里填上侧栏那一块，表示「框还在，只是变暗了」。 */
        'frame-dark' => [
            'label' => '工作台 暗',
            'hint'  => '暗 · 深色窗口，骨架不变',
            'tone'  => 'dark',
            'svg'   => '<rect x="2.5" y="4" width="19" height="16" rx="2.4"/><path d="M2.5 9h19M8.5 9v11"/><path d="M3.9 10.2h3.4v8.6H3.9z" fill="currentColor" stroke="none" opacity=".45"/>',
        ],
    ];
}

/** 合法皮肤键列表 */
function hp_skin_keys(): array
{
    return array_keys(hp_skins());
}

/**
 * 把任意输入收敛到合法皮肤。
 *
 * 这里同时承担「旧皮肤迁移」：数据库里可能还存着 paper / night / celadon /
 * cyber。它们已不在 hp_skins() 里，若直接兜底到 atlas，原本用「深夜」的人
 * 升级后会在半夜被一张白纸闪到。所以按下表迁移，保持明暗不变：
 *
 *   night  → atlas-dark   原本就是暗色，只是从墨紫换成石墨
 *   cyber  → atlas-dark   同上
 *   paper  → atlas        原本就是浅色
 *   celadon→ atlas        同上
 *
 * 迁移只在读取时发生，不改数据库 —— 用户下次在菜单里选一次皮肤，
 * 存的值自然就更新了。
 */
function hp_skin_safe(?string $v): string
{
    $v = (string)$v;

    static $legacy = [
        'night'   => 'atlas-dark',
        'cyber'   => 'atlas-dark',
        'paper'   => 'atlas',
        'celadon' => 'atlas',
    ];
    if (isset($legacy[$v])) {
        return $legacy[$v];
    }

    return in_array($v, hp_skin_keys(), true) ? $v : 'atlas';
}

/**
 * 该皮肤是否属于暗色调（用于 <meta name="color-scheme"> 与后台预览底色）。
 * auto 返回 null —— 它由系统决定。
 */
function hp_skin_is_dark(string $skin): ?bool
{
    $tone = hp_skins()[$skin]['tone'] ?? 'light';
    return match ($tone) {
        'dark'  => true,
        'light' => false,
        default => null,
    };
}
