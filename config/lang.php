<?php
// +----------------------------------------------------------------------
// | 多语言设置
// +----------------------------------------------------------------------

return [
    // 默认语言
    'default_lang'    => env('lang.default_lang', 'zh-cn'),
    // 允许的语言列表
    'allow_lang_list' => [],
    // 多语言自动侦测变量名
    'detect_var'      => 'lang',
    // 是否使用Cookie记录
    'use_cookie'      => false,
    // 多语言cookie变量
    'cookie_var'      => 'think_lang',
    // 扩展语言包
    'extend_list'     => [
        //中文简体
        'zh-cn' => [
            app()->getAppPath() . 'lang\zh-cn\admin.php',
            app()->getAppPath() . 'lang\zh-cn\api.php',
            app()->getAppPath() . 'lang\zh-cn\callback.php',
            app()->getAppPath() . 'lang\zh-cn\common.php',
            app()->getAppPath() . 'lang\zh-cn\manager.php',
            app()->getAppPath() . 'lang\zh-cn\middleware.php',
            app()->getAppPath() . 'lang\zh-cn\models.php',
            app()->getAppPath() . 'lang\zh-cn\open.php',
            app()->getAppPath() . 'lang\zh-cn\services.php',
            app()->getAppPath() . 'lang\zh-cn\remark.php',
        ],
        //中文繁体
        'zh-hk' => [
            app()->getAppPath() . 'lang\zh-hk\admin.php',
            app()->getAppPath() . 'lang\zh-hk\api.php',
            app()->getAppPath() . 'lang\zh-hk\callback.php',
            app()->getAppPath() . 'lang\zh-hk\common.php',
            app()->getAppPath() . 'lang\zh-hk\manager.php',
            app()->getAppPath() . 'lang\zh-hk\middleware.php',
            app()->getAppPath() . 'lang\zh-hk\models.php',
            app()->getAppPath() . 'lang\zh-hk\open.php',
            app()->getAppPath() . 'lang\zh-hk\services.php',
            app()->getAppPath() . 'lang\zh-hk\remark.php',
        ],
        //英语(美国)
        'en-us' => [
            app()->getAppPath() . 'lang\en-us\admin.php',
            app()->getAppPath() . 'lang\en-us\api.php',
            app()->getAppPath() . 'lang\en-us\callback.php',
            app()->getAppPath() . 'lang\en-us\common.php',
            app()->getAppPath() . 'lang\en-us\manager.php',
            app()->getAppPath() . 'lang\en-us\middleware.php',
            app()->getAppPath() . 'lang\en-us\models.php',
            app()->getAppPath() . 'lang\en-us\open.php',
            app()->getAppPath() . 'lang\en-us\services.php',
            app()->getAppPath() . 'lang\en-us\remark.php',
        ],
        //阿拉伯语(阿联酋)
        'ar-ae' => [
            app()->getAppPath() . 'lang\ar-ae\admin.php',
            app()->getAppPath() . 'lang\ar-ae\api.php',
            app()->getAppPath() . 'lang\ar-ae\callback.php',
            app()->getAppPath() . 'lang\ar-ae\common.php',
            app()->getAppPath() . 'lang\ar-ae\manager.php',
            app()->getAppPath() . 'lang\ar-ae\middleware.php',
            app()->getAppPath() . 'lang\ar-ae\models.php',
            app()->getAppPath() . 'lang\ar-ae\open.php',
            app()->getAppPath() . 'lang\ar-ae\services.php',
            app()->getAppPath() . 'lang\ar-ae\remark.php',
        ],
    ],
    // Accept-Language转义为对应语言包名称
    'accept_language' => [
        'zh-hans-cn' => 'zh-cn',
    ],
    // 是否支持语言分组
    'allow_group'     => true,

    'header_var'    => 'think-lang',

    //AOP自定义异常报错类的报错文案是否开启多语言
    'exception_lang' => env('lang.exception_lang', false),
];
