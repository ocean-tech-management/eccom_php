<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 优惠券模块Controller]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\admin;


use app\BaseController;
use app\lib\models\CouponDeliver;
use app\lib\models\CouponType;
use app\lib\models\CouponUsed;
use app\lib\models\CouponUserType;
use app\lib\models\UserCoupon;
use app\lib\services\Coupon as CouponService;
use app\lib\models\Coupon as CouponModel;
use app\lib\models\User;

class Coupon extends BaseController
{
    protected $middleware = [
        'checkAdminToken',
        'checkRule',
        'OperationLog',
    ];

    /**
     * @title  优惠券列表
     * @param CouponModel $model
     * @return string
     * @throws \Exception
     */
    public function list(CouponModel $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  优惠券销毁列表
     * @param CouponModel $model
     * @return string
     * @throws \Exception
     */
    public function deleteList(CouponModel $model)
    {
        $data = $this->requestData;
        $data['sear_delete'] = true;
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
     * @title  新增优惠券
     * @param CouponService $service
     * @return string
     */
    public function create(CouponService $service)
    {
        $res = $service->create($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  更新优惠券
     * @param CouponService $service
     * @return string
     */
    public function update(CouponService $service)
    {
        $res = $service->edit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  优惠券其他更新操作
     * @param CouponModel $model
     * @return string
     * @throws \Exception
     */
    public function operating(CouponModel $model)
    {
        $data = $this->requestData;
        $data['adminInfo'] = $this->request->adminInfo;
        $res = $model->operating($data);
        if (!empty($data['compensateCoupon'] ?? false)) {
            return returnData($res);
        } else {
            return returnMsg($res);
        }

    }

    /**
     * @title  使用场景列表
     * @param CouponUsed $model
     * @return string
     * @throws \Exception
     */
    public function usedList(CouponUsed $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  优惠券类型列表
     * @param CouponType $model
     * @return string
     * @throws \Exception
     */
    public function typeList(CouponType $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  优惠券面向对象列表
     * @param CouponUserType $model
     * @return string
     * @throws \Exception
     */
    public function userTypeList(CouponUserType $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  系统派券
     * @param CouponService $service
     * @return string
     * @throws \Exception
     */
    public function deliverCoupon(CouponService $service)
    {
        $data = $this->requestData;
        $data['adminInfo'] = $this->request->adminInfo;
        $res = $service->systemDeliverCoupon($data);
        return returnData($res);
    }

    /**
     * @title  系统补券(针对销毁用户券后的补券行为)
     * @param CouponModel $mode
     * @return string
     * @throws \Exception
     */
    public function compensateCoupon(CouponModel $mode)
    {
        $data = $this->requestData;
        $data['adminInfo'] = $this->request->adminInfo;
        $data['isDelete'] = true;
        $res = $mode->compensateCoupon($data);
        return returnData($res);
    }

    /**
     * @title 用户已领取的优惠券列表
     * @param UserCoupon $model
     * @return string
     * @throws \Exception
     */
    public function userCouponList(UserCoupon $model)
    {
        $list = $model->adminUserCouponList($this->requestData);
        return returnData($list['list'], $list['pageTotal'], 0, '成功', $list['total'] ?? 0);
    }

    /**
     * @title  修改优惠券数量
     * @param CouponModel $model
     * @return string
     */
    public function updateCouponNumber(CouponModel $model)
    {
        $res = $model->updateNumber($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  销毁用户优惠券
     * @param UserCoupon $model
     * @return string
     * @throws \Exception
     */
    public function destroyUserCoupon(UserCoupon $model)
    {
        $data = $this->requestData;
        $data['adminInfo'] = $this->request->adminInfo;
        $res = $model->destroyUserCoupon($data);
        return returnData($res);
    }

    /**
     * @title  根据Excel获取用户信息
     * @param User $model
     * @return string
     * @throws \Exception
     */
    public function getUserInfoByExcel(User $model)
    {
        $list = $model->getUserInfoByExcel($this->requestData);
        return returnData($list);
    }

    /**
     * @title  派券历史列表
     * @param CouponDeliver $model
     * @return string
     * @throws \Exception
     */
    public function deliverHistory(CouponDeliver $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal'], 0, '成功', $list['total'] ?? 0);
    }

    public function cList(CouponModel $model)
    {
        $list = $model->cList($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

}