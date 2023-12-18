<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 售后模块流程明细Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\AfterSaleException;
use app\lib\exceptions\ParamException;
use think\facade\Db;

class AfterSaleDetail extends BaseModel
{
    /**
     * @title  新增一条明细
     * @param array $data
     * @return mixed
     */
    public function DBNew(array $data)
    {
        $afSn = $data['after_sale_sn'];
        $operateUser = $data['operate_user'];
        $changeType = $data['change_type'] ?? 1;
        $images = $data['images'] ?? [];
        $detailUserName = $data['detailUserName'] ?? null;
        $afInfo = AfterSale::where(['after_sale_sn' => $afSn, 'status' => 1])->findOrEmpty()->toArray();
        if (empty($afInfo)) {
            throw new AfterSaleException(['errorCode' => 2000101]);
        }
        $type = $data['after_type'] ?? $afInfo['after_status'];
        $aDetail['after_sale_sn'] = $afInfo['after_sale_sn'];
        $aDetail['order_sn'] = $afInfo['order_sn'];
        $aDetail['uid'] = $afInfo['uid'];
        $aDetail['type'] = $afInfo['type'];
        $aDetail['after_status'] = $type;
        $aDetail['operate_user'] = $operateUser;
        $afterType = [1 => '仅退款', 2 => '退货退款', 3 => '换货'];
        $buyerReceivedGoodsStatus = [1 => '已收到货', 2 => '未收到货'];
        $changeTypeStatus = [1 => '发起了', 2 => '修改了'];
        $afterStatus = [1 => '为售后申请中', 2 => '为商家同意申请', 3 => '为商家拒绝售后申请', 4 => '为等待用户退货中', 5 => '等待商家收货中', 6 => '商家确认收货', 7 => '退款成功', 8 => '等待用户确认换货中', 9 => '用户确认收到换货', 10 => '售后完成', -1 => '用户取消售后申请', -2 => '商家拒绝确认收货', -3 => '商家拒绝退款'];
//        if($type != 10){
//            if($afInfo['after_status'] != $type){
//                throw new AfterSaleException(['errorCode'=>2000102]);
//            }
//        }

        switch ($type) {
            case 1:
                $content = $changeTypeStatus[$changeType] . $afterType[$afInfo['type']] . '申请，货物状态: ' . $buyerReceivedGoodsStatus[$afInfo['buyer_received_goods']] . '，原因: ' . $afInfo['apply_reason'] . '，金额: ' . $afInfo['apply_price'] . '。';
                if ($changeType == 1) {
                    $closeTime = time() + (3600 * 24 * 2);
                }
                break;
            case 2:
                $content = '商家同意了本次售后服务申请。';
                $closeTime = time() + (3600 * 24 * 2);
                break;
            case 3:
                $content = '商家拒绝了本次售后服务申请。拒绝理由: ' . $afInfo['verify_reason'] . '。';
                $closeTime = time();
                break;
            case 4:
                $content = '商家确认收货地址: ' . $afInfo['seller_shipping_name'] . '，' . $afInfo['seller_shipping_phone'] . '，' . $afInfo['seller_shipping_address'] . '，说明:' . $afInfo['seller_remark'] . '。';
                $closeTime = time() + (3600 * 24 * 3);
                break;
            case 5:
                $content = '买家退货: 物流公司: ' . $afInfo['buyer_shipping_company'] . '，物流单号: ' . $afInfo['buyer_shipping_code'] . '，快递方式:快递。';
                $closeTime = time() + (3600 * 24 * 14);
                break;
            case 6:
                $content = '商家确认收货。';
                $closeTime = time() + (3600 * 24 * 1);
                break;
            case 7:
                $content = '商家主动同意，退款给买家 ' . $afInfo['real_withdraw_price'] . '元。';
                $closeTime = time();
                break;
            case 8:
                $content = '商家换货: 物流公司: ' . $afInfo['change_shipping_company'] . '，物流单号: ' . $afInfo['change_shipping_code'] . '，快递方式: 快递。';
                $closeTime = time() + (3600 * 24 * 10);
                break;
            case 9:
                $content = '买家确认换货。';
                $closeTime = time();
                break;
            case 10:
                $content = '本次售后服务已完结。';
                $closeTime = time();
                break;
            case -1:
                $content = (empty($detailUserName) ? '买家主动' : $detailUserName) . '撤销了本次售后服务申请。';
                $closeTime = time();
                break;
            case -2:
                $content = '商家拒绝确认收货。拒绝原因: ' . $afInfo['refuse_reason'] . '。';
                $closeTime = time() + (3600 * 24 * 2);
                break;
            case -3:
                $content = '商家拒绝退款。';
                $closeTime = time() + (3600 * 24 * 2);
                break;
            default:
                $content = '系统未知原因。';
                $closeTime = time() + (3600 * 24 * 2);
        }
        $aDetail['content'] = $content;
        if (!empty($closeTime)) {
            $aDetail['close_time'] = $closeTime;
        }
        $newDetailID = $this->baseCreate($aDetail, true);
        if (!empty($images)) {
            foreach ($images as $key => $value) {
                $auImages['after_sale_detail_id'] = $newDetailID;
                $auImages['after_sale_sn'] = $afSn;
                $auImages['order_sn'] = $aDetail['order_sn'];
                $auImages['image_path'] = $value;
                $imgRes = AfterSaleImages::create($auImages);
            }
        }
        return $newDetailID;

    }

    /**
     * @title  待处理的售后消息列表
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    public function msgList(array $data)
    {
        $uid = $data['uid'] ?? '';
        $returnType = $data['returnType'] ?? 1;
        if (empty($uid)) {
            throw new ParamException();
        }
        $list = [];

        $map[] = ['status', '=', 1];
        $map[] = ['after_status', '=', 88];
        $map[] = ['operate_user', '=', '商家'];
        $map[] = ['is_reply', '=', 2];
        $map[] = ['uid', '=', $uid];
        $list = $this->where($map)->field('after_sale_sn,order_sn,uid,type,content,msg_code,is_reply,create_time')->select()->toArray();

        if (!empty($list)) {
            $afInfo = AfterSale::where(['after_sale_sn' => array_unique(array_column($list, 'after_sale_sn'))])->field('after_sale_sn,order_sn,uid,type,after_status,status')->select()->toArray();

            if (!empty($afInfo)) {
                foreach ($afInfo as $key => $value) {
                    if (in_array($value['after_status'], [3, 10, -1, -3])) {
                        $notShowAfterSaleOrder[$value['after_sale_sn']] = $value;
                        $notShowAfterSaleSn[] = $value['after_sale_sn'];
                    }
                }
            }

            if (!empty($notShowAfterSaleSn)) {
                foreach ($list as $key => $value) {
                    if (in_array($value['after_sale_sn'], $notShowAfterSaleSn)) {
                        unset($list[$key]);
                    }
                }
            }
        }
        if (!empty($list ?? [])) {
            $list = array_values($list);
        }

        switch ($returnType == 1) {
            case 1:
                $returnData = count($list ?? []);
                break;
            case 2:
                $returnData = $list;
                break;
            case 3:
                $returnData = current($list);
                break;
            default:
                $returnData = $list;
        }

        return $returnData;
    }

}