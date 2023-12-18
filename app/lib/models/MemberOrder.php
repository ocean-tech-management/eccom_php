<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\UserException;
use app\lib\services\CodeBuilder;
use think\facade\Db;

class MemberOrder extends BaseModel
{
    protected $field = ['order_sn', 'order_belong', 'user_phone', 'pay_no', 'uid', 'discount_price', 'total_price', 'real_pay_price', 'pay_type', 'pay_status', 'order_status', 'pay_time', 'close_time', 'end_time', 'status'];
    protected $validateFields = ['order_sn', 'uid', 'real_pay_price', 'status' => 'number'];
    private $belong = 1;

    /**
     * @title  订单列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear = []): array
    {
        $map = [];
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('a.order_sn|b.name', $sear['keyword']))];
        }
        if (!empty($sear['searType'])) {
            $map[] = ['a.order_status', 'in', $sear['searType'] ?? [1, 2]];
        }

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = Db::name('member_order')->alias('a')
                ->join('sp_user b', 'a.uid = b.uid', 'left')
                ->where($map)
                ->group('a.order_sn')->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = Db::name('member_order')->alias('a')
            ->join('sp_user b', 'a.uid = b.uid', 'left')
            ->where($map)
            ->field('a.order_sn,a.uid,a.total_price,a.member_level,a.discount_price,a.real_pay_price,a.pay_type,a.pay_status,a.order_status,a.create_time,a.pay_time,a.end_time,b.name as user_name,b.phone as user_phone')
            ->group('a.order_sn')
            ->order('a.create_time desc')
            ->when($page, function ($query) use ($page) {
                $query->page($page, $this->pageNumber);
            })
            ->select()->each(function ($item, $key) {
                $item['create_time'] = date('Y-m-d H:i:s', $item['create_time']);
                if (!empty($item['pay_time'])) {
                    $item['pay_time'] = date('Y-m-d H:i:s', $item['pay_time']);
                }
                if (!empty($item['end_time'])) {
                    $item['end_time'] = date('Y-m-d H:i:s', $item['end_time']);
                }
                return $item;
            })->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }


    /**
     * @title  新建会员订单
     * @param array $data
     * @return mixed
     */
    public function new(array $data)
    {
        $firstLevel = 1;
        $aDefaultLevel = (new MemberVdc())->where(['level' => $firstLevel, 'belong' => $this->belong, 'status' => [1]])->value('become_price');
        $aUserInfo = (new User())->getUserInfo($data['uid']);
        if (empty($aUserInfo['phone'])) {
            throw new UserException(['errorCode' => 800102]);
        }
        $add['order_sn'] = (new CodeBuilder())->buildOrderNo();
        $add['order_belong'] = $this->belong;
        $add['uid'] = $aUserInfo['uid'];
        $add['user_phone'] = $aUserInfo['phone'];
        $add['total_price'] = priceFormat($aDefaultLevel);
        $add['discount_price'] = priceFormat($data['discount_price'] ?? 0);
        $add['real_pay_price'] = priceFormat($add['total_price'] - $add['discount_price']);
        $add['pay_type'] = $data['pay_type'] ?? 2;
        $add['member_level'] = $firstLevel;
        $res = $this->validate()->baseCreate($add);
        return $res->getData();
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
}