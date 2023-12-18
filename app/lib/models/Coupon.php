<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 优惠券模块model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\CouponException;
use app\lib\services\CodeBuilder;
use app\lib\validates\Coupon as CouponValidate;
use think\facade\Db;

class Coupon extends BaseModel
{
    protected $field = ['code', 'name', 'icon', 'used', 'type', 'belong_type', 'with_special', 'with_category', 'with_goods_sn', 'with_brand', 'with_condition', 'with_amount', 'with_discount', 'used_amount', 'number', 'take_user_type', 'take_limit', 'take_count', 'used_count', 'start_time', 'end_time', 'valid_type', 'valid_start_time', 'valid_end_time', 'valid_days', 'valid_status', 'desc', 'status', 'create_user', 'update_user', 'show_status'];

    protected $validateFields = ['code', 'name', 'used', 'type', 'number'];

    /**
     * @title  优惠券列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear = []): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('a.name', $sear['keyword']))];
        }
        if (!empty($sear['used_code'])) {
            $map[] = ['b.u_type', '=', $sear['used_code']];
        }
        if (!empty($sear['type_code'])) {
            $map[] = ['c.t_type', '=', $sear['type_code']];
        }
        if (!empty($sear['valid_status'])) {
            $map[] = ['a.valid_status', '=', $sear['valid_status']];
        }
        if (!empty($sear['belong_type'])) {
            $map[] = ['a.belong_type', '=', $sear['belong_type']];
        }
        if (!empty($sear['category_code'])) {
            $map[] = ['', 'exp', Db::raw('FIND_IN_SET("' . $sear['category_code'] . '",`a`.`with_category`)')];
        }
        //优惠券列表可以提供查询删除状态的列表
        if ($this->module == 'admin' && !empty($sear['sear_delete'])) {
            $map[] = ['a.status', '=', -1];
        } else {
            $map[] = ['a.status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        }


        $aMemberType = [];
        $useLevel = 0;
        //C端接口仅返回可领取的优惠券
        if ($this->module == 'api') {
            $map[] = ['a.start_time', '<=', time()];
            $map[] = ['a.end_time', '>=', time()];
            $map[] = ['', 'exp', Db::raw('a.number > a.take_count')];
            //仅显示可显示的优惠券列表
            $map[] = ['a.show_status', '=', 1];
            if (!empty($sear['uid'])) {
                $useLevel = (new Member())->getUserLevel($sear['uid']);
                if (empty($useLevel)) {
                    $aUserType = (new CouponUserType())->userTypeList();
                    $map[] = ['a.take_user_type', 'in', $aUserType];
                }
                $aMemberType = (new CouponUserType())->memberTypeList();
            }

        }
        $field = $this->getListFieldByModule();
        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = Db::name('Coupon')->alias('a')
                ->join('sp_coupon_used b', 'a.used = b.u_type', 'left')
                ->join('sp_coupon_type c', 'a.type = c.t_type', 'left')
                ->join('sp_coupon_user_type d', 'a.take_user_type = d.u_type', 'left')
                ->where($map)
                ->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }
        $userCoupon = new UserCoupon();
        $category = new Category();
        $goodsSku = new GoodsSku();
        $list = Db::name('Coupon')->alias('a')
            ->join('sp_coupon_used b', 'a.used = b.u_type', 'left')
            ->join('sp_coupon_type c', 'a.type = c.t_type', 'left')
            ->join('sp_coupon_user_type d', 'a.take_user_type = d.u_type', 'left')
            ->where($map)
            ->field($field)
            ->when($page, function ($query) use ($page) {
                $query->page($page, $this->pageNumber);
            })->order('a.create_time desc')->group('a.code')->select()->each(function ($item, $key) use ($sear, $userCoupon, $category, $goodsSku, $useLevel, $aMemberType) {
                $item['start_time'] = date('Y-m-d H:i:s', $item['start_time']);
                $item['end_time'] = date('Y-m-d H:i:s', $item['end_time']);
                $item['create_time'] = date('Y-m-d H:i:s', $item['create_time']);
                $item['margin'] = intval($item['number'] - $item['take_count']);
                if ($item['margin'] <= 0) {
                    $item['margin'] = 0;
                }
                if ($this->module == 'api') {
                    $item['goods_name'] = [];
                    $item['category_name'] = [];
                    $item['user_take_number'] = 0;
                    $item['take_auth'] = 1;
                    if (!empty($sear['uid'])) {
                        //判断可领取数量
                        $item['user_take_number'] = $userCoupon->couponNumberByCouponCode($sear['uid'], $item['code']);
                        if ($item['user_take_number'] >= $item['take_limit']) {
                            $item['take_auth'] = 2;
                        }
                        //判断用户类型(新人)
                        if ($item['take_user_type'] == 2 && !empty($useLevel)) {
                            $item['take_auth'] = 2;
                        }
                        //判断用户类型(会员)
                        if (in_array($item['take_user_type'], $aMemberType) && empty($useLevel)) {
                            $item['take_auth'] = 2;
                        }
                    }
//                    //如果是类目优惠券则查询相关类目
//                    if($item['used'] == 20){
//                        $item['category_name'] = $category->where(['code'=>explode(',',$item['with_category']),'status'=>[1]])->column('name');
//                    }
//                    //如果是商品优惠券则查询相关前三个商品
//                    if($item['used'] == 30){
//                        $item['goods_name'] = $goodsSku->where(['sku_sn'=>explode(',',$item['with_goods_sn']),'status'=>[1]])->limit(3)->column('title');
//                    }
                    unset($item['number']);
                    unset($item['take_count']);
                    unset($item['take_limit']);
                }
                return $item;
            })->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  商品详情中可领取的优惠券列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function goodsInfoCouponList(array $sear = []): array
    {
        if (!empty($sear['used_code'])) {
            $map[] = ['used', '=', $sear['used_code']];
        }

//        if (!empty($sear['category_code'])) {
//            $map[] = ['', 'exp', Db::raw('FIND_IN_SET("' . $sear['category_code'] . '",`a`.`with_category`)')];
//        }
        $goodsSkuInfo = GoodsSku::where(['goods_sn' => $sear['goods_sn'], 'status' => 1])->column('sku_sn');
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];

        $aMemberType = [];
        $useLevel = 0;

        //C端接口仅返回可领取的优惠券
        if ($this->module == 'api') {
            $map[] = ['start_time', '<=', time()];
            $map[] = ['end_time', '>=', time()];
            $map[] = ['', 'exp', Db::raw('number > take_count')];
            $map[] = ['valid_status', '=', 1];
            $map[] = ['status', '=', 1];
            //仅显示可显示的优惠券列表
            $map[] = ['show_status', '=', 1];
            if (!empty($sear['uid'])) {
                $useLevel = (new Member())->getUserLevel($sear['uid']);
                if (empty($useLevel)) {
                    $aUserType = (new CouponUserType())->userTypeList();
                    $map[] = ['take_user_type', 'in', $aUserType];
                }
                $aMemberType = (new CouponUserType())->memberTypeList();
            }

        }
        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = self::where(function ($query) use ($map, $sear) {
                $mapAnd = $map;
                $whereMap[] = $mapAnd;
                $whereMap[] = ['used', 'in', [10, 30]];
                if (!empty($sear['category_code'])) {
                    $mapOr = $map;
                    $mapOr[] = ['used', '=', 20];
                    $mapOr[] = ['', 'exp', Db::raw('FIND_IN_SET("' . $sear['category_code'] . '",`with_category`)')];
                    $whereMap[] = $mapOr;
                }
                if (!empty($whereMap)) {
                    $query->whereOr($whereMap);
                }
            })->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $userCoupon = new UserCoupon();
        $category = new Category();
        $goodsSku = new GoodsSku();
        $list = self::where(function ($query) use ($map, $sear, $goodsSkuInfo) {
            $mapAnd = $map;
            $whereMap[] = $mapAnd;
            if (!empty($sear['category_code'])) {
                $mapOr = $map;
                $mapOr[] = ['used', '=', 20];
                $mapOr[] = ['', 'exp', Db::raw('FIND_IN_SET("' . $sear['category_code'] . '",`with_category`)')];
                $whereMap[] = $mapOr;
            }
            if (!empty($whereMap)) {
                $query->whereOr($whereMap);
            }
        })
            ->field('code,name,icon,used,type,with_condition,with_category,with_goods_sn,with_amount,with_discount,used_amount,start_time,end_time,number,take_count,take_user_type,valid_status,valid_days,create_time,take_limit')
            ->when($page, function ($query) use ($page) {
                $query->page($page, $this->pageNumber);
            })->order('create_time desc')->group('code')->select()->each(function ($item, $key) use ($sear, $userCoupon, $category, $goodsSku, $useLevel, $aMemberType, $goodsSkuInfo) {
                $item['margin'] = intval($item['number'] - $item['take_count']);
                if ($item['margin'] <= 0) {
                    $item['margin'] = 0;
                }
                if ($this->module == 'api') {
                    $item['goods_name'] = [];
                    $item['category_name'] = [];
                    $item['user_take_number'] = 0;
                    $item['take_auth'] = 1;
                    if (!empty($sear['uid'])) {
                        //判断可领取数量
                        $item['user_take_number'] = $userCoupon->couponNumberByCouponCode($sear['uid'], $item['code']);
                        if ($item['user_take_number'] >= $item['take_limit']) {
                            $item['take_auth'] = 2;
                        }
                        //判断用户类型(新人)
                        if ($item['take_user_type'] == 2 && !empty($useLevel)) {
                            $item['take_auth'] = 2;
                        }
                        //判断用户类型(会员)
                        if (in_array($item['take_user_type'], $aMemberType) && empty($useLevel)) {
                            $item['take_auth'] = 2;
                        }
                    }
//                    //如果是类目优惠券则查询相关类目
//                    if($item['used'] == 20){
//                        $item['category_name'] = $category->where(['code'=>explode(',',$item['with_category']),'status'=>[1]])->column('name');
//                    }
//                    //如果是商品优惠券则查询相关前三个商品
//                    if($item['used'] == 30){
//                        $item['goods_name'] = $goodsSku->where(['sku_sn'=>explode(',',$item['with_goods_sn']),'status'=>[1]])->limit(3)->column('title');
//                    }
                    unset($item['number']);
                    unset($item['take_count']);
                    unset($item['take_limit']);
                }
                $item['t_name'] = null;
                return $item;
            })->toArray();

        if (!empty($list)) {
            $couponType = CouponType::where(['status' => 1])->column('t_name', 't_type');
            foreach ($list as $key => $item) {
                if (!empty($item['type']) && !empty($couponType[$item['type']])) {
                    $list[$key]['t_name'] = $couponType[$item['type']];
                }

                //如果是商品券,如果查询的商品所有SKU都跟券本身的可使用SKU数组没有交集,则剔除该券
                if (!empty($goodsSkuInfo) && !empty($item['with_goods_sn']) && $item['used'] == 30) {
                    if (empty(array_intersect(explode(',', $item['with_goods_sn']), $goodsSkuInfo))) {
                        unset($list[$key]);
                    }
                }

                //如果是指定商品券,但是商品已经查找不到了,则剔除该券
                if($item['used'] == 30 && empty($goodsSkuInfo)){
                    unset($list[$key]);
                }

                //如果是类目券不符合的类目则剔除
                if ($item['used'] == 20 && !in_array($sear['category_code'], explode(',', $item['with_category']))) {
                    unset($list[$key]);
                }
            }

            if (!empty($list)) {
                $list = array_values($list);
            }
        }

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }


    /**
     * @title  新建优惠券
     * @param array $data 数据
     * @return mixed
     */
    public function new(array $data)
    {
        $data['with_brand'] = $data['with_brand'] ?? "0000";
        $data['name'] = trim($data['name']);
        (new CouponValidate())->goCheck($data, 'create');
        $data['code'] = (new CodeBuilder())->buildCouponCode($data);
        $res = $this->validate()->baseCreate($data, true);
        return $res;
    }

