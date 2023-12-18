<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 拼团订单模块Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\models;


use app\BaseModel;
use Exception;
use think\facade\Db;

class PtOrder extends BaseModel
{
    protected $validateFields = ['order_sn', 'activity_code', 'uid'];

    /**
     * @title  拼团订单列表
     * @param array $sear
     * @return array
     * @throws Exception
     */
    public function list(array $sear = []): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('a.activity_title|a.activity_sn', $sear['keyword']))];
        }
        $map[] = ['a.status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];

        if (!empty($sear['activity_status'])) {
            $map[] = ['a.activity_status', 'in', $sear['activity_status']];
        }
        if (!empty($sear['activity_code'])) {
            $map[] = ['a.activity_code', '=', $sear['activity_code']];
        }
        if (!empty($sear['pay_status'])) {
            $map[] = ['a.pay_status', '=', $sear['pay_status']];
        }
        if (!empty($sear['goods_sn'])) {
            $map[] = ['a.goods_sn', '=', $sear['goods_sn']];
        }

        if ($this->module == 'api') {
            if (!empty($sear['uid'])) {
                $map[] = ['a.uid', '=', $sear['uid']];
            }
            $map[] = ['a.end_time', '>', time()];
        }

        $map[] = ['a.user_role', '=', 1];
        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($sear['pageNumber'])) {
            $this->pageNumber = intval($sear['pageNumber']);
        }

        if (!empty($page)) {
            $aTotal = Db::name('pt_order')->alias('a')->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }
        $list = Db::name('pt_order')->alias('a')
            ->where($map)
            ->field('a.activity_sn,a.activity_code,a.activity_title,a.uid,a.activity_type,a.join_user_type,a.start_time,a.end_time,a.group_number,a.activity_status,a.create_time,a.goods_sn,a.sku_sn')
            ->when($page, function ($query) use ($page) {
                $query->page($page, $this->pageNumber);
            })->order('a.create_time asc')->select()->each(function ($item) {
                if (!empty($item['start_time'])) {
                    $item['start_time'] = timeToDateFormat($item['start_time']);
                }
                if (!empty($item['end_time'])) {
                    $item['end_time'] = timeToDateFormat($item['end_time']);
                }
                return $item;
            })->toArray();

        if (!empty($list)) {
            $activitySn = array_column($list, 'activity_sn');
            $joinPtList = $this->with(['userBind'])->where(['activity_sn' => $activitySn, 'pay_status' => 2, 'status' => 1])->field('activity_sn,uid,user_role')->order('user_role asc')->select()->toArray();
            if (!empty($joinPtList)) {
                foreach ($list as $key => $value) {
                    foreach ($joinPtList as $cKey => $cValue) {
                        if ($value['activity_sn'] == $cValue['activity_sn']) {
                            $list[$key]['user'][] = $cValue;
                        }
                    }
                }
            }
        }

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  用户拼团详情
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function ptInfo(array $data)
    {
        $activityCode = $data['activity_code'];
        $activitySn = $data['activity_sn'];
//        $ptOrder = $this->with(['activity', 'goods', 'user'])->where(['activity_code' => $activityCode, 'activity_sn' => $activitySn,'activity_status'=>[1,2,3,-2,-1],'pay_status'=>[1,2,-2,-1]])->withoutField('id,update_time')->order('user_role asc,create_time asc')->select()->toArray();
        $ptOrder = $this->with(['activity', 'goods', 'user'])->where(['activity_code' => $activityCode, 'activity_sn' => $activitySn, 'activity_status' => [1, 2, 3, -2], 'pay_status' => [1, 2, -2]])->withoutField('id,update_time')->order('user_role asc,create_time asc')->select()->toArray();
        return $ptOrder;
    }

    /**
     * @title  用户拼团列表
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function userPtList(array $data)
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('activity_title', $sear['keyword']))];
        }
        $map[] = ['uid', '=', $data['uid']];

        if (!empty($data['activity_status'])) {
            $map[] = ['activity_status', '=', $data['activity_status']];
        }

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($sear['pageNumber'])) {
            $this->pageNumber = intval($sear['pageNumber']);
        }
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->with(['activity', 'goods'])->where($map)->withoutField('id,update_time')
            ->withCount(['joinNumber'])
            ->when($page, function ($query) use ($page) {
                $query->page($page, $this->pageNumber);
            })->order('create_time desc')->select()->each(function ($item) {
                if (!empty($item['goods']) && !empty($item['goods']['image'])) {
                    $item['goods']['image'] .= '?x-oss-process=image/format,webp';
                }
                return $item;
            })->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }


    public function getStartTimeAttr($value)
    {
        return !empty($value) ? timeToDateFormat($value) : $value;
    }

    public function getEndTimeAttr($value)
    {
        return !empty($value) ? timeToDateFormat($value) : $value;
    }


    public function activity()
    {
        return $this->hasOne('PtActivity', 'activity_code', 'activity_code')->withoutField('id,create_time,update_time');
    }

    public function goods()
    {
        return $this->hasOne('GoodsSku', 'sku_sn', 'sku_sn')->field('goods_sn,sku_sn,title,image,sub_title,specs');
    }

    public function joinNumber()
    {
        return $this->hasMany(get_class($this), 'activity_sn', 'activity_sn')->where(['activity_status' => [1, 2, 3], 'status' => [1]]);
    }

    public function user()
    {
        return $this->hasOne('User', 'uid', 'uid')->field('uid,name,phone,avatarUrl');
    }

    public function userBind()
    {
        return $this->hasOne('User', 'uid', 'uid')->bind(['userName' => 'name', 'avatarUrl']);
    }

    public function orders()
    {
        return $this->hasOne('Order', 'order_sn', 'order_sn');
    }

    public function success()
    {
        return $this->hasMany(get_class($this), 'activity_code', 'activity_code')->where(['pay_status' => 2]);
    }

}