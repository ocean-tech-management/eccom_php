<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 活动商品模块Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\ActivityException;
use app\lib\exceptions\ServiceException;
use Exception;
use think\facade\Cache;
use think\facade\Db;

class ActivityGoods extends BaseModel
{

    protected $validateFields = ['activity_id', 'goods_sn'];

    /**
     * @title  添加/编辑活动商品
     * @param array $data
     * @return bool
     * @throws \Exception
     */
    public function DBNewOrEdit(array $data)
    {
        $aId = $data['activity_id'];
        $goods = $data['goods'];

        $startTime = null;
        $endTime = null;
        $goodsPrice = [];
        $activityInfo = Activity::where(['id' => $aId, 'status' => $this->getStatusByRequestModule(1)])->withoutField('create_time,update_time,status')->findOrEmpty()->toArray();
        if (empty($activityInfo)) {
            throw new ServiceException(['msg' => '未知的活动']);
        }
        $goodsSn = array_unique(array_column($goods, 'goods_sn'));
        $goodsSkuSn = array_unique(array_column($goods, 'sku_sn'));

        if ($activityInfo['a_type'] == 2) {
            $allExistGoods = ActivityGoodsSku::where(['activity_id' => $aId, 'sku_sn' => $goodsSkuSn, 'status' => $this->getStatusByRequestModule(1)])->group('goods_sn')->field('goods_sn,vip_level')->select()->toArray();
            if (!empty($allExistGoods)) {
                foreach ($allExistGoods as $key => $value) {
                    $existGoods[$value['goods_sn']] = $value['vip_level'];
                }

            }

            foreach ($goods as $key => $value) {
                if (!isset($value['growth_value']) || empty($value['growth_value'])) {
                    throw new ActivityException(['msg' => '会员大礼包产品必须填写成长值~']);
                }
                if (!empty($existGoods[$value['goods_sn']])) {
                    if ($value['vip_level'] != $existGoods[$value['goods_sn']]) {
                        throw new ActivityException(['msg' => '会员大礼包同个商品不同SKU不允许存在不同的最高可用等级']);
                    }
                } else {
                    $existGoods[$value['goods_sn']] = intval($value['vip_level']) ?? 0;
                }
            }
        }

        if ($activityInfo['allow_custom_growth'] == 2) {
            foreach ($goods as $key => $value) {
                if (!empty(doubleval($value['growth_value'] ?? null))) {
                    throw new ActivityException(['msg' => '该活动专区不允许自定义成长值!']);
                }
            }
        }

        if ($activityInfo['limit_type'] == 1) {
            if (empty($data['start_time']) || empty($data['end_time'])) {
                throw new ServiceException(['msg' => '限时活动请选择时间']);
            }
            $startTime = strtotime($data['start_time']);
            $endTime = strtotime($data['end_time']);
        }
        //判断普通商品不允许添加入会员大礼包
        $memberActivityId = Activity::with(['sku'])->where(['a_type' => 2, 'status' => $this->getStatusByRequestModule(1)])->findOrEmpty()->toArray();

        if (!empty($memberActivityId) && !empty($memberActivityId['sku'])) {
            foreach ($memberActivityId['sku'] as $key => $value) {
                if (in_array($value['sku_sn'], $goodsSkuSn) && ($aId != $memberActivityId['id'])) {
                    throw new ActivityException(['msg' => $value['title'] . '已经存在于团长大礼包中,为确保系统数据检验,不允许此商品添入其他活动中']);
                }
            }
        }

        //判断一个商品仅允许添加入一个活动
        $allActivityGoods = ActivityGoods::where(['goods_sn' => $goodsSn, 'status' => $this->getStatusByRequestModule(1)])->field('goods_sn,title,status,activity_id')->select()->toArray();
        if (!empty($allActivityGoods)) {
            foreach ($allActivityGoods as $key => $value) {
                if (in_array($value['goods_sn'], $goodsSn) && ($aId != $value['activity_id'])) {
                    throw new ActivityException(['msg' => $value['title'] . '已经存在于其他活动中,为确保系统数据检验,一个商品仅允许添加一个活动']);
                }
            }
        }

        //判断一个商品不允许同时添加到福利活动内
        $allCrowdActivityGoods = CrowdfundingActivityGoods::where(['goods_sn' => $goodsSn, 'status' => $this->getStatusByRequestModule(1)])->field('goods_sn,title,status')->select()->toArray();
        if (!empty($allCrowdActivityGoods)) {
            foreach ($allCrowdActivityGoods as $key => $value) {
                if (in_array($value['goods_sn'], $goodsSn)) {
                    throw new ActivityException(['msg' => $value['title'] . '已经存在于福利活动中,为确保系统数据检验,本商品无法继续操作']);
                }
            }
        }


        $goodsList = GoodsSpu::where(['goods_sn' => $goodsSn, 'status' => $this->getStatusByRequestModule(1)])->field('goods_sn,main_image,title,sub_title')->withMin('sku', 'sale_price')->select()->toArray();
        if (empty($goodsList)) {
            throw new ServiceException(['msg' => '无有效商品']);
        }

        $goodsSkuList = GoodsSku::where(['sku_sn' => $goodsSkuSn, 'status' => $this->getStatusByRequestModule(1)])->field('goods_sn,sku_sn,image,title,sub_title,specs,stock,sale_price,virtual_stock')->select()->toArray();
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
        $dbRes = Db::transaction(function () use ($goodsList, $activityInfo, $goodsSn, $startTime, $endTime, $goodsSkuList, $goods, $goodsPrice, $data) {

            foreach ($goodsList as $key => $value) {
                $dbData = $value;
                $dbData['sale_price'] = $value['sku_min'];
                $dbData['image'] = $value['main_image'];
                $dbData['activity_id'] = $activityInfo['id'];
                $dbData['activity_type'] = $activityInfo['type'];
                $dbData['limit_type'] = $activityInfo['limit_type'];
                $dbData['activity_price'] = min(array_column($goodsPrice[$value['goods_sn']], 'activity_price'));
//                $dbData['sale_number'] = $data['sale_number'][$value['goods_sn']] ?? 0;
                $dbData['vip_level'] = $data['vip_level'][$value['goods_sn']] ?? 0;
                if ($activityInfo['limit_type'] == 1) {
                    $dbData['start_time'] = $startTime;
                    $dbData['end_time'] = $endTime;
                }
                $dbData['desc'] = current(array_column($goodsPrice[$value['goods_sn']], 'desc')) ?? null;

                $goodsRes = $this->updateOrCreate(['activity_id' => $dbData['activity_id'], 'goods_sn' => $dbData['goods_sn'], 'status' => $this->getStatusByRequestModule(1)], $dbData);
                Cache::tag(['apiWarmUpActivityInfo' . ($dbData['goods_sn'] ?? '')])->clear();
            }

            if (!empty($goodsSkuList)) {
                $activityGoodsSkuModel = (new ActivityGoodsSku());
                foreach ($goods as $key => $value) {
                    foreach ($goodsSkuList as $gKey => $gValue) {
                        if ($value['sku_sn'] == $gValue['sku_sn']) {
                            $skuData = $gValue;
                            $skuData['activity_price'] = $value['activity_price'];
                            $skuData['growth_value'] = $value['growth_value'] ?? 0;
                            $skuData['vip_level'] = $value['vip_level'] ?? 0;
                            $skuData['sale_number'] = $value['sale_number'] ?? 0;
                            $skuData['activity_id'] = $activityInfo['id'];
                            $skuData['custom_growth'] = $value['custom_growth'] ?? 2;
                            //普通活动如果不需要自定义商品成长值一定为0
                            if ($skuData['custom_growth'] == 2 && $activityInfo['a_type'] == 1) {
                                $skuData['growth_value'] = 0;
                            }
                            $skuData['limit_buy_number'] = $value['limit_buy_number'] ?? 0;
                            $skuData['gift_type'] = $value['gift_type'] ?? -1;
                            $skuData['gift_number'] = $value['gift_number'] ?? 0;
                            $skuRes = $activityGoodsSkuModel->updateOrCreate(['activity_id' => $skuData['activity_id'], 'goods_sn' => $skuData['goods_sn'], 'sku_sn' => $skuData['sku_sn'], 'status' => $this->getStatusByRequestModule(1)], $skuData);
                        }

                    }
                }
            }

            return $goodsRes;
        });

        if (empty($data['noClearCache'] ?? null)) {
            cache('HomeApiActivityList', null);
            cache('ApiHomeAllList', null);
            //清除首页活动列表标签的缓存
            Cache::tag(['HomeApiActivityList', 'ApiHomeAllList'])->clear();
        }

        return judge($dbRes);

    }


