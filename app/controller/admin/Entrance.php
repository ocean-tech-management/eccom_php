<?php
// +----------------------------------------------------------------------
// |[ 文档说明: C端首页顶部icon入口模块Controller]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\admin;


use app\BaseController;
use app\lib\models\Entrance as EntranceModel;

class Entrance extends BaseController
{
    protected $middleware = [
        'checkAdminToken',
        'checkRule',
        'OperationLog',
    ];

    /**
     * @title  icon列表
     * @param EntranceModel $model
     * @return string
     * @throws \Exception
     */
    public function list(EntranceModel $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  icon详情
     * @param EntranceModel $model
     * @return string
     */
    public function info(EntranceModel $model)
    {
        $info = $model->info($this->requestData);
        return returnData($info);
    }

    /**
     * @title  新增icon
     * @param EntranceModel $model
     * @return string
     */
    public function create(EntranceModel $model)
    {
        $res = $model->DBNew($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  更新icon
     * @param EntranceModel $model
     * @return string
     */
    public function update(EntranceModel $model)
    {
        $res = $model->DBEdit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  删除icon
     * @param EntranceModel $model
     * @return string
     */
    public function delete(EntranceModel $model)
    {
        $res = $model->DBDel($this->request->param('id'));
        return returnMsg($res);
    }

    /**
     * @title  上/下架icon
     * @param EntranceModel $model
     * @return string
     */
    public function upOrDown(EntranceModel $model)
    {
        $res = $model->upOrDown($this->requestData);
        return returnMsg($res);
    }
}