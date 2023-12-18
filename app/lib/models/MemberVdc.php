<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\MemberException;
use Exception;
use think\facade\Db;
use app\lib\validates\MemberVdc as MemberVdcValidate;

class MemberVdc extends BaseModel
{
    protected $field = ['name', 'level', 'belong', 'become_price', 'discount', 'vdc_type', 'vdc_one', 'vdc_two', 'icon', 'status', 'handing_scale', 'become_condition', 'recommend_level', 'recommend_number', 'train_level', 'train_number', 'sales_team_level', 'sales_price', 'vdc_genre', 'growth_value', 'need_team_condition', 'close_first_divide', 'close_upgrade', 'growth_scale', 'share_growth_scale', 'background', 'poster', 'demotion_growth_value', 'demotion_type','grateful_vdc_one','grateful_vdc_two'];
    protected $validateFields = ['level' => 'in:1,2,3', 'status' => 'number'];
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
     * @return MemberVdc
     */
    public function edit(array $data)
    {
        //(new MemberVdcValidate())->goCheck($data);
        if (!empty($data['demotion_growth_value'] ?? null)) {
            if ((string)$data['demotion_growth_value'] > (string)$data['growth_value']) {
                throw new MemberException(['msg' => '降级门槛不允许高于当前的升级门槛哦']);
            }
        }
        $res = $this->validate()->baseUpdate(['belong' => $this->belong, 'level' => $data['level']], $data);
        //同时修改商品SKU的分销比例
        GoodsSkuVdc::update(['vdc_allow' => $data['vdc_allow'] ?? 1, 'vdc_genre' => $data['vdc_genre'] ?? 1, 'vdc_one' => $data['vdc_one'], 'vdc_two' => $data['vdc_two'] ?? 0], ['level' => $data['level']]);
//        //保证一二级分销比例完全相同
//        if($data['level'] == 1){
//            $this->baseUpdate(['belong'=>$this->belong,'level'=>2],['vdc_one'=>$data['vdc_one']]);
//        }
        cache('apiHomeMemberBg', null);
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