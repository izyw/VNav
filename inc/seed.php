<?php
/**
 * 默认链接库与分组库。
 *
 * 这份数据是从正式站（2026-11-16）导出的「网站默认」那一份：9 个分组、
 * 67 条站点。安装时如果 links 表是空的，就灌进去 ——
 * 新装的人一打开就有东西可点，而不是面对一个空页面从零加起。
 *
 * 种子**不带图标**。图标是各家站点的 favicon，商标归各站点所有，
 * 不适合随 MIT 授权一起分发；而且它们本来就是抓一次就有的东西。
 * 装完之后在后台「图标」页点一次「补图标」，或在主页编辑态勾选全部
 * 再点「拉取图标」，几十秒就能补齐。
 */
declare(strict_types=1);

function hp_seed_groups(): array
{
    return array (
  0 => 
  array (
    'name' => '常用',
    'icon' => '',
    'sort' => 10,
  ),
  1 => 
  array (
    'name' => '影音',
    'icon' => '',
    'sort' => 20,
  ),
  2 => 
  array (
    'name' => '资讯',
    'icon' => '',
    'sort' => 30,
  ),
  3 => 
  array (
    'name' => 'AI 工具',
    'icon' => '',
    'sort' => 40,
  ),
  4 => 
  array (
    'name' => '开发',
    'icon' => '',
    'sort' => 50,
  ),
  5 => 
  array (
    'name' => '游戏',
    'icon' => '',
    'sort' => 60,
  ),
  6 => 
  array (
    'name' => '生活',
    'icon' => '',
    'sort' => 70,
  ),
  7 => 
  array (
    'name' => '效率',
    'icon' => '',
    'sort' => 80,
  ),
  8 => 
  array (
    'name' => '小说',
    'icon' => '',
    'sort' => 90,
  ),
);
}

