<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 用户寄售数量表Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\PpylException;
use think\facade\Db;

class UserRepurchase extends BaseModel
{
    /**
     * @title  用户寄售次数列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear): array
    {
        $map[] = ['uid', '=',$sear['uid']];
        if(!empty($sear['activity_code'])){
            $map[] = ['activity_code', '=',$sear['activity_code']];
        }
        if(!empty($sear['area_code'])){
            $map[] = ['area_code', '=',$sear['area_code']];
        }
        $map[] = ['status','=',1];

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($sear['pageNumber'])) {
            $this->pageNumber = intval($sear['pageNumber']);
        }

        if (!empty($page)) {
            $aTotal = self::where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = self::where($map) ->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('create_time desc')->select()->toArray();

        if(!empty($list)){
            $activityCode = array_unique(array_column($list,'activity_code'));
            $areaCode = array_unique(array_column($list,'area_code'));
            $activityList = [];
            $areaList = [];
            if(!empty($activityCode)){
                $activityList = PpylActivity::where(['activity_code'=>$activityCode])->column('activity_title','activity_code');
            }
            if(!empty($areaCode)){
                $areaList = PpylArea::where(['area_code'=>$areaCode])->column('name','area_code');
            }

            if(!empty($activityList ?? [])){
                foreach ($list as $key => $value) {
                    if(!empty($activityList[$value['activity_code']] ?? null)){
                        $list[$key]['activity_title'] = $activityList[$value['activity_code']];
                    }
                }
            }

            if(!empty($areaList ?? [])){
                foreach ($list as $key => $value) {
                    if(!empty($areaList[$value['area_code']])){
                        $list[$key]['area_title'] = $areaList[$value['area_code']];
                    }
                }
            }
        }

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0,'total'=>$aTotal ?? 0];
    }

    /**
     * @title  更新用户寄售次数
     * @param array $data
     * @return bool|mixed
     */
    public function updateUserRepurchase(array $data)
    {
        if (!is_numeric($data['number'])) {
            throw new PpylException(['msg' => '请填写阿拉伯数字的次数']);
        }
        if(empty($data['number'] ?? null) || ($data['number'] ?? null) == 0){
            return true;
        }
        //如果有传订单号,则默认根据订单号查找对应的活动和专区和用户, 增加或减少寄售次数
        if (!empty($data['order_sn'] ?? null)) {
            $orderInfo = PpylOrder::where(['order_sn' => trim($data['order_sn'])])->findOrEmpty()->toArray();
            if (empty($orderInfo)) {
                throw new PpylException(['msg' => '订单不存在']);
            }
            $data['activity_code'] = $orderInfo['activity_code'];
            $data['area_code'] = $orderInfo['area_code'];
            $data['uid'] = $orderInfo['uid'];
        }

        $uid = $data['uid'] ?? null;
        if(empty($data['uid']) || empty($data['activity_code'] ?? null) || empty($data['area_code'] ?? null)){
            throw new PpylException(['msg'=>'请选择用户、拼拼活动、专场']);
        }

        $DBRes = Db::transaction(function () use ($data, $uid) {
            $map[] = ['uid', '=', $uid];
            $map[] = ['activity_code', '=', $data['activity_code']];
            $map[] = ['area_code', '=', $data['area_code']];
            $map[] = ['status', '=', 1];
            $exist = self::where($map)->findOrEmpty()->toArray();
            $res = false;
            if (!empty($exist)) {
                if ($data['number'] < 0) {
                    if (($exist['repurchase_capacity'] + $data['number']) <= 0) {
                        $res = self::update(['repurchase_capacity' => 0], $map);
                        return $res;
                    }
                }
                $res = self::where($map)->inc('repurchase_capacity', $data['number'])->update();
            } else {
                if ($data['number'] > 0) {
                    $new['uid'] = $uid;
                    $new['activity_code'] = $data['activity_code'];
                    $new['area_code'] = $data['area_code'];
                    $new['repurchase_capacity'] = $data['number'];
                    $res = self::create($new);
                } else {
                    throw new PpylException(['msg' => '不存在剩余可寄售记录,无法扣除!']);
                }
            }
            return $res;
        });

        return $DBRes;

    }
}