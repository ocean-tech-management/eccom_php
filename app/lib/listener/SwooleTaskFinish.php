<?php
declare (strict_types=1);

namespace app\lib\listener;

use Swoole\Server;

class SwooleTaskFinish
{
    /**
     * 事件监听处理
     *
     * @return mixed
     */
    public function handle($event)
    {
        var_dump('task finish');
        var_dump($event[2]);
        return;
    }
}
