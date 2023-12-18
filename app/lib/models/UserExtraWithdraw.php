<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 用户额外提现额度表]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\ServiceException;
use app\lib\exceptions\UserException;
use think\facade\Db;

class UserExtraWithdraw extends BaseModel
{
    /**
     * @title  额外提现额度明细列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear): array
    {
//        if (!empty($sear['keyword'])) {
//            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('price', $sear['keyword']))];
//        }

        if (!empty($sear['valid_type'])) {
            $map[] = ['valid_type', '=', $sear['valid_type']];
        }

        if (!empty($sear['userKeyword'])) {
            $uMap[] = ['', 'exp', Db::raw($this->getFuzzySearSql('phone|name', $sear['userKeyword']))];
            $linkUid = User::where($uMap)->value('uid');
            if (!empty($linkUid)) {
                $map[] = ['uid', '=', $linkUid];
            }
        }

        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];

        if ($this->module == 'api') {
            $map[] = ['uid', '=', $sear['uid']];
            $map[] = ['price', '<>', 0];
        }

        if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
            $map[] = ['create_time', 'between', [strtotime($sear['start_time']), strtotime($sear['end_time'])]];
        }

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($sear['pageNumber'] ?? 0)) {
            $this->pageNumber = intval($sear['pageNumber']);
        }
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->with(['user'])->where($map)->withoutField('update_time,status')->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('create_time desc,id asc')->select()->each(function ($item) {
            if (!empty($item['valid_start_time'] ?? null)) {
                $item['valid_start_time'] = date('Y-m-d H:i:s', $item['valid_start_time']);
            }
            if (!empty($item['valid_end_time'] ?? null)) {
                $item['valid_end_time'] = date('Y-m-d H:i:s', $item['valid_end_time']);
            }
        })->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  详情
     * @param array $data
     * @return array
     */
    public function info(array $data)
    {
        $info = self::where(['id' => $data['id'], 'uid' => $data['uid']])->findOrEmpty()->toArray();
        if (!empty($info['valid_start_time'] ?? null)) {
            $info['valid_start_time'] = timeToDateFormat($info['valid_start_time']);
        }
        if (!empty($info['valid_end_time'] ?? null)) {
            $info['valid_end_time'] = timeToDateFormat($info['valid_end_time']);
        }
        return $info;
    }

    /**
     * @title  新增
     * @param array $data
     * @return bool
     * @throws \Exception
     */
    public function DBNew(array $data)
    {
        $userList = $data['userList'] ?? [];

        $userInfo = User::where(['phone' => array_unique(array_column($userList, 'phone')), 'status' => 1])->column('uid', 'phone');

        if (empty($userInfo)) {
            throw new UserException(['msg' => '暂无有效用户']);
        }

//        //增加的额度一人只能一条
//        $existUser = self::where(['uid' => array_values($userInfo), 'status' => 1, 'type' => 1])->column('uid');

//        foreach ($userList as $key => $value) {
//            if ($value['price'] > 0  && in_array($value['uid'], $existUser)) {
//                unset($userList[$key]);
//            }
//        }

        if (empty($userList)) {
            throw new UserException(['msg' => '无有效可添加用户']);
        }


        $number = 0;
        foreach ($userList as $key => $value) {
            if (!empty($value['phone'])) {
                $newData[$number]['uid'] = $userInfo[$value['phone']] ?? null;
                if (empty($newData[$number]['uid'])) {
                    throw new UserException(['msg' => $value['phone'] . '不存在此用户']);
                }
            } else {
                $newData[$number]['uid'] = $value['uid'];
            }

            $newData[$number]['price'] = $value['price'];
            if (doubleval($value['price']) < 0) {
                $newData[$number]['type'] = 2;
            } else {
                $newData[$number]['type'] = 1;
            }
            if($value['valid_type'] == 1){
                throw new ServiceException(['msg'=>'暂不支持设置永久有效期']);
            }
            $newData[$number]['valid_type'] = $value['valid_type'];
            if ($value['valid_type'] == 2) {
                if (empty($value['valid_start_time'] ?? null) || empty($value['valid_end_time'] ?? null)) {
                    throw new UserException(['msg' => '生效类型为有效时间时开始结束时间必填']);
                }
                if (explode(' ', $value['valid_start_time'])[1] != '00:00:00' || explode(' ', $value['valid_end_time'])[1] != '00:00:00') {
                    throw new UserException(['msg' => '生效类型为有效时间开始和结束必须为0点0分0秒']);
                }
                $newData[$number]['valid_start_time'] = strtotime($value['valid_start_time']);
                $newData[$number]['valid_end_time'] = strtotime($value['valid_end_time']);
                if ($newData[$number]['valid_end_time'] <= time()) {
                    throw new ServiceException(['msg' => '结束时间不允许小于当前时间']);
                }
                if ($newData[$number]['valid_end_time'] - $newData[$number]['valid_start_time'] <= 0) {
                    throw new ServiceException(['msg' => '选择时间范围有误']);
                }
            }
            $newData[$number]['remark'] = $value['remark'] ?? null;
            $cacheKey = 'userRechargeSummary-' . $newData[$number]['uid'] . 1;
            cache($cacheKey, null);
        }
        $res = false;
        if (!empty($newData)) {
            $res = $this->saveAll($newData);
        }
        return judge($res);
    }

