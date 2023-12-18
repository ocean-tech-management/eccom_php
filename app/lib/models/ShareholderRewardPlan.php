<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 股东奖发放计划模块Model]
// +----------------------------------------------------------------------



namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\ServiceException;
use app\lib\services\CodeBuilder;
use think\facade\Db;

class ShareholderRewardPlan extends BaseModel
{
    protected $belong = 1;

    /**
     * @title  股东奖发放计划列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear = []): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('plan_name|plan_sn', $sear['keyword']))];
        }
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->where($map)->withoutField('update_time,id')->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('create_time desc')->select()->each(function($item){
            if(!empty($item['grant_time'])){
                $item['grant_time'] = timeToDateFormat($item['grant_time']);
            }
        })->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  详情
     * @param array $data
     * @return mixed
     */
    public function info(array $data)
    {
        $info = self::where(['plan_sn'=>$data['plan_sn']])->findOrEmpty()->toArray();
        return $info;
    }

    /**
     * @title  新增股东奖计划
     * @param array $data
     * @return PropagandaRewardPlan|\think\Model
     */
    public function DBNew(array $data)
    {
        $data['plan_sn'] = (new CodeBuilder())->buildPropagandaRewardPlanCode();
        if ($data['total_reward_price'] <= 0) {
            throw new ServiceException(['msg' => '奖池总金额不可小于0']);
        }
        $res = self::create($data);
        return $res;
    }

    /**
     * @title  编辑股东奖计划
     * @param array $data
     * @return bool
     */
    public function DBEdit(array $data)
    {
        if ($data['total_reward_price'] <= 0) {
            throw new ServiceException(['msg' => '奖池总金额不可小于0']);
        }
        $info = $this->where(['plan_sn' => $data['plan_sn']])->findOrEmpty()->toArray();
        if (empty($info)) {
            throw new ServiceException(['msg' => '查无有效计划']);
        }
        if ($info['grant_res'] != 4) {
            throw new ServiceException(['msg' => '该计划正在发放奖励中或已完成发放,无法修改']);
        }
        $res = self::update($data, ['plan_sn' => $data['plan_sn'], 'status' => [1, 2], 'grant_res' => [4]]);
        return judge($res);
    }

    /**
     * @title  删除股东奖计划
     * @param array $data
     * @return PropagandaRewardPlan|\think\Model
     */
    public function DBDel(array $data)
    {
        $info = $this->where(['plan_sn' => $data['plan_sn']])->findOrEmpty()->toArray();
        if (empty($info)) {
            throw new ServiceException(['msg' => '查无有效计划']);
        }
        if ($info['grant_res'] != 4) {
            throw new ServiceException(['msg' => '该计划正在发放奖励中或已完成发放,无法修改']);
        }
        $res = self::update(['status' => -1], ['plan_sn' => $data['plan_sn'], 'status' => [1, 2], 'grant_res' => [4]]);
        return $res;
    }
}