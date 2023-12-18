<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | 1. 定时器仅在当前进程空间内有效
// | 2. 定时器是纯异步实现的，不能与同步 IO 的函数一起使用，否则定时器的执行时间会发生错乱
// | 3. 定时器在执行的过程中可能会产生微小的偏差，请勿基于定时器实现精确时间计算
// | 同步阻塞函数有
// | mysql、mysqli、pdo以及其他DB操作函数
// | sleep、usleep
// | curl
// | stream、socket扩展的函数
// | swoole_client同步模式
// | file_get_contents/fread等文件读取函数
// | swoole_server->taskwait/swoole_server->sendwait
// | 异步非阻塞函数有
// | swoole_client异步模式
// | mysql-async库
// | redis-async库
// | swoole_timer_tick/swoole_timer_after
// | swoole_event系列函数
// | swoole_table/swoole_atomic/swoole_buffer
// | swoole_server->task/finish函数
// +----------------------------------------------------------------------

return[
//    'Timer' => [
//        //执行周期(毫秒) 1000为1S
//        'tally' => 2000,
//        //事件名称-注意大小写
//        'event' => 'Timer',
//    ],
    //订单超时定时任务
    'OrderTimeOutTimer' => [
        //执行周期(毫秒) 1000为1S
        'tally' => 1000,
        //事件名称-注意大小写
        'event' => 'OrderTimeOutTimer',
    ],

    //订单超时定时任务
    'PtOrderTimeOutTimer' => [
        //执行周期(毫秒) 1000为1S
        'tally' => 1000,
        //事件名称-注意大小写
        'event' => 'PtOrderTimeOutTimer',
    ],

//    //拼拼有礼订单超时定时任务
//    'PpylOrderTimeOutTimer' => [
//        //执行周期(毫秒) 1000为1S
//        'tally' => 1000,
//        //事件名称-注意大小写
//        'event' => 'PpylOrderTimeOutTimer',
//    ],
//
//    //拼拼有礼订单超时定时任务
//    'PpylWaitOrderTimeOutTimer' => [
//        //执行周期(毫秒) 1000为1S
//        'tally' => 1000,
//        //事件名称-注意大小写
//        'event' => 'PpylWaitOrderTimeOutTimer',
//    ],
//
//    //拼拼有礼订单超时定时任务(数据库防漏)
//    'PpylWaitOrderTimeOutByDBTimer' => [
//        //执行周期(毫秒) 1000为1S
//        'tally' => 3000,
//        //事件名称-注意大小写
//        'event' => 'PpylWaitOrderTimeOutByDBTimer',
//    ],
//
//    //拼拼有礼自动化超时定时任务
//    'PpylAuto' => [
//        //执行周期(毫秒) 1000为1S
//        'tally' => 1000,
//        //事件名称-注意大小写
//        'event' => 'PpylAuto',
//    ],

    //订单超时定时任务
    'ReceiveTimeOutTimer' => [
        //执行周期(毫秒) 1000为1S
        'tally' => 1000,
        //事件名称-注意大小写
        'event' => 'ReceiveTimeOutTimer',
    ],

    //商品上下架状态定时任务
    'onGoodsStatusTimer' => [
        //执行周期(毫秒) 1000为1S
        'tally' => 1000,
        //事件名称-注意大小写
        'event' => 'GoodsStatusTimer',
    ],

    //清算/结算教育基金定时任务
    'onIncentives' => [
        //执行周期(毫秒) 1000为1S,一小时一次
        'tally' => 3600000,
        //事件名称-注意大小写
        'event' => 'Incentives',
    ],

    //众筹活动定时器
    'onCrowdCheckTimer'=> [
        //执行周期(毫秒) 1000为1S,一小时一次
        'tally' => 1000,
        //事件名称-注意大小写
        'event' => 'CrowdCheckTimer',
    ],
];