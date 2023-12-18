<?php
// 事件定义文件
return [
    'bind' => [
    ],

    'listen' => [
        'AppInit' => [],
        'HttpRun' => [],
        'HttpEnd' => [],
        'LogLevel' => [],
        'LogWrite' => [],
        'swoole.managerStart' => ['\app\lib\listener\SwooleManagerStart'],
        'swoole.task' => ['\app\lib\listener\SwooleTask'],
        'swoole.finish' => ['\app\lib\listener\SwooleTaskFinish'],
    ],

    'subscribe' => [
        '\app\lib\subscribe\Timer',
    ],
];