    /**
     * @title  优惠券详情
     * @param array $data 包含优惠券编码code和用户uid
     * @return mixed
     * @throws \Exception
     */
    public function info(array $data)
    {
        $code = $data['code'];
        $uid = $data['uid'] ?? null;
        $field = $this->getInfoFieldByModule();

        $userCoupon = 0;
        if (!empty($uid) && $this->module == 'api') {
            $userCoupon = UserCoupon::where(['coupon_code' => $code, 'status' => [1]])->count();
        }
        if (empty($userCoupon ?? 0) && $this->module == 'api') {
            $cMap[] = ['status', 'in', $this->getStatusByRequestModule()];
        }
        if (is_array($code)) {
            $cMap[] = ['code', 'in', $code];
        } else {
            $cMap[] = ['code', '=', $code];
        }

        $info = $this->with(['used', 'type', 'user'])->where($cMap)->field($field)->findOrEmpty()->toArray();

        if (!empty($info)) {
            $info['category'] = [];
            $info['goods'] = [];
            if (!empty($info['with_category'])) {
                $aCategory = explode(',', $info['with_category']);
                $aCategoryList = Category::where(['code' => $aCategory, 'status' => [1, 2]])->field('code,name')->select()->toArray();
                $info['category'] = $aCategoryList;
            }
            if (!empty($info['with_goods_sn'])) {
                $aGoodsSku = explode(',', $info['with_goods_sn']);
                $aGoodsSkuList = GoodsSku::where(['sku_sn' => $aGoodsSku, 'status' => [1, 2]])->field('sku_sn,title,specs')->select()->toArray();
                $info['goods'] = $aGoodsSkuList;
            }
            if ($this->module == 'api') {
                $info['user_take_number'] = 0;
                $info['take_auth'] = 1;
                if (!empty($uid)) {
                    $info['user_take_number'] = (new UserCoupon())->couponNumberByCouponCode($uid, $code);
                    if ($info['user_take_number'] >= $info['take_limit']) {
                        $info['take_auth'] = 2;
                    }

                    $useLevel = (new Member())->getUserLevel($uid);
                    //判断用户类型(新人)
                    if ($info['take_user_type'] == 2 && !empty($useLevel)) {
                        $info['take_auth'] = 2;
                    }

                    $aMemberType = (new CouponUserType())->memberTypeList();
                    //判断用户类型(会员)
                    if (in_array($info['take_user_type'], $aMemberType) && empty($useLevel)) {
                        $info['take_auth'] = 2;
                    }

                }
            }

        }
        return $info;
    }

