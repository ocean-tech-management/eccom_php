<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 拼拼有礼排队订单模块Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use think\facade\Db;

class PpylWaitOrder extends BaseModel
{
    /**
     * @title  拼拼有礼排队订单列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear = []): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('activity_title|area_name', $sear['keyword']))];
        }
        if (!empty($sear['searOrderSn'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('order_sn', trim($sear['searOrderSn'])))];
        }

        if (!empty($sear['searUserName'])) {
            $userMap[] = ['', 'exp', Db::raw($this->getFuzzySearSql('name', trim($sear['searUserName'])))];
            $userMap[] = ['status', '=', 1];
            $user = User::where($userMap)->column('uid');
            if (!empty($user)) {
                $map[] = ['uid', 'in', $user];
            }
        }

        if (!empty($sear['searUserPhone'])) {
            $userMap[] = ['', 'exp', Db::raw($this->getFuzzySearSql('phone', trim($sear['searUserPhone'])))];
            $userMap[] = ['status', '=', 1];
            $user = User::where($userMap)->column('uid');
            if (!empty($user)) {
                $map[] = ['uid', 'in', $user];
            }
        }

        if (!empty($sear['searPayNo'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('pay_no', trim($sear['searPayNo'])))];
        }

        if (!empty($sear['wait_status'])) {
            $map[] = ['wait_status', 'in', $sear['wait_status']];
        }
        if (!empty($sear['activity_code'])) {
            $map[] = ['activity_code', '=', $sear['activity_code']];
        }
        if (!empty($sear['area_code'])) {
            $map[] = ['area_code', '=', $sear['area_code']];
        }
        if (!empty($sear['pay_status'])) {
            $map[] = ['pay_status', '=', $sear['pay_status']];
        }

        //查找商品
        if (!empty($sear['searGoodsSpuSn'])) {
            if (is_array($sear['searGoodsSpuSn'])) {
                $map[] = ['goods_sn', 'in', $sear['searGoodsSpuSn']];
            } else {
                $map[] = ['goods_sn', '=', $sear['searGoodsSpuSn']];
            }
        }
        if (!empty($sear['searGoodsSkuSn'])) {
            if (is_array($sear['searGoodsSkuSn'])) {
                $map[] = ['sku_sn', 'in', $sear['searGoodsSkuSn']];
            } else {
                $map[] = ['sku_sn', '=', $sear['searGoodsSkuSn']];
            }
        }

        if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
            $map[] = ['create_time', '>=', strtotime($sear['start_time'])];
            $map[] = ['create_time', '<=', strtotime($sear['end_time'])];
        }

        if ($this->module == 'api') {
            if (!empty($sear['uid'])) {
                $map[] = ['uid', '=', $sear['uid']];
            }
            $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
//            $map[] = ['end_time', '>', time()];
        }

//        //后台只查开团的订单,参团的之后的查询补上
//        if($this->module == 'admin'){
//            $map[] = ['user_role', '=', 1];
//        }

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($sear['pageNumber'])) {
            $this->pageNumber = intval($sear['pageNumber']);
        }

        if (!empty($page)) {
            $aTotal = self::where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = self::with(['activity','area','goods','user'])->where($map)
            ->field('order_sn,activity_code,area_code,activity_title,area_name,uid,create_time,goods_sn,sku_sn,close_time,c_vip_level,user_role,real_pay_price,wait_start_time,wait_end_time,timeout_time,wait_status,pay_status,pay_type,pay_time,refund_route,refund_price,refund_time,reward_price,pay_no,pay_order_sn')
            ->when($page, function ($query) use ($page) {
                $query->page($page, $this->pageNumber);
            })->order('create_time desc')
            ->select()->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  用户排队列表
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function userWaitList(array $data)
    {
        if (!empty($data['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('activity_title|area_name', $data['keyword']))];
        }
        $map[] = ['uid', '=', $data['uid']];
        $map[] = ['', 'exp', Db::raw('pay_status not in (1)')];

        if (!empty($data['wait_status'])) {
            $map[] = ['wait_status', 'in', $data['wait_status']];
        }

        $page = intval($data['page'] ?? 0) ?: null;
        if (!empty($data['pageNumber'])) {
            $this->pageNumber = intval($data['pageNumber']);
        }
        //筛选创建时间
        if(!empty($data['start_time']) && !empty($data['end_time'])){
            $map[] = ['create_time','>=',strtotime($data['start_time'])];
            $map[] = ['create_time','<=',strtotime($data['end_time'])];
        }

        $mapOrOne = [];
        $mapOrTwo = [];
        //筛选超时时间
        if(!empty($data['timeout_start_time']) && !empty($data['timeout_end_time'])){
            $mapOrOne[] = ['timeout_time','>=',strtotime($data['timeout_start_time'])];
            $mapOrOne[] = ['timeout_time','<=',strtotime($data['timeout_end_time'])];
            $mapOrTwo[] = ['','exp',Db::raw('timeout_time is null')];
        }

        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->with(['orderGoods' => function ($query) use ($map, $data) {
            $query->field('order_sn,goods_sn,sku_sn,price,sale_price,total_price,title,images,specs,total_fare_price,shipping_status,real_pay_price');
        }])->where($map)->where(function ($query) use ($mapOrOne, $mapOrTwo) {
            if(!empty($mapOrOne)){
                $query->whereOr([$mapOrOne, $mapOrTwo]);
            }
        })->withoutField('id,update_time')
            ->when($page, function ($query) use ($page) {
                $query->page($page, $this->pageNumber);
            })->order('create_time desc')->select()->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    public function cvip()
    {
        return $this->hasOne('User','uid','uid')->bind(['user_c_vip_level'=>'c_vip_level','c_vip_time_out_time']);
    }

    public function activity()
    {
        return $this->hasOne('PpylActivity', 'activity_code', 'activity_code')->withoutField('id,create_time,update_time');
    }
    public function area()
    {
        return $this->hasOne('PpylArea','area_code', 'area_code')->withoutField('id,create_time,update_time');
    }

    public function goods()
    {
        return $this->hasOne('GoodsSku', 'sku_sn', 'sku_sn')->field('goods_sn,sku_sn,title,image,sub_title,specs');
    }

    public function orderGoods()
    {
        return $this->hasOne('PpylOrderGoods', 'sku_sn', 'sku_sn');
    }

    public function user()
    {
        return $this->hasOne('User', 'uid', 'uid')->field('uid,name,phone,avatarUrl');
    }

    public function getWaitStartTimeAttr($value)
    {
        return !empty($value) ? timeToDateFormat($value) : $value;
    }

    public function getWaitEndTimeAttr($value)
    {
        return !empty($value) ? timeToDateFormat($value) : $value;
    }

    public function getCloseTimeAttr($value)
    {
        return !empty($value) ? timeToDateFormat($value) : $value;
    }

    public function getTimeoutTimeAttr($value)
    {
        return !empty($value) ? timeToDateFormat($value) : $value;
    }

    public function getPayTimeAttr($value)
    {
        return !empty($value) ? timeToDateFormat($value) : $value;
    }

    public function refundPayNo()
    {
        return $this->hasOne('PpylOrder', 'pay_no', 'pay_no')->where(['pay_status' => -2])->field('id,order_sn,pay_no');
    }

    public function refundWaitPayNo()
    {
        return $this->hasOne(get_class($this), 'pay_no', 'pay_no')->where(['pay_status' => -2])->field('id,order_sn,pay_no');
    }
}