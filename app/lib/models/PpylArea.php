<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 拼拼有礼活动专场模块Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\PtException;
use app\lib\services\CodeBuilder;
use app\lib\validates\PpylArea as PpylAreaValidate;
use Exception;
use think\facade\Cache;
use think\facade\Db;

class PpylArea extends BaseModel
{
    /**
     * @title  拼拼有礼活动专场列表
     * @param array $sear
     * @return array
     * @throws Exception
     */
    public function list(array $sear = []): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('name', $sear['keyword']))];
        }
        $map[] = ['activity_code', '=', $sear['activity_code']];
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->where($map)->field('activity_code,area_code,name,limit_type,start_time,end_time,show_status,join_number,join_limit_type,win_number,win_limit_type,lottery_delay_time,sort,status,icon,highlight_status,create_time')->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('sort asc,create_time desc')->select()->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  C端拼拼有礼活动中的专场列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function cList(array $sear)
    {
        $cacheKey = $sear['cache'] ?? false;
        $cacheExpire = 600;
        $cacheTag = 'ApiHomePpylAreaList';

        $map[] = ['status', '=', 1];
        $page = intval($sear['page'] ?? 0) ?: null;

        if (!empty($sear['pageNumber'])) {
            $this->pageNumber = intval($sear['pageNumber']);
        }
        $map[] = ['show_status', '=', 1];
        $map[] = ['end_time', '>', time()];
        $map[] = ['activity_code', '=', $sear['activity_code']];

        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->with(['goods' => function ($query) {
            $goodsMap[] = ['end_time', '>', time()];
            $goodsMap[] = ['status', '=', 1];
            $query->where($goodsMap)->field('activity_code,area_code,goods_sn,title,sub_title,image,activity_price,sale_price,sort')->order('sort asc,create_time desc')->withLimit(1);
        }])->where($map)->field('activity_code,area_code,name,limit_type,start_time,end_time,sort,icon,background_image,lottery_delay_time,create_time,highlight_status')->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('sort asc,create_time desc')
            ->when($cacheKey, function ($query) use ($cacheKey, $cacheExpire) {
                $query->cache($cacheKey, $cacheExpire)->withCache(['goods', 60], $cacheExpire);
            })->select()->each(function ($item) {
                if (!empty($item['goods'])) {
                    //图片展示缩放,OSS自带功能
                    foreach ($item['goods'] as $key => $value) {
//                        $item['goods'][$key]['image'] .= '?x-oss-process=image/resize,h_400,m_lfit';
                        $item['goods'][$key]['image'] .= '?x-oss-process=image/format,webp';
                    }
                }
                return $item;
            })->toArray();
        if (!empty($list)) {
            //过滤没有商品数组的拼团活动
            foreach ($list as $key => $value) {
                if (empty($value['goods'])) {
                    unset($list[$key]);
                }
                foreach ($value['goods'] as $gKey => $gValue) {
                    $list[$key]['goods'][$gKey]['stock'] = 0;
                    $list[$key]['goods'][$gKey]['market_price'] = $gValue['sale_price'];
                    $allPtSpu[] = $gValue['goods_sn'];
                }
            }
            if(empty($list)){
                return ['list' => [], 'pageTotal' => $pageTotal ?? 0];
            }

            if (!empty($list)) {
                $list = array_values($list);
            }

            if(!empty($sear['needGoodsInfo'])){
                if (!empty($allPtSpu)) {
                    $allPtGoodsSn = array_unique(array_filter($allPtSpu));
                    $allPt = array_unique(array_filter(array_column($list, 'area_code')));
                    if (!empty($allPtGoodsSn) && !empty($allPt)) {
                        $sku = PpylGoodsSku::where(['goods_sn' => $allPtGoodsSn, 'activity_code' => $sear['activity_code'], 'area_code' => $allPt, 'status' => [1]])->select()->toArray();
                        if (!empty($sku)) {
                            foreach ($sku as $key => $value) {
                                if (!isset($allPtActivitySpu[$value['goods_sn']])) {
                                    $allPtActivitySpu[$value['goods_sn']] = 0;
                                }
                                $allPtActivitySpu[$value['goods_sn']] += $value['stock'] ?? 0;
                            }
                        }
                    }
                    if (!empty($allPtActivitySpu)) {
                        foreach ($list as $key => $value) {
                            foreach ($value['goods'] as $gKey => $gValue) {
                                $list[$key]['goods'][$gKey]['stock'] = 0;
                                if (!empty($allPtActivitySpu[$gValue['goods_sn']])) {
                                    $list[$key]['goods'][$gKey]['stock'] = $allPtActivitySpu[$gValue['goods_sn']] ?? 0;
                                }
                            }
                        }
                    }
                    //补回普通商品的信息
                    $goodsInfo = GoodsSpu::where(['goods_sn' => $allPtGoodsSn, 'status' => [1]])->field('goods_sn,title,main_image,sub_title')->withMin(['sku' => 'market_price'], 'market_price')->select()->toArray();
                    if (!empty($goodsInfo)) {
                        foreach ($goodsInfo as $key => $value) {
                            $skuInfo[$value['goods_sn']] = $value;
                        }
                        if (!empty($skuInfo)) {
                            foreach ($list as $key => $value) {
                                foreach ($value['goods'] as $gKey => $gValue) {
                                    if (!empty($skuInfo[$gValue['goods_sn']]) && !empty($skuInfo[$gValue['goods_sn']]['market_price'])) {
                                        $list[$key]['goods'][$gKey]['market_price'] = $skuInfo[$gValue['goods_sn']]['market_price'];
                                    }
                                }
                            }
                        }
                    }
                }
            }

        }
        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  拼拼有礼活动专场详情
     * @param array $data
     * @return array
     */
    public function info(array $data)
    {
//        $code = $data['activity_code'];
        $areaCode = $data['area_code'];
        $code = PpylArea::where(['area_code' => $data['area_code'], 'status' => 1])->value('activity_code');
        $map[] = ['activity_code', '=', $code];
        $map[] = ['area_code', '=', $areaCode];
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        $info = $this->where($map)->withoutField('id,update_time')->findOrEmpty()->toArray();
        if (!empty($info)) {
            $existGoods = PpylGoods::where(['activity_code' => $code, 'area_code' => $areaCode, 'status' => [1, 2]])->count();
            $info['existGoods'] = !empty($existGoods) ? true : false;
            $info['existOrder'] = $this->checkExistOrder(['activity_code' => $code, 'area_code' => $areaCode, 'pay_status' => [1]]);
        }
        return $info;
    }

    /**
     * @title  新增拼拼有礼活动专场
     * @param array $data
     * @return mixed
     */
    public function DBNew(array $data)
    {
        (new PpylAreaValidate())->goCheck($data, 'create');
        $data['area_code'] = (new CodeBuilder())->buildAreaCode();
        $data['start_time'] = strtotime($data['start_time']);
        $data['end_time'] = strtotime($data['end_time']);
        $data['create_time'] = time();
        $data['update_time'] = time();
        $res = self::insert($data);
        if ($res) {
            //清除首页活动列表标签的缓存
            cache('ApiHomeAllList', null);
            cache('ApiHomePpylAreaList', null);
        }
        return $res;
    }

    /**
     * @title  编辑拼拼有礼活动专场
     * @param array $data
     * @return mixed
     */
    public function DBEdit(array $data)
    {
        $activityInfo = PpylArea::where(['area_code' => $data['area_code'], 'status' => [1, 2]])->field('activity_code,reward_scale')->findOrEmpty()->toArray();
        $activityCode = $activityInfo['activity_code'];
        $data['activity_code'] = $activityCode;
        (new PpylAreaValidate())->goCheck($data, 'edit');

        $data['start_time'] = strtotime($data['start_time']);
        $data['end_time'] = strtotime($data['end_time']);

        $res = Db::transaction(function () use ($activityInfo, $data, $activityCode) {
            //修改专区详情
            $res = $this->where(['activity_code' => $activityCode, 'area_code' => $data['area_code'], 'status' => [1, 2]])->save($data);

            //同时修改比例
            if ($activityInfo['reward_scale'] != $data['reward_scale']) {
                $allSku = PpylGoodsSku::where(['status' => [1, 2], 'area_code' => $data['area_code']])->field('goods_sn,sku_sn,area_code,activity_price,reward_scale,reward_price')->select()->toArray();
                if (!empty($allSku)) {
                    foreach ($allSku as $key => $value) {
                        $skuData['reward_scale'] = ($data['reward_scale'] / 100);
                        $skuData['reward_price'] = priceFormat($value['activity_price'] * $skuData['reward_scale']);
                        $skuRes = PpylGoodsSku::update($skuData, ['goods_sn' => $value['goods_sn'], 'sku_sn' => $value['sku_sn'], 'area_code' => $value['area_code'], 'status' => [1, 2]]);
                    }
                }

            }
            return $res;
        });


        if ($res) {
            //清除首页活动列表标签的缓存
            cache('ApiHomeAllList', null);
            cache('ApiHomePpylAreaList', null);
        }
        return true;
    }

    /**
     * @title  删除拼拼有礼活动专场
     * @param array $data
     * @return mixed
     */
    public function DBDelete(array $data)
    {
        $activityCode = PpylArea::where(['area_code' => $data['area_code'], 'status' => [1, 2]])->value('activity_code');
        $data['activity_code'] = $activityCode;
        (new PpylAreaValidate())->goCheck($data, 'delete');

        //判断该活动是否存在有效订单,有效订单则不允许删除
        if ($this->checkExistOrder(['activity_code' => $activityCode, 'area_code' => $data['area_code'], 'activity_status' => [1]])) {
            throw new PtException(['msg' => '该拼团活动存在尚未拼团成功的订单,不允许删除!']);
        }
        $res = $this->cache('HomeApiPpylAreaList')->where(['activity_code' => $data['activity_code'], 'area_code' => $data['area_code'], 'status' => [1, 2]])->save(['status' => -1]);
        //一并删除商品
        PpylGoods::where(['activity_code' => $activityCode, 'area_code' => $data['area_code'], 'status' => [1, 2]])->save(['status' => -1]);
        PpylGoodsSku::where(['activity_code' => $activityCode, 'area_code' => $data['area_code'], 'status' => [1, 2]])->save(['status' => -1]);
        if ($res) {
            cache('ApiHomeAllList', null);
            //清除首页活动列表标签的缓存
            Cache::tag(['HomeApiActivityList', 'ApiHomeAllList'])->clear();
        }
        return $res;
    }

    /**
     * @title  上下架拼拼有礼活动专场
     * @param array $data
     * @return mixed
     */
    public function upOrDown(array $data)
    {
        $activityCode = PpylArea::where(['area_code' => $data['area_code'], 'status' => [1, 2]])->value('activity_code');
        if ($data['status'] == 1) {
            if ($this->checkExistOrder(['activity_code' => $activityCode, 'area_code' => $data['area_code'], 'activity_status' => [1]])) {
                throw new PtException(['msg' => '该专场活动存在尚未拼团成功的订单,不允许下架!']);
            }
            $save['status'] = 2;
        } elseif ($data['status'] == 2) {
            $save['status'] = 1;
        } else {
            return false;
        }
        $res = $this->cache('HomeApiPpylAreaList')->where(['activity_code' => $activityCode, 'area_code' => $data['area_code']])->save($save);
        if ($res) {
            cache('ApiHomeAllList', null);
            //清除首页活动列表标签的缓存
            Cache::tag(['HomeApiActivityList', 'ApiHomeAllList'])->clear();
        }
        return $res;
    }

    /**
     * @title  检查拼拼有礼活动专场是否存在有效订单
     * @param array $data
     * @return bool
     */
    public function checkExistOrder(array $data)
    {
        $activityCode = $data['activity_code'];
        $orderStatus = $data['pay_status'] ?? [1, 2];
        $activityStatus = $data['activity_status'] ?? [1, 2];
        $areaCode = $data['area_code'];
        if (empty($activityCode) || empty($areaCode)) {
            return true;
        }
        $ptOrder = PpylOrder::where(['pay_status' => $orderStatus, 'activity_code' => $activityCode, 'activity_status' => $activityStatus, 'area_code' => $areaCode])->count();
        if (!empty($ptOrder)) {
            return true;
        }

        return false;
    }



    public function setStartTimeAttr($value)
    {
        if (!empty($value)) $value = strtotime($value);
        return $value;
    }

    public function setEndTimeAttr($value)
    {
        if (!empty($value)) $value = strtotime($value);
        return $value;
    }

    public function getStartTimeAttr($value)
    {
        return !empty($value) ? date('Y-m-d H:i:s', $value) : $value;
    }

    public function getEndTimeAttr($value)
    {
        return !empty($value) ? date('Y-m-d H:i:s', $value) : $value;
    }

    public function ptOrder()
    {
        return $this->hasMany('PtOrder', 'activity_code', 'activity_code')->where(['status' => [2]]);
    }

    public function goods()
    {
        return $this->hasMany('PpylGoods', 'area_code', 'area_code')->where(['status' => 1]);
    }

    public function startUserType()
    {
        return $this->hasOne('CouponUserType', 'u_type', 'start_user_type')->bind(['start_user_name' => 'u_name']);
    }

    public function joinUserType()
    {
        return $this->hasOne('CouponUserType', 'u_type', 'join_user_type')->bind(['join_user_name' => 'u_name']);
    }
}