<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 礼品卡规格模块Model]
// +----------------------------------------------------------------------



namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\GiftCardException;
use app\lib\services\CodeBuilder;
use think\facade\Db;

class GiftAttr extends BaseModel
{
    protected $validateFields = ['batch_sn'];

    /**
     * @title  礼品规格详情
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function info(array $data)
    {
        $batchSn = $data['batch_sn'] ?? null;
        $attrSn = $data['attr_sn'] ?? null;
        if (empty($batchSn) || empty($attrSn)) {
            throw new GiftCardException(['errorCode' => 2500106]);
        }
        //批次详情
        $batchInfo = GiftBatch::with(['batchGoods' => function ($query) {
            $query->where(['status' => [1, 2]]);
        }])->where(['batch_sn' => $batchSn, 'status' => [1, 2]])->findOrEmpty()->toArray();

        //规格和礼品卡列表
        $attrInfo = $this->with(['card' => function ($query) {
            $query->withoutField('update_time')->where(['status' => [1, 2, -2]]);
        }])->withoutField('id,update_time')->where(['attr_sn' => $attrSn, 'status' => [1, 2, -2]])->select()->toArray();
        if (!empty($attrInfo)) {
            //补齐规格的商品名称
            if (!empty($batchInfo['batchGoods'])) {
                foreach ($batchInfo['batchGoods'] as $key => $value) {
                    $allGoodsInfo[$value['sku_sn']] = $value;
                }
                foreach ($attrInfo as $key => $value) {
                    $attrGoods = json_decode($value['all_goods'], true);
                    if (!empty($attrGoods)) {
                        foreach ($attrGoods as $gKey => $gValue) {
                            if (!empty($allGoodsInfo[$gValue['sku_sn']])) {
                                $attrInfo[$key]['all_goods_info'][$gKey]['goods_sn'] = $allGoodsInfo[$gValue['sku_sn']]['goods_sn'];
                                $attrInfo[$key]['all_goods_info'][$gKey]['sku_sn'] = $allGoodsInfo[$gValue['sku_sn']]['sku_sn'];
                                $attrInfo[$key]['all_goods_info'][$gKey]['title'] = $allGoodsInfo[$gValue['sku_sn']]['title'];
                                $attrInfo[$key]['all_goods_info'][$gKey]['image'] = $allGoodsInfo[$gValue['sku_sn']]['image'];
                                $attrInfo[$key]['all_goods_info'][$gKey]['specs'] = $allGoodsInfo[$gValue['sku_sn']]['specs'];
                                $attrInfo[$key]['all_goods_info'][$gKey]['number'] = $gValue['number'];
                            }
                        }
                    }
                }
            }
        }

        $all = ['batchInfo' => $batchInfo ?? [], 'attrInfo' => $attrInfo ?? []];

        return $all;
    }

    /**
     * @title  新增礼品卡规格
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    public function DBNew(array $data)
    {
        $batchSn = $data['batch_sn'] ?? null;
        $goods = $data['goods'] ?? null;
        $allNumber = $data['all_number'] ?? 0;
        if (empty($batchSn) || empty($goods) || empty($allNumber)) {
            throw new GiftCardException(['errorCode' => 2500106]);
        }
        $batchInfo = GiftBatch::where(['batch_sn' => $batchSn, 'status' => [1, 2]])->findOrEmpty()->toArray();
        $batchGoods = GiftBatchGoods::where(['batch_sn' => $batchSn, 'status' => [1, 2]])->select()->toArray();

        if (empty($batchInfo) || empty($batchGoods)) {
            throw new GiftCardException(['errorCode' => 2500107]);
        }
        if ($data['card_type'] == 2 && empty(doubleval($data['price']))) {
            throw new GiftCardException(['msg' => '请填写充值卡面值']);
        }
        if (empty($data['take_limit'])) {
            throw new GiftCardException(['msg' => '请填写每人限领次数']);
        }
        $goodsInfos = GoodsSku::where(['sku_sn' => array_column($batchGoods, 'sku_sn')])->field('goods_sn,sku_sn,title')->select()->toArray();
        foreach ($goodsInfos as $key => $value) {
            $goodsInfos[$value['sku_sn']] = $value;
        }
        foreach ($batchGoods as $key => $value) {
            foreach ($goods as $gKey => $gValue) {
                if (($value['sku_sn'] == $gValue['sku_sn']) && ($value['goods_sn'] == $gValue['sku_sn']) && ($gValue['batch_sn'] == $value['batch_sn'])) {
                    $goods[$gKey]['specs'] = $goodsInfos[$gValue['sku_sn']]['specs'] ?? null;
                    $goods[$gKey]['title'] = $goodsInfos[$gValue['sku_sn']]['title'] ?? null;
                    $goods[$gKey]['image'] = $goodsInfos[$gValue['sku_sn']]['image'] ?? null;
                    if ((string)(($gValue['number'] * $allNumber) + $value['generate_number']) > (string)$value['total_number']) {
                        throw new GiftCardException(['msg' => '商品 <' . $goodsInfos[$gValue['sku_sn']]['title'] . '> 最多可新增的剩余数量为' . ($value['total_number'] - $value['generate_number'])]);
                    }
                }
            }
        }
        $DBRes = Db::transaction(function () use ($data, $goods) {
            $newAttr['batch_sn'] = $data['batch_sn'];
            $newAttr['attr_sn'] = (new CodeBuilder())->buildGiftAttrSn();
            $newAttr['card_type'] = $data['card_type'];
            $newAttr['title'] = $data['title'] ?? null;
            if ($newAttr['card_type'] == 2) {
                $newAttr['price'] = $data['price'];
            }
            $newAttr['convert_type'] = $data['convert_type'];
            $newAttr['take_limit_type'] = $data['take_limit_type'];
            if ($newAttr['take_limit_type'] == 1) {
                $newAttr['take_start_time'] = strtotime($data['take_start_time']);
                $newAttr['take_end_time'] = strtotime($data['take_end_time']);
            }
            $newAttr['used_limit_type'] = $data['used_limit_type'];
            switch ($newAttr['used_limit_type']) {
                case 1:
                    $newAttr['used_start_time'] = strtotime($data['used_start_time']);
                    $newAttr['used_end_time'] = strtotime($data['used_end_time']);
                    break;
                case 2:
                    $newAttr['valid_days'] = $data['valid_days'];
                    break;
            }
            $newAttr['take_limit'] = $data['take_limit'];
            $newAttr['all_number'] = $data['all_number'];

            if ($newAttr['card_type'] == 1) {
                $goodsCount = 0;
                foreach ($goods as $key => $value) {
                    $jsonGoods[$goodsCount]['goods_sn'] = $value['goods_sn'];
                    $jsonGoods[$goodsCount]['sku_sn'] = $value['sku_sn'];
                    $jsonGoods[$goodsCount]['number'] = $value['number'];
                    $jsonGoods[$goodsCount]['specs'] = $value['specs'] ?? '';
                    $jsonGoods[$goodsCount]['title'] = $value['title'] ?? '';
                    $jsonGoods[$goodsCount]['image'] = $value['image'] ?? '';
                    $goodsCount++;
                }
                if (!empty($jsonGoods)) {
                    $newAttr['all_goods'] = json_encode($jsonGoods, 256);
                }
            }

            $newAttr['remark'] = $data['remark'] ?? null;
            $attrRes = $this->baseCreate($newAttr);

            //新增礼品卡
            $cardData = $data;
            $cardData['attr_sn'] = $newAttr['attr_sn'];
            $cardRes = (new GiftCard())->DBNew($cardData);

            return $attrRes;
        });
        return $DBRes;
    }

    public function card()
    {
        return $this->hasMany('GiftCard', 'attr_sn', 'attr_sn');
    }

}