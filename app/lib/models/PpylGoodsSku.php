<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 拼拼有礼活动商品SKU Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\PtException;
use app\lib\exceptions\ServiceException;
use think\facade\Cache;
use think\facade\Db;

class PpylGoodsSku extends BaseModel
{
    protected $validateFields = ['activity_code'];

    /**
     * @title  拼团商品sku详情
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear)
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('title', $sear['keyword']))];
        }
        $activityCode = PpylArea::where(['area_code' => $sear['area_code'], 'status' => 1])->value('activity_code');
        $sear['activity_code'] = $activityCode;

        $map[] = ['activity_code', '=', $sear['activity_code']];
        $map[] = ['area_code', '=', $sear['area_code']];
        $map[] = ['goods_sn', '=', $sear['goods_sn']];

        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->with(['sku'])->where($map)->withMax(['vdc' => 'max_purchase_price'], 'purchase_price')->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('create_time desc')->select()->each(function ($item) {
            if (!empty($item['reward_scale'])) {
                $item['reward_scale'] *= 100;
            }
        })->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  删除拼团商品SKU
     * @param array $data
     * @return mixed
     */
    public function del(array $data)
    {
        $activityCode = PpylArea::where(['area_code' => $data['area_code'], 'status' => 1])->value('activity_code');
        $data['activity_code'] = $activityCode;

        $activityCode = PpylArea::where(['area_code' => $data['area_code'], 'status' => 1])->value('activity_code');
        $sear['activity_code'] = $activityCode;

        if ($this->checkExistOrder(['activity_code' => $data['activity_code'],'area_code'=>$data['area_code'], 'goods_sn' => $data['goods_sn'], 'sku_sn' => $data['sku_sn'], 'activity_status' => [1]])) {
            throw new PtException(['msg' => '该拼团活动商品SKU存在尚未拼团成功的订单,不允许删除!']);
        }
        $res = $this->where(['activity_code' => $data['activity_code'],'area_code'=>$data['area_code'], 'goods_sn' => $data['goods_sn'], 'sku_sn' => $data['sku_sn']])->save(['status' => -1]);
        $aGoods = $this->where(['activity_code' => $data['activity_code'],'area_code'=>$data['area_code'], 'goods_sn' => $data['goods_sn'], 'status' => [1, 2]])->count();
        if (empty($aGoods)) {
            PpylGoods::update(['status' => -1], ['activity_code' => $data['activity_code'],'area_code'=>$data['area_code'], 'goods_sn' => $data['goods_sn']]);
        }
        //清除首页拼团活动列表标签的缓存
        cache('ApiHomeAllList', null);
        cache('ApiHomePpylList', null);
        Cache::tag(['apiWarmUpPpylActivityInfo' . ($data['goods_sn'] ?? '')])->clear();
        return $res;
    }

    /**
     * @title  检查拼团活动商品SKU是否存在有效订单
     * @param array $data
     * @return bool
     */
    public function checkExistOrder(array $data)
    {
        $activityCode = $data['activity_code'];
        $areaCode = $data['area_code'];
        $goodsSn = $data['goods_sn'];
        $skuSn = $data['sku_sn'];
        $orderStatus = $data['pay_status'] ?? [1, 2];
        $activityStatus = $data['activity_status'] ?? [1, 2];
        if (empty($activityCode) || empty($goodsSn) || empty($skuSn)) {
            return true;
        }
        $ptOrder = PpylOrder::where(['pay_status' => $orderStatus, 'goods_sn' => $goodsSn, 'sku_sn' => $skuSn, 'activity_code' => $activityCode, 'activity_status' => $activityStatus, 'area_code' => $areaCode])->count();
        if (!empty($ptOrder)) {
            return true;
        }

        return false;
    }