    /**
     * @title  编辑
     * @param array $data
     * @return bool
     */
    public function DBEdit(array $data)
    {
        $info = self::where(['id' => $data['id'],'uid'=>$data['uid'], 'status' => 1])->findOrEmpty()->toArray();
//        if (date('Y-m-d', strtotime($info['create_time'])) == date('Y-m-d', time()) && $info['price'] > $data['price']) {
//            throw new ServiceException(['msg' => '今日创建的价格不允许调低']);
//        }
        if($data['valid_type'] == 1){
            throw new ServiceException(['msg'=>'暂不支持设置永久有效期']);
        }
        if ($data['valid_type'] == 2) {
            if (empty($data['valid_start_time'] ?? null) || empty($data['valid_end_time'] ?? null)) {
                throw new UserException(['msg' => '生效类型为有效时间时开始结束时间必填']);
            }
            if (explode(' ', $data['valid_start_time'])[1] != '00:00:00' || explode(' ', $data['valid_end_time'])[1] != '00:00:00') {
                throw new UserException(['msg' => '生效类型为有效时间开始和结束必须为0点0分0秒']);
            }
            $data['valid_start_time'] = strtotime($data['valid_start_time']);
            $data['valid_end_time'] = strtotime($data['valid_end_time']);
            if ($data['valid_end_time'] <= time()) {
                throw new ServiceException(['msg' => '结束时间不允许小于当前时间']);
            }
            if ($data['valid_end_time'] - $data['valid_start_time'] <= 0) {
                throw new ServiceException(['msg' => '选择时间范围有误']);
            }
        }
        if (doubleval($data['price']) < 0) {
            $data['type'] = 2;
        } else {
            $data['type'] = 1;
        }
        $res = self::where(['id' => $data['id'],'uid'=>$data['uid'], 'status' => 1])->update($data);
        $cacheKey = 'userRechargeSummary-' . $info['uid'] . 1;
        cache($cacheKey, null);
        return judge($res);
    }

    /**
     * @title  删除
     * @param array $data
     * @return bool
     */
    public function DBDel(array $data)
    {
        $info = self::where(['id' => $data['id'], 'status' => 1])->findOrEmpty()->toArray();
        if (empty($info)) {
            throw new ServiceException(['msg' => '查无此记录']);
        }
        if (date('Y-m-d', strtotime($info['create_time'])) == date('Y-m-d', time())) {
            throw new ServiceException(['msg' => '今日创建的额外额度不允许今日删除,请等今天过后删除, 若是设置错误可以将额度设置为1']);
        }
        $res = self::update(['status' => -1], ['id' => $data['id']]);
        $cacheKey = 'userRechargeSummary-' . $info['uid'] . 1;
        cache($cacheKey, null);
        return judge($res);
    }

    public function user()
    {
        return $this->hasOne('User', 'uid', 'uid')->bind(['user_name' => 'name', 'user_phone' => 'phone','user_vip_level'=>'vip_level','user_team_vip_level'=>'team_vip_level','user_area_vip_level'=>'team_area_vip_level']);
    }
}