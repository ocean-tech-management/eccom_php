<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\controller\api\v1;


use app\BaseController;
use app\lib\exceptions\ServiceException;
use app\lib\models\Member as MemberModel;
use app\lib\models\Member;
use app\lib\models\MemberVdc;
use app\lib\models\Order;
use app\lib\services\Divide;
use app\lib\models\User;
use app\lib\models\Divide as DivideModel;
use app\lib\services\UserSummary;
use think\facade\Db;

class Summary extends BaseController
{
    protected $middleware = [
        'checkApiToken',
    ];

    /**
     * @title  我的业绩
     * @param UserSummary $service
     * @return string
     * @throws \Exception
     */
    public function myPerformance(UserSummary $service)
    {
        $info = $service->myPerformance();
        return returnData($info);
    }

    /**
     * @title  我的业绩(团队代理人)
     * @param UserSummary $service
     * @return string
     * @throws \Exception
     */
    public function myTeamPerformance(UserSummary $service)
    {
        $info = $service->myTeamPerformance();
        return returnData($info);
    }

    /**
     * @title  我的直推团队
     * @param UserSummary $service
     * @return mixed
     * @throws \Exception
     */
    public function myDirectTeam(UserSummary $service)
    {
        $info = $service->myDirectTeam();
        return returnData($info);
    }

    /**
     * @title  我的全部团队(数量及人数等级汇总)
     * @param UserSummary $service
     * @return mixed
     * @throws \Exception
     */
    public function myAllTeamSummary(UserSummary $service)
    {
        $info = $service->myAllTeamSummary();
        return returnData($info);
    }

    /**
     * @title  我的全部团队
     * @param array $aData
     * @return mixed
     * @throws \Exception
     */
    public function myAllTeam(array $aData = [])
    {
        $info = (new UserSummary())->myAllTeam($aData ?? []);
        return returnData($info);
    }

    /**
     * @title  我的团队数据汇总面板中详细的用户列表
     * @return string
     * @throws \Exception
     */
    public function allTeamSpecific()
    {
        $list = (new UserSummary())->allTeamSpecific($this->requestData);
        return returnData($list);
    }

    /**
     * @title  查找我的团队
     * @return string
     * @throws \Exception
     */
    public function searMyTeam()
    {
        $data = $this->requestData;
        $first = (new MemberModel())->searTeamUser($data);
        $list = $this->myAllTeam(['userList' => $first, 'page' => $data['page'] ?? 1, 'searType' => $data['searType'] ?? 1])->getData('data');
        if (!empty($list) && !empty($list['data'])) {
            $list = $list['data'] ?? [];
        }
        return returnData($list);
    }

    /**
     * @title  我的同级直推团队
     * @param UserSummary $service
     * @return string
     * @throws \Exception
     */
    public function sameLevelDirectTeam(UserSummary $service)
    {
        $info = $service->sameLevelDirectTeam();
        return returnData($info);
    }

    /**
     * @title  我的同级间推团队
     * @param UserSummary $service
     * @return string
     * @throws \Exception
     */
    public function nextDirectTeam(UserSummary $service)
    {
        $info = $service->nextDirectTeam();
        return returnData($info);
    }

    /**
     * @title  个人余额报表
     * @param UserSummary $service
     * @return mixed
     * @throws \Exception
     */
    public function balanceAll(UserSummary $service)
    {
        $info = $service->balanceAll();
        return returnData($info);
    }

    /**
     * @title  筛选用户分润收益
     * @param UserSummary $service
     * @return mixed
     * @throws \Exception
     */
    public function userTeamIncome(UserSummary $service)
    {
        $info = $service->userTeamIncome();
        return returnData($info);
    }

    /**
     * @title  用户收益情况
     * @param UserSummary $service
     * @return string
     */
    public function userAllInCome(UserSummary $service)
    {
        $info = $service->userAllInCome();
        return returnData($info);
    }

    /**
     * @title  用户团队全部成员数量
     * @param UserSummary $service
     * @return string
     */
    public function teamAllUserNumber(UserSummary $service)
    {
        $info = $service->teamAllUserNumber();
        return returnData($info);
    }

    /**
     * @title  团队结构新查询(汇总)
     * @param UserSummary $service
     * @throws \Exception
     * @return string
     */
    public function newTeamUserSummary(UserSummary $service)
    {
        $data = $this->requestData;
        $info = $service->userTeamSummaryNew($data);
        return returnData($info);
    }

    /**
     * @title  团队结构新查询
     * @param UserSummary $service
     * @return string
     * @throws \Exception
     */
    public function newTeamUser(UserSummary $service)
    {
        $data = $this->requestData;
        $data['tokenUid'] = $this->request->tokenUid;
        $info = $service->userTeamSummaryNew($data);
        if (in_array($data['searType'], [3, 4])) {
            return json($info);
        } else {
            return returnData($info);
        }
    }


}