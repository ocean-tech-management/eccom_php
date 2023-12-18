<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 区域代理奖励模块Controller]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\admin;


use app\BaseController;
use app\lib\exceptions\ParamException;
use app\lib\models\AreaMember;
use app\lib\models\AreaMemberVdc;
use app\lib\models\Member as MemberModel;
use app\lib\models\MemberVdc;
use app\lib\models\Lecturer;
use app\lib\models\Divide as DivideModel;
use app\lib\models\TeamMember;
use app\lib\services\TeamMember as TeamMemberService;
use app\lib\models\TeamMemberVdc;
use app\Request;
use think\facade\Cache;

class AreaDivide extends BaseController
{
    protected $middleware = [
        'checkAdminToken',
        'checkRule',
        'OperationLog',
    ];

    /**
     * @title  分销规则列表
     * @param AreaMemberVdc $model
     * @return string
     * @throws \Exception
     */
    public function ruleList(AreaMemberVdc $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  分销规则详情
     * @param AreaMemberVdc $model
     * @return string
     */
    public function ruleInfo(AreaMemberVdc $model)
    {
        $info = $model->getMemberRule($this->request->param('level'));
        return returnData($info);
    }

    /**
     * @title  新增分销规则
     * @param AreaMemberVdc $model
     * @return string
     */
    public function ruleCreate(AreaMemberVdc $model)
    {
        $res = $model->new($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  编辑分销规则
     * @param AreaMemberVdc $model
     * @return string
     */
    public function ruleUpdate(AreaMemberVdc $model)
    {
        $res = $model->edit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  会员分销规则列表
     * @param AreaMemberVdc $model
     * @return string
     * @throws \Exception
     */
    public function vdcList(AreaMemberVdc $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  区代会员会员列表
     * @param AreaMember $model
     * @return string
     * @throws \Exception
     */
    public function memberList(AreaMember $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  会员各等级总数列表
     * @param AreaMember $model
     * @return string
     * @throws \Exception
     */
    public function memberLevel(AreaMember $model)
    {
        $list = $model->levelMember($this->requestData);
        return returnData($list);
    }

    /**
     * @title  指定会员等级
     * @param AreaMember $model
     * @throws \Exception
     * @return string
     */
    public function assign(AreaMember $model)
    {
        $res = $model->assign($this->requestData);
        return returnMsg($res);
    }

}