    /**
     * @title  活动商品列表
     * @param array $sear
     * @return array
     * @throws Exception
     */
    public function list(array $sear = []): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('title', $sear['keyword']))];
        }
        $map[] = ['activity_id', '=', $sear['aId']];
        $map[] = ['status', 'in', [1, 2]];
        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->where($map)->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->withSum(['SaleGoods'], 'count')
            ->withMin(['sku' => 'limit_buy_number'], 'limit_buy_number')
            ->withSum('goodsStock', 'stock')
            ->withSum(['goodsStock' => 'virtual_stock'], 'virtual_stock')->order('sort asc,create_time desc,id asc')->select()->each(function ($item) {
                if (!empty($item['show_start_time'])) {
                    $item['show_start_time'] = timeToDateFormat($item['show_start_time']);
                }
                if ($item['activity_id'] == 1) {
                    $item['salePercentage'] = 0;
                    //虚拟销量
                    $item['virtual_sale_number'] = ActivityGoodsSku::where(['activity_id' => $item['activity_id'], 'status' => 1, 'goods_sn' => $item['goods_sn']])->sum('sale_number');
                    if (intval($item['virtual_sale_number']) <= 0) {
                        $item['virtual_sale_number'] = 0;
                    }
                    $realStock = $item['goods_stock_sum'];
                    $realSale = $item['sale_goods_sum'] ?? 0;
                    //添加虚拟销量
                    if ($item['limit_type'] == 1) {
                        if (strtotime($item['start_time']) > time()) {
                            $item['virtual_sale_number'] = 0;
                            $item['virtual_stock'] = 0;
                        }
                    }
                    if (intval($item['goods_stock_sum']) <= 0) {
                        $item['salePercentage'] = 100;
                    } else {
                        //比例应该用(虚拟销量+实际销量)/(剩余库存+实际销量+虚拟库存)
                        $item['salePercentage'] = (sprintf("%.2f", (intval($item['virtual_sale_number'] + $realSale) / intval($realStock + $realSale + $item['virtual_stock'])))) * 100;
                        if (doubleval($item['salePercentage']) <= 1) {
                            $item['salePercentage'] = 1;
                        }
                        if (doubleval($item['salePercentage']) >= 100) {
                            $item['salePercentage'] = 100;
                        }
                    }
//                $item['goods_stock_sum'] -= $item['virtual_sale_number'];
                    $item['goods_stock_sum'] = $item['goods_stock_sum'] <= 0 ? 0 : intval($item['goods_stock_sum']);
                    $item['real_sale_goods_sum'] = $realSale;
                    $item['sale_goods_sum'] += $item['virtual_sale_number'];
                    $item['sale_goods_sum'] = $item['sale_goods_sum'] <= 0 ? 0 : intval($item['sale_goods_sum']);
                    $item['sale_price'] = $item['activity_price'];
                    unset($item['virtual_sale_number']);

                    //如果没到开始时间无论如何百分比都为0
                    if ($item['limit_type'] == 1) {
                        if (strtotime($item['start_time']) > time()) {
                            $item['salePercentage'] = 0;
                        }
                    }
                    if (!empty($item['salePercentage'])) {
                        $item['salePercentage'] = intval($item['salePercentage']);
                    }
                }
                return $item;
            })->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  删除商品
     * @param string $id
     * @param bool $noClearCache 是否清除对应的缓存
     * @return mixed
     */
    public function del(string $id, bool $noClearCache = false)
    {
        $goodsInfo = $this->where([$this->getPk() => $id])->findOrEmpty()->toArray();
        $res = $this->where([$this->getPk() => $id])->save(['status' => -1]);
        ActivityGoodsSku::where(['goods_sn' => $goodsInfo['goods_sn'], 'activity_id' => $goodsInfo['activity_id']])->save(['status' => -1]);

        if (empty($noClearCache)) {
            if ($res) {
                cache('ApiHomeAllList', null);
                cache('HomeApiActivityList', null);
                //清除首页活动列表标签的缓存
                Cache::tag(['HomeApiActivityList', 'ApiHomeAllList'])->clear();
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
                $res = $this->where(['activity_id' => $value['activity_id'], 'goods_sn' => $value['goods_sn']])->save(['sort' => $value['sort'] ?? 1]);
            }
            return true;
        });

        cache('ApiHomeAllList', null);
        cache('HomeApiActivityList', null);
        cache('HomeApiOtherActivityList', null);
        //清除首页活动列表标签的缓存
        Cache::tag(['apiHomeGoodsList', 'HomeApiActivityList'])->clear();

        return judge($DBRes);
    }

    /**
     * @title  计算限时秒杀活动进度条的虚拟库存和虚拟销量
     * @param array $data
     * @return false|float|int
     * @throws \Exception
     */
    public function computeProgressBarVirtualSaleNumber(array $data)
    {
        $DBRes = false;
        $scale = $data['scale'];
        if ($scale <= 0 || $scale >= 100) {
            throw new ActivityException(['msg' => '可调整进度条的数值为1-99%']);
        }
        $scale /= 100;

        $map[] = ['goods_sn', '=', $data['goods_sn']];
        $map[] = ['status', '=', 1];

        $goodsInfo = GoodsSpu::where($map)
            ->field('goods_sn,title')
            ->withSum(['SaleGoods'], 'count')
            ->withSum('goodsStock', 'stock')->findOrEmpty()->toArray();

        //由公式 比例 = (虚拟销量+实际销量)/(剩余库存+实际销量+虚拟库存),设虚拟库存和虚拟销量同事为X,得出X = ((比例 - 1) * 虚拟销量 + (比例 * 剩余库存)) / (1 - 比例), 若计算出结果为负证明此时无需虚拟销量计算出来的比例已经超过所需的比例,若为正数则向上取整得出需要的虚拟销量和虚拟库存
        if (empty($goodsInfo)) {
            throw new ActivityException(['msg' => '查无此商品信息']);
        }

        $VirtualNumber = (($scale - 1) * ($goodsInfo['sale_goods_sum'] ?? 0) + ($scale * $goodsInfo['goods_stock_sum'])) / (1 - $scale);

        if ($VirtualNumber > 0) {
            $VirtualNumber = ceil($VirtualNumber);
        } else {
            $VirtualNumber = 0;
        }

        if (empty($VirtualNumber)) {
            throw new ActivityException(['msg' => '当前已达到填写的进度,无需重复设置']);
        }

        $allActivitySku = ActivityGoodsSku::where(['status' => 1, 'goods_sn' => $data['goods_sn'], 'activity_id' => $data['activity_id']])->field('goods_sn,sku_sn,sale_number,activity_id')->select()->toArray();
        $allGoodsSku = GoodsSku::where(['status' => 1, 'goods_sn' => $data['goods_sn']])->field('goods_sn,sku_sn,virtual_stock')->select()->toArray();
        if (empty($allActivitySku)) {
            throw new ActivityException(['msg' => '暂无有效参与活动的SKU,无法设置~']);
        }
        $allActivitySkuCount = count($allActivitySku);
        if ($allActivitySkuCount == 1) {
            $onceSkuVirtualNumber = $VirtualNumber;
        } else {
            $onceSkuVirtualNumber = floor($VirtualNumber / $allActivitySkuCount);
        }

        $lastNumber = $VirtualNumber;
        foreach ($allActivitySku as $key => $value) {
            $activitySkuSave[$key]['activity_id'] = $value['activity_id'];
            $activitySkuSave[$key]['goods_sn'] = $value['goods_sn'];
            $activitySkuSave[$key]['sku_sn'] = $value['sku_sn'];
            if ($key == ($allActivitySkuCount - 1)) {
                $activitySkuSave[$key]['sale_number'] = $lastNumber;
            } else {
                $activitySkuSave[$key]['sale_number'] = $onceSkuVirtualNumber;
                $lastNumber -= $onceSkuVirtualNumber;
            }
        }
        $goodsSkuSave = $activitySkuSave;
        foreach ($goodsSkuSave as $key => $value) {
            $goodsSkuSave[$key]['virtual_stock'] = $value['sale_number'];
            unset($goodsSkuSave[$key]['sale_number']);
            unset($goodsSkuSave[$key]['activity_id']);
        }

        //如果商品库SKU数量不等于参与活动SKU数量,则表明该商品不是全量参与活动,需要把不参与活动的SKU虚拟库存设为0
//            if (count($allGoodsSku) != count($allActivitySku)) {
//                $goodsSkuSaveCount = count($goodsSkuSave);
//                foreach ($allGoodsSku as $key => $value) {
//                    if (!in_array($value['sku_sn'], array_column($allActivitySku, 'sku_sn'))) {
//                        $goodsSkuSave[$goodsSkuSaveCount]['goods_sn'] = $value['goods_sn'];
//                        $goodsSkuSave[$goodsSkuSaveCount]['sku_sn'] = $value['sku_sn'];
//                        $goodsSkuSave[$goodsSkuSaveCount]['virtual_stock'] = 0;
//                        $goodsSkuSaveCount++;
//                    }
//                }
//            }

        //数据库操作
        $DBRes = Db::transaction(function () use ($activitySkuSave, $goodsSkuSave) {
            foreach ($activitySkuSave as $key => $value) {
                if ($value['sale_number'] > 0) {
                    $aSkuRes[] = ActivityGoodsSku::update(['sale_number' => $value['sale_number']], ['activity_id' => $value['activity_id'], 'goods_sn' => $value['goods_sn'], 'sku_sn' => $value['sku_sn'], 'status' => 1])->getData();
                }
            }
            foreach ($goodsSkuSave as $key => $value) {
                if ($value['virtual_stock'] > 0) {
                    $gSkuRes[] = GoodsSku::update(['virtual_stock' => $value['virtual_stock']], ['goods_sn' => $value['goods_sn'], 'sku_sn' => $value['sku_sn'], 'status' => 1])->getData();
                }
            }
            return ['aSkuRes' => $aSkuRes ?? [], 'gSkuRes' => $gSkuRes ?? []];
        });

        if (empty($data['noClearCache'] ?? null)) {
            cache('HomeApiActivityList', null);
            cache('ApiHomeAllList', null);
            //清除首页活动列表标签的缓存
            Cache::tag(['HomeApiActivityList', 'ApiHomeAllList'])->clear();
        }

        return $DBRes;
    }

    /**
     * @title  更新活动商品SPU销量
     * @param array $data
     * @return ActivityGoods
     */
    public function updateActivityGoodsSpuSaleNumber(array $data)
    {
        $activityId = $data['activity_id'];
        $goodsSn = $data['goods_sn'];
        if (!is_numeric($data['sale_number'] ?? null) || $data['sale_number'] < 0) {
            throw new ActivityException(['msg' => '虚拟销量必须为正整数']);
        }

        //商品信息
        $goodsInfo = self::where(['activity_id' => $activityId, 'goods_sn' => $goodsSn, 'status' => [1, 2]])->findOrEmpty()->toArray();

        if (empty($goodsInfo)) {
            throw new ActivityException(['msg' => '不存在的活动商品信息']);
        }
        $DBRes = false;
        $saleNumber = intval($data['sale_number'] ?? 0);
        $DBRes = self::update(['sale_number' => $saleNumber], ['activity_id' => $activityId, 'goods_sn' => $goodsSn, 'status' => [1, 2]]);

        //清楚缓存
        if(empty($data['noClearCache'] ?? null)){
            cache('HomeApiActivityList',null);
            cache('ApiHomeAllList',null);
            //清除首页活动列表标签的缓存
            Cache::tag(['HomeApiActivityList','ApiHomeAllList'])->clear();
        }

        return $DBRes;
    }


    public function goodsStock()
    {
        return $this->hasMany('GoodsSku', 'goods_sn', 'goods_sn')->where(['status' => 1]);
    }

    public function saleGoods()
    {
        return $this->hasMany('OrderGoods', 'goods_sn', 'goods_sn')->where(['pay_status' => 2, 'status' => 1]);
    }

    public function marketPrice()
    {
        return $this->hasMany('GoodsSku', 'goods_sn', 'goods_sn')->where(['status' => 1]);
    }

    public function getStartTimeAttr($value)
    {
        return !empty($value) ? timeToDateFormat($value) : $value;
    }

    public function getEndTimeAttr($value)
    {
        return !empty($value) ? timeToDateFormat($value) : $value;
    }

    public function activity()
    {
        return $this->hasOne('Activity', 'id', 'activity_id')->field('id,title,a_type,desc,type,limit_type');
    }

    public function activitySku()
    {
        return $this->hasMany('ActivityGoodsSku', 'goods_sn', 'goods_sn')->where(['status' => 1]);
    }

    public function sku()
    {
        return $this->hasMany('ActivityGoodsSku', 'goods_sn', 'goods_sn')->where(['status' => 1]);
    }

    private function getListFieldByModule()
    {
        switch ($this->module) {
            case 'admin':
            case 'manager':
                $field = '*';
                break;
            case 'api':
                $field = 'goods_sn,title,image,sub_title,sale_price,activity_price,sort,limit_type,start_time,end_time,sale_number,activity_id,vip_level,sale_number as spu_sale_number';
                break;
            default:
                $field = 'a.order_sn,a.uid,b.name as user_name,a.goods_sn,a.sku_sn,c.title as goods_title,c.image as goods_images,a.type,a.apply_reason,a.apply_status,a.apply_price,a.received_goods,a.verify_status,a.verify_reason,a.apply_time,a.verify_time,a.create_time,a.status';
        }
        return $field;
    }


}