    /**
     * @title  商品销售情况
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function goodsSkuSale(array $sear)
    {
        $activityCode = PpylArea::where(['area_code' => $sear['area_code'], 'status' => 1])->value('activity_code');
        $sear['activity_code'] = $activityCode;

        $goodsSn = $sear['goods_sn'];
        $activityCode = $sear['activity_code'];
        $areaCode = $sear['area_code'];
        if (empty($goodsSn) || empty($activityCode)) {
            return [];
        }

        $allGoodsSku = $this->with(['sku' => function ($query) {
            $query->field('title,specs,image,stock');
        }])->where(['goods_sn' => $goodsSn, 'activity_code' => $activityCode, 'area_code'=>$areaCode,'status' => [1, 2]])->field('goods_sn,sku_sn,title,specs,stock,start_number')->withCount(['PpylOrder' => function ($query, &$alias) {
            $query->where(['activity_status' => 1, 'pay_status' => 2]);
            $alias = 'ppyl_processing_number';
        }])->withCount(['PpylOrder' => function ($query, &$alias) {
            $query->where(['activity_status' => 1, 'pay_status' => 1]);
            $alias = 'ppyl_wait_pay_number';
        }])->select()->each(function ($item) {
            $item['processing_group_number'] = 0;
            $item['success_group_number'] = 0;
        })->toArray();

        //获取当前商品所有SKU拼团的团数情况
        if (!empty($allGoodsSku)) {
            $allPtOrder = PpylOrder::where(['goods_sn' => $goodsSn, 'pay_status' => [1, 2, -2], 'activity_status' => [1, 2]])->select()->toArray();
            if (!empty($allPtOrder)) {
                foreach ($allPtOrder as $key => $value) {
                    if ($value['activity_status'] == 2) {
                        $successPt[$value['sku_sn']][] = $value['activity_sn'];
                    } elseif ($value['activity_status'] == 1) {
                        $processingPt[$value['sku_sn']][] = $value['activity_sn'];
                    }
                }
                foreach ($allGoodsSku as $key => $value) {
                    if (!empty($successPt[$value['sku_sn']])) {
                        $allGoodsSku[$key]['success_group_number'] = count(array_unique(array_filter($successPt[$value['sku_sn']]))) ?? 0;
                    }
                    if (!empty($processingPt[$value['sku_sn']])) {
                        $allGoodsSku[$key]['processing_group_number'] = count(array_unique(array_filter($processingPt[$value['sku_sn']]))) ?? 0;
                    }
                }
            }
        }
        return $allGoodsSku ?? [];
    }


    /**
     * @title  更新库存
     * @param array $sear
     * @return mixed
     */
    public function updateStock(array $sear)
    {
        $goods = $sear['goods'] ?? [];

        if (empty($goods)) {
            return false;
        }
        $allGoodsSn = array_unique(array_filter(array_column($goods, 'goods_sn')));
        if (count($allGoodsSn) > 1) {
            throw new ServiceException(['msg' => '一次仅允许修改一个商品的库存哟']);
        }

        $allAreaCode = array_unique(array_filter(array_column($goods, 'area_code')));

        if (empty(count($allAreaCode))) {
            return false;
        }
        if (count($allAreaCode) > 1) {
            throw new ServiceException(['msg' => '一次仅允许修改一个美好拼拼专场一个商品的库存哟']);
        }

        $activityCodes = PpylArea::where(['area_code' => $allAreaCode, 'status' => 1])->value('activity_code');
        $allActivityCode = [$activityCodes];

        $goodsSn = current($allGoodsSn);
        $activityCode = current($allActivityCode);
        $areaCode = current($allAreaCode);
        $ptInfo = PpylActivity::where(['activity_code' => $activityCode])->findOrEmpty()->toArray();
        foreach ($goods as $key => $value) {
            if (empty($value['stock_number'])) {
                unset($goodsSn[$key]);
                continue;
            }
//            if (abs(intval($value['stock_number'])) % intval($ptInfo['group_number']) != 0) {
//                throw new PtException(['msg' => '为提高成团率,请保证增加或减少的拼团商品库存必须能整除成团人数,当前拼团活动要求成团人数为' . $ptInfo['group_number']]);
//            }
            $stock[$value['sku_sn']] = $value['stock_number'];
        }

        if (empty($goods)) {
            throw new ServiceException(['msg' => '没有可以实际修改的库存哟~']);
        }
        $skuSn = array_unique(array_filter(array_column($goods, 'sku_sn')));

        $skuInfo = $this->where(['activity_code' => $activityCode, 'area_code'=>$areaCode,'goods_sn' => $goodsSn, 'sku_sn' => $skuSn])->column('stock', 'sku_sn');

        $goodsSkuInfo = GoodsSku::where(['goods_sn' => $goodsSn, 'sku_sn' => $skuSn])->column('stock', 'sku_sn');
        if (empty($skuInfo)) {
            throw new ServiceException(['msg' => '没有可以实际修改的商品库存哟~']);
        }
        //是否需要清除全部库存
        $clearAllStock = false;

        $count = 0;
        foreach ($skuInfo as $key => $value) {
            if (!empty($stock[$key])) {
                $finally[$count]['goods_sn'] = $goodsSn;
                $finally[$count]['sku_sn'] = $key;
                //如果填写的数量是正数则表示添加库存,负数则需要判断是否超过当前库存,超过则当前库存清0
                if (!empty($goodsSkuInfo[$key]) && ($stock[$key] + $skuInfo[$key]) > (string)$goodsSkuInfo[$key]) {
                    throw new PtException(['msg' => '拼团商品库存不允许超过商品库库存哦,商品库当前库存剩余 ' . intval($goodsSkuInfo[$key])]);
                }

                if (intval($stock[$key]) > 0) {
                    $finally[$count]['type'] = 1;
                    $finally[$count]['number'] = intval($stock[$key]);
//                    $finally[$count]['start_number'] = intval(intval($stock[$key]) / intval($ptInfo['group_number']));
                    $finally[$count]['start_number'] = intval($stock[$key]);
                } else {
                    if ($value + $stock[$key] <= 0) {
                        $finally[$count]['type'] = 2;
                        $finally[$count]['number'] = intval($value);
                        $finally[$count]['start_number'] = 0;
                        $clearAllStock = true;
                    } else {
                        $finally[$count]['type'] = 1;
                        $finally[$count]['number'] = intval($stock[$key]);
//                        $finally[$count]['start_number'] = '-' . intval(abs(intval($stock[$key])) / intval($ptInfo['group_number']));
                        $finally[$count]['start_number'] = '-' . (intval($stock[$key]));
                    }
                }
                $count++;
            }
        }
        //数据库操作
        $DBRes = false;
        if (!empty($finally)) {
            $DBRes = Db::transaction(function () use ($finally, $clearAllStock) {
                foreach ($finally as $key => $value) {
                    if ($value['type'] == 1) {
                        $res = $this->where(['goods_sn' => $value['goods_sn'], 'sku_sn' => $value['sku_sn']])->inc('stock', intval($value['number']))->update();
                        if (!empty($value['start_number'])) {
                            $this->where(['goods_sn' => $value['goods_sn'], 'sku_sn' => $value['sku_sn']])->inc('start_number', intval($value['start_number']))->update();
                        }
                    } elseif ($value['type'] == 2) {
                        if (empty($clearAllStock)) {
                            $res = $this->where(['goods_sn' => $value['goods_sn'], 'sku_sn' => $value['sku_sn']])->dec('stock', intval($value['number']))->update();
                            if (!empty($value['start_number'])) {
                                $this->where(['goods_sn' => $value['goods_sn'], 'sku_sn' => $value['sku_sn']])->dec('start_number', intval($value['start_number']))->update();
                            }
                        } else {
                            $res = $this->where(['goods_sn' => $value['goods_sn'], 'sku_sn' => $value['sku_sn']])->save(['stock' => 0]);
                            $this->where(['goods_sn' => $value['goods_sn'], 'sku_sn' => $value['sku_sn']])->save(['start_number' => 0]);
                        }
                    }
                    Cache::tag(['apiWarmUpPpylActivityInfo' . ($value['goods_sn'] ?? '')])->clear();
                }
                return $res;
            });
        }
        return $DBRes;

    }

    public function activity()
    {
        return $this->hasOne('PpylActivity', 'activity_code', 'activity_code')->where(['status' => 1])->withoutField('id,status,create_time,update_time');
    }

    public function area()
    {
        return $this->hasOne('PpylArea', 'area_code', 'area_code')->where(['status' => 1])->withoutField('id,status,create_time,update_time');
    }

    public function goodsSpu()
    {
        return $this->hasOne('PpylGoods', 'goods_sn', 'goods_sn')->where(['status' => 1])->withoutField('id,status,create_time,update_time');
    }

    public function sku()
    {
        return $this->hasOne('GoodsSku', 'sku_sn', 'sku_sn')->where(['status' => 1])->withoutField('id,status,create_time,update_time');
    }

    public function vdc()
    {
        return $this->hasMany('GoodsSkuVdc', 'sku_sn', 'sku_sn')->field('id,sku_sn,level,purchase_price,vdc_genre,vdc_type,belong,vdc_one,vdc_two')->where(['status' => [1, 2]]);
    }

    public function PpylOrder()
    {
        return $this->hasMany('PpylOrder', 'sku_sn', 'sku_sn');
    }

}