    /**
     * @title  仅供内部服务调用的优惠券详情
     * @param string $code
     * @return mixed
     */
    public function infoForInternal(string $code)
    {
        return $this->with(['used', 'type', 'user'])->where(['code' => $code, 'status' => 1])->field('code,name,icon,used,type,with_condition,with_goods_sn,with_category,belong_type,number,with_amount,with_discount,used_amount,take_count,take_limit,valid_type,valid_start_time,valid_end_time,valid_days,start_time,end_time,take_user_type,valid_status')->findOrEmpty()->toArray();

    }


    /**
     * @title  编辑优惠券
     * @param array $data 数据
     * @return Coupon
     */
    public function edit(array $data)
    {
        //$data['with_brand'] = $data['with_brand']??"0000";
        //(new CouponValidate())->goCheck($data,'edit');
        $save['name'] = trim($data['name']);
        $save['desc'] = trim($data['desc'] ?? null);
        if (!empty($data['valid_status'] ?? null)) {
            $save['valid_status'] = $data['valid_status'];
        }
        if (!empty($data['start_time'] ?? null) && !empty($data['end_time'] ?? null)) {
            $save['start_time'] = $data['start_time'];
            $save['end_time'] = $data['end_time'];
        }
        if (!empty($data['valid_type'] ?? null)) {
            if ($data['valid_type'] == 1) {
                if (!empty($data['valid_start_time'] ?? null) && !empty($data['valid_end_time'] ?? null)) {
                    $save['valid_start_time'] = $data['valid_start_time'];
                    $save['valid_end_time'] = $data['valid_end_time'];
                    $save['valid_type'] = $data['valid_type'];
                }
            } elseif ($data['valid_type'] == 2) {
                if (!empty($data['valid_days'] ?? null)) {
                    $save['valid_days'] = $data['valid_days'];
                    $save['valid_type'] = $data['valid_type'];
                }
            }
        }

        if (!empty($data['take_limit'] ?? null)) {
            $save['take_limit'] = $data['take_limit'];
        }
        if (!empty($data['show_status'] ?? null)) {
            $save['show_status'] = $data['show_status'];
        }
        $res = $this->baseUpdate(['code' => $data['code']], $save);
        return $res;
    }

