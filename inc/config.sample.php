<?php
/**
 * 配置模板 —— 复制为 config.php 后填写
 * 或直接访问 /install.php 自动生成
 */
declare(strict_types=1);

return [
    // 站点名称（后台可改，这里只是兜底）
    'app_name' => 'Vnav',

    // SQLite 数据库文件路径（需可写）
    'db_path'  => __DIR__ . '/../data/home.db',

    // 用于签名 / CSRF 的随机密钥，install.php 会自动生成 48 位
    'secret'   => '',

    // 管理员账号。admin_pass 必须是 password_hash() 生成的 bcrypt 串
    'admin_user' => 'admin',
    'admin_pass' => '',

    'timezone' => 'Asia/Shanghai',

    // 生产环境务必 false
    'debug'    => false,

    // 是否允许服务端抓取网页标题与图标（自动识别功能）
    'fetch_meta' => true,

    // 抓取超时（秒）
    'fetch_timeout' => 6,

    /* 是否信任反向代理传来的真实 IP。
     *
     * false（默认，强烈建议）：只认 REMOTE_ADDR。这时如果站点在 Nginx /
     * Cloudflare / 各类 CDN 后面，所有访客的 IP 都会是你的反代 IP，
     * 统计里全变成一个地区 —— 但至少没人能伪造。
     *
     * true：会采纳 X-Forwarded-For 的第一段。只有在「反代确实会覆盖
     * 这个头」的前提下才能开，否则任何人加一个请求头就能伪造成任意 IP，
     * 访问控制形同虚设。
     */
    'trust_proxy' => false,
];