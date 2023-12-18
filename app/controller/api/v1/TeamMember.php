<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------



namespace app\controller\api\v1;


use app\BaseController;
use app\lib\models\MemberVdc;
use app\lib\models\TeamMemberVdc;

class TeamMember extends BaseController
{
    /**
     * @title  各级会员名称
     * @return string
     * @throws \Exception
     */
    public function memberName()
    {
        $list = (new TeamMemberVdc())->where(['status' => 1])->field('level,name')->select()->toArray();
        return returnData($list);
    }
}