    /**
     * @title  修改优惠券数量
     * @param array $data
     * @return mixed
     */
    public function updateNumber(array $data)
    {
        $code = $data['code'];
        //$number可正可负
        $number = $data['number'];
        $DBRes = Db::transaction(function () use ($code, $number) {
            $info = self::where(['code' => $code, 'status' => [1, 2]])->lock(true)->field('code,name,number,take_count')->findOrEmpty()->toArray();
            if (empty($info)) {
                throw new CouponException(['msg' => '非法优惠券']);
            }
            $lastNumber = intval($info['number'] - $info['take_count']);
            if ($number > 0) {
                $res = self::where(['code' => $code, 'status' => [1, 2]])->inc('number', intval($number))->update();
            } else {
                if (intval($lastNumber) < 0) {
                    throw new CouponException(['msg' => '优惠券数量异常!']);
                }
                if (intval($lastNumber) + $number < 0) {
                    $res = self::update(['number' => ($info['take_count'] ?? 0)], ['code' => $code, 'status' => [1, 2]]);
                } else {
                    $res = self::where(['code' => $code, 'status' => [1, 2]])->inc('number', intval($number))->update();
                }
            }
            return $res;
        });
        return $DBRes;
    }

    /**
     * @title  编辑优惠券,其他操作(删除,修改优惠券状态)
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    public function operating(array $data)
    {
        //仅允许status=-1的删除和优惠券规则,以及优惠券状态的修改
        $paramKey = array_keys($data);
        $allow = ['code', 'status', 'valid_status', 'desc', 'adminInfo'];
//        if (count(array_intersect($paramKey, $allow)) != count($paramKey)) {
//            throw new CouponException(['errorCode' => 1200102]);
//        }
        $save = $data;
        unset($save['code']);
        if (array_key_exists('status', $save)) {
            if (!in_array($save['status'], [2, -1])) {
                throw new CouponException(['errorCode' => 1200102]);
            }
        }
        if (empty($save)) {
            throw new CouponException(['errorCode' => 1200102]);
        }
        if (!empty($data['valid_status'])) {
            if ($save['valid_status'] == 1) {
                $save['valid_status'] = 2;
            } elseif ($save['valid_status'] == 2) {
                $save['valid_status'] = 1;
            }
        }
        $DBRes = Db::transaction(function () use ($data, $save) {
            //修改优惠券
            $res = $this->baseUpdate(['code' => $data['code']], $save);

            //是否需要一并删除用户已领取但未使用的优惠券
            if (!empty($save['status'] ?? null) && $save['status'] == -1 && !empty($data['deleteUserCoupon'] ?? null)) {
                //锁行
                UserCoupon::where(['valid_status' => 1, 'status' => 1, 'coupon_code' => $data['code']])->lock(true)->column('id');

                //补发优惠券
                if (!empty($data['compensateCoupon'])) {
                    $compensateRes = $this->compensateCoupon($data);
                }

                UserCoupon::update(['status' => -1, 'valid_status' => -2, 'delete_time' => time()], ['valid_status' => 1, 'status' => 1, 'coupon_code' => $data['code']]);
            }
            if (!empty($data['compensateCoupon'] ?? false)) {
                return ['res' => $res, 'compensateRes' => $compensateRes ?? []];
            } else {
                return $res;
            }
        });

        return $DBRes;


    }

    /**
     * @title  补发优惠券
     * @param array $data
     * @return array|mixed
     * @throws \Exception
     * @remark 仅支持补发单张优惠券
     */
    public function compensateCoupon(array $data)
    {
        if (!empty($data['uc_code'])) {
            if (is_array($data['uc_code'])) {
                $map[] = ['uc_code', 'in', $data['uc_code']];
            } else {
                $map[] = ['uc_code', '=', $data['uc_code']];
            }

        }
//        $map[] = ['','exp',Db::raw('is_compensate is null')];
        if (!empty($data['isDelete'] ?? null)) {
            $map[] = ['status', '=', -1];
            $map[] = ['valid_status', '=', -2];
        }
        $map[] = ['is_compensate', '=', 2];
        $map[] = ['coupon_code', '=', $data['code']];
        $allUserCoupon = UserCoupon::where($map)->field('uid,group_concat(uc_code) as uc_code,count(id) as number,is_compensate')->group('uid')->select()->toArray();
        if (empty($allUserCoupon)) {
            throw new CouponException(['msg' => '暂无需要补偿的用户名单']);
        }
        foreach ($allUserCoupon as $key => $value) {
            $allUserCouponInfo[$value['uid']] = $value;
        }
        $compensateRes = [];

        foreach ($allUserCoupon as $key => $value) {
            $userList[$value['uid']] = $value['number'];
            $allNumberList[] = $value['number'];
        }

        $couponCardList = $data['couponCardList'] ?? [];
        if (count($couponCardList) > 1) {
            throw new CouponException(['msg' => '仅支持补发一张优惠券,如需额外补发请选择派券']);
        }
        $compensateCouponCode = $data['compensateCouponCode'] ?? (key($couponCardList));
        $compensateCouponInfo = self::where(['code' => $compensateCouponCode, 'status' => [1, 2]])->findOrEmpty()->toArray();
        $nowCouponInfo = self::where(['code' => $data['code']])->findOrEmpty()->toArray();

        if (empty($compensateCouponInfo)) {
            throw new CouponException(['msg' => '补发的优惠券为失效优惠券']);
        }

        if ($compensateCouponInfo['take_limit'] < max($allNumberList ?? [])) {
            throw new CouponException(['msg' => '补发的优惠券每个人限领的数量低于本次需要补偿的个人最高数量,本次需要补偿的个人最高数量为' . max($allNumberList ?? [])]);
        }

        if (($compensateCouponInfo['number'] - $compensateCouponInfo['take_count']) < array_sum($allNumberList ?? [])) {
            throw new CouponException(['msg' => '补发的优惠券剩余可领取数量不足本次全额补发!']);
        }

        if ($compensateCouponInfo['take_user_type'] != $nowCouponInfo['take_user_type']) {
            throw new CouponException(['msg' => '请选择跟原优惠券相同适用用户对象的券哈~']);
        }

        //系统重新派券
        if (!empty($userList) && !empty($couponCardList)) {
            $compensateRes = Db::transaction(function () use ($couponCardList, $userList, $data, $allUserCouponInfo, $compensateCouponCode) {
                $compensateRes = (new \app\lib\services\Coupon())->systemDeliverCoupon(['user' => $userList, 'coupon' => $couponCardList, 'adminInfo' => $data['adminInfo'] ?? [], 'type' => 2]);
                //新增原来这批被销毁的券的补发券
                if (!empty($compensateRes) && !empty($compensateRes['success'])) {
                    $successList = $compensateRes['success'][$compensateCouponCode] ?? [];
                    if (!empty($successList)) {
                        foreach ($successList as $key => $value) {
                            $successUserList[] = $value['userInfo']['uid'];
                            foreach ($value['res'] as $rK => $rV) {
                                if (!isset($successUserCount[$value['userInfo']['uid']])) {
                                    $successUserCount[$value['userInfo']['uid']] = 0;
                                }
                                if (is_numeric($rV)) {
                                    $successUserCount[$value['userInfo']['uid']] += 1;
                                }
                            }
                        }

                        if (!empty($successUserList)) {
                            $successUserList = array_unique($successUserList);
                        }
                        foreach ($successUserCount as $key => $value) {
                            if (!empty($allUserCouponInfo[$key]) && !empty(explode(',', $allUserCouponInfo[$key]['uc_code']))) {
                                foreach (explode(',', $allUserCouponInfo[$key]['uc_code']) as $uK => $uV) {
                                    if ($uK < $value) {
                                        $changeUcCode[] = $uV;
                                    }
                                }
                            }
                        }

                        //修改补发记录
                        if (!empty($changeUcCode)) {
                            $cMap = ['valid_status' => 1, 'status' => 1, 'coupon_code' => $data['code'], 'uc_code' => $changeUcCode];
                            //如果是已经被删除然后重补的优惠券领取明细则不需要判断状态
                            if (!empty($data['isDelete'] ?? null)) {
                                $cMap['status'] = -1;
                                $cMap['valid_status'] = -2;
                            }
                            UserCoupon::update(['is_compensate' => 1, 'compensate_code' => $compensateCouponCode], $cMap);
                            $compensateRes['changeUcCode'] = $changeUcCode;
                        }
                    }
                }
                return $compensateRes;
            });

        }

        return $compensateRes ?? [];

    }

