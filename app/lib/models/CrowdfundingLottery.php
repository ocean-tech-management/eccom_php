<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------



namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\ServiceException;
use app\lib\services\CodeBuilder;
use app\lib\services\Lottery;
use app\lib\subscribe\Timer;
use think\facade\Db;

class CrowdfundingLottery extends BaseModel
{
    public $lockCacheKey = 'lotteryDeal';

    /**
     * @title  抽奖列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear = []): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('title', $sear['keyword']))];
        }
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->where($map)->with(['activityTitle'])->withoutField('id,update_time')->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('create_time desc')->select()->each(function($item){
            if(!empty($item['lottery_time'])){
                $item['lottery_time'] = timeToDateFormat($item['lottery_time']);
            }
            if(!empty($item['apply_start_time'])){
                $item['apply_start_time'] = timeToDateFormat($item['apply_start_time']);
            }
            if(!empty($item['apply_end_time'])){
                $item['apply_end_time'] = timeToDateFormat($item['apply_end_time']);
            }
            if(!empty($item['lottery_start_time'])){
                $item['lottery_start_time'] = timeToDateFormat($item['lottery_start_time']);
            }
        })->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  后台-抽奖详情
     * @param array $data
     * @return array
     */
    public function info(array $data)
    {
        $info = $this->where(['plan_sn' => $data['plan_sn'], 'status' => $this->getStatusByRequestModule(1)])->findOrEmpty()->toArray();
        if(!empty($info)){
            $data['lottery_scope'] = json_decode($data['lottery_scope'], true);
            if(!empty($data['apply_start_time'])){
                $data['apply_start_time'] = timeToDateFormat($data['apply_start_time']);
            }
            if(!empty($data['apply_end_time'])){
                $data['apply_end_time'] = timeToDateFormat($data['apply_end_time']);
            }
            if(!empty($data['lottery_start_time'])){
                $data['lottery_start_time'] = timeToDateFormat($data['lottery_start_time']);
            }
            if(!empty($data['lottery_time'])){
                $data['lottery_time'] = timeToDateFormat($data['lottery_time']);
            }
        }
        return $info;
    }

    /**
     * @title  新增抽奖计划
     * @param array $data
     * @return bool
     */
    public function DBNew(array $data)
    {
        $existLottery = $this->where(['activity_code' => $data['activity_code'], 'round_number' => $data['round_number'], 'period_number' => $data['period_number'], 'status' => [1, 2]])->count();
        if (!empty($existLottery)) {
            throw new ServiceException(['msg' => '该期已存在抽奖, 请勿重复添加']);
        }
        if (empty($data['lottery_scope'] ?? [])) {
            throw new ServiceException(['msg' => '请添加对应的奖励等级']);
        }
        if(!empty($data['apply_start_time'])){
            $data['apply_start_time'] = strtotime($data['apply_start_time']);
        }
        if(!empty($data['apply_end_time'])){
            $data['apply_end_time'] = strtotime($data['apply_end_time']);
        }
        if(!empty($data['lottery_start_time'])){
            $data['lottery_start_time'] = strtotime($data['lottery_start_time']);
        }
        $data['lottery_scope'] = json_encode($data['lottery_scope'],256);
        $data['plan_sn'] = (new CodeBuilder())->buildCrowdFundingLotteryPlanSn();
        $res = self::save($data);
        return judge($res);
    }

    /**
     * @title  编辑抽奖计划
     * @param array $data
     * @return bool
     */
    public function DBEdit(array $data)
    {
        if (!empty(cache($this->lockCacheKey . $data['plan_sn']))) {
            throw new ServiceException(['msg' => '该期正在操作抽奖中!请勿继续编辑']);
        }
        $info = self::where(['plan_sn' => $data['plan_sn'], 'status' => [1, 2]])->findOrEmpty()->toArray();
        if (empty($info)) {
            throw new ServiceException(['msg' => '查无有效抽奖计划']);
        }
        if ($info['lottery_status'] != 3) {
            throw new ServiceException(['msg' => '仅允许报名中的计划编辑信息!']);
        }
        if (!empty($data['lottery_scope'])) {
            $data['lottery_scope'] = json_encode($data['lottery_scope'], 256);
        }
        if (empty($data['lottery_scope'] ?? null)) {
            throw new ServiceException(['msg' => '请添加对应的奖励等级']);
        }
        if(!empty($data['apply_start_time'] ?? null)){
            $data['apply_start_time'] = strtotime($data['apply_start_time']);
        }
        if(!empty($data['apply_end_time'] ?? null)){
            $data['apply_end_time'] = strtotime($data['apply_end_time']);
        }
        if(!empty($data['lottery_start_time'])){
            $data['lottery_start_time'] = strtotime($data['lottery_start_time']);
        }
        $res = self::update($data, ['plan_sn' => $data['plan_sn'], 'status' => [1, 2], 'lottery_status' => [3]]);
        return judge($res);
    }

    /**
     * @title  删除计划
     * @param string $planSn
     * @return bool
     */
    public function del(string $planSn)
    {
        $info = self::where(['plan_sn' => $planSn, 'status' => [1, 2]])->findOrEmpty()->toArray();
        if (empty($info)) {
            throw new ServiceException(['msg' => '查无有效抽奖计划']);
        }
        if ($info['lottery_status'] != 3) {
            throw new ServiceException(['msg' => '仅允许报名中的计划删除']);
        }
        $res = self::update(['status' => -1], ['plan_sn' => $planSn, 'status' => [1, 2], 'lottery_status' => [3]]);
        //顺便删除报名列表
        CrowdfundingLotteryApply::update(['status' => -1], ['plan_sn' => $planSn, 'status' => [1, 2]]);
        return judge($res);
    }

    /**
     * @title  定时任务查询是否有开奖的计划
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    public function timeForLottery(array $data= [])
    {
        $map[] = ['lottery_status', '=', 3];
        $map[] = ['status', '=', 1];
        $map[] = ['lottery_start_time', '<=', time()];
        $timeToStartLottery = self::where($map)->column('plan_sn');
        if (!empty($timeToStartLottery)) {
            $lotteryService = (new Lottery());
            foreach ($timeToStartLottery as $key => $value) {
                $lotteryService->crowdLottery(['plan_sn' => $value]);
            }
        }
        return true;
    }

    public function activityTitle()
    {
        return $this->hasOne('CrowdfundingActivity', 'activity_code', 'activity_code')->bind(['activity_title' => 'title']);
    }
}