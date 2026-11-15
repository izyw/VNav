<?php
/**
 * 展示形态清单 —— 单一真源。
 * 主页下拉菜单、后台「外观」页、api.php 白名单全部读这里，
 * 新增一种形态只需在本文件加一行，不必回各处补 switch。
 */
declare(strict_types=1);

function hp_layouts(): array
{
    return [
        'card' => [
            'label' => '卡片',
            'name'  => '卡片聚合',
            'hint'  => '方块卡片，信息量最全，适合每天扫一眼',
            'svg'   => '<rect x="3" y="3" width="8" height="8" rx="2"/><rect x="13" y="3" width="8" height="8" rx="2"/><rect x="3" y="13" width="8" height="8" rx="2"/><rect x="13" y="13" width="8" height="8" rx="2"/>',
        ],
        'menu' => [
            'label' => '横排',
            'name'  => '横排菜单',
            'hint'  => '单行胶囊串起来，像导航条，最省高度',
            'svg'   => '<path d="M4 7h16M4 12h16M4 17h10"/>',
        ],
        'compact' => [
            'label' => '紧凑',
            'name'  => '紧凑列表',
            'hint'  => '单列密集行，几十个链接也不累',
            'svg'   => '<path d="M4 6h5M4 12h5M4 18h5M13 6h7M13 12h7M13 18h7"/>',
        ],
        'grid' => [
            'label' => '宫格',
            'name'  => '图标宫格',
            'hint'  => '大图标居中，最接近手机主屏',
            'svg'   => '<rect x="3" y="3" width="7" height="7" rx="1.6"/><rect x="14" y="3" width="7" height="7" rx="1.6"/><rect x="3" y="14" width="7" height="7" rx="1.6"/><rect x="14" y="14" width="7" height="7" rx="1.6"/>',
        ],
    ];
}

function hp_layout_keys(): array
{
    return array_keys(hp_layouts());
}

/** 只接受白名单值。归一到安全值，避免脏值让 html[data-layout] 匹配不到规则。 */
function hp_layout_safe(?string $v): string
{
    return in_array((string)$v, hp_layout_keys(), true) ? (string)$v : 'card';
}