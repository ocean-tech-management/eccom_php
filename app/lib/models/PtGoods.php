<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 拼团活动商品]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\ActivityException;
use app\lib\exceptions\PtException;
use app\lib\exceptions\ServiceException;
use Exception;
use think\facade\Cache;
use think\facade\Db;

class PtGoods extends BaseModel
{
    protected $validateFields = ['activity_code'];

    /**
     * @title  C端拼团商品SPU列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function cList(array $sear)
    {
        $cacheKey = false;
        $cacheExpire = false;
        if ($this->module == 'api') {
            if (!empty($sear['cache'])) {
                $cacheKey = $sear['cache'] ?? false;
                $cacheExpire = $sear['cache_expire'] ?? false;
            }
        }
        if (!empty($sear['activity_code'])) {
            $map[] = ['activity_code', '=', $sear['activity_code']];
        }
        $map[] = ['status', '=', 1];
        $page = intval($sear['page'] ?? 0) ?: null;

        if (!empty($sear['pageNumber'])) {
            $this->pageNumber = intval($sear['pageNumber']);
        }
        $map[] = ['end_time', '>=', time()];
        if (!empty($page)) {
            $aTotal = $this->where($map)->when($cacheKey, function ($query) use ($cacheKey, $cacheExpire) {
                $query->cache($cacheKey . 'Num', $cacheExpire);
            })->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->with(['activity', 'sku'])->where($map)->withoutField('id,update_time,status')->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('create_time desc')
            ->when($cacheKey, function ($query) use ($cacheKey, $cacheExpire) {
                $query->cache($cacheKey, $cacheExpire)->withCache(['goods', 60], $cacheExpire);
            })->select()->toArray();

        if (!empty($list)) {
            foreach ($list as $key => $value) {
                if (!empty($value['activity']) && ($value['activity']['status'] != 1 || $value['activity']['end_time'] >= time())) {
                    unset($list[$key]);
                }
            }
        }

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  拼团活动商品列表
     * @param array $sear
     * @return array
     * @throws Exception
     */
    public function list(array $sear = []): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('title', $sear['keyword']))];
        }
        $map[] = ['activity_code', '=', $sear['activity_code']];
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->where($map)->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('sort asc,create_time desc')->select()->toArray();;

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }


    /**
     * @title  添加/编辑拼团活动商品
     * @param array $data
     * @return bool
     * @throws \Exception
     */
    public function DBNewOrEdit(array $data)
    {
        $activityCode = $data['activity_code'];
        $goods = $data['goods'];
        $startTime = null;
        $endTime = null;
        $goodsPrice = [];
        $activityInfo = PtActivity::where(['activity_code' => $activityCode, 'status' => $this->getStatusByRequestModule(1)])->withoutField('create_time,update_time,status')->findOrEmpty()->toArray();
        if (empty($activityInfo)) {
            throw new ServiceException(['msg' => '未知的活动']);
        }

        if (empty($data['start_time']) || empty($data['end_time'])) {
            throw new ServiceException(['msg' => '请选择开始和结束时间']);
        }
        $startTime = strtotime($data['start_time']);
        $endTime = strtotime($data['end_time']);
        $goodsSn = array_unique(array_filter(array_column($goods, 'goods_sn')));
        $goodsSkuSn = array_unique(array_filter(array_column($goods, 'sku_sn')));

        $goodsList = GoodsSpu::where(['goods_sn' => $goodsSn, 'status' => $this->getStatusByRequestModule(1)])->field('goods_sn,main_image,title,sub_title')->withMin('sku', 'sale_price')->select()->toArray();
        if (empty($goodsList)) {
            throw new ServiceException(['msg' => '无有效商品']);
        }

        $goodsSkuList = GoodsSku::with(['vdc'])->where(['sku_sn' => $goodsSkuSn, 'status' => $this->getStatusByRequestModule(1)])->field('goods_sn,sku_sn,image,title,sub_title,market_price,sub_title,specs,sale_price')->select()->toArray();
        foreach ($goods as $key => $value) {
            $goodsPrice[$value['goods_sn']][] = $value;
        }

        //判断一个商品仅允许添加入一个拼团活动
        $allPtGoods = PtGoods::where(['goods_sn' => $goodsSn, 'status' => $this->getStatusByRequestModule(1)])->field('goods_sn,title,status,activity_code')->select()->toArray();
        if (!empty($allPtGoods)) {
            foreach ($allPtGoods as $key => $value) {
                if (in_array($value['goods_sn'], $goodsSn) && ($activityCode != $value['activity_code'])) {
                    throw new ActivityException(['msg' => $value['title'] . '已经存在于其他活动中,为确保系统数据检验,一个商品仅允许添加一个拼团活动']);
                }
            }
        }


        $dbRes = false;
        $dbRes = Db::transaction(function () use ($goodsList, $activityInfo, $goodsSn, $startTime, $endTime, $goodsSkuList, $goods, $goodsPrice, $activityCode) {

            foreach ($goodsList as $key => $value) {
                $dbData = $value;
                $dbData['sale_price'] = $value['sku_min'];
                $dbData['image'] = $value['main_image'];
                $dbData['activity_code'] = $activityInfo['activity_code'];
                $dbData['activity_price'] = min(array_column($goodsPrice[$value['goods_sn']], 'activity_price'));
                $dbData['start_time'] = $startTime;
                $dbData['end_time'] = $endTime;
                $dbData['desc'] = current(array_column($goodsPrice[$value['goods_sn']], 'desc')) ?? null;
                $skuData['share_title'] = current(array_column($goodsPrice[$value['goods_sn']], 'share_title')) ?? null;
                $skuData['share_desc'] = current(array_column($goodsPrice[$value['goods_sn']], 'share_desc')) ?? null;
                $skuData['share_cover'] = current(array_column($goodsPrice[$value['goods_sn']], 'share_cover')) ?? null;
                $goodsRes = $this->updateOrCreate(['activity_code' => $dbData['activity_code'], 'goods_sn' => $dbData['goods_sn'], 'status' => $this->getStatusByRequestModule(1)], $dbData);

                Cache::tag(['apiWarmUpPtActivityInfo' . ($dbData['goods_sn'] ?? '')])->clear();
            }

            if (!empty($goodsSkuList)) {
                $activityGoodsSkuModel = (new PtGoodsSku());
                $existPtSku = $activityGoodsSkuModel->where(['activity_code' => $activityCode, 'goods_sn' => array_unique(array_filter(array_column($goods, 'goods_sn'))), 'sku_sn' => array_unique(array_filter(array_column($goods, 'sku_sn'))), 'status' => [1, 2]])->column('id', 'sku_sn');

                foreach ($goods as $key => $value) {
                    foreach ($goodsSkuList as $gKey => $gValue) {
                        if ($value['sku_sn'] == $gValue['sku_sn']) {
                            $skuData = $gValue;
                            $skuData['activity_price'] = $value['activity_price'];
                            $skuData['activity_code'] = $activityInfo['activity_code'];
                            $skuData['market_price'] = $gValue['market_price'];
                            $skuData['sale_price'] = $gValue['sale_price'];
                            if (in_array($activityInfo['type'], [2])) {
                                if (empty($value['leader_price'])) {
                                    throw new PtException(['errorCode' => 2100113]);
                                }
//                                else{
//                                    if($value['leader_price'] <= 0 || (string)$value['leader_price'] < (string))
//                                }
                            }
                            if (!empty($value['stock']) && intval($value['stock']) % intval($activityInfo['group_number']) != 0) {
                                throw new PtException(['msg' => '为提高成团率,请保证拼团商品库存必须能整除成团人数,当前拼团活动要求成团人数为' . $activityInfo['group_number']]);
                            }

                            //如果不存在老记录才能修改库存
                            if (empty($existPtSku[$gValue['sku_sn']])) {
                                if (intval($value['stock']) <= 0) {
                                    throw new PtException(['msg' => '拼团商品库存不允许设置为小于0']);
                                }
                                $skuData['stock'] = $value['stock'];
                                $skuData['start_number'] = intval(intval($value['stock']) / intval($activityInfo['group_number']));
                            }
                            $skuData['share_title'] = $value['share_title'] ?? null;
                            $skuData['share_desc'] = $value['share_desc'] ?? null;
                            $skuData['share_cover'] = $value['share_cover'] ?? null;
                            $skuRes = $activityGoodsSkuModel->updateOrCreate(['activity_code' => $skuData['activity_code'], 'goods_sn' => $skuData['goods_sn'], 'sku_sn' => $skuData['sku_sn'], 'status' => $this->getStatusByRequestModule(1)], $skuData);
                        }

                    }
                }
            }

            return $goodsRes;
        });

        //清除首页活动列表标签的缓存
        cache('ApiHomePtList', null);
        cache('ApiHomeAllList', null);
        return judge($dbRes);

    }

    /**
     * @title  删除商品
     * @param string $id
     * @return mixed
     */
    public function del(string $id)
    {
        $goodsInfo = $this->where([$this->getPk() => $id])->findOrEmpty()->toArray();
        if ($this->checkExistOrder(['activity_code' => $goodsInfo['activity_code'], 'goods_sn' => $goodsInfo['goods_sn'], 'activity_status' => [1]])) {
            throw new PtException(['msg' => '该拼团活动商品存在尚未拼团成功的订单,不允许删除!']);
        }
        $res = $this->where([$this->getPk() => $id])->save(['status' => -1]);
        PtGoodsSku::where(['goods_sn' => $goodsInfo['goods_sn'], 'activity_code' => $goodsInfo['activity_code']])->save(['status' => -1]);

        //清除首页活动列表标签的缓存
        cache('ApiHomePtList', null);
        cache('ApiHomeAllList', null);
        Cache::tag(['apiWarmUpPtActivityInfo' . ($goodsInfo['goods_sn'] ?? '')])->clear();
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
                $res = $this->where(['activity_code' => $value['activity_code'], 'goods_sn' => $value['goods_sn']])->save(['sort' => $value['sort'] ?? 1]);
            }
            return true;
        });

        //清除首页活动列表标签的缓存
        cache('ApiHomePtList', null);
        cache('ApiHomeAllList', null);

        return judge($DBRes);
    }

    /**
     * @title  检查拼团活动商品是否存在有效订单
     * @param array $data
     * @return bool
     */
    public function checkExistOrder(array $data)
    {
        $activityCode = $data['activity_code'];
        $goodsSn = $data['goods_sn'];
        $orderStatus = $data['pay_status'] ?? [1, 2];
        $activityStatus = $data['activity_status'] ?? [1, 2];
        if (empty($activityCode) || empty($goodsSn)) {
            return true;
        }
        $ptOrder = PtOrder::where(['pay_status' => $orderStatus, 'goods_sn' => $goodsSn, 'activity_code' => $activityCode, 'activity_status' => $activityStatus])->count();
        if (!empty($ptOrder)) {
            return true;
        }

        return false;
    }


    public function sku()
    {
        return $this->hasMany('PtGoodsSku', 'goods_sn', 'goods_sn')->where(['status' => 1])->withoutField('id,create_time,update_time,status');
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
        return $this->hasOne('PtActivity', 'activity_code', 'activity_code')->withoutField('id,create_time,update_time');
    }
}