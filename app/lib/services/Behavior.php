<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 行为轨迹模块Service]
// +----------------------------------------------------------------------



namespace app\lib\services;


use app\lib\models\User;
use app\lib\models\Behavior as BehaviorModel;

class Behavior
{
    /**
     * @title  行为轨迹记录
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    public function record(array $data)
    {
        if (empty($data['step'])) {
            return false;
        }
        $res = (new BehaviorModel())->newOrEdit($data['step']);
        return $res;
    }
}