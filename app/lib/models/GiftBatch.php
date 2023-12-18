<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 礼品卡批次模块Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\GiftCardException;
use app\lib\services\CodeBuilder;
use think\facade\Db;

class GiftBatch extends BaseModel
{
    protected $validateFields = ['batch_sn'];

    /**
     * @title  礼品批次列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear = []): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('title', $sear['keyword']))];
        }
        if (!empty($sear['type'])) {
            $map[] = ['type', '=', $sear['type']];
        }
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->with(['batchGoods' => function ($query) {
            $query->where(['status' => [1, 2]]);
        }])->withoutField('id,update_time')->where($map)
            ->when($page, function ($query) use ($page) {
                $query->page($page, $this->pageNumber);
            })->order('create_time desc')->select()->each(function ($item) {
                if (!empty($item['start_time'])) {
                    $item['start_time'] = timeToDateFormat($item['start_time']);
                }
                if (!empty($item['end_time'])) {
                    $item['end_time'] = timeToDateFormat($item['end_time']);
                }
            })->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  批次详情
     * @param array $sear
     * @return mixed
     * @throws \Exception
     */
    public function info(array $sear)
    {
        $batchSn = $sear['batch_sn'];
        if (empty($batchSn)) {
            throw new GiftCardException(['errorCode' => 2500106]);
        }
        //批次详情
        $info = $this::with(['batchGoods' => function ($query) {
            $query->where(['status' => [1, 2]]);
        }])->withoutField('id,update_time')->where(['batch_sn' => $batchSn, 'status' => $this->getStatusByRequestModule(1)])->findOrEmpty()->toArray();

        if (!empty($info)) {
            if (!empty($info['start_time'])) {
                $info['start_time'] = timeToDateFormat($info['start_time']);
            }
            if (!empty($info['end_time'])) {
                $info['end_time'] = timeToDateFormat($info['end_time']);
            }
        }

        //批次关联的规格和礼品卡详情
        $allAttr = GiftAttr::where(['batch_sn' => $batchSn, 'status' => [1, 2]])->column('attr_sn');
        $finally['batchInfo'] = $info ?? [];
        $finally['attrInfo'] = [];
        if (!empty($allAttr)) {
            $attrInfo = (new GiftAttr())->info(['batch_sn' => $batchSn, 'attr_sn' => $allAttr]);
            if (!empty($attrInfo)) {
                $finally['attrInfo'] = $attrInfo['attrInfo'] ?? [];
            }
        }

        return $finally;
    }

