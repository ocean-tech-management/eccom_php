<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 众筹活动模块Controller]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\controller\admin;


use app\BaseController;
use app\lib\models\ActivityGoods;
use app\lib\models\ActivityGoodsSku;
use app\lib\models\CrowdfundingActivity;
use app\lib\models\CrowdfundingActivityGoods;
use app\lib\models\CrowdfundingActivityGoodsSku;
use app\lib\models\CrowdfundingBalanceDetail;
use app\lib\models\CrowdfundingFreezeCustom;
use app\lib\models\CrowdfundingLottery;
use app\lib\models\CrowdfundingLotteryApply;
use app\lib\models\CrowdfundingPeriod;
use app\lib\models\CrowdfundingPeriodSaleDuration;
use app\lib\models\CrowdfundingSystemConfig;
use app\lib\models\CrowdfundingWithdrawCustom;
use app\lib\models\GoodsSku;
use app\lib\models\OfflineRecharge;
use app\lib\models\Order as OrderModel;
use app\lib\models\PtActivity;
use app\lib\models\Activity as ActivityModel;
use app\lib\models\PtGoods;
use app\lib\models\PtGoodsSku;
use app\lib\services\CrowdFunding;

class CrowdFund extends BaseController
{
    protected $middleware = [
        'checkAdminToken',
        'checkRule',
        'OperationLog',
    ];

