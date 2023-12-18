<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\api\v1;

use app\lib\models\MemberVdc;
use app\lib\services\Member as MemberService;
use app\lib\models\Member as MemberModel;

use app\BaseController;

class Member extends BaseController
{
//    protected $middleware = [
//        'checkUser',
//        'checkApiToken',
//    ];


    /**
     * @title  成为会员
     * @param MemberService $service
     * @return string
     */
    public function becomeMember(MemberService $service)
    {
        $res = $service->order($this->requestData);
        return returnData($res);
    }

    /**
     * @title  各级会员名称
     * @return string
     * @throws \Exception
     */
    public function memberName()
    {
        $list = (new MemberVdc())->where(['status' => 1])->field('level,name')->select()->toArray();
        return returnData($list);
    }

    /**
     * @title  刷新判断自己是否可以升级
     * @param MemberService $service
     * @return string
     * @throws \Exception
     */
    public function refreshLevel(MemberService $service)
    {
        $res = $service->memberUpgrade($this->request->param('uid'), false);
        return returnMsg($res);
    }

    public function info(MemberModel $model)
    {

    }
}