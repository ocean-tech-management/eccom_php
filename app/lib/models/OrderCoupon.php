<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 订单优惠券模块Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use app\lib\services\Coupon as CouponService;

class OrderCoupon extends BaseModel
{
    /**
     * @title  添加订单优惠券
     * @param array $data
     * @return \think\Collection|null
     * @throws \Exception
     */
    public function new(array $data)
    {
        $orderSn = $data['order_sn'];
        $aUserCoupon = (new CouponService())->userChooseCoupon($data['order_data']);
        $res = null;
        if (!empty($aUserCoupon['disArr'])) {
            foreach ($aUserCoupon['disArr'] as $key => $value) {
                $add[$key]['order_sn'] = $orderSn;
                $add[$key]['coupon_code'] = $value['coupon_code'];
                $add[$key]['coupon_name'] = $value['coupon_name'];
                $add[$key]['coupon_uc_code'] = $value['coupon_uc_code'];
                $add[$key]['used_amount'] = $value['dis_price'];
            }
            $res = $this->saveAll($add);
        }
        return $res;
    }
}