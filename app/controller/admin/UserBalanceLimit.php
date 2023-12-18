<?php

namespace app\controller\admin;

use app\BaseController;
use app\lib\models\UserBalanceLimit as UserBalanceLimitModel;

class UserBalanceLimit extends BaseController
{
    protected $middleware = [
        'checkAdminToken',
        'checkRule',
        'OperationLog',
    ];

    /**
     * @title 限制用户余额列表
     * @param UserBalanceLimitModel $model
     * @return string
     */
    public function list(UserBalanceLimitModel $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  详情
     * @param UserBalanceLimitModel $model
     * @return string
     */
    public function info(UserBalanceLimitModel $model)
    {
        $info = $model->info($this->request->param('limit_sn'));
        return returnData($info);
    }

    /**
     * @title  新增
     * @param UserBalanceLimitModel $model
     * @return string
     */
    public function create(UserBalanceLimitModel $model)
    {
        $res = $model->DBNew($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  更新
     * @param UserBalanceLimitModel $model
     * @return string
     */
    public function update(UserBalanceLimitModel $model)
    {
        $res = $model->DBEdit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  删除
     * @param UserBalanceLimitModel $model
     * @return string
     */
    public function delete(UserBalanceLimitModel $model)
    {
        $res = $model->del($this->request->param('limit_sn'));
        return returnMsg($res);
    }
}