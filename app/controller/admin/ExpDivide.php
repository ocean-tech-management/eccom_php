<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 体验中心奖励模块Controller]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\admin;


use app\BaseController;
use app\lib\models\ExpMemberVdc;
use app\lib\models\TeamShareholderMemberVdc;
use app\Request;
use think\facade\Cache;

class ExpDivide extends BaseController
{
    protected $middleware = [
        'checkAdminToken',
        'checkRule',
        'OperationLog',
    ];

    /**
     * @title  分销规则列表
     * @param ExpMemberVdc $model
     * @return string
     * @throws \Exception
     */
    public function ruleList(ExpMemberVdc $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  分销规则详情
     * @param ExpMemberVdc $model
     * @return string
     */
    public function ruleInfo(ExpMemberVdc $model)
    {
        $info = $model->getMemberRule($this->request->param('level'));
        return returnData($info);
    }

    /**
     * @title  新增分销规则
     * @param ExpMemberVdc $model
     * @return string
     */
    public function ruleCreate(ExpMemberVdc $model)
    {
        $res = $model->new($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  编辑分销规则
     * @param ExpMemberVdc $model
     * @return string
     */
    public function ruleUpdate(ExpMemberVdc $model)
    {
        $res = $model->edit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  会员分销规则列表
     * @param ExpMemberVdc $model
     * @return string
     * @throws \Exception
     */
    public function vdcList(ExpMemberVdc $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  团队股东分销规则列表
     * @param ExpMemberVdc $model
     * @return string
     * @throws \Exception
     */
    public function TeamShareholderVdcList(TeamShareholderMemberVdc $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

}