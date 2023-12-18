<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 礼品卡模块Model]
// +----------------------------------------------------------------------



namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\GiftCardException;
use app\lib\services\CodeBuilder;
use think\facade\Db;

class GiftCard extends BaseModel
{
    protected $validateFields = ['batch_sn', 'attr_sn'];

    /**
     * @title  礼品卡列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear = []): array
    {
        $batchSn = $sear['batch_sn'] ?? null;
        $attrSn = $sear['attr_sn'] ?? null;

        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('card_sn|bind_phone|convert_sn|convert_user_phone|notice_phone', $sear['keyword']))];
        }

        if (!empty($sear['card_type'])) {
            $map[] = ['card_type', '=', $sear['card_type']];
        }

        if ($this->module == 'api') {
            $map[] = ['status', '=', 1];
        } else {
            $map[] = ['status', 'in', [1, 2, -2]];
        }

        if (!empty($batchSn)) {
            $map[] = ['batch_sn', '=', $batchSn];
        }
        if (!empty($attrSn)) {
            $map[] = ['attr_sn', '=', $attrSn];
        }

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        //礼品卡列表
        $list = $this->with(['attr'])->withoutField('update_time')->where($map)->order('create_time desc')->select()->each(function ($item) {
            if (!empty($item['attr']) && !empty($item['attr']['all_goods'])) {
                $item['attr']['allGoodsArray'] = json_decode($item['attr']['all_goods'], 1);
            }
            if (!empty($item['take_start_time'])) {
                $item['take_start_time'] = timeToDateFormat($item['take_start_time']);
            }
            if (!empty($item['take_end_time'])) {
                $item['take_end_time'] = timeToDateFormat($item['take_end_time']);
            }
        })->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  生成礼品卡
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    public function DBNew(array $data)
    {
        //生成礼品卡是按照规格一批一批生成的
        $batchSn = $data['batch_sn'];
        $attrSn = $data['attr_sn'];
//        $goods = $data['goods'];
        $card = $data['card'] ?? [];
        $linkUser = $data['pre_link_superior_user'] ?? null;
        if (empty($batchSn) || empty($attrSn)) {
            throw new GiftCardException(['errorCode' => 2500106]);
        }

        $batchInfo = GiftBatch::where(['batch_sn' => $batchSn, 'status' => [1, 2]])->findOrEmpty()->toArray();
        $batchGoods = GiftBatchGoods::where(['batch_sn' => $batchSn, 'status' => [1, 2]])->select()->toArray();
        $attrInfo = GiftAttr::where(['batch_sn' => $batchSn, 'attr_sn' => $attrSn, 'status' => [1, 2]])->findOrEmpty()->toArray();

        //获取规格的商品数量,有且只有规格内的商品才能允许添加礼品卡
        $attrGoods = json_decode($attrInfo['all_goods'], true);
        $attrSku = array_unique(array_filter(array_column($attrGoods, 'sku_sn')));
        //商品属性
        foreach ($batchGoods as $key => $value) {
            $goodsInfo[$value['sku_sn']] = $value;
        }

        if (empty($batchInfo) || empty($batchGoods) || empty($attrInfo) || empty($attrGoods)) {
            throw new GiftCardException(['errorCode' => 2500107]);
        }
//        foreach ($goods as $key => $value) {
//            if (!in_array($value['sku_sn'], $attrSku)) {
//                unset($goods[$key]);
//                continue;
//            }
//            foreach ($attrGoods as $aKey => $aValue) {
//                if (($value['sku_sn'] == $aValue['sku_sn']) && ($value['goods_sn'] == $aValue['goods_sn']) && ($data['batch_sn'] == $aValue['batch_sn']) && ($data['attr_sn'] == $aValue['attr_sn'])) {
//                    if ($value['number'] > $aValue['number']) {
//                        throw new GiftCardException(['msg' => '商品数量只能跟规格的商品数量一致哦']);
//                    }
//                }
//            }
//        }
        $goods = $attrGoods;
        if (empty($goods) || empty($attrInfo['all_number'])) {
            throw new GiftCardException(['errorCode' => 2500103]);
        }

        $newCard = [];
        $newCardGoods = [];
        $codeBuilderService = (new CodeBuilder());
        $gi = 0;

        for ($i = 0; $i < intval($attrInfo['all_number']); $i++) {
            $newCard[$i]['batch_sn'] = $data['batch_sn'];
            $newCard[$i]['attr_sn'] = $data['attr_sn'];
            $newCard[$i]['card_sn'] = $codeBuilderService->buildGiftCardSn($data['batch_sn']);
            $newCard[$i]['card_type'] = $attrInfo['card_type'];
            if ($newCard[$i]['card_type'] == 2) {
                $newCard[$i]['price'] = $attrInfo['price'];
            }
            $newCard[$i]['convert_type'] = $attrInfo['convert_type'];
            $newCard[$i]['convert_type'] = $attrInfo['convert_type'];
            if ($newCard[$i]['convert_type'] == 1) {
                $newCard[$i]['bind_phone'] = !empty($card[$i]) ? $card[$i]['bind_phone'] : null;
            }
            $newCard[$i]['notice_phone'] = !empty($card[$i]) ? $card[$i]['notice_phone'] : null;
            $newCard[$i]['pre_link_superior_user'] = !empty($card[$i]) ? $card[$i]['pre_link_superior_user'] : ($linkUser ?? null);
            $newCard[$i]['convert_sn'] = $codeBuilderService->buildGiftCardConvertSn();
            $newCard[$i]['convert_pwd'] = $codeBuilderService->buildCardPassword();
            $newCard[$i]['take_limit_type'] = $attrInfo['take_limit_type'];
            $newCard[$i]['take_start_time'] = $attrInfo['take_start_time'];
            $newCard[$i]['take_end_time'] = $attrInfo['take_end_time'];
            $newCard[$i]['used_limit_type'] = $attrInfo['used_limit_type'];
            switch ($newCard[$i]['used_limit_type']) {
                case 1:
                    $newCard[$i]['used_start_time'] = strtotime($attrInfo['used_start_time']);
                    $newCard[$i]['used_end_time'] = strtotime($attrInfo['used_end_time']);
                    break;
                case 2:
                    $newCard[$i]['valid_days'] = $attrInfo['valid_days'];
                    break;
            }
            $newCard[$i]['card_status'] = 1;
            $newCard[$i]['convert_status'] = 2;
            $newCard[$i]['goods_convert_status'] = 3;
            $newCard[$i]['remark'] = !empty($card[$i]) ? $card[$i]['remark'] : ($attrInfo['remark'] ?? null);

            //新增礼品卡商品卡
            if ($newCard[$i]['card_type'] == 1) {
                foreach ($goods as $key => $value) {
                    $newCardGoods[$gi]['batch_sn'] = $newCard[$i]['batch_sn'];
                    $newCardGoods[$gi]['attr_sn'] = $newCard[$i]['attr_sn'];
                    $newCardGoods[$gi]['card_sn'] = $newCard[$i]['card_sn'];
                    $newCardGoods[$gi]['convert_type'] = $newCard[$i]['convert_type'];
                    $newCardGoods[$gi]['goods_sn'] = $value['goods_sn'];
                    $newCardGoods[$gi]['sku_sn'] = $value['sku_sn'];
                    $newCardGoods[$gi]['title'] = !empty($goodsInfo[$value['sku_sn']]) ? $goodsInfo[$value['sku_sn']]['title'] : null;
                    $newCardGoods[$gi]['image'] = !empty($goodsInfo[$value['sku_sn']]) ? $goodsInfo[$value['sku_sn']]['image'] : null;
                    $newCardGoods[$gi]['specs'] = !empty($goodsInfo[$value['sku_sn']]) ? $goodsInfo[$value['sku_sn']]['specs'] : null;
                    $newCardGoods[$gi]['total_number'] = $value['number'];
                    $newCardGoods[$gi]['surplus_number'] = $value['number'];
                    $newCardGoods[$gi]['remark'] = $newCard[$i]['remark'];
                    $newCardGoods[$gi]['card_status'] = 1;
                    $gi++;
                }
            }
        }

        //数据库操作
        $DBRes = Db::transaction(function () use ($newCard, $data, $newCardGoods, $batchInfo, $batchGoods, $attrInfo, $goods) {
            $cardRes = false;
            $cardGoodsRes = false;
            //批量新增礼品卡
            if (!empty($newCard)) {
                $cardRes = $this->saveAll($newCard);
            }

            //批量新增礼品卡商品
            if (!empty($newCardGoods)) {
                $cardGoodsRes = (new GiftCardGoods())->saveAll($newCardGoods);
            }
            //累加批次商品数生成数量
            foreach ($goods as $key => $value) {
                GiftBatchGoods::where(['batch_sn' => $data['batch_sn'], 'goods_sn' => $value['goods_sn'], 'sku_sn' => $value['sku_sn'], 'status' => [1, 2]])->inc('generate_number', intval(($value['number'] * $attrInfo['all_number'])))->update();
            }
            //累加批次礼品卡数量
            GiftBatch::where(['batch_sn' => $data['batch_sn'], 'status' => [1, 2]])->inc('all_number', intval($attrInfo['all_number']))->update();

            //判断商品是否全部生成完,生成完就把该批次生成状态修改为不可生成
            $nowGiftBatchGoods = GiftBatchGoods::where(['batch_sn' => $data['batch_sn'], 'status' => [1, 2]])->select()->toArray();
            $clearGoodsNumber = 0;
            foreach ($nowGiftBatchGoods as $key => $value) {
                if (($value['total_number'] - $value['generate_number']) <= 0) {
                    $clearGoodsNumber += 1;
                }
            }
            $batchGenerateStatus = 2;
            if ($clearGoodsNumber >= count($nowGiftBatchGoods)) {
                $batchGenerateStatus = 1;
            }
            GiftBatch::update(['generate_status' => $batchGenerateStatus], ['batch_sn' => $data['batch_sn'], 'status' => [1, 2]]);
            return $cardRes;
        });

        return $DBRes;
    }

    /**
     * @title  销毁礼品卡
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    public function destroyCard(array $data)
    {
        $cardSn = $data['card_sn'] ?? [];
        if (empty($cardSn)) {
            throw new GiftCardException(['errorCode' => 2500106]);
        }
        //礼品卡详情
        $cardInfo = $this->where(['card_sn' => $cardSn, 'status' => [1, 2]])->select()->toArray();

        if (empty($cardInfo)) {
            throw new GiftCardException(['errorCode' => 2500110]);
        }
        foreach ($cardInfo as $key => $value) {
            if (!empty($value['convert_uid']) || !empty($value['convert_time'])) {
                throw new GiftCardException(['msg' => '卡号为 ' . $value['convert_sn'] . ' 的礼品卡已被领取,无法销毁']);
            }
        }
        //查看领取历史,检查是否被领取,被领取了不允许销毁
        $receiveHis = UserGiftCard::where(['card_sn' => $cardSn])->count();

        if (!empty($receiveHis)) {
            throw new GiftCardException(['errorCode' => 2500111]);
        }

        //礼品卡商品列表
        $cardGoods = GiftCardGoods::where(['card_sn' => $cardSn, 'status' => [1, 2]])->select()->toArray();

        //数据库操作
        $DBRes = Db::transaction(function () use ($cardInfo, $cardGoods, $cardSn) {
            //修改礼品卡状态为销毁
            $res = self::update(['status' => -2, 'card_status' => -1, 'destroy_time' => time()], ['card_sn' => $cardSn, 'status' => [1, 2]]);
            //修改礼品卡商品为删除
            $goodsRes = GiftCardGoods::update(['status' => -2, 'card_status' => -1, 'destroy_time' => time()], ['card_sn' => $cardSn, 'status' => [1, 2]]);

            if (!empty($cardGoods)) {
                //累减批次商品数生成数量
                foreach ($cardGoods as $key => $value) {
                    GiftBatchGoods::where(['batch_sn' => $value['batch_sn'], 'goods_sn' => $value['goods_sn'], 'sku_sn' => $value['sku_sn'], 'status' => [1, 2]])->dec('generate_number', intval($value['total_number']))->update();
                }
            }
            //累减批次礼品卡数量
            GiftBatch::where(['batch_sn' => $cardSn, 'status' => [1, 2]])->dec('generate_number', intval(count($cardSn)))->update();

            //判断该规格的礼品卡是否全部删除,如果是一并删除对应的规格
            foreach ($cardInfo as $key => $value) {
                $surplusCardNumber = self::where(['batch_sn' => $value['batch_sn'], 'attr_sn' => $value['attr_sn'], 'status' => [1, 2]])->count();
                if (intval($surplusCardNumber) <= 0) {
                    GiftAttr::update(['status' => -2], ['batch_sn' => $value['batch_sn'], 'attr_sn' => $value['attr_sn']]);
                }
            }

            return $res;
        });

        return $DBRes;
    }

    public function attr()
    {
        return $this->hasOne('GiftAttr', 'attr_sn', 'attr_sn');
    }
}