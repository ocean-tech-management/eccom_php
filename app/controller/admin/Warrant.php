<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 授权书模块Controller]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\admin;


use app\BaseController;
use app\lib\models\Warrant as WarrantModel;


class Warrant extends BaseController
{
    protected $middleware = [
        'checkAdminToken',
        'checkRule',
        'OperationLog',
    ];

    /**
     * @title  授权书列表
     * @param WarrantModel $model
     * @return string
     * @throws \Exception
     */
    public function list(WarrantModel $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  授权书详情
     * @param WarrantModel $model
     * @return string
     */
    public function info(WarrantModel $model)
    {
        $info = $model->info($this->request->param('id'));
        return returnData($info);
    }

    /**
     * @title  新增授权书
     * @param WarrantModel $model
     * @return string
     */
    public function create(WarrantModel $model)
    {
        $res = $model->new($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  更新授权书
     * @param WarrantModel $model
     * @return string
     */
    public function update(WarrantModel $model)
    {
        $res = $model->edit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  删除授权书
     * @param WarrantModel $model
     * @return string
     */
    public function delete(WarrantModel $model)
    {
        $res = $model->del($this->request->param('id'));
        return returnMsg($res);
    }

    /**
     * @title  上/下架授权书
     * @param WarrantModel $model
     * @return string
     */
    public function upOrDown(WarrantModel $model)
    {
        $res = $model->upOrDown($this->requestData);
        return returnMsg($res);
    }
}