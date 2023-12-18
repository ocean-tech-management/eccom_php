<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 礼品卡批次商品模块Model]
// +----------------------------------------------------------------------



namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\GiftCardException;
use think\facade\Db;

class GiftBatchGoods extends BaseModel
{
    protected $validateFields = ['batch_sn'];

    /**
     * @title  新增或编辑批次商品
     * @param array $data
     * @return bool|mixed
     * @throws \Exception
     */
    public function DBNewOrEdit(array $data)
    {
        $batchSn = $data['batch_sn'] ?? null;
        $goods = $data['goods'] ?? null;
        if (empty($batchSn) || empty($goods)) {
            throw new GiftCardException(['errorCode' => 2500106]);
        }
        $batchInfo = GiftBatch::where(['batch_sn' => $batchSn, 'status' => [1, 2]])->findOrEmpty()->toArray();
        if (empty($batchInfo)) {
            throw new GiftCardException(['errorCode' => 2500107]);
        }
        //只有后台自定义生成的批次类型且没有生成规格或礼品卡的才允许新增
        if ($batchInfo['type'] != 1) {
            throw new GiftCardException(['errorCode' => 2500108]);
        }
        $existAttr = GiftAttr::where(['batch_sn' => $batchSn, 'status' => [1, 2]])->count();
        $existCard = GiftCard::where(['batch_sn' => $batchSn, 'status' => [1, 2]])->count();

        if (!empty($existAttr) || !empty($existCard)) {
            throw new GiftCardException(['errorCode' => 2500109]);
        }
        $nowGoods = $this->where(['batch_sn' => $batchSn, 'status' => [1, 2]])->select()->toArray();
        $nowGoodsSku = array_unique(array_filter(array_column($nowGoods, 'sku_sn')));
        //暂时不允许修改之前存在的数据
        foreach ($goods as $key => $value) {
            if (in_array($value['sku_sn'], $nowGoodsSku)) {
                unset($goods[$key]);
            }
        }
        if (empty($goods)) {
            throw new GiftCardException(['errorCode' => 2500103]);
        }
        $goodsInfos = GoodsSku::where(['sku_sn' => $nowGoodsSku])->field('goods_sn,sku_sn,title,image,specs')->select()->toArray();
        foreach ($goodsInfos as $key => $value) {
            $goodsInfo[$value['sku_sn']] = $value;
        }

        $DBRes = false;
        $DBRes = Db::transaction(function () use ($data, $goods, $goodsInfo) {
            foreach ($goods as $key => $value) {
                $save['goods_sn'] = $value['goods_sn'];
                $save['sku_sn'] = $value['sku_sn'];
                $save['total_number'] = $value['total_number'];
                $save['title'] = !empty($goodsInfo[$value['sku_sn']]) ? $goodsInfo[$value['sku_sn']]['title'] : null;
                $save['image'] = !empty($goodsInfo[$value['sku_sn']]) ? $goodsInfo[$value['sku_sn']]['image'] : null;
                $save['specs'] = !empty($goodsInfo[$value['sku_sn']]) ? $goodsInfo[$value['sku_sn']]['specs'] : null;
                $res[] = $this->updateOrCreate([$this->getPk() => $value[$this->getPk()], 'batch_sn' => $data['batch_sn'], 'status' => [1, 2]], $save);
            }
            return $res ?? [];
        });

        return $DBRes;
    }
}