<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\controller\api\v1;


use app\BaseController;
use app\lib\models\LiveRoom;

class Live extends BaseController
{
    /**
     * @title  直播间列表
     * @param LiveRoom $model
     * @return string
     * @throws \Exception
     */
    public function list(LiveRoom $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }
}