    /**
     * @title  区列表
     * @param CrowdfundingActivity $model
     * @return string
     * @throws \Exception
     */
    public function activityList(CrowdfundingActivity $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  新增区
     * @param CrowdfundingActivity $model
     * @return string
     */
    public function activityCreate(CrowdfundingActivity $model)
    {
        $data = $this->requestData;
        $res = $model->DBNew($data);
        return returnMsg($res);
    }

    /**
     * @title  活动详情
     * @param CrowdfundingActivity $model
     * @return string
     * @throws \Exception
     */
    public function activityInfo(CrowdfundingActivity $model)
    {
        $info = $model->info($this->requestData);
        return returnData($info);
    }

    /**
     * @title  编辑活动
     * @param CrowdfundingActivity $model
     * @return string
     */
    public function activityUpdate(CrowdfundingActivity $model)
    {
        $res = $model->edit($this->requestData);
        return returnMsg($res);
    }


    /**
     * @title  上下架活动
     * @param CrowdfundingActivity $model
     * @return string
     */
    public function activityUpOrDown(CrowdfundingActivity $model)
    {
        $res = $model->upOrDown($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  删除活动
     * @param CrowdfundingActivity $model
     * @return string
     */
    public function activityDelete(CrowdfundingActivity $model)
    {
        $res = $model->del($this->request->param('activity_code'));
        return returnMsg($res);
    }

    /**
     * @title  期列表
     * @param CrowdfundingPeriod $model
     * @return string
     * @throws \Exception
     */
    public function periodList(CrowdfundingPeriod $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  新增期
     * @param CrowdfundingPeriod $model
     * @throws \Exception
     * @return string
     */
    public function periodCreate(CrowdfundingPeriod $model)
    {
        $data = $this->requestData;
        $res = $model->DBNew($data);
        return returnMsg($res);
    }

    /**
     * @title  期详情
     * @param CrowdfundingPeriod $model
     * @return string
     * @throws \Exception
     */
    public function periodInfo(CrowdfundingPeriod $model)
    {
        $info = $model->info($this->requestData);
        return returnData($info);
    }

    /**
     * @title  编辑期
     * @param CrowdfundingPeriod $model
     * @return string
     */
    public function periodUpdate(CrowdfundingPeriod $model)
    {
        $res = $model->edit($this->requestData);
        return returnMsg($res);
    }


    /**
     * @title  上下架期
     * @param CrowdfundingPeriod $model
     * @return string
     */
    public function periodUpOrDown(CrowdfundingPeriod $model)
    {
        $res = $model->upOrDown($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  删除期
     * @param CrowdfundingPeriod $model
     * @return string
     */
    public function periodDelete(CrowdfundingPeriod $model)
    {
        $res = $model->del($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  新增/编辑商品
     * @param CrowdfundingActivityGoods $model
     * @return string
     * @throws \Exception
     */
    public function periodCreateOrUpdateGoods(CrowdfundingActivityGoods $model)
    {
        $res = $model->DBNewOrEdit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  活动商品SPU详情
     * @param CrowdfundingActivityGoods $model
     * @return string
     * @throws \Exception
     */
    public function periodGoodsInfo(CrowdfundingActivityGoods $model)
    {
        $info = $model->list($this->requestData);
        return returnData($info['list'], $info['pageTotal']);
    }

    /**
     * @title  活动商品SKU详情
     * @param CrowdfundingActivityGoodsSku $model
     * @return string
     * @throws \Exception
     */
    public function periodGoodsSkuInfo(CrowdfundingActivityGoodsSku $model)
    {
        $info = $model->list($this->requestData);
        return returnData($info['list'], $info['pageTotal']);
    }


    /**
     * @title  删除商品
     * @param CrowdfundingActivityGoods $model
     * @return string
     * @throws \Exception
     */
    public function periodDeleteGoods(CrowdfundingActivityGoods $model)
    {
        $res = $model->del($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  更新活动商品排序
     * @param CrowdfundingActivityGoods $model
     * @return string
     */
    public function periodUpdateGoodsSort(CrowdfundingActivityGoods $model)
    {
        $res = $model->updateSort($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  删除期商品SKU
     * @param CrowdfundingActivityGoodsSku $model
     * @return string
     * @throws \Exception
     */
    public function periodDeleteGoodsSku(CrowdfundingActivityGoodsSku $model)
    {
        $res = $model->del($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  期开放时间段列表
     * @param CrowdfundingPeriodSaleDuration $model
     * @return string
     * @throws \Exception
     */
    public function periodDurationList(CrowdfundingPeriodSaleDuration $model)
    {
        $info = $model->list($this->requestData);
        return returnData($info['list'], $info['pageTotal']);
    }

    /**
     * @title  编辑期开放时间段
     * @param CrowdfundingPeriodSaleDuration $model
     * @return string
     * @throws \Exception
     */
    public function periodDurationNewOrUpdate(CrowdfundingPeriodSaleDuration $model)
    {
        $res = $model->DBNewOrEdit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  删除期开放时间段
     * @param CrowdfundingPeriodSaleDuration $model
     * @return string
     * @throws \Exception
     */
    public function periodDurationDelete(CrowdfundingPeriodSaleDuration $model)
    {
        $res = $model->del($this->request->param('duration_code'));
        return returnMsg($res);
    }

    /**
     * @title  所有活动名称列表
     * @param ActivityModel $model
     * @return string
     * @throws \Exception
     */
    public function allActivity(ActivityModel $model)
    {
        $list = $model->allActivity($this->requestData);
        return returnData($list);
    }

    /**
     * @title  查看配置
     * @param CrowdfundingSystemConfig $model
     * @return string
     */
    public function configInfo(CrowdfundingSystemConfig $model)
    {
        $info = $model->info($this->requestData);
        return returnData($info);
    }

    /**
     * @title  修改配置
     * @param CrowdfundingSystemConfig $model
     * @return string
     */
    public function configUpdate(CrowdfundingSystemConfig $model)
    {
        $info = $model->DBEdit($this->requestData);
        return returnData($info);
    }

    /**
     * @title  编辑销售额
     * @param CrowdfundingPeriod $model
     * @return string
     */
    public function updateSalesPrice(CrowdfundingPeriod $model)
    {
        $res = $model->updateSalesPrice($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  编辑参与门槛
     * @param CrowdfundingPeriod $model
     * @return string
     */
    public function updateCondition(CrowdfundingPeriod $model)
    {
        $res = $model->updateCondition($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  强制完成期
     * @param CrowdfundingPeriod $model
     * @return string
     */
    public function completePeriod(CrowdfundingPeriod $model)
    {
        $res = $model->completePeriod($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  根据订单判断是否可以完成期
     * @param CrowdfundingPeriod $model
     * @return string
     */
    public function completePeriodByOrder(CrowdfundingPeriod $model)
    {
        $res = $model->completePeriodByOrder($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  福利专区订单列表
     * @param OrderModel $model
     * @return string
     * @throws \Exception
     */
    public function orderList(OrderModel $model)
    {
        $data = $this->requestData;
        $data['order_type'] = 6;
        $data['needCrowdDelayRewardOrder'] = true;
        $list = $model->list($data);
        return returnData($list['list'], $list['pageTotal'], 0, '成功', $list['total'] ?? 0);
    }

    /**
     * @title  抽奖列表
     * @param CrowdfundingLottery $model
     * @return string
     * @throws \Exception
     */
    public function lotteryList(CrowdfundingLottery $model)
    {
        $info = $model->list($this->requestData);
        return returnData($info['list'], $info['pageTotal']);
    }

    /**
     * @title  抽奖详情
     * @param CrowdfundingLottery $model
     * @return string
     * @throws \Exception
     */
    public function lotteryInfo(CrowdfundingLottery $model)
    {
        $info = $model->info($this->requestData);
        return returnData($info);
    }

    /**
     * @title  新增抽奖计划
     * @param CrowdfundingLottery $model
     * @return string
     * @throws \Exception
     */
    public function lotteryCreate(CrowdfundingLottery $model)
    {
        $res = $model->DBNew($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  编辑抽奖计划
     * @param CrowdfundingLottery $model
     * @return string
     * @throws \Exception
     */
    public function lotteryUpdate(CrowdfundingLottery $model)
    {
        $res = $model->DBEdit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  删除抽奖计划
     * @param CrowdfundingLottery $model
     * @return string
     * @throws \Exception
     */
    public function lotteryDelete(CrowdfundingLottery $model)
    {
        $res = $model->del($this->request->param('plan_sn'));
        return returnMsg($res);
    }

    /**
     * @title  众筹抽奖计划中奖明细
     * @param CrowdfundingLotteryApply $model
     * @return string
     * @throws \Exception
     */
    public function lotteryWinInfo(CrowdfundingLotteryApply $model)
    {
        $res = $model->winInfo($this->requestData);
        return returnData($res);
    }

    /**
     * @title  线下充值提交记录列表
     * @param OfflineRecharge $model
     * @return string
     * @throws \Exception
     */
    public function offlineRechargeList(OfflineRecharge $model)
    {
        $info = $model->list($this->requestData);
        return returnData($info['list'], $info['pageTotal']);
    }

    /**
     * @title  线下充值提交记录审核
     * @param OfflineRecharge $model
     * @return string
     * @throws \Exception
     */
    public function offlineRechargeCheck(OfflineRecharge $model)
    {
        $res = $model->check($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  线下充值提交记录发放美丽金
     * @param OfflineRecharge $model
     * @return string
     * @throws \Exception
     */
    public function offlineRechargeGrantPrice(OfflineRecharge $model)
    {
        $res = $model->grantPrice($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  修改线下充值配置
     * @param CrowdfundingSystemConfig $model
     * @return string
     */
    public function offlineConfigUpdate(CrowdfundingSystemConfig $model)
    {
        $res = $model->DBEdit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  修改线下充值配置
     * @param CrowdfundingSystemConfig $model
     * @return string
     */
    public function offlineConfigInfo(CrowdfundingSystemConfig $model)
    {
        $data['searField'] = 2;
        $res = $model->info($data);
        return returnData($res);
    }

    /**
     * @title  美丽金余额明细
     * @param CrowdfundingBalanceDetail $model
     * @return string
     * @throws \Exception
     */
    public function crowdFundingBalance(CrowdfundingBalanceDetail $model)
    {
        $data = $this->requestData;
        $list = $model->list($data);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title 美丽金可提现自定义额度列表
     * @param CrowdfundingWithdrawCustom $model
     * @return string
     */
    public function crowdfundingWithdrawCustom(CrowdfundingWithdrawCustom $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title 新增美丽金可提现自定义额度
     * @param CrowdfundingWithdrawCustom $model
     * @return string
     */
    public function createWithdrawCustom(CrowdfundingWithdrawCustom $model)
    {
        $res = $model->DBNew($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title 美丽金冻结自定义额度列表
     * @param CrowdfundingFreezeCustom $model
     * @return string
     */
    public function crowdfundingFreezeCustom(CrowdfundingFreezeCustom $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title 新增美丽金冻结自定义额度
     * @param CrowdfundingFreezeCustom $model
     * @return string
     */
    public function createFreezeCustom(CrowdfundingFreezeCustom $model)
    {
        $res = $model->DBNew($this->requestData);
        return returnMsg($res);
    }

    public function crowdfundingActivityAdvanceInfo()
    {

    }

    /**
     * @title 汇总统计用户众筹余额明细详情
     * @param CrowdfundingBalanceDetail $model
     * @return string
     */
    public function userCrowdBalanceSummaryList(CrowdfundingBalanceDetail $model)
    {
        $list = $model->userCrowdBalanceSummaryList($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title 三级联动查询区轮期
     * @param CrowdfundingActivity $model
     * @throw \Exception
     * @return mixed
     */
    public function crowdfundingSear(CrowdfundingActivity $model)
    {
        $list = $model->crowdingSear($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }
}