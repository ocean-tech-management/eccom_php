<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\api\v1;


use app\BaseController;
use app\lib\exceptions\OrderException;
use app\lib\exceptions\ServiceException;
use app\lib\exceptions\UserException;
use app\lib\models\Order as OrderModel;
use app\lib\models\OrderGoods;
use app\lib\models\TeamPerformance;
use app\lib\models\UserBankCard;
use app\lib\services\BankCard;
use app\lib\services\Order as OrderService;
use app\lib\services\Coupon;
use app\lib\services\Receipt;
use app\lib\services\Ship;
use think\facade\App;
use think\facade\Cache;

class Order extends BaseController
{
    protected $middleware = [
        'checkUser' => ['except' => ['info', 'taskOrder', 'receipt', 'agreementSignContract']],
        'checkApiToken',
    ];

    /**
     * @title  创建订单
     * @param OrderService $service
     * @return string
     * @throws \Exception
     */
    public function create(OrderService $service)
    {
//        throw new ServiceException(['msg'=>'系统升级中, 请耐心等待, 感谢您的理解与支持']);
        $uid = $this->requestData['uid'];
        $res = $service->buildOrder($this->requestData);
        return returnData($res);
    }

    /**
     * @title  预订单
     * @param OrderService $service
     * @return string
     * @throws \Exception
     */
    public function readyOrder(OrderService $service)
    {
//        throw new ServiceException(['msg'=>'系统升级中, 请耐心等待, 感谢您的理解与支持']);
        $uid = $this->requestData['uid'];
        $info = $service->readyOrder($this->requestData);
        return returnData($info);
    }

    /**
     * @title  订单详情
     * @param OrderModel $model
     * @return string
     * @throws \Exception
     */
    public function info(OrderModel $model)
    {
        $data = $this->requestData;
        $data['uid'] = $this->request->tokenUid ?? null;
        $data['needChangeLinkUser'] = true;
        $info = $model->info($data);
        return returnData($info);
    }

    /**
     * @title  用户优惠券列表
     * @param Coupon $service
     * @return string
     * @throws \Exception
     */
    public function coupon(Coupon $service)
    {
        $res = $service->userCouponList($this->requestData);
        return returnData($res);
    }


    /**
     * @title  已购课程
     * @param OrderGoods $model
     * @return string
     * @throws \Exception
     */
    public function buySubject(OrderGoods $model)
    {
        $list = $model->userBuyGoods($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }


    /**
     * @title  重新支付订单
     * @param OrderService $service
     * @return string
     */
    public function orderPayAgain(OrderService $service)
    {
        $data['order_sn'] = $this->request->param('order_sn');
        $data['token_uid'] = $this->request->tokenUid;
        $info = $service->orderPayAgain($data);
        return returnData($info);
    }

    /**
     * @title  取消订单
     * @param OrderService $service
     * @return string
     */
    public function cancelPay(OrderService $service)
    {
        $data['order_sn'] = $this->request->param('order_sn');
        $data['token_uid'] = $this->request->tokenUid;
        $res = $service->cancelPay($data);
        return returnMsg($res);
    }

    /**
     * @title  用户确认收货
     * @param Ship $service
     * @return string
     * @throws \Exception
     */
    public function confirm(Ship $service)
    {
        $res = $service->userConfirmReceiveGoods($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  生成回执图片
     * @param Receipt $service
     * @return string
     * @throws \Exception
     */
    public function receipt(Receipt $service)
    {
        throw new ServiceException(['msg' => '功能维护中, 感谢您的理解与支持']);
        $data = $this->requestData;
        $data['uid'] = $this->request->tokenUid ?? null;
        $model = new OrderModel();
        $info = $model->info($data);
        if (empty($info)) throw new ServiceException(['msg' => '查无订单']);
        $images = $service->build($info);
//        if (!empty($images)) {
//            $images = config('system.apiDomain') . $images;
//        }

        return returnData(['images' => $images]);
    }

    /**
     * @title  生成充值微信支付单
     * @param OrderService $service
     * @return string
     */
    public function recharge(OrderService $service)
    {
        //停用充值
        throw new ServiceException(['msg' => '功能已停用, 感谢您的支持和理解']);
        $cacheKey = 'crowdRecharge';
        $cacheNumber = cache($cacheKey);
        if (intval($cacheNumber) >= 100) {
            throw new ServiceException(['msg' => '前方拥挤, 请稍后再试']);
        }
        if (empty($cacheNumber)) {
            cache($cacheKey, 1, 3600);
        } else {
            Cache::inc($cacheKey);
        }
        $data = $this->requestData;
        switch ($data['pay_type'] ?? 2) {
            case 2:
                $key = getAccessKey();
                $type = substr($key, 0, 1);
//                if ($data['pay_type'] == 2 && !in_array($type, config('system.wxPayType'))) {
                if ($data['pay_type'] == 2 && $type == 'm') {
                    throw new ServiceException(['msg' => '当前客户端不支持微信支付, 请移步使用其他客户端, 谢谢您的支持']);
                }
                if (!empty($cacheNumber)) {
                    Cache::dec($cacheKey);
                }
                throw new ServiceException(['msg' => '请使用银行卡协议支付, 谢谢您的支持']);
                if (doubleval($data['price']) > 500) {
                    throw new ServiceException(['msg' => '单次充值金额不可超过500元~请调整充值金额']);
                }
//                $res = $service->rechargeForPay($data);
                $res = false;
                break;
            case 6:
                if (doubleval($data['price']) > 5000) {
                    throw new ServiceException(['msg' => '单次充值金额不可超过5000元~请调整充值金额']);
                }
//                $res = $service->rechargeForAgreementPay($data);
                $res = false;
                break;
            default:
                throw new ServiceException(['msg' => '暂不支持的支付方式']);
                break;
        }

        return returnData($res);
    }

    /**
     * @title  众筹订单列表
     * @param OrderModel $model
     * @return string
     * @throws \Exception
     */
    public function crowdOrderList(OrderModel $model)
    {
        $list = $model->crowdOrderList($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  协议支付中查询订单状态
     * @param OrderModel $model
     * @return string
     */
    public function agreementCheckOrderStatus(OrderModel $model)
    {
        $info = $model->agreementCheckOrderStatus($this->requestData);
        return returnData($info);
    }

    /**
     * @title  协议支付验证支付短信验证码
     * @return mixed
     */
    public function agreementVerifyPaySmsCode(OrderService $service)
    {
        $data = $this->requestData;
        $data['uid'] = $this->request->uid;
        $res = $service->agreementVerifyPaySmsCode($data);
        return returnMsg($res);
    }

    /**
     * @title  协议支付重新下发支付验证码
     * @param OrderService $service
     * @return string
     */
    public function agreementPaySmsSendAgain(OrderService $service)
    {
        $res = $service->paySendSmsAgain($this->requestData);
        return returnMsg($res);
    }


    /**
     * @title  协议支付下发签约验证码
     * @return mixed
     */
    public function agreementSignSms(BankCard $service)
    {
        $res = $service->signSms($this->requestData);
        return returnData($res);
    }

    /**
     * @title  协议支付验证签约验证码(开始签约)
     * @return mixed
     */
    public function agreementSignContract(BankCard $service)
    {
        $data = $this->requestData;
        $data['uid'] = $this->request->uid;
        $res = $service->signContract($data);
        return returnMsg($res);
    }

    /**
     * @title  协议支付解约
     * @param BankCard $service
     * @return string
     */
    public function agreementUnSign(BankCard $service)
    {
        $res = $service->unSign($this->requestData);
        return returnMsg($res);
    }

}