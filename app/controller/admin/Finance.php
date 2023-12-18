<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 财务模块Controller]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\admin;


use app\BaseController;
use app\lib\exceptions\ServiceException;
use app\lib\models\ConverAmount;
use app\lib\models\CrowdfundingBalanceDetail;
use app\lib\models\FundsWithdraw;
use app\lib\models\HealthyBalanceConver;
use app\lib\models\HealthyBalanceDetail;
use app\lib\models\RechargeLink;
use app\lib\models\RechargeLinkDetail;
use app\lib\models\UserExtraWithdraw;
use app\lib\models\Withdraw;
use app\lib\services\Finance as FinanceService;

class Finance extends BaseController
{
    protected $middleware = [
        'checkAdminToken',
        'checkRule',
        'OperationLog',
    ];

    /**
     * @title  全部用户提现申请列表
     * @param Withdraw $model
     * @return string
     * @throws \Exception
     */
    public function withdrawList(Withdraw $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  提现数据汇总面板
     * @param Withdraw $model
     * @return string
     * @throws \Exception
     */
    public function withdrawSummary(Withdraw $model)
    {
        $info = $model->summary($this->requestData);
        return returnData($info);
    }

    /**
     * @title  审核用户提现申请
     * @param FinanceService $service
     * @return mixed
     */
    public function CheckWithdraw(FinanceService $service)
    {
        $res = $service->checkUserWithdraw($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  批量审核用户提现申请
     * @param FinanceService $service
     * @return mixed
     */
    public function batchCheckWithdraw(FinanceService $service)
    {
        $res = $service->batchCheckUserWithdraw($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  提现详情
     * @param Withdraw $model
     * @return string
     */
    public function withdrawDetail(Withdraw $model)
    {
        $row = $model->detail($this->requestData);
        return returnData($row);
    }

    /**
     * @title  财务备注-资金提现记录列表
     * @param FundsWithdraw $model
     * @return string
     * @throws \Exception
     */
    public function fundsWithdrawList(FundsWithdraw $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  财务备注-提交新资金提现记录
     * @param FundsWithdraw $model
     * @return string
     * @throws \Exception
     */
    public function newFundsWithdraw(FundsWithdraw $model)
    {
        $res = $model->DBNew($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  充值美丽金
     * @param CrowdfundingBalanceDetail $model
     * @return string
     */
    public function rechargeCrowdBalance(CrowdfundingBalanceDetail $model)
    {
        $res = $model->rechargeCrowdBalance($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  美丽金充值明细
     * @param CrowdfundingBalanceDetail $model
     * @return string
     * @throws \Exception
     */
    public function crowdBalanceList(CrowdfundingBalanceDetail $model)
    {
        $data = $this->requestData;
        $data['change_type'] = [1, 7, 8, 9, 12];
        $data['notSearId'] = ['3482849','3482848','3239852','3239851','3256414','3256415','3257505','3257506','5034350','5034351'];
        $list = $model->list($data);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  充值健康豆
     * @param HealthyBalanceDetail $model
     * @return string
     */
    public function rechargeHealthyBalance(HealthyBalanceDetail $model)
    {
        $res = $model->rechargeHealthyBalance($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  健康豆充值明细
     * @param HealthyBalanceDetail $model
     * @return string
     * @throws \Exception
     */
    public function healthyBalanceList(HealthyBalanceDetail $model)
    {
        $data = $this->requestData;
        $data['change_type'] = [1, 4, 5, 6];
        $list = $model->list($data);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  团队充值业绩明细列表
     * @param RechargeLinkDetail $model
     * @throws \Exception
     * @return string
     */
    public function rechargeRecordDetail(RechargeLinkDetail $model)
    {
        $data = $this->requestData;
        $list = $model->list($data);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  用户下级充值额度进度查询
     * @param RechargeLink $model
     * @throws \Exception
     * @return string
     */
    public function userRechargeRate(RechargeLink $model)
    {
        $info = $model->userSubordinateRechargeRate($this->requestData);
        return returnData($info);
    }

    /**
     * @description: 健康豆转换记录表
     * @param {HealthyBalanceConver} $model
     * @return {*}
     */    
    public function healthyBalanceConverList(HealthyBalanceConver $model)
    {
        $info = $model->list($this->requestData);
        return returnData($info);
    }

    /**
     * @description: 查找健康豆转出额度
     * @param {ConverAmount} $model
     * @return {*}
     */    
    public function healthyBalanceAmount(ConverAmount $model)
    {
        $info = $model->amount();
        return returnData($info);
    }
}