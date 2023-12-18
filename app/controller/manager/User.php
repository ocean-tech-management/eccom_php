<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 商户移动端-用户模块]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\manager;


use app\BaseController;
use app\lib\models\User as UserModel;
use app\lib\services\Member as MemberService;

class User extends BaseController
{
    protected $middleware = [
        'checkAdminToken',
        'checkRule',
        'OperationLog',
    ];

    /**
     * @title  用户列表
     * @param UserModel $model
     * @return string
     * @throws \Exception
     */
    public function list(UserModel $model)
    {
        $data = $this->requestData;
        $data['needOrderSummary'] = true;
        $list = $model->list($data);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  用户数量汇总
     * @param UserModel $model
     * @return string
     * @throws \Exception
     */
    public function summary(UserModel $model)
    {
        $data = $this->requestData;
        $data['needTimeSummaryData'] = true;
        $info = $model->total($data);
        return returnData($info);
    }

    /**
     * @title  用户详情
     * @param UserModel $model
     * @return string
     */
    public function info(UserModel $model)
    {
        $info = $model->getUserInfo($this->request->param('uid'));
        return returnData($info);
    }

    /**
     * @title  指定会员上级/等级
     * @param MemberService $service
     * @return string
     */
    public function assign(MemberService $service)
    {
        $res = $service->assignUserLevel($this->requestData);
        return returnMsg($res);
    }
}