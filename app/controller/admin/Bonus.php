<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 额外奖励模块Controller]
// +----------------------------------------------------------------------



namespace app\controller\admin;


use app\BaseController;
use app\lib\models\PropagandaReward;
use app\lib\models\PropagandaRewardPlan;
use app\lib\models\ShareholderMember;
use app\lib\models\ShareholderReward;
use app\lib\models\ShareholderRewardPlan;
use app\lib\models\TeamMember;
use app\lib\services\PropagandaReward as PropagandaRewardService;
use app\lib\models\Divide;
use think\facade\Queue;

class Bonus extends BaseController
{
    protected $middleware = [
        'checkAdminToken',
        'checkRule',
        'OperationLog',
    ];

    /**
     * @title  广宣奖规则列表
     * @param PropagandaReward $model
     * @return string
     * @throws \Exception
     */
    public function propagandaRewardRuleList(PropagandaReward $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  广宣奖规则详情
     * @param PropagandaReward $model
     * @return string
     * @throws \Exception
     */
    public function propagandaRewardRuleInfo(PropagandaReward $model)
    {
        $info = $model->info($this->requestData);
        return returnData($info);
    }

    /**
     * @title  新增广宣奖规则
     * @param PropagandaReward $model
     * @return string
     */
    public function propagandaRewardRuleCreate(PropagandaReward $model)
    {
        $res = $model->DBEdit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  编辑广宣奖规则
     * @param PropagandaReward $model
     * @return string
     */
    public function propagandaRewardRuleUpdate(PropagandaReward $model)
    {
        $res = $model->DBEdit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  广宣奖计划列表
     * @param PropagandaRewardPlan $model
     * @return string
     * @throws \Exception
     */
    public function propagandaRewardPlanList(PropagandaRewardPlan $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  新增广宣奖计划
     * @param PropagandaRewardPlan $model
     * @return string
     * @throws \Exception
     */
    public function propagandaRewardPlanCreate(PropagandaRewardPlan $model)
    {
        $res = $model->DBNew($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  广宣奖详情
     * @param PropagandaRewardPlan $model
     * @return string
     */
    public function propagandaRewardPlanInfo(PropagandaRewardPlan $model)
    {
        $info = $model->info($this->requestData);
        return returnData($info);
    }

    /**
     * @title  编辑广宣奖计划
     * @param PropagandaRewardPlan $model
     * @return string
     * @throws \Exception
     */
    public function propagandaRewardPlanUpdate(PropagandaRewardPlan $model)
    {
        $res = $model->DBEdit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  删除广宣奖计划
     * @param PropagandaRewardPlan $model
     * @return string
     * @throws \Exception
     */
    public function propagandaRewardPlanDelete(PropagandaRewardPlan $model)
    {
        $res = $model->DBDel($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  广宣奖奖励发放明细列表
     * @param Divide $model
     * @return string
     */
    public function planRewardList(Divide $model)
    {
        $data = $this->requestData;
        $list = $model->PropagandaRewardList($data);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  查看广宣奖发放奖励的人群
     * @param PropagandaRewardService $service
     * @return string
     */
    public function showRewardUserForPlan(PropagandaRewardService $service)
    {
        $data = $this->requestData;
        $data['show_number'] = true;
        $list = $service->reward($data);
        return returnData($list);
    }

    /**
     * @title  发放广宣奖
     * @param PropagandaRewardService $service
     * @return string
     */
    public function propagandaReward(PropagandaRewardService $service)
    {
        $data = $this->requestData;
        if (empty($data['plan_sn'] ?? null)) {
            return returnMsg(false);
        }
        $queueData = $data;
        $queueData['autoType'] = 4;
        //默认指定人人群无需遵守实付金额规则
        if(!empty($queueData['selectUser'] ?? false)){
            $queueData['selectUserFollowRule'] = true;
        }
        $res = Queue::push('app\lib\job\Auto', $queueData, config('system.queueAbbr') . 'Auto');
        PropagandaRewardPlan::update(['grant_res' => 3], ['plan_sn' => $data['plan_sn'], 'status' => 1, 'grant_res' => 4]);
//        $res = (new \app\lib\services\PropagandaReward())->reward($queueData);
        return returnMsg(judge($res));
    }


    /**
     * @title  股东奖规则列表
     * @param ShareholderReward $model
     * @return string
     * @throws \Exception
     */
    public function shareholderRewardRuleList(ShareholderReward $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  股东奖规则详情
     * @param ShareholderReward $model
     * @return string
     * @throws \Exception
     */
    public function shareholderRewardRuleInfo(ShareholderReward $model)
    {
        $info = $model->info($this->requestData);
        return returnData($info);
    }

    /**
     * @title  新增股东奖规则
     * @param ShareholderReward $model
     * @return string
     */
    public function shareholderRewardRuleCreate(ShareholderReward $model)
    {
        $res = $model->DBEdit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  编辑股东奖规则
     * @param ShareholderReward $model
     * @return string
     */
    public function shareholderRewardRuleUpdate(ShareholderReward $model)
    {
        $res = $model->DBEdit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  股东奖计划列表
     * @param ShareholderRewardPlan $model
     * @return string
     * @throws \Exception
     */
    public function shareholderRewardPlanList(ShareholderRewardPlan $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  新增股东奖计划
     * @param ShareholderRewardPlan $model
     * @return string
     * @throws \Exception
     */
    public function shareholderRewardPlanCreate(ShareholderRewardPlan $model)
    {
        $res = $model->DBNew($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  股东奖详情
     * @param ShareholderRewardPlan $model
     * @return string
     */
    public function shareholderRewardPlanInfo(ShareholderRewardPlan $model)
    {
        $info = $model->info($this->requestData);
        return returnData($info);
    }

    /**
     * @title  编辑股东奖计划
     * @param ShareholderRewardPlan $model
     * @return string
     * @throws \Exception
     */
    public function shareholderRewardPlanUpdate(ShareholderRewardPlan $model)
    {
        $res = $model->DBEdit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  删除股东奖计划
     * @param ShareholderRewardPlan $model
     * @return string
     * @throws \Exception
     */
    public function shareholderRewardPlanDelete(ShareholderRewardPlan $model)
    {
        $res = $model->DBDel($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  股东奖奖励发放明细列表
     * @param Divide $model
     * @return string
     */
    public function shareholderPlanRewardList(Divide $model)
    {
        $data = $this->requestData;
        $list = $model->shareholderRewardList($data);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  查看股东奖发放奖励的人群
     * @param PropagandaRewardService $service
     * @return string
     */
    public function showShareholderRewardUserForPlan(PropagandaRewardService $service)
    {
        $data = $this->requestData;
        $data['show_number'] = true;
        $list = $service->shareholderReward($data);
        return returnData($list);
    }

    /**
     * @title  发放股东奖
     * @param PropagandaRewardService $service
     * @return string
     */
    public function shareholderReward(PropagandaRewardService $service)
    {
        $data = $this->requestData;
        if (empty($data['plan_sn'] ?? null)) {
            return returnMsg(false);
        }
        $queueData = $data;
        $queueData['autoType'] = 5;
        //默认指定人人群无需遵守规则
        if(!empty($queueData['selectUser'] ?? false)){
            $queueData['selectUserFollowRule'] = false;
        }
        $res = Queue::push('app\lib\job\Auto', $queueData, config('system.queueAbbr') . 'Auto');
        ShareholderRewardPlan::update(['grant_res' => 3], ['plan_sn' => $data['plan_sn'], 'status' => 1, 'grant_res' => 4]);
//        $res = (new \app\lib\services\PropagandaReward())->reward($queueData);
        return returnMsg(judge($res));
    }

    /**
     * @title  指定股东
     * @param ShareholderMember $model
     * @return string
     */
    public function assignShareholderMember(ShareholderMember $model)
    {
        $res = $model->assign($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  股东列表
     * @param ShareholderMember $model
     * @return string
     * @throws \Exception
     */
    public function shareholderMemberList(ShareholderMember $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }
}