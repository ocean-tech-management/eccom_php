<?php

namespace app\controller\api\v1;

use app\BaseController;
use app\lib\models\AppVersion as AppVersionModel;

class AppVersion extends BaseController
{

    /**
     * @title  APP版本列表
     * @param AppVersionModel $model
     * @return string
     * @throws \Exception
     */
    public function list(AppVersionModel $model)
    {
        //仅在APP客户端下查询
        if (substr(getAccessKey(), 0, 1) == 'd') {
            $list = $model->cList($this->requestData);
        } else {
            $list['list'] = [];
            $list['pageTotal'] = 0;
        }
        return returnData($list['list'], $list['pageTotal']);
    }
}