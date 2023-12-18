<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 拼拼有礼活动模块Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\PtException;
use app\lib\services\CodeBuilder;
use app\lib\validates\Ppyl;
use app\lib\validates\Pt;
use Exception;
use think\facade\Cache;
use think\facade\Db;

class PpylActivity extends BaseModel
{
    protected $validateFields = ['activity_code', 'activity_title', 'status' => 'number'];

    /**
     * @title  拼团活动列表
     * @param array $sear
     * @return array
     * @throws Exception
     */
    public function list(array $sear = []): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('activity_title', $sear['keyword']))];
        }
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->with(['startUserType', 'joinUserType'])->where($map)->field('activity_code,activity_title,activity_cover,activity_desc,show_position,type,start_user_type,join_user_type,start_time,end_time,expire_time,group_number,sort,share_title,share_desc,share_cover,status,create_time')->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('create_time desc')->select()->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  C端拼团列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function cList(array $sear)
    {
        $cacheKey = $sear['cache'] ?? false;
        $cacheExpire = 600;
        $cacheTag = 'ApiHomePpylList';

        $map[] = ['status', '=', 1];
        $page = intval($sear['page'] ?? 0) ?: null;

        if (!empty($sear['pageNumber'])) {
            $this->pageNumber = intval($sear['pageNumber']);
        }
        if (!empty($sear['style_type'] ?? null)) {
            $map[] = ['style_type', '=', $sear['style_type']];
        }
        $map[] = ['show_position', '=', 1];
        $map[] = ['end_time', '>', time()];
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->with(['goods' => function ($query) {
            $goodsMap[] = ['end_time', '>', time()];
            $goodsMap[] = ['status', '=', 1];
            $query->where($goodsMap)->field('activity_code,goods_sn,title,sub_title,image,activity_price,sale_price,sort')->order('sort asc,create_time desc')->withLimit(3);
        }, 'startUserType', 'joinUserType'])->where($map)->field('activity_code,activity_title,activity_cover,activity_desc,type,icon,start_user_type,join_user_type,start_time,end_time,expire_time,group_number,share_title,share_desc,share_cover,style_type,create_time')->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('sort asc,create_time desc')
            ->when($cacheKey, function ($query) use ($cacheKey, $cacheExpire) {
                $query->cache($cacheKey, $cacheExpire)->withCache(['goods', 60], $cacheExpire);
            })->select()->each(function ($item) {
                if (!empty($item['goods'])) {
                    //图片展示缩放,OSS自带功能
                    foreach ($item['goods'] as $key => $value) {
//                        $item['goods'][$key]['image'] .= '?x-oss-process=image/resize,h_400,m_lfit';
                        $item['goods'][$key]['image'] .= '?x-oss-process=image/format,webp';
                    }
                }
                return $item;
            })->toArray();

        if (!empty($list)) {
            //过滤没有商品数组的拼团活动
            foreach ($list as $key => $value) {
                if (empty($value['goods'])) {
                    unset($list[$key]);
                    continue;
                }
                foreach ($value['goods'] as $gKey => $gValue) {
                    $list[$key]['goods'][$gKey]['stock'] = 0;
                    $list[$key]['goods'][$gKey]['market_price'] = $gValue['sale_price'];
                    $allPtSpu[] = $gValue['goods_sn'];
                    //列表风格仅展示两个,舍掉最后一个商品
                    if ($value['style_type'] == 2 && $gKey == count($value['goods'])- 1) {
                        unset($list[$key]['goods'][$gKey]);
                    }
                }
            }
            if (!empty($allPtSpu)) {
                $allPtGoodsSn = array_unique(array_filter($allPtSpu));
                $allPt = array_unique(array_filter(array_column($list, 'activity_code')));
                if (!empty($allPtGoodsSn) && !empty($allPt)) {
                    $sku = PpylGoodsSku::where(['goods_sn' => $allPtGoodsSn, 'activity_code' => $allPt, 'status' => [1]])->select()->toArray();
                    if (!empty($sku)) {
                        foreach ($sku as $key => $value) {
                            if (!isset($allPtActivitySpu[$value['goods_sn']])) {
                                $allPtActivitySpu[$value['goods_sn']] = 0;
                            }
                            $allPtActivitySpu[$value['goods_sn']] += $value['stock'] ?? 0;
                        }
                    }
                }
                if (!empty($allPtActivitySpu)) {
                    foreach ($list as $key => $value) {
                        foreach ($value['goods'] as $gKey => $gValue) {
                            $list[$key]['goods'][$gKey]['stock'] = 0;
                            if (!empty($allPtActivitySpu[$gValue['goods_sn']])) {
                                $list[$key]['goods'][$gKey]['stock'] = $allPtActivitySpu[$gValue['goods_sn']] ?? 0;
                            }
                        }
                    }
                }
                //补回普通商品的信息
                $goodsInfo = GoodsSpu::where(['goods_sn' => $allPtGoodsSn, 'status' => [1]])->field('goods_sn,title,main_image,sub_title')->withMin(['sku' => 'market_price'], 'market_price')->select()->toArray();
                if (!empty($goodsInfo)) {
                    foreach ($goodsInfo as $key => $value) {
                        $skuInfo[$value['goods_sn']] = $value;
                    }
                    if (!empty($skuInfo)) {
                        foreach ($list as $key => $value) {
                            foreach ($value['goods'] as $gKey => $gValue) {
                                if (!empty($skuInfo[$gValue['goods_sn']]) && !empty($skuInfo[$gValue['goods_sn']]['market_price'])) {
                                    $list[$key]['goods'][$gKey]['market_price'] = $skuInfo[$gValue['goods_sn']]['market_price'];
                                }
                            }
                        }
                    }
                }
            }
        }
        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  拼团活动详情
     * @param array $data
     * @return array
     */
    public function info(array $data)
    {
        $code = $data['activity_code'];
        $map[] = ['activity_code', '=', $code];
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        $info = $this->with(['startUserType', 'joinUserType'])->where($map)->withoutField('id,update_time')->findOrEmpty()->toArray();
        if (!empty($info)) {
            $existGoods = PtGoods::where(['activity_code' => $code, 'status' => [1, 2]])->count();
            $info['existGoods'] = !empty($existGoods) ? true : false;
            $info['existOrder'] = $this->checkExistOrder(['activity_code' => $code, 'pay_status' => [1]]);
        }
        return $info;
    }

    /**
     * @title  新增拼团活动
     * @param array $data
     * @return mixed
     */
    public function DBNew(array $data)
    {
        (new Ppyl())->goCheck($data, 'create');
        $data['activity_code'] = (new CodeBuilder())->buildActivityCode();
        //$res = $this->validate()->baseCreate($data);
        $data['start_time'] = strtotime($data['start_time']);
        $data['end_time'] = strtotime($data['end_time']);
        $res = $this->insert($data);
        if ($res) {
            //清除首页活动列表标签的缓存
            cache('ApiHomeAllList', null);
            cache('ApiHomePpylList', null);
        }
        return $res;
    }

    /**
     * @title  编辑拼团活动
     * @param array $data
     * @return mixed
     */
    public function DBEdit(array $data)
    {

        (new Ppyl())->goCheck($data, 'edit');
//        $res = $this->validate()->baseUpdate(['activity_code'=>$data['activity_code'],'status'=>[1,2]],$data);
        //判断该活动是否存在有效订单,有效订单则不允许修改活动成团人数
        if ($this->checkExistOrder(['activity_code' => $data['activity_code']])) {
            unset($data['group_number']);
        }
        $data['start_time'] = strtotime($data['start_time']);
        $data['end_time'] = strtotime($data['end_time']);
        $res = $this->where(['activity_code' => $data['activity_code'], 'status' => [1, 2]])->save($data);
        if ($res) {
            //清除首页活动列表标签的缓存
            cache('ApiHomeAllList', null);
            cache('ApiHomePpylList', null);
        }
        return true;
    }

    /**
     * @title  删除
     * @param array $data
     * @return mixed
     */
    public function DBDelete(array $data)
    {
        (new Ppyl())->goCheck($data, 'delete');
        //判断该活动是否存在有效订单,有效订单则不允许删除
        if ($this->checkExistOrder(['activity_code' => $data['activity_code'], 'activity_status' => [1]])) {
            throw new PtException(['msg' => '该拼团活动存在尚未拼团成功的订单,不允许删除!']);
        }
        $res = $this->cache('HomeApiPtList')->where(['activity_code' => $data['activity_code'], 'status' => [1, 2]])->save(['status' => -1]);
        if ($res) {
            cache('ApiHomeAllList', null);
            //清除首页活动列表标签的缓存
            Cache::tag(['HomeApiActivityList', 'ApiHomeAllList'])->clear();
        }
        return $res;
    }

    /**
     * @title  上下架拼团活动
     * @param array $data
     * @return mixed
     */
    public function upOrDown(array $data)
    {
        if ($data['status'] == 1) {
            if ($this->checkExistOrder(['activity_code' => $data['activity_code'], 'activity_status' => [1]])) {
                throw new PtException(['msg' => '该拼团活动存在尚未拼团成功的订单,不允许下架!']);
            }
            $save['status'] = 2;
        } elseif ($data['status'] == 2) {
            $save['status'] = 1;
        } else {
            return false;
        }
        $res = $this->cache('HomeApiPtList')->where(['activity_code' => $data['activity_code']])->save($save);
        if ($res) {
            cache('ApiHomeAllList', null);
            //清除首页活动列表标签的缓存
            Cache::tag(['HomeApiActivityList', 'ApiHomeAllList'])->clear();
        }
        return $res;
    }

    /**
     * @title  检查拼团活动是否存在有效订单
     * @param array $data
     * @return bool
     */
    public function checkExistOrder(array $data)
    {
        $activityCode = $data['activity_code'];
        $orderStatus = $data['pay_status'] ?? [1, 2];
        $activityStatus = $data['activity_status'] ?? [1, 2];
        if (empty($activityCode)) {
            return true;
        }
        $ptOrder = PpylOrder::where(['pay_status' => $orderStatus, 'activity_code' => $activityCode, 'activity_status' => $activityStatus])->count();
        if (!empty($ptOrder)) {
            return true;
        }

        return false;
    }


    public function setStartTimeAttr($value)
    {
        if (!empty($value)) $value = strtotime($value);
        return $value;
    }

    public function setEndTimeAttr($value)
    {
        if (!empty($value)) $value = strtotime($value);
        return $value;
    }

    public function getStartTimeAttr($value)
    {
        return !empty($value) ? date('Y-m-d H:i:s', $value) : $value;
    }

    public function getEndTimeAttr($value)
    {
        return !empty($value) ? date('Y-m-d H:i:s', $value) : $value;
    }

    public function ptOrder()
    {
        return $this->hasMany('PpylOrder', 'activity_code', 'activity_code')->where(['status' => [2]]);
    }

    public function goods()
    {
        return $this->hasMany('PpylGoods', 'activity_code', 'activity_code')->where(['status' => 1]);
    }

    public function startUserType()
    {
        return $this->hasOne('CouponUserType', 'u_type', 'start_user_type')->bind(['start_user_name' => 'u_name']);
    }

    public function joinUserType()
    {
        return $this->hasOne('CouponUserType', 'u_type', 'join_user_type')->bind(['join_user_name' => 'u_name']);
    }
}