    /**
     * @title  新增批次
     * @param array $data
     * @return bool|mixed
     * @throws \Exception
     */
    public function DBNew(array $data)
    {
        $createType = $data['type'] ?? 1;
        $orderSn = $data['order_sn'] ?? null;
        $goods = $data['goods'];
        $allGoodsSku = array_unique(array_filter(array_column($goods, 'sku_sn')));
        switch ($createType) {
            //如果选择订单导入,需要判断实际发放的商品总数是否为订单商品的数量
            case 2:
            case 3:
                if (empty($orderSn)) {
                    throw new GiftCardException(['errorCode' => 2500101]);
                }
                //判断订单是否已经生成批次了,不允许重复生成
                $existBatch = $this->where(['order_sn' => $orderSn, 'type' => [2, 3], 'status' => [1, 2]])->field('batch_sn')->findOrEmpty()->toArray();
                if (!empty($existBatch)) {
                    throw new GiftCardException(['errorCode' => 2500105]);
                }

                $orderGoods = OrderGoods::where(['order_sn' => $orderSn, 'status' => 1, 'pay_status' => 2, 'after_status' => [1, 5, -1]])->field('order_sn,goods_sn,sku_sn,title,count')->select()->toArray();
                if (empty($orderGoods)) {
                    throw new GiftCardException(['errorCode' => 2500102]);
                }
                //剔除不在本订单的礼品商品
                foreach ($goods as $key => $value) {
                    if (!in_array($value['sku_sn'], array_column($orderGoods, 'sku_sn'))) {
                        unset($goods[$key]);
                    }
                }
                if (empty($goods)) {
                    throw new GiftCardException(['errorCode' => 2500103]);
                }
                if (count($goods) != count($allGoodsSku)) {
                    throw new GiftCardException(['errorCode' => 2500104]);
                }
                //判断填入的商品数量必须为原订单的商品数,不允许一笔订单分成多个批次添加
                foreach ($orderGoods as $key => $value) {
                    foreach ($goods as $gKey => $gValue) {
                        if ($value['goods_sn'] == $gValue['goods_sn'] && $value['sku_sn'] == $gValue['sku_sn']) {
                            if (intval($gValue['number']) != $value['count']) {
                                throw new GiftCardException(['msg' => '请检查发放商品数量! 商品 <' . $value['title'] . '> 的数量必须为' . $value['count']]);
                            }
                        }
                    }
                }
                break;
        }
        $res = false;
        $goodsInfo = [];

        $nowAllGoodsSku = array_unique(array_filter(array_column($goods, 'sku_sn')));
        $goodsInfos = GoodsSku::where(['sku_sn' => $nowAllGoodsSku])->field('goods_sn,sku_sn,title,image,specs')->select()->toArray();
        foreach ($goodsInfos as $key => $value) {
            $goodsInfo[$value['sku_sn']] = $value;
        }

        $res = Db::transaction(function () use ($data, $goodsInfo) {
            //新增批次
            $newBatch['batch_sn'] = (new CodeBuilder())->buildGiftBatchSn();
            $newBatch['title'] = $data['title'];
            $newBatch['type'] = $data['type'];
            if (in_array($newBatch['type'], [2, 3])) {
                $newBatch['order_sn'] = $data['order_sn'];
            }
            $newBatch['limit_type'] = $data['limit_type'];
            if ($newBatch['limit_type'] == 1) {
                $newBatch['start_time'] = strtotime($data['start_time']);
                $newBatch['end_time'] = strtotime($data['end_time']);
            }
            $newBatch['generate_status'] = 2;
            $newBatch['take_limit'] = $data['take_limit'] ?? 1;
            $newBatch['remark'] = $data['remark'] ?? null;
            $batchRes = $this->save($newBatch);

            //新增批次商品
            $goodsCount = 0;
            foreach ($data['goods'] as $key => $value) {
                $batchGoods[$goodsCount]['batch_sn'] = $newBatch['batch_sn'];
                $batchGoods[$goodsCount]['goods_sn'] = $value['goods_sn'];
                $batchGoods[$goodsCount]['sku_sn'] = $value['sku_sn'];
                $batchGoods[$goodsCount]['total_number'] = $value['number'];
                $batchGoods[$goodsCount]['title'] = !empty($goodsInfo[$value['sku_sn']]) ? $goodsInfo[$value['sku_sn']]['title'] : null;
                $batchGoods[$goodsCount]['image'] = !empty($goodsInfo[$value['sku_sn']]) ? $goodsInfo[$value['sku_sn']]['image'] : null;
                $batchGoods[$goodsCount]['specs'] = !empty($goodsInfo[$value['sku_sn']]) ? $goodsInfo[$value['sku_sn']]['specs'] : null;
                $goodsCount++;
            }
            if (!empty($batchGoods)) {
                $batchGoodsRes = (new GiftBatchGoods())->saveAll($batchGoods);
            }

            return $batchRes;
        });

        return $res;
    }

    /**
     * @title  修改批次部分内容
     * @param array $data
     * @return mixed
     */
    public function DBEdit(array $data)
    {
        $res = false;
        //修改批次内容
        $res = Db::transaction(function () use ($data) {
            $batch['title'] = $data['title'];
            $batch['limit_type'] = $data['limit_type'];
            if ($batch['limit_type'] == 1) {
                $batch['start_time'] = strtotime($data['start_time']);
                $batch['end_time'] = strtotime($data['end_time']);
            }
            $batch['take_limit'] = $data['take_limit'] ?? 1;
            $batch['remark'] = $data['remark'] ?? null;
            $batchRes = self::update($batch, ['batch_sn' => $data['batch_sn']])->getData();
            return $batchRes ?? [];
        });
        return $res;
    }

    /**
     * @title  删除批次
     * @param array $data
     * @return mixed
     */
    public function DBDel(array $data)
    {
        $res = Db::transaction(function () use ($data) {
            $batchSn = $data['batch_sn'];
            $existAttr = GiftAttr::where(['batch_sn' => $batchSn, 'status' => [1, 2]])->count();
            $existCard = GiftCard::where(['batch_sn' => $batchSn, 'status' => [1, 2]])->count();
            if (!empty($existAttr) || !empty($existCard)) {
                throw new GiftCardException(['msg' => '存在有效的礼品卡,不可删除']);
            }
            //删除批次
            $res = $this->baseDelete(['batch_sn' => $batchSn, 'status' => [1, 2]]);
            //删除批次商品
            $goodsRes = (new GiftBatchGoods())->baseDelete(['batch_sn' => $batchSn, 'status' => [1, 2]]);
            return $res;
        });
        return judge($res);
    }

    /**
     * @title  上下架
     * @param array $data
     * @return mixed
     */
    public function upOrDown(array $data)
    {
        if ($data['status'] == 1) {
            $save['status'] = 2;
        } elseif ($data['status'] == 2) {
            $save['status'] = 1;
        } else {
            return false;
        }
        return $this->baseUpdate(['batch_sn' => $data['batch_sn']], $save);
    }

    public function batchGoods()
    {
        return $this->hasMany('GiftBatchGoods', 'batch_sn', 'batch_sn');
    }
}