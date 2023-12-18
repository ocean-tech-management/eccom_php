<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 股东会员规则模块Controller]
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
use app\lib\models\ShareholderMember;
use app\lib\models\ShareholderMemberVdc;
use app\lib\models\TeamMember;
use app\lib\services\TeamMember as TeamMemberService;
use app\lib\models\TeamMemberVdc;
use app\Request;
use think\facade\Cache;

class ShareholderDivide extends BaseController
{
    protected $middleware = [
        'checkAdminToken',
        'checkRule',
        'OperationLog',
    ];

    /**
     * @title  分销规则列表
     * @param ShareholderMemberVdc $model
     * @return string
     * @throws \Exception
     */
    public function ruleList(ShareholderMemberVdc $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  分销规则详情
     * @param ShareholderMemberVdc $model
     * @return string
     */
    public function ruleInfo(ShareholderMemberVdc $model)
    {
        $info = $model->getMemberRule($this->request->param('level'));
        return returnData($info);
    }

    /**
     * @title  新增分销规则
     * @param ShareholderMemberVdc $model
     * @return string
     */
    public function ruleCreate(ShareholderMemberVdc $model)
    {
        $res = $model->new($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  编辑分销规则
     * @param ShareholderMemberVdc $model
     * @return string
     */
    public function ruleUpdate(ShareholderMemberVdc $model)
    {
        $res = $model->edit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  会员分销规则列表
     * @param ShareholderMemberVdc $model
     * @return string
     * @throws \Exception
     */
    public function vdcList(ShareholderMemberVdc $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  团队会员会员列表
     * @param ShareholderMember $model
     * @return string
     * @throws \Exception
     */
    public function memberList(ShareholderMember $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }


}