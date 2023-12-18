<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 众筹模块Controller]
// +----------------------------------------------------------------------



namespace app\controller\api\v1;


use app\BaseController;
use app\lib\models\AdvanceCardDetail;
use app\lib\models\CrowdfundingActivity;
use app\lib\models\CrowdfundingActivityGoods;
use app\lib\models\CrowdfundingBalanceDetail;
use app\lib\models\CrowdfundingLotteryApply;
use app\lib\models\CrowdfundingPeriod;
use app\lib\models\CrowdfundingSystemConfig;
use app\lib\services\CrowdFunding;

class CrowdFund extends BaseController
{
    /**
     * @title  活动(区)列表
     * @param CrowdfundingActivity $model
     * @return string
     * @throws \Exception
     */
    public function activityList(CrowdfundingActivity $model)
    {
        $list = $model->cHomeList($this->requestData);
        return returnData($list);
    }

    /**
     * @title  活动(期)列表
     * @param CrowdfundingPeriod $model
     * @return string
     * @throws \Exception
     */
    public function periodList(CrowdfundingPeriod $model)
    {
        $list = $model->cHomeList($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  活动(期)详情
     * @param CrowdfundingPeriod $model
     * @return string
     * @throws \Exception
     */
    public function periodInfo(CrowdfundingPeriod $model)
    {
        $list = $model->cHomeList($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  期对应的商品列表
     * @param CrowdfundingActivityGoods $model
     * @return string
     * @throws \Exception
     */
    public function goodsList(CrowdfundingActivityGoods $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  获取用户收益面板数据
     * @param CrowdfundingBalanceDetail $model
     * @return string
     */
    public function incomeDetail(CrowdfundingBalanceDetail $model)
    {
        $info = $model->getUserIncomeDetail($this->requestData);
        return returnData($info);
    }

    /**
     * @title  规则
     * @param CrowdfundingSystemConfig $model
     * @return string
     */
    public function config(CrowdfundingSystemConfig $model)
    {
        $info = $model->cInfo(['type' => 1]);
        return returnData($info);
    }

    /**
     * @title  提前卡明细
     * @param AdvanceCardDetail $model
     * @return string
     * @throws \Exception
     */
    public function advanceCardDetail(AdvanceCardDetail $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  中奖明细
     * @param CrowdfundingLotteryApply $model
     * @return string
     * @throws \Exception
     */
    public function lotteryWinInfo(CrowdfundingLotteryApply $model)
    {
        $info = $model->winInfo($this->requestData);
        return returnData($info);
    }

    /**
     * @title  用户报名抽奖
     * @param CrowdfundingLotteryApply $model
     * @return string
     * @throws \Exception
     */
    public function applyLottery(CrowdfundingLotteryApply $model)
    {
        $res = $model->userApplyLottery($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  用户选择熔断方案
     * @param CrowdFunding $service
     * @throws \Exception
     * @return string
     */
    public function userChoosePeriodFusePlan(CrowdFunding $service)
    {
        $res = $service->userChoosePeriodFusePlanGroupByPeriod($this->requestData);
        return returnMsg($res);
    }

}