<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 团队奖励模块Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use think\facade\Db;
use app\lib\validates\Reward as RewardValidate;

class Reward extends BaseModel
{

    protected $field = ['name', 'user_level', 'level', 'recommend_number', 'train_level', 'train_number', 'team_number', 'reward_scale', 'reward_other', 'remark', 'freed_scale', 'freed_cycle', 'status'];
    protected $validateFields = ['recommend_number', 'reward_scale', 'team_number'];

    /**
     * @title  奖励列表
     * @param array $sear 搜索条件
     * @return array
     * @throws \Exception
     */
    public function list(array $sear)
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('level', $sear['keyword']))];
        }
        if (!empty($sear['level'])) {
            $map[] = ['level', '=', $sear['level']];
        }
        if (!empty($sear['notInId'])) {
            $map[] = ['id', 'not in', explode(',', $sear['notInId'])];
        }

        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->with(['trainLevel'])->where($map)->field('id,name,user_level,train_level,train_number,freed_scale,freed_cycle,level,recommend_number,team_number,reward_scale,reward_other,status')->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('level asc,create_time desc')->select()->each(function ($item) {
            if (empty($item['train_level_name'])) {
                $item['train_level_name'] = null;
            }
            return $item;
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
        $map[] = [$this->getPk(), '=', $data[$this->getPk()]];
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        return $this->where($map)->field('id,name,user_level,train_level,train_number,freed_scale,freed_cycle,level,recommend_number,team_number,reward_scale,reward_other,create_time,remark')->findOrEmpty()->toArray();
    }

    /**
     * @title  新增
     * @param array $data
     * @return mixed
     */
    public function new(array $data)
    {
        (new RewardValidate())->goCheck($data, 'create');
        return $this->validate()->baseCreate($data);
    }

    /**
     * @title  编辑
     * @param array $data
     * @return mixed
     */
    public function edit(array $data)
    {
        (new RewardValidate())->goCheck($data, 'edit');
        return $this->validate()->baseUpdate([$this->getPk() => $data[$this->getPk()]], $data);
    }

    /**
     * @title  根据等级获取团队奖励规格
     * @param string $level
     * @return mixed
     */
    public function getTeamRewardRule(string $level)
    {
        return $this->where(['level' => $level, 'status' => 1])->findOrEmpty()->toArray();
    }


    /**
     * @title  删除
     * @param string $id id
     * @return Reward
     */
    public function del(string $id)
    {
        return $this->baseDelete([$this->getPk() => $id]);
    }

    public function trainLevel()
    {
        return $this->hasOne(get_class($this), 'level', 'train_level')->bind(['train_level_name' => 'name']);
    }
}