function hp_seed_links(): array
{
    return array (
  0 => 
  array (
    'g' => '常用',
    'title' => '知乎 - 有问题，就会有答案',
    'url' => 'https://www.zhihu.com',
    'descr' => '知乎，中文互联网高质量的问答社区和创作者聚集的原创内容平台，于 2011 年 1 月正式上线，以「让人们更好的分享知识、经验和见解，找到自己的解答」为品牌使命。知乎凭借认真、专业、友善的社区氛围、独特的产品机制以及结构化和易获得的优质内容，',
    'icon' => '',
    'sort' => 1,
  ),
  1 => 
  array (
    'g' => '常用',
    'title' => '百度一下，你就知道',
    'url' => 'https://www.baidu.com',
    'descr' => '全球领先的中文搜索引擎、致力于让网民更便捷地获取信息，找到所求。百度超过千亿的中文网页数据库，可以瞬间找到相关的搜索结果。',
    'icon' => '',
    'sort' => 2,
  ),
  2 => 
  array (
    'g' => '常用',
    'title' => 'bilibili',
    'url' => 'https://www.bilibili.com',
    'descr' => '哔哩哔哩（bilibili.com)是国内知名的视频弹幕网站，这里有及时的动漫新番，活跃的ACG氛围，有创意的Up主。大家可以在这里找到许多欢乐。',
    'icon' => '',
    'sort' => 3,
  ),
  3 => 
  array (
    'g' => '常用',
    'title' => 'GitHub',
    'url' => 'https://github.com',
    'descr' => 'Join the world\'s most widely adopted, AI-powered developer platform where millions of developers, businesses, and the la',
    'icon' => '',
    'sort' => 4,
  ),
  4 => 
  array (
    'g' => '常用',
    'title' => 'ChatGPT',
    'url' => 'https://chat.openai.com',
    'descr' => 'OpenAI 的对话助手',
    'icon' => '',
    'sort' => 5,
  ),
  5 => 
  array (
    'g' => '常用',
    'title' => 'Chat Deepseek',
    'url' => 'https://chat.deepseek.com',
    'descr' => '国产推理模型，免费',
    'icon' => '',
    'sort' => 6,
  ),
  6 => 
  array (
    'g' => '常用',
    'title' => 'Sina Visitor System',
    'url' => 'https://weibo.com',
    'descr' => '随时随地发现新鲜事',
    'icon' => '',
    'sort' => 7,
  ),
  7 => 
  array (
    'g' => '常用',
    'title' => 'The AI workspace that works for you.',
    'url' => 'https://www.notion.so',
    'descr' => 'Build Custom Agents, search across all your apps, and automate busywork. The AI workspace where teams get more done, fas',
    'icon' => '',
    'sort' => 8,
  ),
  8 => 
  array (
    'g' => '影音',
    'title' => '爱奇艺 iQIYI-热门独播剧集在线观看',
    'url' => 'https://www.iqiyi.com',
    'descr' => '爱奇艺（iQIYI）是中国领先的在线视频平台，提供海量免费正版高清电视剧、短剧、电影、综艺、动漫等视频内容，成功打造了狂飙、新说唱、偶像练习生、我是刑警、迷雾剧场等现象级IP，热门大片、独播剧集实时更新，畅享流畅观影体验。',
    'icon' => '',
    'sort' => 1,
  ),
  9 => 
  array (
    'g' => '影音',
    'title' => '斗鱼 - 看见每种热爱',
    'url' => 'https://www.douyu.com',
    'descr' => '斗鱼 - 看见每种热爱 提供高清、快捷、流畅的视频直播和游戏赛事直播服务，包含英雄联盟lol直播、穿越火线cf直播、dota2直播、美女直播等各类热门游戏赛事直播和各种名家大神游戏直播，内容丰富，推送及时，带给你不一样的视听体验，一切尽在斗',
    'icon' => '',
    'sort' => 2,
  ),
  10 => 
  array (
    'g' => '影音',
    'title' => 'QQ音乐',
    'url' => 'https://y.qq.com',
    'descr' => 'QQ音乐是腾讯公司推出的一款网络音乐服务产品，海量音乐在线试听、新歌热歌在线首发、歌词翻译、手机铃声下载、高品质无损音乐试听、海量无损曲库、正版音乐下载、空间背景音乐设置、MV观看等，是互联网音乐播放和下载的优选。',
    'icon' => '',
    'sort' => 3,
  ),
  11 => 
  array (
    'g' => '影音',
    'title' => '虎牙直播-技术驱动娱乐-弹幕式互动直播平台',
    'url' => 'https://www.huya.com',
    'descr' => '虎牙直播是以游戏直播为主的弹幕式互动直播平台，累计注册用户2亿，提供热门游戏直播、电竞赛事直播与游戏赛事直播，手游直播等。包含英雄联盟lol，王者荣耀，绝地求生，和平精英等游戏直播，lol、dota2、dnf等热门游戏直播以及单机游戏、手游',
    'icon' => '',
    'sort' => 4,
  ),
  12 => 
  array (
    'g' => '影音',
    'title' => 'Youku',
    'url' => 'https://www.youku.com',
    'descr' => '优酷视频是中国领先的在线视频平台，提供海量正版高清电视剧、电影、综艺、动漫、纪录片等视频内容，独家热播剧集实时更新，畅享流畅观影体验。',
    'icon' => '',
    'sort' => 5,
  ),
  13 => 
  array (
    'g' => '影音',
    'title' => 'Douyin',
    'url' => 'https://www.douyin.com',
    'descr' => '记录美好生活的短视频平台',
    'icon' => '',
    'sort' => 6,
  ),
  14 => 
  array (
    'g' => '影音',
    'title' => '芒果TV-天生青春',
    'url' => 'https://www.mgtv.com',
    'descr' => '芒果TV-大家都在看的在线视频网站-热门综艺最新电影电视剧在线观看',
    'icon' => '',
    'sort' => 7,
  ),
  15 => 
  array (
    'g' => '影音',
    'title' => '快手',
    'url' => 'https://www.kuaishou.com',
    'descr' => '快手是一款国民级短视频App。在快手，了解真实的世界，认识有趣的人，也可以记录真实而有趣的自己。快手，拥抱每一种生活。',
    'icon' => '',
    'sort' => 8,
  ),
  16 => 
  array (
    'g' => '影音',
    'title' => '哔哩哔哩漫画 - bilibili 正版漫画平台',
    'url' => 'https://manga.bilibili.com',
    'descr' => '哔哩哔哩正版漫画阅读平台, 海量官方漫画连载在线观看, 二次元动漫迷的追漫神器, 热门漫画: 海贼王, 五等分的新娘, 约定的梦幻岛, 辉夜大小姐想让我告白 ~天才们的恋爱头脑战~, 关于我转生变成史莱姆这档事, 我的英雄学园, 修真聊天群',
    'icon' => '',
    'sort' => 9,
  ),
  17 => 
  array (
    'g' => '影音',
    'title' => '快看漫画_官方漫画_漫画大全免费在线观看',
    'url' => 'https://www.kuaikanmanhua.com',
    'descr' => '快看漫画是引领行业的新生代漫画阅读平台和兴趣社区。它为用户提供优质原创漫画内容，营造良好的二次元社区氛围，成为年轻一代的潮流文化阵地。',
    'icon' => '',
    'sort' => 10,
  ),
  18 => 
  array (
    'g' => '影音',
    'title' => 'YouTube',
    'url' => 'https://www.youtube.com',
    'descr' => 'Enjoy the videos and music you love, upload original content, and share it all with friends, family, and the world on Yo',
    'icon' => '',
    'sort' => 11,
  ),
  19 => 
  array (
    'g' => '影音',
    'title' => '网易云音乐',
    'url' => 'https://music.163.com',
    'descr' => '网易云音乐是一款专注于发现与分享的音乐产品，依托专业音乐人、DJ、好友推荐及社交功能，为用户打造全新的音乐生活。',
    'icon' => '',
    'sort' => 12,
  ),
  20 => 
  array (
    'g' => '影音',
    'title' => '豆瓣',
    'url' => 'https://www.douban.com',
    'descr' => '提供图书、电影、音乐唱片的推荐、评论和价格比较，以及城市独特的文化生活。',
    'icon' => '',
    'sort' => 13,
  ),
  21 => 
  array (
    'g' => '影音',
    'title' => '腾讯视频',
    'url' => 'https://v.qq.com',
    'descr' => '腾讯视频官方网站，腾讯视频提供最新热门电视剧、电影、综艺、动漫、纪录片等高清正版视频在线观看。海量独家内容持续更新，涵盖国产剧、美剧、韩剧、院线大片、热门综艺，支持超清流畅播放，尽在腾讯视频，当前热播《日落时分说爱你 第2季》，支持高清在线',
    'icon' => '',
    'sort' => 14,
  ),
  22 => 
  array (
    'g' => '资讯',
    'title' => '百度贴吧',
    'url' => 'https://tieba.baidu.com',
    'descr' => '以兴趣为主题的互动交流社区',
    'icon' => '',
    'sort' => 1,
  ),
  23 => 
  array (
    'g' => '资讯',
    'title' => '36Kr',
    'url' => 'https://36kr.com',
    'descr' => '科技与商业创投报道',
    'icon' => '',
    'sort' => 2,
  ),
  24 => 
  array (
    'g' => '资讯',
    'title' => '少数派 - 高效工作，品质生活',
    'url' => 'https://sspai.com',
    'descr' => '少数派致力于更好地运用数字产品或科学方法，帮助用户提升工作效率和生活品质',
    'icon' => '',
    'sort' => 3,
  ),
  25 => 
  array (
    'g' => '资讯',
    'title' => '今日头条',
    'url' => 'https://www.toutiao.com',
    'descr' => '个性化推荐信息资讯聚合平台',
    'icon' => '',
    'sort' => 4,
  ),
  26 => 
  array (
    'g' => '资讯',
    'title' => 'V2EX',
    'url' => 'https://www.v2ex.com',
    'descr' => '创意工作者的社区。讨论编程、设计、硬件、游戏等令人激动的话题。',
    'icon' => '',
    'sort' => 5,
  ),
  27 => 
  array (
    'g' => '资讯',
    'title' => '虎嗅网',
    'url' => 'https://www.huxiu.com',
    'descr' => '虎嗅是一个聚焦科技与创新的资讯平台，致力于为一切热爱思考与发现的用户，提供有效率的信息服务。内容包含前沿科技、汽车、消费、商业、医疗、健康、社会文化、金融财经、出海、国际热点、游戏、娱乐、3C数码、书影音等',
    'icon' => '',
    'sort' => 6,
  ),
  28 => 
  array (
    'g' => '资讯',
    'title' => '东方财富网：财经门户，提供专业的财经、股票、行情、证券、基金、理财、银行、保险、',
    'url' => 'https://www.eastmoney.com',
    'descr' => '东方财富网，专业的互联网财经媒体，提供7*24小时财经资讯及全球金融市场报价，汇聚全方位的综合财经资讯和金融市场资讯，覆盖股票、财经、证券、金融、美股、港股、行情、基金、债券、期货、外汇、科创板、保险、信托、黄金、理财、商业、银行、博客、股',
    'icon' => '',
    'sort' => 7,
  ),
  29 => 
  array (
    'g' => '资讯',
    'title' => '雪球 - 聪明的投资者都在这里',
    'url' => 'https://xueqiu.com',
    'descr' => '雪球，聪明的投资者都在这里 - 超过7800万投资者都在用的投资社区和财富管理平台，沪深、港股、美股全球市场实时行情，公募私募股票基金债券免费热点资讯，与投资高手实战交流。支持股票基金在线开户，炒股、投资理财低佣金，交易安全、方便、快捷。提',
    'icon' => '',
    'sort' => 8,
  ),
  30 => 
  array (
    'g' => '资讯',
    'title' => '果壳 科技有意思',
    'url' => 'https://www.guokr.com',
    'descr' => '果壳网是一个泛科技主题网站，提供负责任、有智趣、贴近生活的内容，你可以在这里阅读、分享、交流、提问。果壳网致力于让科技兴趣成为人们文化生活和娱乐生活的重要元素。',
    'icon' => '',
    'sort' => 9,
  ),
  31 => 
  array (
    'g' => '资讯',
    'title' => '煎蛋 - 地球上没有新鲜事',
    'url' => 'http://jandan.net',
    'descr' => '煎蛋以译介方式传播网络新鲜资讯',
    'icon' => '',
    'sort' => 10,
  ),
  32 => 
  array (
    'g' => 'AI 工具',
    'title' => 'App unavailable in region',
    'url' => 'https://claude.ai',
    'descr' => 'Anthropic 的对话助手',
    'icon' => '',
    'sort' => 1,
  ),
  33 => 
  array (
    'g' => 'AI 工具',
    'title' => '豆包 - 字节跳动旗下 AI 智能助手',
    'url' => 'https://www.doubao.com',
    'descr' => 'Seedance 2.0 视频生成模型现已全面接入豆包，现在登录即可免费使用！豆包 是你的 AI 聊天智能对话问答助手，写作文案翻译编程工具。豆包为你答疑解惑，提供灵感，辅助创作，也可以和你畅聊任何你感兴趣的话题',
    'icon' => '',
    'sort' => 2,
  ),
  34 => 
  array (
    'g' => 'AI 工具',
    'title' => '即梦AI - 即刻造梦',
    'url' => 'https://jimeng.jianying.com',
    'descr' => '即梦AI一站式智能创作平台，即刻造梦。提供AI绘画和AIGC视频创作体验，拥有激发无限创作灵感的社区。让即梦AI开启您的智能创作之旅，探索梦境实现的无限可能！',
    'icon' => '',
    'sort' => 3,
  ),
  35 => 
  array (
    'g' => 'AI 工具',
    'title' => 'Perplexity Ai',
    'url' => 'https://www.perplexity.ai',
    'descr' => '带出处的 AI 搜索',
    'icon' => '',
    'sort' => 4,
  ),
  36 => 
  array (
    'g' => 'AI 工具',
    'title' => 'Kimi AI 官网：K3.1 Pro 上线，编程与智能体任务全面升级',
    'url' => 'https://www.kimi.com',
    'descr' => '全新 Kimi K3.1 Pro 正式上线：轻松应对复杂编程项目、构建 3D 模型、开展深度金融研究，以更强大的智能体能力，高效完成更多复杂任务。',
    'icon' => '',
    'sort' => 5,
  ),
  37 => 
  array (
    'g' => 'AI 工具',
    'title' => '‎Google Gemini',
    'url' => 'https://gemini.google.com',
    'descr' => 'Meet Gemini, Google’s AI assistant. Get help with writing, planning, brainstorming, and more. Experience the power of ge',
    'icon' => '',
    'sort' => 6,
  ),
  38 => 
  array (
    'g' => '开发',
    'title' => 'Cloudflare: Build for the agent era',
    'url' => 'https://www.cloudflare.com',
    'descr' => 'Welcome to Cloudflare - Powering the next generation of applications',
    'icon' => '',
    'sort' => 1,
  ),
  39 => 
  array (
    'g' => '开发',
    'title' => 'Docker Hub Container Image Library | App',
    'url' => 'https://hub.docker.com',
    'descr' => 'Welcome to the world\'s largest container registry built for developers and open source contributors to find, use, and sh',
    'icon' => '',
    'sort' => 2,
  ),
  40 => 
  array (
    'g' => '开发',
    'title' => '阮一峰的网络日志',
    'url' => 'https://www.ruanyifeng.com/blog',
    'descr' => '中文技术写作的老牌博客',
    'icon' => '',
    'sort' => 3,
  ),
  41 => 
  array (
    'g' => '开发',
    'title' => '1Panel',
    'url' => 'https://1panel.cn',
    'descr' => '1Panel 是现代化的开源 Linux 服务器运维管理面板，同时也是新一代的 AI 服务器管理面板。它通过直观的 Web 界面，为用户提供一站式的服务器管理能力。',
    'icon' => '',
    'sort' => 4,
  ),
  42 => 
  array (
    'g' => '开发',
    'title' => '菜鸟教程',
    'url' => 'https://www.runoob.com',
    'descr' => '学的不仅是技术，更是梦想！',
    'icon' => '',
    'sort' => 5,
  ),
  43 => 
  array (
    'g' => '游戏',
    'title' => 'Taptap',
    'url' => 'https://www.taptap.cn',
    'descr' => '发现好游戏',
    'icon' => '',
    'sort' => 1,
  ),
  44 => 
  array (
    'g' => '游戏',
    'title' => 'Welcome to Steam',
    'url' => 'https://store.steampowered.com',
    'descr' => 'Steam is the ultimate destination for playing, discussing, and creating games.',
    'icon' => '',
    'sort' => 2,
  ),
  45 => 
  array (
    'g' => '游戏',
    'title' => '游民星空',
    'url' => 'https://www.gamersky.com',
    'descr' => '游民星空是国内单机游戏门户网站,提供特色的游戏资讯,大量游戏攻略,经验,评测文章,以及热门游戏资料专题',
    'icon' => '',
    'sort' => 3,
  ),
  46 => 
  array (
    'g' => '游戏',
    'title' => 'NGA玩家社区',
    'url' => 'https://bbs.nga.cn',
    'descr' => '国内知名的游戏玩家交流平台',
    'icon' => '',
    'sort' => 4,
  ),
  47 => 
  array (
    'g' => '生活',
    'title' => '携程旅行网:酒店预订,机票预订查询,旅游度假,商旅管理',
    'url' => 'https://www.ctrip.com/?AllianceID=665756&sid=1195985&ouid=&app=0101F00',
    'descr' => '携程旅行网是中国领先的在线旅行服务公司，向超过9000万会员提供酒店预订、酒店点评及特价酒店查询、机票预订、飞机票查询、时刻表、票价查询、航班查询、度假预订、商旅管理、为您的出行提供全方位旅行服务。',
    'icon' => '',
    'sort' => 1,
  ),
  48 => 
  array (
    'g' => '生活',
    'title' => '酷安网',
    'url' => 'https://www.coolapk.com/officialEvaluation',
    'descr' => '真实有趣的数码社区',
    'icon' => '',
    'sort' => 2,
  ),
  49 => 
  array (
    'g' => '生活',
    'title' => '中国铁路12306网站',
    'url' => 'https://www.12306.cn',
    'descr' => '中国铁路12306网站',
    'icon' => '',
    'sort' => 3,
  ),
  50 => 
  array (
    'g' => '生活',
    'title' => '飞机票查询',
    'url' => 'https://www.fliggy.com',
    'descr' => '飞猪是阿里巴巴旗下的综合性旅游出行服务平台。飞猪整合数千家机票代理商、航空公司、旅行社、旅行代理商资源，直签酒店，客栈卖家等为广大旅游者提供特价机票，酒店预订，客栈查询，国内外度假信息，门票购买，签证代理，旅游卡券，租车，邮轮等旅游产品的信',
    'icon' => '',
    'sort' => 4,
  ),
  51 => 
  array (
    'g' => '生活',
    'title' => '机票查询预订，酒店预订，旅游团购，度假搜索，门票预订',
    'url' => 'https://www.qunar.com',
    'descr' => '去哪儿Qunar.com提供机票,飞机票,特价机票,打折机票的查询预订；99元春秋航空特惠折扣机票，百元南航、海航惊喜特价机票任您挑选,国航、深航1折特价机票和折扣机票一网打尽，更多打折机票尽在Qunar.com。实时提供上百家旅游预订网站',
    'icon' => '',
    'sort' => 5,
  ),
  52 => 
  array (
    'g' => '生活',
    'title' => 'Apple',
    'url' => 'https://www.apple.com.cn',
    'descr' => '探索 Apple 充满创新的世界，选购各式 iPhone、iPad、Apple Watch 和 Mac，浏览各种配件、娱乐产品，并获得相关产品的专家支持服务。',
    'icon' => '',
    'sort' => 6,
  ),
  53 => 
  array (
    'g' => '生活',
    'title' => '爱范儿',
    'url' => 'https://www.ifanr.com',
    'descr' => '聚焦新创和消费主题的科技媒体，成立于 2008 年 10 月，关注产品及体验，致力于“独立，前瞻，深入”的原创报道和分析评论，是国内唯一一家在产业和产品领域同时具有强势影响力的科技媒体。旗下现有 ifanr.com、SocialBase.c',
    'icon' => '',
    'sort' => 7,
  ),
  54 => 
  array (
    'g' => '效率',
    'title' => '飞书文档-可多人实时编辑的在线文档高效协同办公软件',
    'url' => 'https://docs.feishu.cn',
    'descr' => '飞书文档作为新一代高效协作工具，融合了在线文档和协同文档的所有功能，不仅能插入在线表格，将数据表转换成看板，还能用思维笔记，将思考路径可视化，更有丰富模板满足多场景创作需求。用飞书文档，共创无限可能。',
    'icon' => '',
    'sort' => 1,
  ),
  55 => 
  array (
    'g' => '效率',
    'title' => '腾讯文档',
    'url' => 'https://docs.qq.com',
    'descr' => '可多人协作的在线文档',
    'icon' => '',
    'sort' => 2,
  ),
  56 => 
  array (
    'g' => '效率',
    'title' => '石墨文档官网',
    'url' => 'https://shimo.im',
    'descr' => '石墨文档-在线协同办公软件,支持多人在线文档协同办公,实现多终端、跨地域、随时随地在线办公,涵盖在线文档、在线表格、应用表格等8大协作应用即写即存统一管理、高效共享是企业云协同办公系统与在线办公平台的更好选择。',
    'icon' => '',
    'sort' => 3,
  ),
  57 => 
  array (
    'g' => '效率',
    'title' => '163网易免费邮-你的专业电子邮局',
    'url' => 'https://mail.163.com',
    'descr' => '网易163免费邮箱，你的专业电子邮局，注册用户数超10亿，专业稳定安全。网易邮箱官方App“邮箱大师”帮您高效处理邮件，支持所有邮箱，并可在手机、Windows和Mac上多端协同使用。',
    'icon' => '',
    'sort' => 4,
  ),
  58 => 
  array (
    'g' => '效率',
    'title' => 'Accounts Google',
    'url' => 'https://mail.google.com',
    'descr' => '谷歌旗下的电子邮箱服务',
    'icon' => '',
    'sort' => 5,
  ),
  59 => 
  array (
    'g' => '效率',
    'title' => '腾讯会议官方——腾讯会议 会开会',
    'url' => 'https://meeting.tencent.com',
    'descr' => '基于腾讯20多年音视频通讯经验，腾讯会议提供一站式音视频会议解决方案，让您能随时随地体验高清流畅的会议以及会议协作。',
    'icon' => '',
    'sort' => 6,
  ),
  60 => 
  array (
    'g' => '效率',
    'title' => '语雀，为每一个人提供优秀的文档和知识库工具 · 语雀',
    'url' => 'https://www.yuque.com',
    'descr' => '优雅高效的在线文档编辑与协同工具，让每个企业轻松拥有文档中心，阿里巴巴集团内部使用多年，众多中小企业首选。主流 Office 文件全兼容，多人协同，轻松拥有团队知识库。企业文档中心化管理，各类文档井然有序，独立域名，随时随地安全获取。语雀致',
    'icon' => '',
    'sort' => 7,
  ),
  61 => 
  array (
    'g' => '效率',
    'title' => 'TinyPNG',
    'url' => 'https://tinypng.com',
    'descr' => 'Free online image optimizer for faster websites! Reduce the file size of AVIF, JPEG XL, WebP, PNG and JPEG images with T',
    'icon' => '',
    'sort' => 8,
  ),
  62 => 
  array (
    'g' => '小说',
    'title' => 'Qidian',
    'url' => 'https://www.qidian.com',
    'descr' => '国内领先的原创文学网站',
    'icon' => '',
    'sort' => 1,
  ),
  63 => 
  array (
    'g' => '小说',
    'title' => '小说,番茄小说网_好看的小说尽在番茄小说官网',
    'url' => 'https://fanqienovel.com',
    'descr' => '番茄小说是抖音旗下免费阅读产品，拥有海量正版小说资源，支持听书畅读，提供书荒广场、优质书圈社区，推广优秀原创网络小说文学，全方位满足读者需求。番茄小说网提供玄幻小说,武侠小说,原创小说,网游小说,都市小说,言情小说,青春小说,历史小说,军事',
    'icon' => '',
    'sort' => 2,
  ),
  64 => 
  array (
    'g' => '小说',
    'title' => '七猫中文网-全本免费小说-免费小说排行榜',
    'url' => 'https://www.qimao.com',
    'descr' => '七猫中文网（免费小说大全）与国内各大小说站合作，为小说爱好者提供更多更全的各类免费小说上百万本。七猫免费小说包括言情小说，穿越小说，玄幻小说，校园小说，都市小说，武侠小说，网游小说等等。七猫中文网只有想不到，没有找不到！',
    'icon' => '',
    'sort' => 3,
  ),
  65 => 
  array (
    'g' => '小说',
    'title' => '微信读书',
    'url' => 'https://weread.qq.com',
    'descr' => '微信读书提供海量正版书籍、小说、漫画、公众号、听书，多设备同步实现跨屏阅读。与微信好友一起发现更多精品好书，随时交流感想，让阅读不再孤独。',
    'icon' => '',
    'sort' => 4,
  ),
  66 => 
  array (
    'g' => '小说',
    'title' => '刺猬猫阅读',
    'url' => 'https://www.ciweimao.com',
    'descr' => '刺猬猫提供最新好看的全本二次元穿越，娘化百合，各种萌化后宫小说在线阅读，综漫小说，动漫小说，火影小说，海贼小说，动漫同人',
    'icon' => '',
    'sort' => 5,
  ),
);
}

