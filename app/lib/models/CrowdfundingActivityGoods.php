<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 众筹活动期商品SPU模块Model]
// +----------------------------------------------------------------------



namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\ActivityException;
use app\lib\exceptions\CrowdFundingActivityException;
use app\lib\exceptions\ServiceException;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Log;

class CrowdfundingActivityGoods extends BaseModel
{
    protected $validateFields = ['activity_code'];

    /**
     * @title  添加/编辑活动商品
     * @param array $data
     * @return bool
     * @throws \Exception
     */
    public function DBNewOrEdit(array $data)
    {
        $activityCode = $data['activity_code'];
        $roundNumber = $data['round_number'];
        $periodNumber = $data['period_number'];
        $goods = $data['goods'];

        $startTime = null;
        $endTime = null;
        $goodsPrice = [];
        $activityInfo = CrowdfundingPeriod::where(['activity_code' => $activityCode, 'round_number' => $roundNumber, 'period_number' => $periodNumber, 'status' => [1, 2]])->withoutField('create_time,update_time,status')->findOrEmpty()->toArray();
        if (empty($activityInfo)) {
            throw new CrowdFundingActivityException(['msg' => '未知的活动']);
        }
        $goodsSn = array_unique(array_column($goods, 'goods_sn'));
        $goodsSkuSn = array_unique(array_column($goods, 'sku_sn'));


//        if ($activityInfo['limit_type'] == 1) {
//            if (empty($data['start_time']) || empty($data['end_time'])) {
//                throw new CrowdFundingActivityException(['msg' => '限时活动请选择时间']);
//            }
//            $startTime = strtotime($data['start_time']);
//            $endTime = strtotime($data['end_time']);
//        }
        if ($activityInfo['limit_type'] == 1) {
            $startTime = $activityInfo['start_time'];
            $endTime = $activityInfo['end_time'];
        }

        //判断一个商品仅允许添加入一个活动
        $allActivityGoods = CrowdfundingActivityGoods::where(['goods_sn' => $goodsSn, 'status' => [1, 2]])->field('goods_sn,title,status,activity_code,round_number,period_number')->select()->toArray();
        if (!empty($allActivityGoods)) {
            //取出所有区轮期判断是否是有效可购买期, 如果是则不不允许继续添加, 如果是过往已经认购完成的期则不计入判断
            $buyingPeriodInfo = [];
            $pwhere[] = ['result_status', '=', 4];
            $buyingPeriod = CrowdfundingPeriod::where(function ($query) use ($allActivityGoods) {
                $number = 0;
                foreach ($allActivityGoods as $key => $value) {
                    ${'where' . ($number + 1)}[] = ['activity_code', '=', $value['activity_code']];
                    ${'where' . ($number + 1)}[] = ['round_number', '=', $value['round_number']];
                    ${'where' . ($number + 1)}[] = ['period_number', '=', $value['period_number']];
                    $number++;
                }

                for ($i = 0; $i < count($allActivityGoods); $i++) {
                    $allWhereOr[] = ${'where' . ($i + 1)};
                }
                $query->whereOr($allWhereOr);
            })->where($pwhere)->field('activity_code,round_number,period_number')->select()->toArray();
            if (!empty($buyingPeriod ?? [])) {
                foreach ($buyingPeriod as $key => $value) {
                    $buyingPeriodInfo[] = $value['activity_code'] . '-' . $value['round_number'] . '-' . $value['period_number'];
                }
            }
            foreach ($allActivityGoods as $key => $value) {
                if (in_array($value['goods_sn'], $goodsSn) && ($activityCode != $value['activity_code']) && (!empty($buyingPeriodInfo ?? []) && in_array($value['activity_code'] . '-' . $value['round_number'] . '-' . $value['period_number'], $buyingPeriodInfo))) {
                    throw new ActivityException(['msg' => $value['title'] . '已经存在于其他区中,为确保系统数据检验,一个商品仅允许添加一个区']);
                }
            }
        }

        //判断一个商品不允许同时添加到普通活动内
        $allNormalActivityGoods = ActivityGoods::where(['goods_sn' => $goodsSn, 'status' => $this->getStatusByRequestModule(1)])->field('goods_sn,title,status')->select()->toArray();
        if (!empty($allNormalActivityGoods)) {
            foreach ($allNormalActivityGoods as $key => $value) {
                if (in_array($value['goods_sn'], $goodsSn)) {
                    throw new ActivityException(['msg' => $value['title'] . '已经存在于其他普通活动中,为确保系统数据检验,本商品无法继续操作']);
                }
            }
        }


        $goodsList = GoodsSpu::where(['goods_sn' => $goodsSn, 'status' => [1]])->field('goods_sn,main_image,title,sub_title')->withMin('sku', 'sale_price')->select()->toArray();
        if (empty($goodsList)) {
            throw new ServiceException(['msg' => '无有效商品']);
        }

        $goodsSkuList = GoodsSku::where(['sku_sn' => $goodsSkuSn, 'status' => [1]])->field('goods_sn,sku_sn,image,title,sub_title,specs,stock,sale_price,virtual_stock')->select()->toArray();
        foreach ($goods as $key => $value) {
            foreach ($goodsSkuList as $gKey => $gValue) {
                if ($value['sku_sn'] == $gValue['sku_sn'] && !empty($value['sale_number'])) {
                    if ($value['sale_number'] > $gValue['virtual_stock']) {
                        throw new ServiceException(['msg' => '虚拟销量不能超过虚拟库存哦,现在的虚拟库存是' . ($gValue['virtual_stock'] ?? 0)]);
                    }
                }
            }
        }
        foreach ($goods as $key => $value) {
            $goodsPrice[$value['goods_sn']][] = $value;
        }

        $dbRes = false;
        $dbRes = Db::transaction(function () use ($goodsList, $activityInfo, $goodsSn, $startTime, $endTime, $goodsSkuList, $goods, $goodsPrice, $data, $activityCode, $roundNumber, $periodNumber) {

            foreach ($goodsList as $key => $value) {
                $dbData = $value;
                $dbData['sale_price'] = $value['sku_min'];
                $dbData['image'] = $value['main_image'];
                $dbData['activity_code'] = $activityInfo['activity_code'];
                $dbData['round_number'] = $activityInfo['round_number'];
                $dbData['period_number'] = $activityInfo['period_number'];
                $dbData['activity_type'] = $activityInfo['type'];
                $dbData['limit_type'] = $activityInfo['limit_type'] ?? 2;
                $dbData['activity_price'] = min(array_column($goodsPrice[$value['goods_sn']], 'activity_price'));
//                $dbData['sale_number'] = $data['sale_number'][$value['goods_sn']] ?? 0;
                $dbData['vip_level'] = $data['vip_level'][$value['goods_sn']] ?? 0;
                if ($activityInfo['limit_type'] == 1) {
                    $dbData['start_time'] = $startTime;
                    $dbData['end_time'] = $endTime;
                }
                $dbData['desc'] = current(array_column($goodsPrice[$value['goods_sn']], 'desc')) ?? null;

                $goodsRes = $this->updateOrCreate(['activity_code' => $activityCode, 'round_number' => $roundNumber, 'period_number' => $periodNumber, 'goods_sn' => $dbData['goods_sn'], 'status' => [1, 2]], $dbData);
                Cache::tag(['apiWarmUpCrowdFundingActivityInfo' . ($dbData['goods_sn'] ?? '')])->clear();
            }

            if (!empty($goodsSkuList)) {
                $activityGoodsSkuModel = (new CrowdfundingActivityGoodsSku());
                foreach ($goods as $key => $value) {
                    foreach ($goodsSkuList as $gKey => $gValue) {
                        if ($value['sku_sn'] == $gValue['sku_sn']) {
                            $skuData = $gValue;
                            $skuData['activity_price'] = $value['activity_price'];
                            $skuData['growth_value'] = $value['growth_value'] ?? 0;
                            $skuData['vip_level'] = $value['vip_level'] ?? 0;
                            $skuData['sale_number'] = $value['sale_number'] ?? 0;
                            $skuData['activity_code'] = $activityInfo['activity_code'];
                            $skuData['round_number'] = $activityInfo['round_number'];
                            $skuData['period_number'] = $activityInfo['period_number'];
                            $skuData['custom_growth'] = $value['custom_growth'] ?? 2;
                            $skuData['limit_buy_number'] = $value['limit_buy_number'] ?? 0;
                            $skuData['gift_type'] = $value['gift_type'] ?? -1;
                            $skuData['gift_number'] = $value['gift_number'] ?? 0;
                            $skuRes = $activityGoodsSkuModel->updateOrCreate(['activity_code' => $activityCode, 'round_number' => $roundNumber, 'period_number' => $periodNumber, 'goods_sn' => $skuData['goods_sn'], 'sku_sn' => $skuData['sku_sn'], 'status' => [1, 2]], $skuData);
                        }

                    }
                }
            }

            return $goodsRes;
        });

        if (empty($data['noClearCache'] ?? null)) {
            cache('HomeApiCrowdFundingActivityList', null);
//            cache('ApiHomeAllList', null);
            //清除首页活动列表标签的缓存
//            Cache::tag(['HomeApiCrowdFundingActivityList', 'ApiHomeAllList'])->clear();
            Cache::tag(['HomeApiCrowdFundingActivityList'])->clear();
        }

        return judge($dbRes);

    }


