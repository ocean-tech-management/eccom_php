<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\api\v1;


use app\BaseController;
use app\lib\models\Collection as CollectionModel;

class Collection extends BaseController
{
    protected $middleware = [
        'checkUser',
        'checkApiToken',
    ];

    /**
     * @title  收藏列表
     * @param CollectionModel $model
     * @return string
     * @throws \Exception
     */
    public function list(CollectionModel $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  收藏操作
     * @param CollectionModel $model
     * @return string
     */
    public function update(CollectionModel $model)
    {
        $res = $model->newOrEdit($this->requestData);
        return returnMsg($res);
    }
}