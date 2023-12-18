<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 商户移动端-会员模块]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\manager;


use app\BaseController;
use app\lib\models\Member as MemberModel;
use app\lib\models\MemberVdc;

class Member extends BaseController
{
    protected $middleware = [
        'checkAdminToken',
        'checkRule',
        'OperationLog',
    ];

    /**
     * @title  会员列表
     * @param MemberModel $model
     * @return string
     * @throws \Exception
     */
    public function list(MemberModel $model)
    {
        $data = $this->requestData;
        $data['needOrderTotal'] = true;
        $list = $model->list($data);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  会员各等级总数列表
     * @param MemberModel $model
     * @return string
     * @throws \Exception
     */
    public function level(MemberModel $model)
    {
        $list = $model->levelMember($this->requestData);
        return returnData($list);
    }

    /**
     * @title  会员详情
     * @param MemberModel $model
     * @return string
     * @throws \Exception
     */
    public function info(MemberModel $model)
    {
        $info = $model->info($this->requestData);
        return returnData($info);
    }

    /**
     * @title  会员分销规则列表
     * @param MemberVdc $model
     * @return string
     * @throws \Exception
     */
    public function vdcList(MemberVdc $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }
}