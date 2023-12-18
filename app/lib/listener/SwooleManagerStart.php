<?php
// +----------------------------------------------------------------------
// |[ 文档说明: swoole定时任务模块监听器]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\listener;


class SwooleManagerStart
{
    public function handle()
    {
        $this->startTimer();
        return;
    }

    /**
     * @title  开启定时任务
     * @return void
     */
    public function startTimer(): void
    {
        //获取定时器配置
        $config = config('timer');
        foreach ($config as $conf) {
            swoole_timer_tick($conf['tally'], function () use ($conf) {
                event($conf['event']);
            });
        }
    }
}