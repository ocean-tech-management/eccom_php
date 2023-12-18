<?php
declare (strict_types=1);

namespace app\lib\listener;

use app\lib\job\DividePrice;
use Swoole\Server\Task;
use Swoole\Server;

class SwooleTask
{
    /**
     * 事件监听处理
     *
     * @return mixed
     */
    public function handle(Task $task, Server $server)
    {
        var_dump(date('Y-m-d H:i:s', time()) . 'on task收到异步任务了');
        $taskData = $task->data;
        var_dump($task->data);
//        switch ($taskData['type']){
//            case 'divide':
//                $list = (new DividePrice())->fire(['order_sn' => '202006106562874390']);
//                break;
//        }
        //$task->finish($task->data);
        //return ;
    }
}