function hp_seed_apply(PDO $pdo): array
{
    if ((int)$pdo->query('SELECT COUNT(*) FROM links')->fetchColumn() > 0) {
        return ['groups' => 0, 'links' => 0, 'skipped' => true];
    }

    $now = date('Y-m-d H:i:s');
    $pdo->beginTransaction();
    try {
        $gSt = $pdo->prepare('INSERT INTO groups(name, icon, sort, user_id, created_at) VALUES(?, ?, ?, 0, ?)');
        $idOf = [];
        foreach (hp_seed_groups() as $g) {
            $gSt->execute([$g['name'], (string)$g['icon'], (int)$g['sort'], $now]);
            $idOf[$g['name']] = (int)$pdo->lastInsertId();
        }

        $lSt = $pdo->prepare(
            'INSERT INTO links(group_id, title, url, icon, icon_kind, descr, sort, hits, hidden, created_at, updated_at, user_id)
             VALUES(?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, 0)'
        );
        $n = 0;
        foreach (hp_seed_links() as $l) {
            $gid = $idOf[$l['g']] ?? null;
            if ($gid === null) {
                continue; // 分组名对不上就跳过，不硬塞进未分组
            }
            $icon = (string)$l['icon'];
            $lSt->execute([
                $gid,
                (string)$l['title'],
                (string)$l['url'],
                $icon,
                $icon !== '' ? 'file' : 'auto',
                (string)$l['descr'],
                (int)$l['sort'],
                $now,
                $now,
            ]);
            $n++;
        }
        $pdo->commit();
        return ['groups' => count($idOf), 'links' => $n, 'skipped' => false];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