    /**
     * @title  活动商品列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear = []): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('title', $sear['keyword']))];
        }
        $cacheKey = 'ApiCrowdFundingPeriodGoodsList'.'-'.md5(implode($sear));
        $cacheExpire = 60;
        $cacheTag = 'ApiCrowdFundingPeriodGoodsList';

        if (empty($sear['clearCache']) && $this->module == 'api') {
            $cacheList = cache($cacheKey);
            if (!empty($cacheList)) {
                return ['list' => $cacheList, 'pageTotal' => cache($cacheKey . '-count')];
            }
        }


        $map[] = ['activity_code', '=', $sear['activity_code']];
        $map[] = ['round_number', '=', $sear['round_number']];
        $map[] = ['period_number', '=', $sear['period_number']];
        $map[] = ['status', 'in', $this->getStatusByRequestModule()];

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
            cache($cacheKey.'-count', $pageTotal, $cacheExpire, $cacheTag);
        }

        $list = $this->where($map)->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->withSum(['SaleGoods'], 'count')->order('sort asc,create_time desc,id desc')->select()->each(function ($item) {
            if (!empty($item['show_start_time'])) {
                $item['show_start_time'] = timeToDateFormat($item['show_start_time']);
            }
            if (!empty($item['start_time'])) {
                $item['start_time'] = timeToDateFormat($item['start_time']);
            }
            if (!empty($item['end_time'])) {
                $item['end_time'] = timeToDateFormat($item['end_time']);
            }
//                if ($item['activity_code'] == 1) {
//                    $item['salePercentage'] = 0;
//                    //虚拟销量
//                    $item['virtual_sale_number'] = ActivityGoodsSku::where(['activity_id' => $item['activity_id'], 'status' => 1, 'goods_sn' => $item['goods_sn']])->sum('sale_number');
//                    if (intval($item['virtual_sale_number']) <= 0) {
//                        $item['virtual_sale_number'] = 0;
//                    }
//                    $realStock = $item['goods_stock_sum'];
//                    $realSale = $item['sale_goods_sum'] ?? 0;
//                    //添加虚拟销量
//                    if ($item['limit_type'] == 1) {
//                        if (strtotime($item['start_time']) > time()) {
//                            $item['virtual_sale_number'] = 0;
//                            $item['virtual_stock'] = 0;
//                        }
//                    }
//                    if (intval($item['goods_stock_sum']) <= 0) {
//                        $item['salePercentage'] = 100;
//                    } else {
//                        //比例应该用(虚拟销量+实际销量)/(剩余库存+实际销量+虚拟库存)
//                        $item['salePercentage'] = (sprintf("%.2f", (intval($item['virtual_sale_number'] + $realSale) / intval($realStock + $realSale + $item['virtual_stock'])))) * 100;
//                        if (doubleval($item['salePercentage']) <= 1) {
//                            $item['salePercentage'] = 1;
//                        }
//                        if (doubleval($item['salePercentage']) >= 100) {
//                            $item['salePercentage'] = 100;
//                        }
//                    }
////                $item['goods_stock_sum'] -= $item['virtual_sale_number'];
//                    $item['goods_stock_sum'] = $item['goods_stock_sum'] <= 0 ? 0 : intval($item['goods_stock_sum']);
//                    $item['real_sale_goods_sum'] = $realSale;
//                    $item['sale_goods_sum'] += $item['virtual_sale_number'];
//                    $item['sale_goods_sum'] = $item['sale_goods_sum'] <= 0 ? 0 : intval($item['sale_goods_sum']);
//                    $item['sale_price'] = $item['activity_price'];
//                    unset($item['virtual_sale_number']);
//
//                    //如果没到开始时间无论如何百分比都为0
//                    if ($item['limit_type'] == 1) {
//                        if (strtotime($item['start_time']) > time()) {
//                            $item['salePercentage'] = 0;
//                        }
//                    }
//                    if (!empty($item['salePercentage'])) {
//                        $item['salePercentage'] = intval($item['salePercentage']);
//                    }
//                }
            return $item;
        })->toArray();

        if (!empty($list ?? [])) {
            //加入缓存
            cache($cacheKey, $list, $cacheExpire, $cacheTag);
        }
        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  删除商品
     * @param array $data
     * @param bool $noClearCache 是否清除对应的缓存
     * @return mixed
     */
    public function del(array $data, bool $noClearCache = false)
    {
        $activity_code = $data['activity_code'];
        $round_number = $data['round_number'];
        $period_number = $data['period_number'];
        $goodsSn = $data['goods_sn'];
        $goodsInfo = $this->where(['activity_code' => $activity_code, 'round_number' => $round_number, 'period_number' => $period_number, 'goods_sn' => $goodsSn, 'status' => $this->getStatusByRequestModule()])->findOrEmpty()->toArray();
        $res = $this->where(['activity_code' => $activity_code, 'round_number' => $round_number, 'period_number' => $period_number, 'goods_sn' => $goodsSn])->save(['status' => -1]);
        CrowdfundingActivityGoodsSku::where(['goods_sn' => $goodsInfo['goods_sn'], 'activity_code' => $goodsInfo['activity_code'], 'round_number' => $round_number, 'period_number' => $period_number])->save(['status' => -1]);

        if (empty($noClearCache)) {
            if ($res) {
                cache('ApiHomeAllList', null);
                cache('HomeApiCrowdFundingActivityList', null);
                //清除首页活动列表标签的缓存
                Cache::tag(['HomeApiCrowdFundingActivityList', 'ApiHomeAllList'])->clear();
                Cache::tag(['apiWarmUpActivityInfo' . ($goodsInfo['goods_sn'] ?? '')])->clear();
            }
        }

        return $res;
    }

