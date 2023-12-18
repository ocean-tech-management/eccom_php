<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\admin;


use app\BaseController;
use app\lib\exceptions\MemberException;
use app\lib\models\MemberVdc;
use app\lib\services\Member as MemberService;
use app\lib\models\Member as MemberModel;

class Member extends BaseController
{
    protected $middleware = [
        'checkAdminToken',
        'checkRule' => ['except' => ['vdcList']],
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
        $list = $model->list($this->requestData);
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

    /**
     * @title  指定会员等级
     * @param MemberService $service
     * @return string
     */
    public function assign(MemberService $service)
    {
        $res = $service->assignUserLevel($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  取消会员资格
     * @param MemberService $service
     * @return string
     * @throws \Exception
     */
    public function revoke(MemberService $service)
    {
//        throw new MemberException(['msg'=>'功能升级中, 重新开放时间请联系运维人员']);
        $data = $this->requestData;
        $uid = $data['uid'];
        $parentUid = $data['parent_uid'] ?? null;
        $res = $service->revokeUserMember($uid, $parentUid);
        return returnMsg($res);
    }

    /**
     * @title  导入老用户数据(有序数据,上级已存在的同个团队的新成员数据)
     * @param MemberService $service
     * @return string
     * @throws \PhpOffice\PhpSpreadsheet\Reader\Exception
     * @throws \PhpOffice\PhpSpreadsheet\Exception
     */
    public function importMember(MemberService $service)
    {
        $res = $service->importMember($this->requestData);
        return returnData($res);
    }

    /**
     * @title  导入老用户数据(无序数据,可能存在部分存在部分不存在的团队成员,数据可能是混乱的)
     * @param MemberService $service
     * @return string
     * @throws \Exception
     */
    public function importConfusionTeam(MemberService $service)
    {
        $res = $service->importConfusionTeam();
        return returnData($res);
    }

}