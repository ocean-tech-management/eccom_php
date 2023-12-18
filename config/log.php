<?php
use think\facade\Env;

// +----------------------------------------------------------------------
// | 日志设置
// +----------------------------------------------------------------------
$rootPath = app()->getRootPath().'log'.DIRECTORY_SEPARATOR;

return [
    // 默认日志记录通道
    'default'      => Env::get('log.channel', 'file'),
    // 日志记录级别
    'level'        => [],
    // 日志类型记录的通道 ['error'=>'email',...]
    'type_channel' => [
        'file'   => 'file',
        'code'   => 'code',
        'divide' => 'divide',
        'callback' => 'callback',
        'pay' => 'pay',
        'wx' => 'wx',
        'baidu' => 'baidu',
        'shipping' => 'shipping',
        'refundCallback' => 'refundCallback',
        'member' => 'member',
        'memberChain' => 'memberChain',
        'timer' => 'timer',
        'incentives' => 'incentives',
        'growth' => 'growth',
        'autoAfterSale' => 'autoAfterSale',
        'operation' => 'operation',
        'team' => 'team',
        'ppyl' => 'ppyl',
        'ppylWait' => 'ppylWait',
        'ppylAuto' => 'ppylAuto',
        'ppylTimer' => 'ppylTimer',
        'kuaishang' => 'kuaishang',
        'propaganda' => 'propaganda',
        'handsel' => 'handsel',
        'teamDivide' => 'teamDivide',
        'teamMember' => 'teamMember',
        'shareholderMember' => 'shareholderMember',
        'areaDivide' => 'areaDivide',
        'areaMember' => 'areaMember',
        'areaMemberChain' => 'areaMemberChain',
        'crowd' => 'crowd',
        'advanceBuy' => 'advanceBuy',
        'lottery' => 'lottery',
        'device' => 'device',
        'open' => 'open',
        'letfree' => 'letfree',
        'maintenance' => 'maintenance',
        'debugLog' => 'debugLog',
        'transpond' => 'transpond',
    ],

    // 关闭全局日志写入
    'close'        => false,
    // 全局日志处理 支持闭包
    'processor'    => null,

    // 日志通道列表
    'channels'     => [
        'file' => [
            // 日志记录方式
            'type'           => 'File',
            // 日志保存目录
            'path'           => '',
            // 单文件日志写入
            'single'         => false,
            // 独立日志级别
            'apart_level'    => ['info','debug','error','sql'],
            // 最大日志文件数量
            'max_files'      => 0,
            // 使用JSON格式记录
            'json'           => false,
            // 日志处理
            'processor'      => null,
            // 关闭通道日志写入
            'close'          => false,
            //时间记录格式
            'time_format'    => 'Y-m-d H:i:s',
            // 日志输出格式化
            'format'         => '[%s][%s] %s',
            // 是否实时写入
            'realtime_write' => false,
        ],
        // 其它日志通道配置

        //验证码日志通道
        'code' => [
            // 日志记录方式
            'type'           => 'File',
            // 日志保存目录
            'path'           => $rootPath.'system'.DIRECTORY_SEPARATOR.'code',
            // 单文件日志写入
            'single'         => false,
            // 独立日志级别
            'apart_level'    => ['debug','info','error'],
            // 最大日志文件数量
            'max_files'      => 0,
            // 使用JSON格式记录
            'json'           => false,
            // 日志处理
            'processor'      => null,
            // 关闭通道日志写入
            'close'          => false,
            //时间记录格式
            'time_format'    => 'Y-m-d H:i:s',
            // 日志输出格式化
            'format'         => '[%s][%s] %s',
            // 是否实时写入
            'realtime_write' => false,
        ],

        //分销日志通道
        'divide' => [
            // 日志记录方式
            'type'           => 'File',
            // 日志保存目录
            'path'           => $rootPath.'system'.DIRECTORY_SEPARATOR.'divide',
            // 单文件日志写入
            'single'         => false,
            // 独立日志级别
            'apart_level'    => ['debug','info','error'],
            // 最大日志文件数量
            'max_files'      => 0,
            // 使用JSON格式记录
            'json'           => false,
            // 日志处理
            'processor'      => null,
            // 关闭通道日志写入
            'close'          => false,
            //时间记录格式
            'time_format'    => 'Y-m-d H:i:s',
            // 日志输出格式化
            'format'         => '[%s][%s] %s',
            // 是否实时写入
            'realtime_write' => false,
        ],

        //回调日志通道
        'callback' => [
            // 日志记录方式
            'type'           => 'File',
            // 日志保存目录
            'path'           => $rootPath.'system'.DIRECTORY_SEPARATOR.'callback',
            // 单文件日志写入
            'single'         => false,
            // 独立日志级别
            'apart_level'    => ['debug','info','error'],
            // 最大日志文件数量
            'max_files'      => 0,
            // 使用JSON格式记录
            'json'           => false,
            // 日志处理
            'processor'      => null,
            // 关闭通道日志写入
            'close'          => false,
            //时间记录格式
            'time_format'    => 'Y-m-d H:i:s',
            // 日志输出格式化
            'format'         => '[%s][%s] %s',
            // 是否实时写入
            'realtime_write' => false,
        ],

        //支付服务日志通道
        'pay' => [
            // 日志记录方式
            'type'           => 'File',
            // 日志保存目录
            'path'           => $rootPath.'system'.DIRECTORY_SEPARATOR.'pay',
            // 单文件日志写入
            'single'         => false,
            // 独立日志级别
            'apart_level'    => ['debug','info','error'],
            // 最大日志文件数量
            'max_files'      => 0,
            // 使用JSON格式记录
            'json'           => false,
            // 日志处理
            'processor'      => null,
            // 关闭通道日志写入
            'close'          => false,
            //时间记录格式
            'time_format'    => 'Y-m-d H:i:s',
            // 日志输出格式化
            'format'         => '[%s][%s] %s',
            // 是否实时写入
            'realtime_write' => false,
        ],

        //微信服务日志通道
        'wx' => [
            // 日志记录方式
            'type'           => 'File',
            // 日志保存目录
            'path'           => $rootPath.'system'.DIRECTORY_SEPARATOR.'wx',
            // 单文件日志写入
            'single'         => false,
            // 独立日志级别
            'apart_level'    => ['debug','info','error'],
            // 最大日志文件数量
            'max_files'      => 0,
            // 使用JSON格式记录
            'json'           => false,
            // 日志处理
            'processor'      => null,
            // 关闭通道日志写入
            'close'          => false,
            //时间记录格式
            'time_format'    => 'Y-m-d H:i:s',
            // 日志输出格式化
            'format'         => '[%s][%s] %s',
            // 是否实时写入
            'realtime_write' => false,
        ],

        //微信服务日志通道
        'healthyBalanceConver' => [
            // 日志记录方式
            'type'           => 'File',
            // 日志保存目录
            'path'           => $rootPath.'system'.DIRECTORY_SEPARATOR.'healthyBalanceConver',
            // 单文件日志写入
            'single'         => false,
            // 独立日志级别
            'apart_level'    => ['debug','info','error'],
            // 最大日志文件数量
            'max_files'      => 0,
            // 使用JSON格式记录
            'json'           => false,
            // 日志处理
            'processor'      => null,
            // 关闭通道日志写入
            'close'          => false,
            //时间记录格式
            'time_format'    => 'Y-m-d H:i:s',
            // 日志输出格式化
            'format'         => '[%s][%s] %s',
            // 是否实时写入
            'realtime_write' => false,
        ],

        //支付退款回到日志通道
        'refundCallback' => [
            // 日志记录方式
            'type'           => 'File',
            // 日志保存目录
            'path'           => $rootPath.'system'.DIRECTORY_SEPARATOR.'refundCallback',
            // 单文件日志写入
            'single'         => false,
            // 独立日志级别
            'apart_level'    => ['debug','info','error'],
            // 最大日志文件数量
            'max_files'      => 0,
            // 使用JSON格式记录
            'json'           => false,
            // 日志处理
            'processor'      => null,
            // 关闭通道日志写入
            'close'          => false,
            //时间记录格式
            'time_format'    => 'Y-m-d H:i:s',
            // 日志输出格式化
            'format'         => '[%s][%s] %s',
            // 是否实时写入
            'realtime_write' => false,
        ],

        //百度服务日志通道
        'baidu' => [
            // 日志记录方式
            'type'           => 'File',
            // 日志保存目录
            'path'           => $rootPath.'system'.DIRECTORY_SEPARATOR.'baidu',
            // 单文件日志写入
            'single'         => false,
            // 独立日志级别
            'apart_level'    => ['debug','info','error'],
            // 最大日志文件数量
            'max_files'      => 0,
            // 使用JSON格式记录
            'json'           => false,
            // 日志处理
            'processor'      => null,
            // 关闭通道日志写入
            'close'          => false,
            //时间记录格式
            'time_format'    => 'Y-m-d H:i:s',
            // 日志输出格式化
            'format'         => '[%s][%s] %s',
            // 是否实时写入
            'realtime_write' => false,
        ],

        //物流服务日志通道
        'shipping' => [
            // 日志记录方式
            'type'           => 'File',
            // 日志保存目录
            'path'           => $rootPath.'system'.DIRECTORY_SEPARATOR.'shipping',
            // 单文件日志写入
            'single'         => false,
            // 独立日志级别
            'apart_level'    => ['debug','info','error'],
            // 最大日志文件数量
            'max_files'      => 0,
            // 使用JSON格式记录
            'json'           => false,
            // 日志处理
            'processor'      => null,
            // 关闭通道日志写入
            'close'          => false,
            //时间记录格式
            'time_format'    => 'Y-m-d H:i:s',
            // 日志输出格式化
            'format'         => '[%s][%s] %s',
            // 是否实时写入
            'realtime_write' => false,
        ],

        //会员服务日志通道
        'member' => [
            // 日志记录方式
            'type'           => 'File',
            // 日志保存目录
            'path'           => $rootPath.'system'.DIRECTORY_SEPARATOR.'member',
            // 单文件日志写入
            'single'         => false,
            // 独立日志级别
            'apart_level'    => ['debug','info','error'],
            // 最大日志文件数量
            'max_files'      => 0,
            // 使用JSON格式记录
            'json'           => false,
            // 日志处理
            'processor'      => null,
            // 关闭通道日志写入
            'close'          => false,
            //时间记录格式
            'time_format'    => 'Y-m-d H:i:s',
            // 日志输出格式化
            'format'         => '[%s][%s] %s',
            // 是否实时写入
            'realtime_write' => false,
        ],

        //会员冗余结构服务日志通道
        'memberChain' => [
            // 日志记录方式
            'type'           => 'File',
            // 日志保存目录
            'path'           => $rootPath.'system'.DIRECTORY_SEPARATOR.'memberChain',
            // 单文件日志写入
            'single'         => false,
            // 独立日志级别
            'apart_level'    => ['debug','info','error'],
            // 最大日志文件数量
            'max_files'      => 0,
            // 使用JSON格式记录
            'json'           => false,
            // 日志处理
            'processor'      => null,
            // 关闭通道日志写入
            'close'          => false,
            //时间记录格式
            'time_format'    => 'Y-m-d H:i:s',
            // 日志输出格式化
            'format'         => '[%s][%s] %s',
            // 是否实时写入
            'realtime_write' => false,
        ],

        //定时任务日志通道
        'timer' => [
            // 日志记录方式
            'type'           => 'File',
            // 日志保存目录
            'path'           => $rootPath.'system'.DIRECTORY_SEPARATOR.'timer',
            // 单文件日志写入
            'single'         => false,
            // 独立日志级别
            'apart_level'    => ['debug','info','error'],
            // 最大日志文件数量
            'max_files'      => 0,
            // 使用JSON格式记录
            'json'           => false,
            // 日志处理
            'processor'      => null,
            // 关闭通道日志写入
            'close'          => false,
            //时间记录格式
            'time_format'    => 'Y-m-d H:i:s',
            // 日志输出格式化
            'format'         => '[%s][%s] %s',
            // 是否实时写入
            'realtime_write' => false,
        ],

        //会员激励机制日志通道
        'incentives' => [
            // 日志记录方式
            'type'           => 'File',
            // 日志保存目录
            'path'           => $rootPath.'system'.DIRECTORY_SEPARATOR.'incentives',
            // 单文件日志写入
            'single'         => false,
            // 独立日志级别
            'apart_level'    => ['debug','info','error'],
            // 最大日志文件数量
            'max_files'      => 0,
            // 使用JSON格式记录
            'json'           => false,
            // 日志处理
            'processor'      => null,
            // 关闭通道日志写入
            'close'          => false,
            //时间记录格式
            'time_format'    => 'Y-m-d H:i:s',
            // 日志输出格式化
            'format'         => '[%s][%s] %s',
            // 是否实时写入
            'realtime_write' => false,
        ],

        //成长值模块日志通道
        'growth' => [
            // 日志记录方式
            'type'           => 'File',
            // 日志保存目录
            'path'           => $rootPath.'system'.DIRECTORY_SEPARATOR.'growth',
            // 单文件日志写入
            'single'         => false,
            // 独立日志级别
            'apart_level'    => ['debug','info','error'],
            // 最大日志文件数量
            'max_files'      => 0,
            // 使用JSON格式记录
            'json'           => false,
            // 日志处理
            'processor'      => null,
            // 关闭通道日志写入
            'close'          => false,
            //时间记录格式
            'time_format'    => 'Y-m-d H:i:s',
            // 日志输出格式化
            'format'         => '[%s][%s] %s',
            // 是否实时写入
            'realtime_write' => false,
        ],

        //自动售后日志通道
        'autoAfterSale' => [
            // 日志记录方式
            'type'           => 'File',
            // 日志保存目录
            'path'           => $rootPath.'system'.DIRECTORY_SEPARATOR.'autoAfterSale',
            // 单文件日志写入
            'single'         => false,
            // 独立日志级别
            'apart_level'    => ['debug','info','error'],
            // 最大日志文件数量
            'max_files'      => 0,
            // 使用JSON格式记录
            'json'           => false,
            // 日志处理
            'processor'      => null,
            // 关闭通道日志写入
            'close'          => false,
            //时间记录格式
            'time_format'    => 'Y-m-d H:i:s',
            // 日志输出格式化
            'format'         => '[%s][%s] %s',
            // 是否实时写入
            'realtime_write' => false,
        ],

        //操作日志通道
        'operation' => [
            // 日志记录方式
            'type'           => 'File',
            // 日志保存目录
            'path'           => $rootPath.'system'.DIRECTORY_SEPARATOR.'operation',
            // 单文件日志写入
            'single'         => false,
            // 独立日志级别
            'apart_level'    => ['debug','info','error'],
            // 最大日志文件数量
            'max_files'      => 0,
            // 使用JSON格式记录
            'json'           => false,
            // 日志处理
            'processor'      => null,
            // 关闭通道日志写入
            'close'          => false,
            //时间记录格式
            'time_format'    => 'Y-m-d H:i:s',
            // 日志输出格式化
            'format'         => '[%s][%s] %s',
            // 是否实时写入
            'realtime_write' => false,
        ],

        //团队记录日志通道
        'team' => [
            // 日志记录方式
            'type'           => 'File',
            // 日志保存目录
            'path'           => $rootPath.'system'.DIRECTORY_SEPARATOR.'team',
            // 单文件日志写入
            'single'         => false,
            // 独立日志级别
            'apart_level'    => ['debug','info','error'],
            // 最大日志文件数量
            'max_files'      => 0,
            // 使用JSON格式记录
            'json'           => false,
            // 日志处理
            'processor'      => null,
            // 关闭通道日志写入
            'close'          => false,
            //时间记录格式
            'time_format'    => 'Y-m-d H:i:s',
            // 日志输出格式化
            'format'         => '[%s][%s] %s',
            // 是否实时写入
            'realtime_write' => false,
        ],

        //拼拼有礼记录日志通道
        'ppyl' => [
            // 日志记录方式
            'type'           => 'File',
            // 日志保存目录
            'path'           => $rootPath.'system'.DIRECTORY_SEPARATOR.'ppyl',
            // 单文件日志写入
            'single'         => false,
            // 独立日志级别
            'apart_level'    => ['debug','info','error'],
            // 最大日志文件数量
            'max_files'      => 0,
            // 使用JSON格式记录
            'json'           => false,
            // 日志处理
            'processor'      => null,
            // 关闭通道日志写入
            'close'          => false,
            //时间记录格式
            'time_format'    => 'Y-m-d H:i:s',
            // 日志输出格式化
            'format'         => '[%s][%s] %s',
            // 是否实时写入
            'realtime_write' => false,
        ],

        //拼拼有礼排队记录日志通道
        'ppylWait' => [
            // 日志记录方式
            'type'           => 'File',
            // 日志保存目录
            'path'           => $rootPath.'system'.DIRECTORY_SEPARATOR.'ppylWait',
            // 单文件日志写入
            'single'         => false,
            // 独立日志级别
            'apart_level'    => ['debug','info','error'],
            // 最大日志文件数量
            'max_files'      => 0,
            // 使用JSON格式记录
            'json'           => false,
            // 日志处理
            'processor'      => null,
            // 关闭通道日志写入
            'close'          => false,
            //时间记录格式
            'time_format'    => 'Y-m-d H:i:s',
            // 日志输出格式化
            'format'         => '[%s][%s] %s',
            // 是否实时写入
            'realtime_write' => false,
        ],

        //拼拼有礼自动拼团记录日志通道
        'ppylAuto' => [
            // 日志记录方式
            'type'           => 'File',
            // 日志保存目录
            'path'           => $rootPath.'system'.DIRECTORY_SEPARATOR.'ppylAuto',
            // 单文件日志写入
            'single'         => false,
            // 独立日志级别
            'apart_level'    => ['debug','info','error'],
            // 最大日志文件数量
            'max_files'      => 0,
            // 使用JSON格式记录
            'json'           => false,
            // 日志处理
            'processor'      => null,
            // 关闭通道日志写入
            'close'          => false,
            //时间记录格式
            'time_format'    => 'Y-m-d H:i:s',
            // 日志输出格式化
            'format'         => '[%s][%s] %s',
            // 是否实时写入
            'realtime_write' => false,
        ],

        //拼拼有礼自动化处理记录日志通道
        'ppylTimer' => [
            // 日志记录方式
            'type'           => 'File',
            // 日志保存目录
            'path'           => $rootPath.'system'.DIRECTORY_SEPARATOR.'ppylTimer',
            // 单文件日志写入
            'single'         => false,
            // 独立日志级别
            'apart_level'    => ['debug','info','error'],
            // 最大日志文件数量
            'max_files'      => 0,
            // 使用JSON格式记录
            'json'           => false,
            // 日志处理
            'processor'      => null,
            // 关闭通道日志写入
            'close'          => false,
            //时间记录格式
            'time_format'    => 'Y-m-d H:i:s',
            // 日志输出格式化
            'format'         => '[%s][%s] %s',
            // 是否实时写入
            'realtime_write' => false,
        ],

        //快商日志通道
        'kuaishang' => [
            // 日志记录方式
            'type'           => 'File',
            // 日志保存目录
            'path'           => $rootPath.'system'.DIRECTORY_SEPARATOR.'kuaishang',
            // 单文件日志写入
            'single'         => false,
            // 独立日志级别
            'apart_level'    => ['debug','info','error'],
            // 最大日志文件数量
            'max_files'      => 0,
            // 使用JSON格式记录
            'json'           => false,
            // 日志处理
            'processor'      => null,
            // 关闭通道日志写入
            'close'          => false,
            //时间记录格式
            'time_format'    => 'Y-m-d H:i:s',
            // 日志输出格式化
            'format'         => '[%s][%s] %s',
            // 是否实时写入
            'realtime_write' => false,
        ],

        //广宣奖日志通道
        'propaganda' => [
            // 日志记录方式
            'type'           => 'File',
            // 日志保存目录
            'path'           => $rootPath.'system'.DIRECTORY_SEPARATOR.'propaganda',
            // 单文件日志写入
            'single'         => false,
            // 独立日志级别
            'apart_level'    => ['debug','info','error'],
            // 最大日志文件数量
            'max_files'      => 0,
            // 使用JSON格式记录
            'json'           => false,
            // 日志处理
            'processor'      => null,
            // 关闭通道日志写入
            'close'          => false,
            //时间记录格式
            'time_format'    => 'Y-m-d H:i:s',
            // 日志输出格式化
            'format'         => '[%s][%s] %s',
            // 是否实时写入
            'realtime_write' => false,
        ],

        //套餐赠送日志通道
        'handsel' => [
            // 日志记录方式
            'type'           => 'File',
            // 日志保存目录
            'path'           => $rootPath.'system'.DIRECTORY_SEPARATOR.'handsel',
            // 单文件日志写入
            'single'         => false,
            // 独立日志级别
            'apart_level'    => ['debug','info','error'],
            // 最大日志文件数量
            'max_files'      => 0,
            // 使用JSON格式记录
            'json'           => false,
            // 日志处理
            'processor'      => null,
            // 关闭通道日志写入
            'close'          => false,
            //时间记录格式
            'time_format'    => 'Y-m-d H:i:s',
            // 日志输出格式化
            'format'         => '[%s][%s] %s',
            // 是否实时写入
            'realtime_write' => false,
        ],

        //团队业绩奖励日志通道
        'teamDivide' => [
            // 日志记录方式
            'type'           => 'File',
            // 日志保存目录
            'path'           => $rootPath.'system'.DIRECTORY_SEPARATOR.'teamDivide',
            // 单文件日志写入
            'single'         => false,
            // 独立日志级别
            'apart_level'    => ['debug','info','error'],
            // 最大日志文件数量
            'max_files'      => 0,
            // 使用JSON格式记录
            'json'           => false,
            // 日志处理
            'processor'      => null,
            // 关闭通道日志写入
            'close'          => false,
            //时间记录格式
            'time_format'    => 'Y-m-d H:i:s',
            // 日志输出格式化
            'format'         => '[%s][%s] %s',
            // 是否实时写入
            'realtime_write' => false,
        ],

        //团队业绩会员日志通道
        'teamMember' => [
            // 日志记录方式
            'type'           => 'File',
            // 日志保存目录
            'path'           => $rootPath.'system'.DIRECTORY_SEPARATOR.'teamMember',
            // 单文件日志写入
            'single'         => false,
            // 独立日志级别
            'apart_level'    => ['debug','info','error'],
            // 最大日志文件数量
            'max_files'      => 0,
            // 使用JSON格式记录
            'json'           => false,
            // 日志处理
            'processor'      => null,
            // 关闭通道日志写入
            'close'          => false,
            //时间记录格式
            'time_format'    => 'Y-m-d H:i:s',
            // 日志输出格式化
            'format'         => '[%s][%s] %s',
            // 是否实时写入
            'realtime_write' => false,
        ],

        //股东级会员日志通道
        'shareholderMember' => [
            // 日志记录方式
            'type' => 'File',
            // 日志保存目录
            'path' => $rootPath . 'system' . DIRECTORY_SEPARATOR . 'shareholderMember',
            // 单文件日志写入
            'single' => false,
            // 独立日志级别
            'apart_level' => ['debug', 'info', 'error'],
            // 最大日志文件数量
            'max_files' => 0,
            // 使用JSON格式记录
            'json' => false,
            // 日志处理
            'processor' => null,
            // 关闭通道日志写入
            'close' => false,
            //时间记录格式
            'time_format' => 'Y-m-d H:i:s',
            // 日志输出格式化
            'format' => '[%s][%s] %s',
            // 是否实时写入
            'realtime_write' => false,
        ],

        //区代级会员日志通道
        'areaMember' => [
            // 日志记录方式
            'type' => 'File',
            // 日志保存目录
            'path' => $rootPath . 'system' . DIRECTORY_SEPARATOR . 'areaMember',
            // 单文件日志写入
            'single' => false,
            // 独立日志级别
            'apart_level' => ['debug', 'info', 'error'],
            // 最大日志文件数量
            'max_files' => 0,
            // 使用JSON格式记录
            'json' => false,
            // 日志处理
            'processor' => null,
            // 关闭通道日志写入
            'close' => false,
            //时间记录格式
            'time_format' => 'Y-m-d H:i:s',
            // 日志输出格式化
            'format' => '[%s][%s] %s',
            // 是否实时写入
            'realtime_write' => false,
        ],

        //区代级会员分润日志通道
        'areaDivide' => [
            // 日志记录方式
            'type' => 'File',
            // 日志保存目录
            'path' => $rootPath . 'system' . DIRECTORY_SEPARATOR . 'areaDivide',
            // 单文件日志写入
            'single' => false,
            // 独立日志级别
            'apart_level' => ['debug', 'info', 'error'],
            // 最大日志文件数量
            'max_files' => 0,
            // 使用JSON格式记录
            'json' => false,
            // 日志处理
            'processor' => null,
            // 关闭通道日志写入
            'close' => false,
            //时间记录格式
            'time_format' => 'Y-m-d H:i:s',
            // 日志输出格式化
            'format' => '[%s][%s] %s',
            // 是否实时写入
            'realtime_write' => false,
        ],

        //区代级会员团队冗余结构日志通道
        'areaMemberChain' => [
            // 日志记录方式
            'type' => 'File',
            // 日志保存目录
            'path' => $rootPath . 'system' . DIRECTORY_SEPARATOR . 'areaMemberChain',
            // 单文件日志写入
            'single' => false,
            // 独立日志级别
            'apart_level' => ['debug', 'info', 'error'],
            // 最大日志文件数量
            'max_files' => 0,
            // 使用JSON格式记录
            'json' => false,
            // 日志处理
            'processor' => null,
            // 关闭通道日志写入
            'close' => false,
            //时间记录格式
            'time_format' => 'Y-m-d H:i:s',
            // 日志输出格式化
            'format' => '[%s][%s] %s',
            // 是否实时写入
            'realtime_write' => false,
        ],

        //众筹活动日志通道
        'crowd' => [
            // 日志记录方式
            'type' => 'File',
            // 日志保存目录
            'path' => $rootPath . 'system' . DIRECTORY_SEPARATOR . 'crowd',
            // 单文件日志写入
            'single' => false,
            // 独立日志级别
            'apart_level' => ['debug', 'info', 'error'],
            // 最大日志文件数量
            'max_files' => 0,
            // 使用JSON格式记录
            'json' => false,
            // 日志处理
            'processor' => null,
            // 关闭通道日志写入
            'close' => false,
            //时间记录格式
            'time_format' => 'Y-m-d H:i:s',
            // 日志输出格式化
            'format' => '[%s][%s] %s',
            // 是否实时写入
            'realtime_write' => false,
        ],

        //众筹提前购日志通道
        'advanceBuy' => [
            // 日志记录方式
            'type' => 'File',
            // 日志保存目录
            'path' => $rootPath . 'system' . DIRECTORY_SEPARATOR . 'advanceBuy',
            // 单文件日志写入
            'single' => false,
            // 独立日志级别
            'apart_level' => ['debug', 'info', 'error'],
            // 最大日志文件数量
            'max_files' => 0,
            // 使用JSON格式记录
            'json' => false,
            // 日志处理
            'processor' => null,
            // 关闭通道日志写入
            'close' => false,
            //时间记录格式
            'time_format' => 'Y-m-d H:i:s',
            // 日志输出格式化
            'format' => '[%s][%s] %s',
            // 是否实时写入
            'realtime_write' => false,
        ],

        //众筹提前购日志通道
        'lottery' => [
            // 日志记录方式
            'type' => 'File',
            // 日志保存目录
            'path' => $rootPath . 'system' . DIRECTORY_SEPARATOR . 'lottery',
            // 单文件日志写入
            'single' => false,
            // 独立日志级别
            'apart_level' => ['debug', 'info', 'error'],
            // 最大日志文件数量
            'max_files' => 0,
            // 使用JSON格式记录
            'json' => false,
            // 日志处理
            'processor' => null,
            // 关闭通道日志写入
            'close' => false,
            //时间记录格式
            'time_format' => 'Y-m-d H:i:s',
            // 日志输出格式化
            'format' => '[%s][%s] %s',
            // 是否实时写入
            'realtime_write' => false,
        ],

        //设备日志通道
        'device' => [
            // 日志记录方式
            'type' => 'File',
            // 日志保存目录
            'path' => $rootPath . 'system' . DIRECTORY_SEPARATOR . 'device',
            // 单文件日志写入
            'single' => false,
            // 独立日志级别
            'apart_level' => ['debug', 'info', 'error'],
            // 最大日志文件数量
            'max_files' => 0,
            // 使用JSON格式记录
            'json' => false,
            // 日志处理
            'processor' => null,
            // 关闭通道日志写入
            'close' => false,
            //时间记录格式
            'time_format' => 'Y-m-d H:i:s',
            // 日志输出格式化
            'format' => '[%s][%s] %s',
            // 是否实时写入
            'realtime_write' => false,
        ],

        //开放平台日志通道
        'open' => [
            // 日志记录方式
            'type' => 'File',
            // 日志保存目录
            'path' => $rootPath . 'system' . DIRECTORY_SEPARATOR . 'open',
            // 单文件日志写入
            'single' => false,
            // 独立日志级别
            'apart_level' => ['debug', 'info', 'error'],
            // 最大日志文件数量
            'max_files' => 0,
            // 使用JSON格式记录
            'json' => false,
            // 日志处理
            'processor' => null,
            // 关闭通道日志写入
            'close' => false,
            //时间记录格式
            'time_format' => 'Y-m-d H:i:s',
            // 日志输出格式化
            'format' => '[%s][%s] %s',
            // 是否实时写入
            'realtime_write' => false,
        ],

        //乐小活日志通道
        'letfree' => [
            // 日志记录方式
            'type' => 'File',
            // 日志保存目录
            'path' => $rootPath . 'system' . DIRECTORY_SEPARATOR . 'letfree',
            // 单文件日志写入
            'single' => false,
            // 独立日志级别
            'apart_level' => ['debug', 'info', 'error'],
            // 最大日志文件数量
            'max_files' => 0,
            // 使用JSON格式记录
            'json' => false,
            // 日志处理
            'processor' => null,
            // 关闭通道日志写入
            'close' => false,
            //时间记录格式
            'time_format' => 'Y-m-d H:i:s',
            // 日志输出格式化
            'format' => '[%s][%s] %s',
            // 是否实时写入
            'realtime_write' => false,
        ],
        'maintenance' => [
            // 日志记录方式
            'type' => 'File',
            // 日志保存目录
            'path' => '',
            // 单文件日志写入
            'single' => false,
            // 独立日志级别
            'apart_level' => ['info', 'debug', 'error', 'sql'],
            // 最大日志文件数量
            'max_files' => 0,
            // 使用JSON格式记录
            'json' => false,
            // 日志处理
            'processor' => null,
            // 关闭通道日志写入
            'close' => false,
            //时间记录格式
            'time_format' => 'Y-m-d H:i:s',
            // 日志输出格式化
            'format' => '[%s][%s] %s',
            // 是否实时写入
            'realtime_write' => false,
        ],

        //乐小活日志通道
        'debugLog' => [
            // 日志记录方式
            'type' => 'File',
            // 日志保存目录
            'path' => $rootPath . 'system' . DIRECTORY_SEPARATOR . 'debugLog',
            // 单文件日志写入
            'single' => false,
            // 独立日志级别
            'apart_level' => ['debug', 'info', 'error'],
            // 最大日志文件数量
            'max_files' => 0,
            // 使用JSON格式记录
            'json' => false,
            // 日志处理
            'processor' => null,
            // 关闭通道日志写入
            'close' => false,
            //时间记录格式
            'time_format' => 'Y-m-d H:i:s',
            // 日志输出格式化
            'format' => '[%s][%s] %s',
            // 是否实时写入
            'realtime_write' => false,
        ],

        //测试转发
        'transpond' => [
            // 日志记录方式
            'type' => 'File',
            // 日志保存目录
            'path' => $rootPath . 'system' . DIRECTORY_SEPARATOR . 'transpond',
            // 单文件日志写入
            'single' => false,
            // 独立日志级别
            'apart_level' => ['debug', 'info', 'error'],
            // 最大日志文件数量
            'max_files' => 0,
            // 使用JSON格式记录
            'json' => false,
            // 日志处理
            'processor' => null,
            // 关闭通道日志写入
            'close' => false,
            //时间记录格式
            'time_format' => 'Y-m-d H:i:s',
            // 日志输出格式化
            'format' => '[%s][%s] %s',
            // 是否实时写入
            'realtime_write' => false,
        ],
    ],

];
