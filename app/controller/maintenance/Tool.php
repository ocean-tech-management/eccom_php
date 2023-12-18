<?php


namespace app\controller\maintenance;


use app\lib\services\Data;

class Tool
{
    public function data()
    {
        (new Data())->setMode(2);
    }

    public function log(string $date)
    {
        (new Data())->runLog($date);
    }
}