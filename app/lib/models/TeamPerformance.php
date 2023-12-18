<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 团队订单业绩模块Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\ParamException;
use app\lib\exceptions\ServiceException;
use app\lib\services\Divide as DivideService;
use think\facade\Db;

class TeamPerformance extends BaseModel
{
    /**
     * @title  用户端用户团队全部订单列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function userTeamAllOrderList(array $sear = []): array
    {
        $map = [];

        //查看用户信息
        $linkUserInfo = Member::where(['uid' => $sear['uid']])->findOrEmpty()->toArray();
        if (empty($linkUserInfo)) {
            throw new ServiceException(['msg' => '非会员无法查看哦~~']);
        }

        if (!empty($sear['keyword'])) {
//            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('a.order_sn|b.name|e.title', $sear['keyword']))];
            //根据搜索关键词先筛选一遍
            $keyword = trim($sear['keyword']);

            //筛选的商品
            $gMap[] = ['', 'exp', Db::raw($this->getFuzzySearSql('title', $keyword))];
            $keywordGoods = GoodsSpu::where($gMap)->column('goods_sn');
            if (!empty($keywordGoods)) {
                $keywordGoodsSku = GoodsSku::where(['goods_sn' => $keywordGoods])->column('sku_sn');
            }

            //筛选的用户
            $uMap[] = ['', 'exp', Db::raw($this->getFuzzySearSql('name|phone', $keyword))];
            $keywordUser = User::where($uMap)->column('uid');

            //筛选的订单
            $oMap[] = ['', 'exp', Db::raw($this->getFuzzySearSql('order_sn', $keyword))];
            $oMap[] = ['link_uid', '=', $sear['uid']];
            $keywordOrder = self::where($oMap)->column('order_sn');
        }
        if (!empty($sear['searType'])) {
            $map[] = ['a.order_status', 'in', $sear['searType'] ?? [1, 2]];
        }
        if (!empty($sear['order_belong'])) {
            $map[] = ['a.order_belong', '=', $sear['order_belong']];
        }
        if (!empty($sear['split_status'])) {
            $map[] = ['a.split_status', '=', $sear['split_status']];
        }
        if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
//            $map[] = ['a.create_time', '>=', strtotime($sear['start_time']) . ' :00:00:00'];
//            $map[] = ['a.create_time', '<=', strtotime($sear['end_time']) . ' 23:59:59'];
            $tMap[] = ['order_create_time', '>=', strtotime($sear['start_time'])];
            $tMap[] = ['order_create_time', '<=', strtotime($sear['end_time'])];
        }

        //1为查看直推 2为查看直推以外的订单 3为查看间推
        if (!empty($sear['levelType'])) {
            switch ($sear['levelType'] ?? 1) {
                case 1:
                    $tMap[] = ['link_user_team_level', '=', 2];
                    break;
                case 2:
                    $tMap[] = ['link_user_team_level', '>=', 3];
                    break;
                case 3:
                    $tMap[] = ['link_user_team_level', '=', 3];
                    break;
                default:
                    break;
            }
        }

        $tGMap = [];
        $tGSMap = [];
        $tOUMap = [];
        $tOSNMap = [];
        //查看全部团队订单中时候有相关的订单
        if (!empty($keywordGoods)) {
            $tGMap[] = ['goods_sn', 'in', $keywordGoods];
        }
        if (!empty($keywordGoodsSku)) {
            $tGSMap[] = ['sku_sn', 'in', $keywordGoodsSku];
        }
        if (!empty($keywordUser)) {
            $tOUMap[] = ['order_uid', 'in', $keywordUser];
        }
        if (!empty($keywordOrder)) {
            $tOSNMap[] = ['order_sn', 'in', $keywordOrder];
        }

        $tMap[] = ['link_uid', '=', $sear['uid']];
        if (!empty($sear['order_uid'])) {
            $tMap[] = ['order_uid', '=', $sear['order_uid']];
        }
        //只查团队成员订单
        $tMap[] = ['type', '=', 1];

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = self::where(function($query) use ($tMap,$tGMap,$tGSMap,$tOUMap,$tOSNMap){
                $whereOr = [];
                if(!empty($tGMap)){
                    $whereOr[] = $tGMap;
                }
                if(!empty($tGSMap)){
                    $whereOr[] = $tGSMap;
                }
                if(!empty($tOUMap)){
                    $whereOr[] = $tOUMap;
                }
                if(!empty($tOSNMap)){
                    $whereOr[] = $tOSNMap;
                }
                if(!empty($whereOr)){
                    $query->whereOr($whereOr);
                }
            })->where($tMap)->field('DISTINCT(order_sn)')->select()->toArray();
            $aTotal = count($aTotal ?? []);
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $linkAllOrder = self::where(function ($query) use ($tMap, $tGMap, $tGSMap, $tOUMap, $tOSNMap) {
            $whereOr = [];
            if (!empty($tGMap)) {
                $whereOr[] = $tGMap;
            }
            if (!empty($tGSMap)) {
                $whereOr[] = $tGSMap;
            }
            if (!empty($tOUMap)) {
                $whereOr[] = $tOUMap;
            }
            if (!empty($tOSNMap)) {
                $whereOr[] = $tOSNMap;
            }
            if (!empty($whereOr)) {
                $query->whereOr($whereOr);
            }
        })->where($tMap)->field('order_sn,type,order_uid,link_uid,link_user_team_level,record_status,status')
            ->group('order_sn')
            ->when($page, function ($query) use ($page) {
                $query->page($page, $this->pageNumber);
            })->order('create_time desc')->select()->toArray();
        if (empty($linkAllOrder)) {
            return ['list' => [], 'pageTotal' => 0, 'total' => 0];
        }
        //查询真实订单
        $linkOrder = array_unique(array_filter(array_column($linkAllOrder, 'order_sn')));
        $map[] = ['a.order_sn', 'in', $linkOrder];
        //$map[] = ['a.uid','=',$sear['uid']];
        //默认显示支付成功的订单
        //$map[] = ['a.order_status','=',2];
        if (!empty($sear['searType'])) {
            $map[] = ['a.order_status', '=', $sear['searType']];
        }

//        $page = intval($sear['page'] ?? 0) ?: null;
//        if (!empty($page)) {
//            $aTotal = Db::name('order')->alias('a')
//                ->join('sp_user b', 'a.uid = b.uid', 'left')
////                ->join('sp_order_goods c', 'a.order_sn = c.order_sn', 'left')
////                ->join('sp_divide d', 'a.order_sn = d.order_sn', 'left')
////                ->when($sear['keyword'] ?? false, function ($query) {
////                    $query->join('sp_goods_spu e', 'c.goods_sn = e.goods_sn', 'left');
////                })
//                ->where($map)
//                ->group('a.order_sn')->count();
//            $pageTotal = ceil($aTotal / $this->pageNumber);
//        }

        $list = Db::name('order')->alias('a')
            ->join('sp_user b', 'a.uid = b.uid', 'left')
//            ->join('sp_order_goods c', 'a.order_sn = c.order_sn', 'left')
//            ->join('sp_divide d', 'a.order_sn = d.order_sn', 'left')
//            ->when($sear['keyword'] ?? false, function ($query) {
//                $query->join('sp_goods_spu e', 'c.goods_sn = e.goods_sn', 'left');
//            })
            ->where($map)
            ->field('a.order_sn,a.order_type,a.order_belong,a.uid,a.item_count,a.total_price,a.fare_price,a.discount_price,a.real_pay_price,a.pay_type,a.pay_status,a.order_status,a.after_status,a.create_time,a.pay_time,a.end_time,a.shipping_code,b.name as user_name,a.shipping_code,b.vip_level')
//            ->group('a.order_sn')
            ->order('a.create_time desc')
            ->select()->each(function ($item, $key) {
                $item['create_time'] = date('Y-m-d H:i:s', $item['create_time']);
                if (!empty($item['pay_time'])) {
                    $item['pay_time'] = date('Y-m-d H:i:s', $item['pay_time']);
                }
                if (!empty($item['end_time'])) {
                    $item['end_time'] = date('Y-m-d H:i:s', $item['end_time']);
                }
                $item['pt'] = [];
                return $item;
            })->toArray();

        $firstUserList = [];
        $firstLinkUser = [];
        //C端用户列表会返回商品信息
        if ($this->module == 'api') {
            $memberVdc = MemberVdc::where(['status' => 1])->column('name', 'level');
            if (!empty($list)) {
                $aGoodsSn = array_column($list, 'order_sn');
                $allOrderGoods = (new OrderGoods())->with(['goods'])->where(['order_sn' => $aGoodsSn, 'status' => [1, -2, -3]])->field('goods_sn,order_sn,sku_sn,count,price,total_price,specs,after_status,status,sale_price')->order('create_time desc')->select()->toArray();
                if (!empty($allOrderGoods)) {
                    foreach ($list as $key => $value) {
                        foreach ($allOrderGoods as $k => $v) {
                            if ($v['order_sn'] == $value['order_sn']) {
                                if (!empty($v['main_image'])) {
                                    $v['main_image'] .= '?x-oss-process=image/format,webp';
                                }
                                $list[$key]['goods'][] = $v;
                            }
                        }
                    }
                }
                //查找订单起始的用户是谁,如果出现了订单购买人现在的上级uid跟订单起始uid不同的情况,上级信息替换为订单起始人的用户信息
                $firstUserList = self::with(['link'])->where(['order_sn' => array_unique($aGoodsSn), 'type' => 1])->field('order_sn,id,order_uid,link_uid,link_user_team_level')->order('link_user_team_level asc,id asc')->group('order_sn')->select()->toArray();
                if (!empty($firstUserList)) {
                    foreach ($firstUserList as $key => $value) {
                        $firstLinkUser[$value['order_sn']]['first_link_user_uid'] = $value['link_uid'];
                        $firstLinkUser[$value['order_sn']]['link_user_name'] = $value['link_user_name'] ?? '游客';
                        $firstLinkUser[$value['order_sn']]['link_user_phone'] = $value['link_user_phone'] ?? null;
                        $firstLinkUser[$value['order_sn']]['link_user_level'] = $value['link_user_level'] ?? 0;
                    }
                }

                //查找上级信息
                $aAllUser = array_column($list, 'uid');
                $allUserTop = User::with(['link'])->where(['uid' => $aAllUser])->field('uid,name,vip_level,phone,link_superior_user')->select()->toArray();

                foreach ($list as $key => $value) {
                    $list[$key]['vip_name'] = '普通用户';
                    $list[$key]['link_user_vip_name'] = '普通用户';
                }

                if (!empty($allUserTop)) {
                    //查找订单起始的用户是谁,如果出现了订单购买人现在的上级uid跟订单起始uid不同的情况,上级信息替换为订单起始人的用户信息,以确保信息不会出现偏差,以当时的情况为主
                    if (!empty($firstUserList)) {
                        foreach ($list as $key => $value) {
                            foreach ($allUserTop as $uKey => $uValue) {
                                if ($uValue['uid'] == $value['uid']) {
                                    if (!empty($firstLinkUser) && !empty($firstLinkUser[$value['order_sn']])) {
                                        $firstDivideUserInfo = $firstLinkUser[$value['order_sn']];
                                        if (!empty($uValue['link_superior_user']) && $firstDivideUserInfo['first_link_user_uid'] != $uValue['link_superior_user']) {
                                            $allUserTop[$uKey]['link_user_level'] = $firstDivideUserInfo['link_user_level'];
                                            $allUserTop[$uKey]['link_user_name'] = $firstDivideUserInfo['link_user_name'];
                                            $allUserTop[$uKey]['link_user_phone'] = $firstDivideUserInfo['link_user_phone'];
                                        }
                                    }
                                }
                            }
                        }
                    }

                    foreach ($allUserTop as $key => $value) {
                        foreach ($list as $lKey => $lValue) {
                            if (!empty($memberVdc)) {
                                $list[$lKey]['vip_name'] = $memberVdc[$lValue['vip_level']] ?? '普通用户';
                            }
                            if ($value['uid'] == $lValue['uid']) {
                                $list[$lKey]['link_user_name'] = $value['link_user_name'] ?? null;
                                if (!empty($value['link_user_phone'])) {
                                    if (!empty($sear['orderUserType']) && $sear['orderUserType'] == 1) {
                                        $list[$lKey]['link_user_phone'] = $value['link_user_phone'];
                                    } else {
                                        $list[$lKey]['link_user_phone'] = encryptPhone($value['link_user_phone']);
                                    }

                                } else {
                                    $list[$lKey]['link_user_phone'] = '用户暂未绑定手机号码';
                                }
                                $list[$lKey]['link_user_level'] = $value['link_user_level'] ?? 0;
                                if (!empty($list[$lKey]['link_user_level']) && !empty($memberVdc)) {
                                    $list[$lKey]['link_user_vip_name'] = $memberVdc[$list[$lKey]['link_user_level']] ?? '普通用户';
                                }
                            }
                        }
                    }
                }

                //拼团订单详情
                $ptOrder = PtOrder::where(['order_sn' => $aGoodsSn])->withoutField('id,update_time')->select()->toArray();
                if (!empty($ptOrder)) {
                    foreach ($list as $lKey => $lValue) {
                        foreach ($ptOrder as $key => $value) {
                            if ($lValue['order_sn'] == $value['order_sn']) {
                                $list[$lKey]['pt'] = $value;
                            }
                        }
                    }
                }
            }
        }

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0, 'total' => $aTotal ?? 0];
    }

    /**
     * @title  用户端团队全部订单汇总
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function userTeamAllOrderCount(array $sear = []): array
    {
        $finally['total'] = 0;
        $finally['direct'] = 0;
        $finally['all'] = 0;

        $keywordGoods = [];
        $keywordGoodsSku = [];
        $keywordUser = [];
        $keywordOrder = [];

        //查看相关的订单
        $linkUserInfo = Member::where(['uid' => $sear['uid']])->findOrEmpty()->toArray();
        if (empty($linkUserInfo)) {
            throw new ServiceException(['msg' => '非会员无法查看哦~~']);
        }

        if (!empty($sear['keyword'])) {
//            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('a.order_sn|b.name|e.title', $sear['keyword']))];
            //根据搜索关键词先筛选一遍
            $keyword = trim($sear['keyword']);

            //筛选的商品
            $gMap[] = ['', 'exp', Db::raw($this->getFuzzySearSql('title', $keyword))];
            $keywordGoods = GoodsSpu::where($gMap)->column('goods_sn');
            if (!empty($keywordGoods)) {
                $keywordGoodsSku = GoodsSku::where(['goods_sn' => $keywordGoods])->column('sku_sn');
            }

            //筛选的用户
            $uMap[] = ['', 'exp', Db::raw($this->getFuzzySearSql('name|phone', $keyword))];
            $keywordUser = User::where($uMap)->column('uid');

            //筛选的订单
            $oMap[] = ['', 'exp', Db::raw($this->getFuzzySearSql('order_sn', $keyword))];
            $oMap[] = ['link_uid', '=', $sear['uid']];
            $keywordOrder = self::where($oMap)->column('order_sn');
        }

        $tGMap = [];
        $tGSMap = [];
        $tOUMap = [];
        $tOSNMap = [];
        //查看全部团队订单中时候有相关的订单
        if (!empty($keywordGoods)) {
            $tGMap[] = ['goods_sn', 'in', $keywordGoods];
        }
        if (!empty($keywordGoodsSku)) {
            $tGSMap[] = ['sku_sn', 'in', $keywordGoodsSku];
        }
        if (!empty($keywordUser)) {
            $tOUMap[] = ['order_uid', 'in', $keywordUser];
        }
        if (!empty($keywordOrder)) {
            $tOSNMap[] = ['order_sn', 'in', $keywordOrder];
        }

        if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
//            $map[] = ['a.create_time', '>=', strtotime($sear['start_time']) . ' :00:00:00'];
//            $map[] = ['a.create_time', '<=', strtotime($sear['end_time']) . ' 23:59:59'];
            $tMap[] = ['order_create_time', '>=', strtotime($sear['start_time'])];
            $tMap[] = ['order_create_time', '<=', strtotime($sear['end_time'])];
        }

        $tMap[] = ['link_uid', '=', $sear['uid']];
        //只查团队成员订单
        $tMap[] = ['type', '=', 1];

        $linkAllOrder = self::where(function ($query) use ($tMap, $tGMap, $tGSMap, $tOUMap, $tOSNMap) {
            $whereOr = [];
            if (!empty($tGMap)) {
                $whereOr[] = $tGMap;
            }
            if (!empty($tGSMap)) {
                $whereOr[] = $tGSMap;
            }
            if (!empty($tOUMap)) {
                $whereOr[] = $tOUMap;
            }
            if (!empty($tOSNMap)) {
                $whereOr[] = $tOSNMap;
            }
            if (!empty($whereOr)) {
                $query->whereOr($whereOr);
            }
        })->where($tMap)->field('order_sn,type,order_uid,link_uid,link_user_team_level,record_status,status')->order('create_time desc')->select()->toArray();

        if (empty($linkAllOrder)) {
            return $finally;
        }
        //查询真实订单总数
        $linkOrder = array_unique(array_filter(array_column($linkAllOrder, 'order_sn')));

        $map[] = ['a.order_sn', 'in', $linkOrder];
        //$map[] = ['a.uid','=',$sear['uid']];
        //默认显示支付成功的订单
        //$map[] = ['a.order_status','=',2];
        if (!empty($sear['searType'])) {
            $map[] = ['a.order_status', '=', $sear['searType']];
        }

        $allOrder = Db::name('order')->alias('a')
            ->where($map)
            ->group('a.order_sn')
            ->order('a.create_time desc')->column('a.order_sn');

        if (empty($allOrder)) {
            return $finally;
        }
        //去重
        $orderExist = [];
        foreach ($allOrder as $key => $value) {
            foreach ($linkAllOrder as $tKey => $tValue) {
                if ($value == $tValue['order_sn'] && $tValue['type'] == 1 && !isset($orderExist[$tValue['order_sn']])) {
                    $orderExist[$tValue['order_sn']] = true;
                    //分润层级为2,则代表为直推,大于2则间推直至最后
                    if ($tValue['link_user_team_level'] == 2) {
                        $finally['direct'] += 1;
                    } elseif ($tValue['link_user_team_level'] >= 3) {
                        $finally['all'] += 1;
                    }
                }
            }
        }

        $finally['total'] = ($finally['direct'] ?? 0) + ($finally['all'] ?? 0);
        $finally['team'] = $finally['total'] ?? 0;
        return $finally;
    }

    /**
     * @title  上级查看某个下级的订单
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function teamMemberOrder(array $sear)
    {
        $list = [];
        if (empty(trim($sear['order_uid'])) || empty(trim($sear['observer_uid']))) {
            throw new ParamException();
        }
        if (trim($sear['order_uid']) == trim($sear['observer_uid'])) {
            throw new ParamException(['msg' => '自己的订单请从我的订单查看哟~']);
        }
        $uid = $sear['order_uid'];
        $observerUid = $sear['observer_uid'];
        //判断观察者是否有查看权限
        $checkData['order_uid'] = $uid;
        $checkData['observer_uid'] = $observerUid;
        $checkData['justCheck'] = true;

        if (empty($this->checkUserIsTop($checkData))) {
            return ['list' => [], 'pageTotal' => 0, 'total' => 0];
        }

        //查看指定用户
        $sear['levelType'] = 3;
        $sear['uid'] = $observerUid;
        $list = $this->userTeamAllOrderList($sear);
        return $list;
    }

    /**
     * @title  根据订单冗余表查看某人的上级或判断观察者是否是某个人的上级
     * @param array $data
     * @return array|bool
     */
    public function checkUserIsTop(array $data)
    {
        $maxUserOrderTopUsers = [];
        $returnData = false;
        //是否为仅校验上级,true为是
        $checkStatus = $data['justCheck'] ?? true;
        //检验类型 1为仅检查观察者是否曾经为上级 2为查找最新的全部上级并判断是否处于其中
        $checkType = $data['checkType'] ?? 1;

        switch ($checkType) {
            case 1:
                if (!empty($checkStatus)) {
                    $existOrder = TeamPerformance::where(['order_uid' => $data['order_uid'], 'link_uid' => $data['observer_uid'], 'status' => 1])->count();
                    if (!empty($existOrder)) {
                        $returnData = true;
                    } else {
                        $returnData = false;
                    }
                }
                break;
            case 2:
                $maxUserOrderNumber = TeamPerformance::field('count(*) as number,order_sn')->where(['uid' => $data['order_uid'], 'status' => 1])->group('order_sn')->order('number desc,order_create_time desc')->limit(1)->findOrEmpty()->toArray();
                if (empty($maxUserOrderNumber)) {
                    return [];
                }
                $maxUserOrderTopUsers = TeamPerformance::where(['order_sn' => $maxUserOrderNumber['order_sn'], 'status' => 1])->column('link_uid');
                if (!empty($maxUserOrderTopUsers)) {
                    $maxUserOrderTopUsers = array_unique($maxUserOrderTopUsers);
                }
                if (!empty($checkStatus)) {
                    if (!empty($data['observer_uid'])) {
                        if (in_array($data['observer_uid'], $maxUserOrderTopUsers)) {
                            $returnData = true;
                        } else {
                            $returnData = false;
                        }
                    }
                } else {
                    $returnData = $maxUserOrderTopUsers;
                }
                break;
            default:
                $returnData = false;
        }

        return $returnData;
    }

    public function link()
    {
        return $this->hasOne('User', 'uid', 'link_uid')->bind(['link_user_name' => 'name', 'link_user_phone' => 'phone', 'link_user_level' => 'vip_level']);
    }
}