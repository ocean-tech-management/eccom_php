<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\controller\admin;


use app\BaseController;
use app\lib\models\Banner as BannerModel;
use app\lib\models\UserExtraWithdraw as UserExtraWithdrawModel;

class UserExtraWithdraw extends BaseController
{
    protected $middleware = [
        'checkAdminToken',
        'checkRule',
        'OperationLog',
    ];

    /**
     * @title  列表
     * @param UserExtraWithdrawModel $model
     * @return string
     * @throws \Exception
     */
    public function list(UserExtraWithdrawModel $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  详情
     * @param UserExtraWithdrawModel $model
     * @return string
     */
    public function info(UserExtraWithdrawModel $model)
    {
        $info = $model->info($this->requestData);
        return returnData($info);
    }

    /**
     * @title  新增
     * @param UserExtraWithdrawModel $model
     * @return string
     * @throws \Exception
     */
    public function create(UserExtraWithdrawModel $model)
    {
        $res = $model->DBNew($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  更新
     * @param UserExtraWithdrawModel $model
     * @return string
     */
    public function update(UserExtraWithdrawModel $model)
    {
        $res = $model->DBEdit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  删除
     * @param UserExtraWithdrawModel $model
     * @return string
     */
    public function delete(UserExtraWithdrawModel $model)
    {
        $res = $model->DBDel($this->requestData);
        return returnMsg($res);
    }
}