    public function used()
    {
        return $this->hasOne('CouponUsed', 'u_type', 'used')->bind(['u_name', 'u_type', 'u_icon']);
    }

    public function type()
    {
        return $this->hasOne('CouponType', 't_type', 'type')->bind(['t_name', 't_type', 't_icon']);
    }

    public function user()
    {
        return $this->hasOne('CouponUserType', 'u_type', 'take_user_type')->bind(['us_name' => 'u_name', 'us_type' => 'u_type', 'u_group_type']);
    }

    public function getStartTimeAttr($value)
    {
        return !empty($value) ? date('Y-m-d H:i:s', $value) : null;
    }

    public function getEndTimeAttr($value)
    {
        return !empty($value) ? date('Y-m-d H:i:s', $value) : null;
    }

    public function getValidStartTimeAttr($value)
    {
        return !empty($value) ? date('Y-m-d H:i:s', $value) : null;
    }

    public function getValidEndTimeAttr($value)
    {
        return !empty($value) ? date('Y-m-d H:i:s', $value) : null;
    }

    private function getListFieldByModule()
    {
        switch ($this->module) {
            case 'admin':
            case 'manager':
                $field = 'a.code,a.name,a.icon,a.used,a.type,a.number,a.with_condition,a.with_category,a.with_goods_sn,a.with_amount,a.with_discount,a.used_amount,a.start_time,a.end_time,a.take_user_type,a.take_count,a.used_count,a.create_time,a.valid_days,a.valid_status,a.status,b.u_name,b.u_type,b.u_icon,c.t_name,c.t_type,c.t_icon,d.u_name as us_name,d.u_type as us_type,d.u_group_type,a.take_limit';
                break;
            case 'api':
                $field = 'a.code,a.name,a.icon,a.used,a.type,a.with_condition,a.with_category,a.with_goods_sn,a.with_amount,a.with_discount,a.used_amount,a.start_time,a.end_time,a.number,a.take_count,a.take_user_type,a.valid_status,a.valid_days,a.create_time,b.u_name,b.u_type,b.u_icon,c.t_name,c.t_type,c.t_icon,d.u_name as us_name,d.u_type as us_type,d.u_group_type,a.take_limit';
                break;
            default:
                $field = 'a.*';
        }

        return $field;
    }

    private function getInfoFieldByModule()
    {
        switch ($this->module) {
            case 'admin':
                $field = '*';
                break;
            case 'api':
                $field = 'code,name,icon,used,type,with_condition,with_category,with_goods_sn,with_amount,with_discount,used_amount,start_time,end_time,take_user_type,valid_status,take_limit,show_status';
                break;
            default:
                $field = '*';
        }

        return $field;
    }


}