<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 体验中心会员等级规则模块Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\MemberException;
use Exception;
use think\facade\Db;
use app\lib\validates\MemberVdc as MemberVdcValidate;

class ExpMemberVdc extends BaseModel
{
    protected $field = ['name', 'level', 'belong', 'become_price', 'discount', 'vdc_type', 'vdc_one', 'vdc_two', 'icon', 'status', 'handing_scale', 'become_condition', 'recommend_level', 'recommend_number', 'train_level', 'train_number', 'sales_team_level', 'sales_price', 'vdc_genre', 'growth_value', 'need_team_condition', 'close_first_divide', 'close_upgrade', 'sales_price', 'share_growth_scale', 'background', 'poster', 'demotion_sales_price', 'demotion_type','grateful_vdc_one','grateful_vdc_two','recommend_member_number'];
    protected $validateFields = ['level' => 'in:1,2,3,4,5', 'status' => 'number'];
    private $belong = 1;

    /**
     * @title  会员分销规则列表
     * @param array $sear
     * @return array
     * @throws Exception
     */
    public function list(array $sear = []): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('name', $sear['keyword']))];
        }
        $map[] = ['belong', '=', $this->belong];

        if (!empty($sear['member_level'])) {
            $map[] = ['level', '>', intval($sear['member_level'])];
        }
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($sear['pageNumber'] ?? null)) {
        $this->pageNumber = intval($sear['pageNumber']);
    }
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->where($map)->field('name,level,discount,vdc_genre,vdc_type,vdc_one,vdc_two,handing_scale,close_divide,grateful_vdc_one,grateful_vdc_two,create_time')->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('create_time desc')->select()->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  根据会员等级获取会员折扣/分销规则
     * @param int $level
     * @return mixed
     */
    public function getMemberRule(int $level): array
    {
        $info = $this->where(['belong' => $this->belong, 'level' => $level, 'status' => 1])->findOrEmpty()->toArray();
        $info['recommend_level'] = json_decode($info['recommend_level'], true);
        $info['recommend_number'] = json_decode($info['recommend_number'], true);
        $info['train_level'] = json_decode($info['train_level'], true);
        $info['train_number'] = json_decode($info['train_number'], true);
        $minLevel = self::where(['status' => 1])->max('level');
        $info['min_level'] = $level == $minLevel ? true : false;
        return $info;
    }

    /**
     * @title  获取默认会员折扣
     * @return mixed
     */
    public function getDefaultMemberDis()
    {
        return $this->where(['belong' => $this->belong, 'level' => 3, 'status' => [1, 2]])->field('discount,vdc_one as vdc')->findOrEmpty()->toArray();
    }

    /**
     * @title  新增规则
     * @param array $data
     * @return mixed
     */
    public function new(array $data)
    {
        return $this->validate()->baseCreate($data);
    }

    /**
     * @title  编辑规则
     * @param array $data
     * @return ExpMemberVdc
     */
    public function edit(array $data)
    {
        //(new MemberVdcValidate())->goCheck($data);
        if (!empty($data['demotion_sales_price'] ?? null)) {
            if ((string)$data['demotion_sales_price'] > (string)$data['sales_price']) {
                throw new MemberException(['msg' => '降级门槛不允许高于当前的升级门槛哦']);
            }
        }
        $allLevel = $this->where(['status' => 1])->column('vdc_one', 'level');
        $nowMaxLevel = min(array_keys($allLevel));
        $nowMinLevel = max(array_keys($allLevel));
        if ($data['level'] != $nowMaxLevel && $data['level'] != $nowMinLevel) {
            $minMap[] = ['level', '>', $data['level']];
            $minMap[] = ['status', '=', 1];
            $minLevelScaleList = $this->where($minMap)->column('vdc_one');
            if (!empty($minLevelScaleList) && $data['vdc_one'] < max($minLevelScaleList)) {
                throw new MemberException(['msg' => '当前等级奖励比例 不可低于营销规则中 小于当前等级规则的 最大奖励比例(即' . (max($minLevelScaleList) * 100) . '%)~']);
            }
            $maxMap[] = ['level', '<', $data['level']];
            $maxMap[] = ['status', '=', 1];
            $maxLevelScaleList = $this->where($maxMap)->column('vdc_one');
            if (!empty($maxLevelScaleList) && $data['vdc_one'] > min($maxLevelScaleList)) {
                throw new MemberException(['msg' => '当前等级奖励比例 不可高于营销规则中 大于当前等级规则的 最大奖励比例(即' . (min($maxLevelScaleList) * 100) . '%)~']);
            }
        } else {
            if ($data['level'] == $nowMaxLevel) {
                unset($allLevel[$data['level']]);
                if ($data['vdc_one'] < max($allLevel)) {
                    throw new MemberException(['msg' => '当前等级奖励比例必须为规则中最大比例(即大于' . (max($allLevel) * 100) . '%)~']);
                }
            }
            if ($data['level'] == $nowMinLevel) {
                unset($allLevel[$data['level']]);
                if ($data['vdc_one'] > min($allLevel)) {
                    throw new MemberException(['msg' => '当前等级奖励比例必须为规则中最小比例(即小于' . (min($allLevel) * 100) . '%)~']);
                }
            }
        }
        $res = $this->validate()->baseUpdate(['belong' => $this->belong, 'level' => $data['level']], $data);
        return $res;
    }

    /**
     * @title  直升大礼包背景图列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function background(array $sear = [])
    {
        $cacheKey = null;
        $cacheExpire = 0;
        if (!empty($sear['cache'])) {
            $cacheKey = $sear['cache'];
            $cacheExpire = $sear['cache_expire'];
            if (!empty(cache($cacheKey))) {
                return cache($cacheKey);
            }
        }
        $list = $this->where(['status' => 1])->field('level,background')->order('level asc')->when($cacheKey, function ($query) use ($cacheKey, $cacheExpire) {
            $query->cache($cacheKey, $cacheExpire);
        })->select()->each(function ($item) {
            $levelParam = [1 => 2, 2 => 3, 3 => 0];
            $item['level_param'] = $levelParam[$item['level']];
        })->each(function ($item) {
            if (!empty($item['background'])) {
                $item['background'] .= '?x-oss-process=image/format,webp';
            }
            return $item;
        })->toArray();
        return $list ?? [];
    }

    public function recommend()
    {
        return $this->hasOne(get_class($this), 'level', 'recommend_level')->field('level,name');
    }

    public function train()
    {
        return $this->hasOne(get_class($this), 'level', 'train_level')->field('level,name');
    }
}