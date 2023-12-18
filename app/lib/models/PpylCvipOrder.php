<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\MemberException;
use app\lib\exceptions\PpylException;
use app\lib\exceptions\UserException;
use app\lib\models\Member as MemberModel;
use app\lib\services\CodeBuilder;
use app\lib\services\JoinPay;
use app\lib\services\WxPayService;
use think\facade\Db;

class PpylCvipOrder extends BaseModel
{
    protected $validateFields = ['order_sn', 'uid', 'real_pay_price', 'status' => 'number'];

    /**
     * @title  会员开通记录
     * @param array $data
     * @throws \Exception
     * @return array
     */
    public function cOrderList(array $data)
    {
        $uid = $data['uid'];
        $map[] = ['status', '=', 1];
        $map[] = ['uid', '=', $uid];
        $map[] = ['pay_status','=',2];

        $page = intval($sear['page'] ?? 0) ?: null;

        if (!empty($sear['pageNumber'])) {
            $this->pageNumber = intval($sear['pageNumber']);
        }

        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->where($map)->order('create_time desc')
            ->select()->each(function ($item) {
            if (!empty($item['pay_time'])) {
                $item['pay_time'] = timeToDateFormat($item['pay_time']);
            }
        })->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }


    /**
     * @title  新建会员订单
     * @param array $data
     * @return mixed
     */
    public function buildOrder(array $data)
    {
        $newMember = Db::transaction(function () use ($data) {
            //生成会员订单
            $newMemberInfo = $this->new($data);
            return $newMemberInfo;
        });

        $aUserInfo = (new User())->getUserProtectionInfo($data['uid']);
        //生成支付订单
        $wxRes = [];
        if (!empty($newMember)) {
            $wxOrder['openid'] = $aUserInfo['openid'];
            $wxOrder['out_trade_no'] = $newMember['order_sn'];
            $wxOrder['body'] = 'CVIP会员订单';
            $wxOrder['attach'] = 'CVIP会员订单';
            $wxOrder['total_fee'] = $newMember['real_pay_price'];
            //$wxOrder['total_fee'] = 0.01;
            //微信商户号支付
//                            $wxOrder['notify_url'] = config('system.callback.wxPayCallBackUrl');
//                            $wxRes = (new WxPayService())->order($wxOrder);
            //汇聚支付
            $wxOrder['notify_url'] = config('system.callback.joinPayMemberCallBackUrl');

            $wxRes = (new JoinPay())->order($wxOrder);
            $wxRes['need_pay'] = true;
            $wxRes['complete_pay'] = false;
        }

        return $wxRes;
    }

    /**
     * @title  订单详情
     * @param string $orderSn 订单编号
     * @return array
     */
    public function info(string $orderSn)
    {
        $info = $this->where(['order_sn' => $orderSn])->withoutField('id')->findOrEmpty()->toArray();
        return $info;
    }

    /**
     * @title  新建会员订单
     * @param array $data
     * @return mixed
     */
    public function new(array $data)
    {
        $comboInfo = (new PpylCvipPrice())->where(['combo_sn' => $data['combo_sn'], 'status' => [1]])->findOrEmpty()->toArray();
        if (empty($comboInfo)) {
            throw new PpylException(['errorCode' => 2100117]);
        }
        $aUserInfo = (new User())->getUserInfo($data['uid']);
//        if (empty($aUserInfo['phone'])) {
//            throw new UserException(['errorCode' => 800102]);
//        }
        $add['order_sn'] = (new CodeBuilder())->buildOrderNo();
        $add['uid'] = $aUserInfo['uid'];
        $add['user_phone'] = $aUserInfo['phone'];
        $add['user_level'] = $aUserInfo['vip_level'] ?? 0;
        $add['c_vip_level'] = $aUserInfo['c_vip_level'] ?? 1;
        $add['buy_combo_sn'] = $comboInfo['combo_sn'];
        $add['buy_c_vip_level'] = $comboInfo['level'];
        $add['buy_expire_time'] = $comboInfo['expire_time'];
        $add['buy_combo_name'] = $comboInfo['name'];
        $add['real_pay_price'] = $comboInfo['price'];
        $add['market_price'] = $comboInfo['market_price'];
        $add['pay_type'] = $data['pay_type'] ?? 2;
        $res = $this->validate()->baseCreate($add);
        return $res->getData();
    }
}