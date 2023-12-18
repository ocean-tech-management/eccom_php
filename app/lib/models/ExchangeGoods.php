<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 兑换商品模块Model]
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

class ExchangeGoods extends BaseModel
{

    protected $validateFields = ['goods_sn'];

    /**
     * @title  添加/编辑活动商品
     * @param array $data
     * @return bool
     * @throws \Exception
     */
    public function DBNewOrEdit(array $data)
    {
        $goods = $data['goods'];

        $startTime = null;
        $endTime = null;
        $goodsPrice = [];

        $goodsSn = array_unique(array_column($goods, 'goods_sn'));
        $goodsSkuSn = array_unique(array_column($goods, 'sku_sn'));


        if (!empty($data['limit_type'] ?? null) && $data['limit_type'] == 1) {
            if (empty($data['start_time']) || empty($data['end_time'])) {
                throw new ServiceException(['msg' => '限时活动请选择时间']);
            }
            $startTime = strtotime($data['start_time']);
            $endTime = strtotime($data['end_time']);
        }

        //判断一个商品仅允许添加入一个活动
        $allActivityGoods = self::where(['goods_sn' => $goodsSn, 'type' => $data['type'], 'status' => $this->getStatusByRequestModule(1)])->field('goods_sn,title,status,type')->select()->toArray();
        if (!empty($allActivityGoods)) {
            foreach ($allActivityGoods as $key => $value) {
                if (in_array($value['goods_sn'], $goodsSn) && ($data['type'] != $value['type'])) {
                    throw new ActivityException(['msg' => $value['title'] . '已存在该商品,为确保系统数据检验,不允许重复添加']);
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
        $dbRes = Db::transaction(function () use ($goodsList, $goodsSn, $startTime, $endTime, $goodsSkuList, $goods, $goodsPrice, $data) {

            foreach ($goodsList as $key => $value) {
                $dbData = $value;
                $dbData['sale_price'] = $value['sku_min'];
                $dbData['image'] = $value['main_image'];
                $dbData['limit_type'] = $data['limit_type'] ?? 2;
                $dbData['type'] = $data['type'] ?? 1;
                $dbData['activity_price'] = 0;
//                $dbData['sale_number'] = $data['sale_number'][$value['goods_sn']] ?? 0;
                $dbData['vip_level'] = $data['vip_level'][$value['goods_sn']] ?? 0;
                if ($dbData['limit_type'] == 1) {
                    $dbData['start_time'] = $startTime;
                    $dbData['end_time'] = $endTime;
                }
                $dbData['desc'] = current(array_column($goodsPrice[$value['goods_sn']], 'desc')) ?? null;

                $goodsRes = $this->updateOrCreate(['type' => $dbData['type'], 'goods_sn' => $dbData['goods_sn'], 'status' => $this->getStatusByRequestModule(1)], $dbData);
            }

            if (!empty($goodsSkuList)) {
                $activityGoodsSkuModel = (new ExchangeGoodsSku());
                foreach ($goods as $key => $value) {
                    foreach ($goodsSkuList as $gKey => $gValue) {
                        if ($value['sku_sn'] == $gValue['sku_sn']) {
                            $skuData = $gValue;
                            $skuData['activity_price'] = 0;
                            $skuData['growth_value'] = 0;
                            $skuData['type'] = $value['type'] ?? 1;
                            if (empty($value['id'] ?? null)) {
                                $skuData['stock'] = $value['stock'] ?? 0;
                            }
                            $skuData['exchange_value'] = $value['exchange_value'] ?? 0;
                            $skuData['vip_level'] = $value['vip_level'] ?? 0;
                            $skuData['sale_number'] = $value['sale_number'] ?? 0;
                            $skuData['custom_growth'] = $value['custom_growth'] ?? 2;
                            $skuData['auto_ship'] = $value['auto_ship'] ?? 2;
                            $skuData['auto_complete'] = $value['auto_complete'] ?? 2;
                            $skuRes = $activityGoodsSkuModel->updateOrCreate(['type' => $skuData['type'], 'goods_sn' => $skuData['goods_sn'], 'sku_sn' => $skuData['sku_sn'], 'status' => $this->getStatusByRequestModule(1)], $skuData);
                        }

                    }
                }
            }

            return $goodsRes;
        });

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
        if (!empty($sear['type'])) {
            $map[] = ['type', '=', $sear['type']];
        }
        $map[] = ['status', 'in', [1, 2]];
        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->where($map)->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->withSum(['SaleGoods'], 'count')->order('sort asc,create_time desc')->select()->each(function ($item) {
            if (!empty($item['show_start_time'])) {
                $item['show_start_time'] = timeToDateFormat($item['show_start_time']);
            }
            return $item;
        })->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  C端列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function cList(array $sear = []): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('title', $sear['keyword']))];
        }
        if (!empty($sear['type'])) {
            $map[] = ['type', '=', $sear['type']];
        }
        $map[] = ['status', 'in', [1]];
        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->where($map)->where(function($query){
            $where1[] = ['limit_type', '=', 2];
            $where2[] = ['limit_type', '=', 1];
            $where2[] = ['end_time', '>=', time()];
            $query->whereOr([$where1, $where2]);
        })->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('sort asc,create_time desc')->select()->each(function ($item) {
            if (!empty($item['show_start_time'])) {
                $item['show_start_time'] = timeToDateFormat($item['show_start_time']);
            }
            return $item;
        })->toArray();
        if (!empty($list)) {
            //查找市场价
            $allGoodsSn = array_column($list, 'goods_sn');
            $goodsInfo = GoodsSpu::where(['goods_sn' => $allGoodsSn, 'status' => 1])
                ->field('goods_sn')
                ->withMin(['sku' => 'market_price'], 'market_price')
                ->withSum(['sku' => 'goods_stock_sum'], 'stock')->select()->toArray();
            if (!empty($goodsInfo)) {
                foreach ($goodsInfo as $key => $value) {
                    $goodsInfos[$value['goods_sn']]['market_price'] = $value['market_price'];
                    $goodsInfos[$value['goods_sn']]['goods_stock_sum'] = $value['goods_stock_sum'];
                }
            }

            foreach ($list as $gKey => $gValue) {
                //剔除未开始的商品
                if (!empty($gValue['start_time'] ?? null) && $gValue['start_time'] > time()) {
                    unset($list[$gKey]);
                    continue;
                }
                if (!empty($gValue['start_time'])) {
                    $list[$gKey]['start_time'] = timeToDateFormat($gValue['start_time']);
                }
                if (!empty($gValue['end_time'])) {
                    $list[$gKey]['end_time'] = timeToDateFormat($gValue['end_time']);
                }
                //图片展示缩放,OSS自带功能
                if (!empty($gValue['image'])) {
//                                $gValue['image'] .= '?x-oss-process=image/resize,h_400,m_lfit';
                    $list[$gKey]['image'] .= '?x-oss-process=image/format,webp';
                }
                if (!empty($gValue['poster'])) {
                    $list[$gKey]['poster'] .= '?x-oss-process=image/format,webp';
                }
                $list[$gKey]['market_price'] = $gValue['sale_price'];
                $list[$gKey]['goods_stock_sum'] = 0;
                if (!empty($goodsInfos[$gValue['goods_sn']])) {
                    $list[$gKey]['market_price'] = $goodsInfos[$gValue['goods_sn']]['market_price'] ?? $gValue['sale_price'];
                    $list[$gKey]['goods_stock_sum'] = $goodsInfos[$gValue['goods_sn']]['goods_stock_sum'] ?? 0;
                }
            }
        }

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
        ExchangeGoodsSku::where(['goods_sn' => $goodsInfo['goods_sn'], 'type' => $goodsInfo['type']])->save(['status' => -1]);

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
                $res = $this->where(['goods_sn' => $value['goods_sn'], 'type' => $value['type']])->save(['sort' => $value['sort'] ?? 1]);
            }
            return true;
        });

        return judge($DBRes);
    }


    public function goodsStock()
    {
        return $this->hasMany('ExchangeGoodsSku', 'goods_sn', 'goods_sn')->where(['status' => 1]);
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


    public function activitySku()
    {
        return $this->hasMany('ExchangeGoodsSku', 'goods_sn', 'goods_sn')->where(['status' => 1]);
    }

    public function sku()
    {
        return $this->hasMany('ExchangeGoodsSku', 'goods_sn', 'goods_sn')->where(['status' => 1]);
    }

    private function getListFieldByModule()
    {
        switch ($this->module) {
            case 'admin':
            case 'manager':
                $field = '*';
                break;
            case 'api':
                $field = 'goods_sn,title,image,sub_title,sale_price,sort,limit_type,start_time,end_time,sale_number,vip_level,sale_number as spu_sale_number';
                break;
            default:
                $field = 'a.order_sn,a.uid,b.name as user_name,a.goods_sn,a.sku_sn,c.title as goods_title,c.image as goods_images,a.type,a.apply_reason,a.apply_status,a.apply_price,a.received_goods,a.verify_status,a.verify_reason,a.apply_time,a.verify_time,a.create_time,a.status';
        }
        return $field;
    }


}