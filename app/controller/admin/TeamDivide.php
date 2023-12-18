<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 团队业绩奖励模块Controller]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\admin;


use app\BaseController;
use app\lib\exceptions\ParamException;
use app\lib\models\Member as MemberModel;
use app\lib\models\MemberVdc;
use app\lib\models\Lecturer;
use app\lib\models\Divide as DivideModel;
use app\lib\models\TeamMember;
use app\lib\services\TeamMember as TeamMemberService;
use app\lib\models\TeamMemberVdc;
use app\Request;
use think\facade\Cache;

class TeamDivide extends BaseController
{
    protected $middleware = [
        'checkAdminToken',
        'checkRule',
        'OperationLog',
    ];

    /**
     * @title  分销规则列表
     * @param TeamMemberVdc $model
     * @return string
     * @throws \Exception
     */
    public function ruleList(TeamMemberVdc $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  分销规则详情
     * @param TeamMemberVdc $model
     * @return string
     */
    public function ruleInfo(TeamMemberVdc $model)
    {
        $info = $model->getMemberRule($this->request->param('level'));
        return returnData($info);
    }

    /**
     * @title  新增分销规则
     * @param TeamMemberVdc $model
     * @return string
     */
    public function ruleCreate(TeamMemberVdc $model)
    {
        $res = $model->new($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  编辑分销规则
     * @param TeamMemberVdc $model
     * @return string
     */
    public function ruleUpdate(TeamMemberVdc $model)
    {
        $res = $model->edit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  会员分销规则列表
     * @param TeamMemberVdc $model
     * @return string
     * @throws \Exception
     */
    public function vdcList(TeamMemberVdc $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  团队会员会员列表
     * @param TeamMember $model
     * @return string
     * @throws \Exception
     */
    public function memberList(TeamMember $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  会员各等级总数列表
     * @param TeamMember $model
     * @return string
     * @throws \Exception
     */
    public function memberLevel(TeamMember $model)
    {
        $list = $model->levelMember($this->requestData);
        return returnData($list);
    }

    /**
     * @title  指定会员等级
     * @param TeamMemberService $service
     * @throws \Exception
     * @return string
     */
    public function assign(TeamMemberService $service)
    {
        $res = $service->assignUserLevel($this->requestData);
        return returnMsg($res);
    }

//    /**
//     * @title  指定用户为体验中心身份--废除
//     * @param TeamMember $model
//     * @return string
//     */
//    public function assignToggleExp(TeamMember $model)
//    {
//        $list = $model->assignToggleExp($this->requestData);
//        return returnData($list);
//    }

}