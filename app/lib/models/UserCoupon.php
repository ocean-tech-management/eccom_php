<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 用户优惠券模块Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\CouponException;
use app\lib\services\CodeBuilder;
use think\facade\Db;

class UserCoupon extends BaseModel
{
    private $belong = 1;

    /**
     * @title  用户优惠券列表
     * @param array $sear
     * @return array
     * @remark $type为优惠券使用类型 1为有效期内 2为不可用 3为已使用 4为已过期
     * @throws \Exception
     */
    public function list(array $sear = []): array
    {
        $aUsed = [];
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('coupon_code', $sear['keyword']))];
        }
        if (!empty($sear['uc_code'])) {
            $map[] = ['uc_code', 'in', $sear['uc_code']];
        }
        $map[] = ['coupon_belong', '=', $sear['belong'] ?? $this->belong];
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        $map[] = ['uid', '=', $sear['uid']];
        $type = $sear['type'] ?? 1;

        switch ($type) {
            case 1:
                $map[] = ['valid_status', '=', 1];
                $map[] = ['valid_end_time', '>=', time()];
                $map[] = ['take_number', '>', 0];
                break;
            case 2:
                $map[] = ['valid_status', '=', 2];
                break;
            case 3:
                $map[] = ['valid_status', '=', 3];
                break;
            case 4:
                $map[] = ['valid_status', '=', -1];
                break;
            case 5:
                $map[] = ['valid_status', 'in', [2, 3, 4, -1]];
                break;
            default:
                $map[] = ['valid_status', '=', 1];
                $map[] = ['valid_end_time', '<=', time()];
                break;
        }

        $aUseds = (new CouponUsed())->list();
        foreach ($aUseds['list'] as $key => $value) {
            $aUsed[$value['u_type']] = $value;
        }

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }
        $category = new Category();
        $goodsSku = new GoodsSku();
        $list = $this->with(['coupon'])->where($map)->withoutField('id,take_number,used_number')->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('create_time desc')->select()->each(function ($item, $key) use ($aUsed, $sear, $category, $goodsSku) {
            $item['goods_name'] = [];
            $item['category_name'] = [];
            $item['couponUsedDetail'] = $aUsed[$item['coupon_used']] ?? [];
            //如果是类目优惠券则查询相关类目
            if ($item['coupon']['used'] == 20) {
                $item['coupon']['category_name'] = $category->where(['code' => explode(',', $item['coupon']['with_category']), 'status' => [1]])->column('name');
            }
            //如果是商品优惠券则查询相关前三个商品
            if ($item['coupon']['used'] == 30) {
                $item['coupon']['goods_name'] = $goodsSku->where(['sku_sn' => explode(',', $item['coupon']['with_goods_sn']), 'status' => [1]])->limit(3)->column('title');
            }
            return $item;
        })->toArray();
        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  用户领取优惠券
     * @param array $data
     * @return mixed
     */
    public function new(array $data)
    {
        $res = Db::transaction(function () use ($data) {
            $coupon = (new Coupon())->infoForInternal($data['code']);
            $userInfo = (new User())->getUserInfo($data['uid']);
//            $userCoupon = (new UserCoupon())->where(['coupon_code'=>$data['code']])->count();
//            if(!empty($userCoupon)){
//                throw new CouponException(['errorCode'=>1200108]);
//            }
            if (empty($userInfo)) {
                return false;
            }
            $add['uc_code'] = (new CodeBuilder())->buildUserCouponCode();
            $add['coupon_code'] = $coupon['code'];
            $add['uid'] = $userInfo['uid'];
            $add['phone'] = $userInfo['phone'];
            $add['coupon_used'] = $coupon['used'];
            $add['coupon_type'] = $coupon['type'];
            $add['coupon_belong'] = $coupon['belong_type'];
            $add['take_number'] = $data['take_number'] ?? 1;
            $add['used_amount'] = $coupon['used_amount'];
            $add['valid_type'] = $coupon['valid_type'];
            $add['receive_type'] = $data['receive_type'] ?? 1;
            if ($add['valid_type'] == 1) {
                $add['valid_start_time'] = strtotime($coupon['valid_start_time']);
                $add['valid_end_time'] = strtotime($coupon['valid_end_time']);
                $add['valid_days'] = intval(($add['valid_end_time'] - $add['valid_start_time']) / (3600 * 24));
            } else {
                $add['valid_days'] = $coupon['valid_days'];
                $add['valid_start_time'] = time();
                $add['valid_end_time'] = $add['valid_start_time'] + ($add['valid_days'] * (3600 * 24));
            }
            $userRes = $this->baseCreate($add, true);
            if ($userRes) {
                Db::name('coupon')->where(['code' => $data['code'], 'status' => 1])->inc('take_count', intval($add['take_number']))->update();
            }
            return $userRes;
        });

        return $res;
    }

    /**
     * @title  获取用户关于某张优惠券的领取数量
     * @param string $uid
     * @param string $code
     * @return int
     */
    public function couponNumberByCouponCode(string $uid, string $code)
    {
        $map[] = ['uid', '=', $uid];
        $map[] = ['coupon_code', '=', $code];
//        $map[] = ['valid_start_time','<=',time()];
        $map[] = ['valid_end_time', '>=', time()];
        $map[] = ['status', 'in', [1, 2]];
        //$map[] = ['valid_status','=',1];
        return $this->where($map)->order('create_time desc')->count();
    }

    /**
     * @title  仅供内部服务使用的用户优惠券列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function getUserListByInternal(array $sear)
    {
        $aUsed = [];
        $cacheKey = false;
        $cacheExpire = 0;
        if (!empty($sear['uc_code'])) {
            $map[] = ['uc_code', 'in', $sear['uc_code']];
        }
        $map[] = ['coupon_belong', '=', $sear['belong'] ?? $this->belong];
        $map[] = ['status', 'in', $this->getStatusByRequestModule(2)];
        $map[] = ['uid', '=', $sear['uid']];
        $type = $sear['type'] ?? 1;

        switch ($type) {
            case 1:
                $map[] = ['valid_status', '=', 1];
                $map[] = ['valid_start_time', '<=', time()];
                $map[] = ['valid_end_time', '>', time()];
                $map[] = ['take_number', '>', 0];
                break;
            case 2:
                $map[] = ['valid_status', '=', 2];
                break;
            case 3:
                $map[] = ['valid_status', '=', 3];
                break;
            case 4:
                $map[] = ['valid_status', '=', -1];
                break;
            default:
                $map[] = ['valid_status', '=', 1];
                $map[] = ['valid_end_time', '<=', time()];
                break;
        }

        $aUseds = (new CouponUsed())->list();
        foreach ($aUseds['list'] as $key => $value) {
            $aUsed[$value['u_type']] = $value;
        }
        if ($this->module == 'api') {
            if (!empty($sear['cache'])) {
                $cacheKey = $sear['cache'];
                $cacheExpire = $sear['cache_expire'];
            }
        }

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)
                ->when($cacheKey, function ($query) use ($cacheKey, $cacheExpire) {
                    $query->cache($cacheKey . 'Num', $cacheExpire);
                })->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }
        $list = [];
        if (!empty($cacheKey)) {
            $cacheList = cache($cacheKey);
            if (!empty($cacheList)) {
                $list = $cacheList;
            }
        }

        if (empty($list)) {
            $list = $this->with(['coupon'])->field('uid,phone,uc_code,coupon_code,coupon_used,coupon_type,coupon_belong,take_number,used_amount,valid_type,valid_start_time,valid_end_time,valid_days,valid_status')->where($map)->withoutField('id')->when($page, function ($query) use ($page) {
                $query->page($page, $this->pageNumber);
            })->order('create_time desc')->select()->toArray();
            if (!empty($list)) {
                if (!empty($cacheKey)) {
                    cache($cacheKey, $list, $cacheExpire);
                }
            }
        }


        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  后台使用的用户已领取的优惠券列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function adminUserCouponList(array $sear)
    {
        $map = [];
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('phone', $sear['keyword']))];
        }
        if (!empty($sear['valid_status'])) {
            $map[] = ['valid_status', '=', $sear['valid_status']];
        }
        if (!empty($sear['coupon_code'])) {
            $map[] = ['coupon_code', '=', $sear['coupon_code']];
        }
        if (!empty($sear['is_compensate'])) {
            $map[] = ['is_compensate', '=', $sear['is_compensate']];
        }
        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($sear['pageNumber'])) {
            $this->pageNumber = intval($sear['pageNumber']);
        }
        if (!empty($page)) {
            $aTotal = self::where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }
        $couponType = CouponType::column('t_name', 't_type');
        $couponUsed = CouponUsed::column('u_name', 'u_type');

        $list = self::with(['userInfo'])->withoutField('id')->where($map)->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('create_time desc')->select()->each(function ($item) use ($couponType, $couponUsed) {
            if (!empty($item['use_time'])) {
                $item['use_time'] = timeToDateFormat($item['use_time']);
            }
            if (!empty($item['delete_time'])) {
                $item['delete_time'] = timeToDateFormat($item['delete_time']);
            }
            $item['coupon_type_name'] = $couponType[$item['coupon_type']] ?? '未知优惠券类型';
            $item['coupon_used_name'] = $couponUsed[$item['coupon_used']] ?? '未知优惠券使用场景';
        })->toArray();
        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0, 'total' => $aTotal ?? 0];
    }

    /**
     * @title  销毁某一个或某一批人的优惠券
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    public function destroyUserCoupon(array $data)
    {
        $DBRes = Db::transaction(function () use ($data) {
            $ucCode = $data['uc_code'];
            $codeInfo = self::where(['uc_code' => $ucCode, 'valid_status' => 1, 'status' => 1])->lock(true)->select()->toArray();
            if (empty($codeInfo)) {
                throw new CouponException(['msg' => '券状态必须为已领取未使用! 查无可销毁的券']);
            }
            foreach ($codeInfo as $key => $value) {
                $code[$value['coupon_code']] = $value;
            }
            if (count($code) > 1) {
                throw new CouponException(['msg' => '每次操作仅允许针对同一张优惠券的领券记录!']);
            }
            $data['code'] = current(array_keys($code));

            $notExistCoupon = [];
            $allExistCoupon = array_column($codeInfo, 'uc_code');
            foreach ($ucCode as $key => $value) {
                if (!in_array($value, $allExistCoupon)) {
                    $notExistCoupon[] = $value;
                }
            }
            if (!empty($notExistCoupon)) {
                throw new CouponException(['msg' => '券状态必须为已领取未使用! 以下用户券码为失效记录,请剔除! ' . implode(',', $notExistCoupon)]);
            }
            $notUpdate = false;
            $map = ['valid_status' => 1, 'status' => 1, 'coupon_code' => $data['code']];
            $map['uc_code'] = $data['uc_code'];
            //补发优惠券
            if (!empty($data['compensateCoupon'] ?? null)) {
                $compensateRes = (new Coupon())->compensateCoupon($data);
                if (!empty($compensateRes['changeUcCode'])) {
                    $map['uc_code'] = $compensateRes['changeUcCode'];
                } else {
                    //补偿失败,所以也不销毁券
//                    $notUpdate = true;
                    throw new CouponException(['msg' => '补偿失败,可能存在用户身份不匹配、优惠券领取限制、补偿券数量不足等情况导致,固销毁失败!']);
                }
            }

//            if(empty($notUpdate)){
            $res = UserCoupon::update(['status' => -1, 'valid_status' => -2, 'delete_time' => time()], $map);
//            }


            if (!empty($data['compensateCoupon'] ?? null)) {
                return $compensateRes ?? [];
            } else {
                return $res->getData();
            }
        });
        return $DBRes;
    }


    public function coupon()
    {
        return $this->hasOne('Coupon', 'code', 'coupon_code')->field('code,name,icon,used,type,with_special,with_category,with_goods_sn,with_amount,with_discount,used_amount,take_user_type,with_condition');
    }

    public function getValidStartTimeAttr($value)
    {
        return !empty($value) ? date('Y-m-d H:i:s', $value) : null;
    }

    public function getValidEndTimeAttr($value)
    {
        return !empty($value) ? date('Y-m-d H:i:s', $value) : null;
    }

    public function userInfo()
    {
        return $this->hasOne('User', 'uid', 'uid')->bind(['user_name' => 'name', 'user_avatarUrl' => 'avatarUrl', 'vip_level']);
    }

}