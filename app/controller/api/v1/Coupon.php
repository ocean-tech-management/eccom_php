<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 优惠券模块Controller]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\api\v1;


use app\BaseController;
use app\lib\models\Coupon as CouponModel;
use app\lib\models\UserCoupon;
use app\lib\services\Coupon as CouponService;

class Coupon extends BaseController
{
    protected $middleware = [
        'checkApiToken' => ['except' => ['list', 'info']],
    ];

    /**
     * @title  优惠券列表
     * @param CouponModel $model
     * @return mixed
     * @throws \Exception
     */
    public function list(CouponModel $model)
    {
        $data = $this->requestData;
        $data['valid_status'] = 1;
        $list = $model->list($data);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  优惠券详情
     * @param CouponModel $model
     * @return string
     * @throws \Exception
     */
    public function info(CouponModel $model)
    {
        $info = $model->info($this->requestData);
        return returnData($info);
    }

    /**
     * @title  用户领取优惠券
     * @param CouponService $service
     * @return string
     * @throws \Exception
     */
    public function receive(CouponService $service)
    {
        $res = $service->userReceive($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  商品详情中的可用优惠券列表
     * @param CouponModel $model
     * @return string
     * @throws \Exception
     */
    public function goodsInfoCoupon(CouponModel $model)
    {
        $list = $model->goodsInfoCouponList($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }
}