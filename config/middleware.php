<?php
// 中间件配置
use app\lib\middleware\CheckApiToken;
use app\lib\middleware\CheckUser;
use app\lib\middleware\CheckAccess;

return [
    // 别名或分组
    'alias'    => [
        'checkUser'       => \app\lib\middleware\CheckUser::class,
        'checkApiToken'   => app\lib\middleware\CheckApiToken::class,
        'checkAdminToken' => app\lib\middleware\CheckAdminToken::class,
        'checkRule'       => app\lib\middleware\CheckRule::class,
        'OperationLog'    => app\lib\middleware\OperationLog::class,
        'checkOpenUser'    => app\lib\middleware\CheckOpenUser::class,
        'openRequestLog'    => app\lib\middleware\OpenRequestLog::class,
        'checkAccess'   => app\lib\middleware\CheckAccess::class,
    ],
    // 优先级设置，此数组中的中间件会按照数组中的顺序优先执行
    'priority' => [
        app\lib\middleware\CheckAccess::class,
        app\lib\middleware\CheckApiToken::class,
        app\lib\middleware\CheckUser::class,
        app\lib\middleware\CheckAdminToken::class,
        app\lib\middleware\CheckRule::class,
        app\lib\middleware\OperationLog::class,
        app\lib\middleware\CheckOpenUser::class,
        app\lib\middleware\OpenRequestLog::class,
    ],
];