    /**
     * @title  更新商品排序
     * @param array $data
     * @return bool
     */
    public function updateSort(array $data)
    {
        $goodsNumber = count($data);
        if (empty($goodsNumber)) {
            throw new ServiceException(['msg' => '请选择商品']);
        }
        $DBRes = Db::transaction(function () use ($data) {
            foreach ($data as $key => $value) {
                $res = $this->where(['activity_code' => $value['activity_code'], 'round_number' => $value['round_number'], 'period_number' => $value['period_number'], 'goods_sn' => $value['goods_sn']])->save(['sort' => $value['sort'] ?? 1]);
            }
            return true;
        });

        cache('HomeApiCrowdFundingActivityList', null);
        //清除首页活动列表标签的缓存
        Cache::tag(['HomeApiCrowdFundingActivityList'])->clear();

        return judge($DBRes);
    }

    public function activity()
    {
        return $this->hasOne('CrowdfundingActivity', 'activity_code', 'activity_code');
    }

    public function sku()
    {
        return $this->hasMany('CrowdfundingActivityGoodsSku', 'goods_sn', 'goods_sn')->where(['status' => [1, 2]]);
    }

    public function goodsStock()
    {
        return $this->hasMany('CrowdfundingActivityGoodsSku', 'goods_sn', 'goods_sn')->where(['status' => 1]);
    }

    public function saleGoods()
    {
        return $this->hasMany('OrderGoods', 'goods_sn', 'goods_sn')->where(['pay_status' => 2, 'status' => 1]);
    }
}