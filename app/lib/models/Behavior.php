<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 行为轨迹模块Model]
// +----------------------------------------------------------------------



namespace app\lib\models;


use app\BaseModel;
use think\facade\Db;

class Behavior extends BaseModel
{

    /**
     * @title  用户个人轨迹列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear = []): array
    {
        $cacheKey = false;
        $cacheExpire = 0;
        $finally = [];

        if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
            $map[] = ['enter_time', '>=', strtotime($sear['start_time']) . '000'];
            $map[] = ['enter_time', '<=', strtotime($sear['end_time']) . '000'];
        }

        if (!empty($sear['type'])) {
            if (is_array($sear['type'])) {
                $map[] = ['type', 'in', $sear['type']];
            } else {
                $map[] = ['type', '=', $sear['type']];
            }
        }

        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        if ($this->module == 'api') {
            if (!empty($sear['cache'])) {
                $cacheKey = $sear['cache'];
                $cacheExpire = $sear['cache_expire'];
            }
        }

        if ($this->module == 'api') {
            switch ($sear['userType'] ?? 1) {
                case 1:
                    $map[] = ['uid', '=', $sear['uid']];
                    break;
                case 2:
                    $map[] = ['openid', '=', $sear['openid']];
                    break;
                case 3:
                    $map[] = ['unionId', '=', $sear['unionId']];
                    break;
            }
        } else {
            if (!empty($sear['uid'])) {
                $map[] = ['uid', '=', $sear['uid']];
            }
        }

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->with(['actualUser' => function ($query) {
            $query->field('uid,name,phone,avatarUrl,vip_level');
        }, 'entranceUser' => function ($query) {
            $query->field('uid,name,phone,avatarUrl,vip_level');
        }])->where($map)->field('uid,entrance_type,type,enter_time,enter_number,goods_sn,goods_info,entrance_link_user,link_superior_user')->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('enter_time desc,enter_number desc')->select()->each(function ($item) {
            if (!empty($item['goods_info'])) {
                $item['goods_info'] = json_decode($item['goods_info'], true);
//                if(!empty($item['goods_info']['main_image'] ?? null)){
//
//                    $item['goods_info']['main_image'] .= '?x-oss-process=image/resize,h_1170,m_lfit';
//                }
            }
            if (!empty($item['enter_time'])) {
                $item['enter_time'] = timeToDateFormat(substr($item['enter_time'], 0, 10));
            }
            return $item;
        })->toArray();

        if (!empty($list)) {
            foreach ($list as $key => $value) {
                if (!empty($value['goods_info'] ?? null) && !empty($value['goods_info']['main_image'] ?? null)) {
//                    $list[$key]['goods_info']['main_image'] .= '?x-oss-process=image/format,webp';
                    $list[$key]['goods_info']['main_image'] .= '?x-oss-process=image/resize,h_1170,m_lfit';
                }
            }
        }

        $dataFormatList = returnData(['list' => $list, 'pageTotal' => $pageTotal ?? 0])->getData();

        //前端做数据格式化兼容
        if ($this->module == 'api') {
            switch ($sear['userType'] ?? 1) {
                case 1:
                    $userInfo = User::where(['uid' => $sear['uid']])->field('uid,name,phone,vip_level,openid,avatarUrl')->findOrEmpty()->toArray();
                    break;
                case 2:
                    $userMap[] = ['openid', '=', $sear['openid']];
                    $userInfo = WxUser::with(['uid'])->where($userMap)->field('openid,nickname as name,headimgurl as avatarUrl')->findOrEmpty()->toArray();
                    $userInfo['vip_level'] = 0;
                    break;
                case 3:
                    $userMap[] = ['unionId', '=', $sear['unionId']];
                    $userInfo = WxUser::where($userMap)->field('unionId,nickname as name,headimgurl as avatarUrl')->findOrEmpty()->toArray();
                    $userInfo['vip_level'] = 0;
                    break;
            }
            //如果有用户uid则判断是否允许观察者uid查看用户的订单
            if (!empty($userInfo)) {
                $userInfo['lookOrder'] = false;
                if (!empty($sear['observer_uid']) && !empty($userInfo['uid']) && ($sear['observer_uid'] != $userInfo['uid'])) {
                    $check['justCheck'] = true;
                    $check['order_uid'] = $userInfo['uid'];
                    $check['observer_uid'] = $sear['observer_uid'];
                    $check['checkType'] = 1;
                    $userInfo['lookOrder'] = (new TeamPerformance())->checkUserIsTop($check);
                }
            }

            $finally['info'] = $userInfo ?? [];
            $finally['list'] = [];
            if (!empty($dataFormatList) && !empty($dataFormatList['data'])) {
                $finally['list'] = $dataFormatList['data'] ?? [];
            }
        } else {
            $finally = $dataFormatList['data'] ?? [];
        }
        return $finally ?? [];
    }

    /**
     * @title  关联(下级)用户行为轨迹
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function linkList(array $sear = []): array
    {
        $cacheKey = false;
        $cacheExpire = 0;

        if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
            $map[] = ['enter_time', '>=', intval(strtotime($sear['start_time']) . '000')];
            $map[] = ['enter_time', '<=', intval(strtotime($sear['end_time']) . '000')];
        }

        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        //剔除自己的足迹
        $map[] = ['uid', '<>', trim($sear['uid'])];

        if ($this->module == 'api') {
            if (!empty($sear['cache'])) {
                $cacheKey = $sear['cache'];
                $cacheExpire = $sear['cache_expire'];
            }
        }
        //平台授权类型 1为有开放平台,能获取到unionId; 2为普通平台,获取不到unionId,只能获取到openid
        $platformType = config('system.platformType');

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $countGroupField = $platformType == 1 ? 'unionId' : 'openid';
            $aTotalSql = $this->where($map)->where(function ($query) use ($sear, $map) {
//                $whereOr = $map;
//                $whereAnd = $map;
                $whereAnd[] = ['link_superior_user', '=', trim($sear['uid'])];
                $whereOr[] = ['entrance_link_user', '=', trim($sear['uid'])];
                $query->where($whereAnd)->whereOr([$whereOr]);
            })->field('status')->group($countGroupField)->buildSql();
            $aTotal = Db::table($aTotalSql . ' a')->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $groupField = 'openid';
        $dataSql = self::where($map)->where(function ($query) use ($sear, $map) {
//            $whereOr = $map;
//            $whereAnd = $map;
            $whereAnd[] = ['link_superior_user', '=', trim($sear['uid'])];
            $whereOr[] = ['entrance_link_user', '=', trim($sear['uid'])];
            $query->where($whereAnd)->whereOr([$whereOr]);
        })->order('enter_time desc,create_time desc')->buildSql();

        $listSql = "(SELECT uid,link_superior_user,entrance_link_user,entrance_type,type,main_type,stay_time,activity_id,goods_sn,enter_number,leave_time,a.openid,create_time,a.status,a.enter_time,a.unionId FROM $dataSql a,( SELECT max( enter_time ) AS enter_time, $groupField FROM sp_behavior GROUP BY $groupField ) b WHERE a.$groupField = b.$groupField AND a.enter_time = b.enter_time)";

        $list = Db::table($listSql . ' a')
//            ->join('sp_user b','a.uid = b.uid COLLATE utf8mb4_unicode_ci','left')
//            ->join('sp_wx_user c','a.openid = c.openid COLLATE utf8mb4_unicode_ci','left')
            //,b.name as user_name,b.vip_level,b.phone as user_phone,b.avatarUrl as user_avatarUrl,c.nickname as wx_nickname,c.headimgurl as wx_avatarUrl
            ->field('a.*')->when($page, function ($query) use ($page) {
                $query->page($page, $this->pageNumber);
            })->order('a.enter_time desc')->select()->each(function ($item) use ($sear, $map) {
                $item['userInfo']['name'] = '游客';
                $item['userInfo']['avatarUrl'] = null;
                $item['userInfo']['vip_level'] = 0;

                if (!empty($item['enter_time'])) {
                    $item['enter_time'] = timeToDateFormat(substr($item['enter_time'], 0, 10));
                }

                $item['goodsArray'] = [];
                //查找这个用户对应的商品浏览足迹
                if (!empty($item['uid']) || !empty($item['openid'])) {
                    $gMap = [];
                    $whereAnd = [];
                    $whereOr = [];
                    $item['goodsArray'] = self::where(function ($query) use ($item) {
                        $gMap[] = ['', 'exp', Db::raw('goods_sn is not null')];
//                $gMap[] = ['type', '=', 2];
                        $gMap[] = ['status', '=', 1];
                        if (!empty($item['uid']) && !empty($item['openid'])) {
//                        $whereAnd = $gMap;
//                        $whereOr = $gMap;
//                        $whereAnd[] = ['uid', '=', trim($item['uid'])];
//                        $whereOr[] = ['openid', '=', trim($item['openid'])];
//                        $query->where($whereAnd)->whereOr([$whereOr]);
                            $whereAnd = $gMap;
                            $whereAnd[] = ['uid|openid', 'in', [trim($item['uid']), trim($item['openid'])]];
                            $query->where($whereAnd);


                        } elseif (!empty($item['uid']) && empty($item['openid'])) {
                            $gMap[] = ['uid', '=', trim($item['uid'])];
                            $query->where($gMap);
                        } elseif (empty($item['uid']) && !empty($item['openid'])) {
                            $gMap[] = ['openid', '=', trim($item['openid'])];
                            $query->where($gMap);
                        }
                    })->field('type,goods_sn,goods_info')->order('enter_time desc,create_time desc')->limit(3)->select()->each(function ($gItem) {
                        if (!empty($gItem['goods_info'])) {
                            $gItem['goods_info'] = json_decode($gItem['goods_info'], true);
                        }
                    })->toArray();

                }
                return $item;
            })->toArray();

        //补齐用户信息
        if (!empty($list)) {
            //修改图片格式,OSS自带
            foreach ($list as $key => $value) {
                if (!empty($value['goodsArray'] ?? null)) {
                    foreach ($value['goodsArray'] as $gKey => $gValue) {
                        if (!empty($gValue['goods_info'] ?? null) && !empty($gValue['goods_info']['main_image'] ?? null)) {
                            $list[$key]['goodsArray'][$gKey]['goods_info']['main_image'] .= '?x-oss-process=image/format,webp';
                        }
                    }
                }
            }
            $userUid = array_unique(array_column($list, 'uid'));
            $openidLit = array_unique((array_column($list, 'openid')));
            $userInfos = User::where(['uid' => $userUid])->field('uid,name as user_name,vip_level,phone as user_phone,avatarUrl as user_avatarUrl')->select()->toArray();
            if (!empty($userInfos)) {
                foreach ($userInfos as $key => $value) {
                    $userInfo[$value['uid']] = $value;
                }
            }
            $wxUserInfos = WxUser::where(['openid' => $openidLit])->field('openid,nickname as wx_nickname,headimgurl as wx_avatarUrl')->select()->toArray();
            if (!empty($wxUserInfos)) {
                foreach ($wxUserInfos as $key => $value) {
                    $wxUserInfo[$value['openid']] = $value;
                }
            }

            foreach ($list as $key => &$item) {
                if (!empty($item['uid']) && !empty($userInfo[$item['uid']])) {
                    $item['userInfo']['name'] = $userInfo[$item['uid']]['user_name'] ?? null;
                    $item['userInfo']['avatarUrl'] = $userInfo[$item['uid']]['user_avatarUrl'] ?? null;
                    $item['userInfo']['vip_level'] = $userInfo[$item['uid']]['vip_level'] ?? null;
                } elseif (empty($item['uid']) && !empty($item['openid']) && !empty($wxUserInfo[$item['oepnid']])) {
                    $item['userInfo']['name'] = $wxUserInfo[$item['oepnid']]['wx_nickname'] ?? null;
                    $item['userInfo']['avatarUrl'] = $wxUserInfo[$item['oepnid']]['wx_avatarUrl'] ?? null;
                    $item['userInfo']['vip_level'] = 0;
                }
            }

        }
        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  行为轨迹记录
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    public function newOrEdit(array $data)
    {
        $userInfo = [];
        $allUid = array_unique(array_filter(array_column($data, 'uid')));
        $allOpenid = array_unique(array_filter(array_column($data, 'openid')));
        if (!empty($allUid) || !empty($allOpenid)) {
            $userInfos = User::where(function ($query) use ($allUid, $allOpenid) {
                $whereOr = [];
                if (!empty($allUid)) {
                    $mapU[] = ['uid', 'in', $allUid];
                }
                if (!empty($allOpenid)) {
                    $mapO[] = ['openid', 'in', $allOpenid];
                }
                if (!empty($mapU)) {
                    $whereOr[] = $mapU;
                }
                if (!empty($mapO)) {
                    $whereOr[] = $mapO;
                }
                $query->whereOr($whereOr);

            })->where(['status' => [1, 2]])->field('uid,openid,vip_level,link_superior_user')->select()->toArray();

            if (!empty($userInfos)) {
                foreach ($userInfos as $key => $value) {
                    $userInfo[$value['uid']] = $value;
                    $userOpenidInfo[$value['openid']] = $value;
                }
                foreach ($data as $key => $value) {
                    if (!empty($value['openid']) && empty($value['uid']) && !empty($userOpenidInfo[$value['openid']])) {
                        $data[$key]['uid'] = $userOpenidInfo[$value['openid']]['uid'];
                    }
                }
            }
        }

        $DBRes = false;
        $DBRes = Db::transaction(function () use ($data, $userInfo) {

            $allGoodsSn = array_unique(array_filter(array_column($data, 'goods_sn')));
            if (!empty($allGoodsSn)) {
                $goodsInfos = GoodsSpu::where(['goods_sn' => $allGoodsSn])->field('goods_sn,title,main_image,sub_title')->withMin(['sku' => 'sale_price'], 'sale_price')->withMin(['sku' => 'market_price'], 'market_price')->select()->toArray();
                if (!empty($goodsInfos)) {
                    foreach ($goodsInfos as $key => $value) {
                        $goodsInfo[$value['goods_sn']] = $value;
                    }
                }
            }

            foreach ($data as $key => $value) {
                $saveMap = [];
                if (empty($value['uid']) && empty($value['openid'])) {
                    continue;
                }
                $save['uid'] = $value['uid'] ?? null;
                if (!empty($value['uid'])) {
                    $save['link_superior_user'] = !empty($userInfo[$value['uid']]) ? $userInfo[$value['uid']]['link_superior_user'] : null;
                } else {
                    $save['link_superior_user'] = null;
                }
                $save['openid'] = $value['openid'] ?? null;
                if (!empty($value['unionId'] ?? null) && is_array($value['unionId'])) {
                    $value['unionId'] = $value['unionId']['unionId'] ?? null;
                }
                $save['unionId'] = $value['unionId'] ?? null;
                $save['entrance_link_user'] = $value['entrance_link_user'] ?? null;
                $save['entrance_type'] = $value['entrance_type'];
                $save['type'] = $value['type'];
                $save['main_type'] = $value['main_type'];
                $save['path_name'] = $value['path_name'] ?? null;
                $save['stay_time'] = $value['stay_time'] ?? 0;
                $save['activity_id'] = $value['activity_id'] ?? null;
                $save['goods_sn'] = $value['goods_sn'] ?? null;
                if (!empty($save['goods_sn']) && !empty($goodsInfo[$save['goods_sn']])) {
                    $save['goods_info'] = json_encode($goodsInfo[$save['goods_sn']], 256);
                }
                //这里的进入是13位int类型的带毫秒时间戳,前端传
                $save['enter_time'] = $value['enter_time'] ?? (time() . '000');
                $save['leave_time'] = $value['leave_time'] ?? null;
                $save['enter_number'] = 1;

                //统计今日是否存在该行为轨迹,不存在则新增,存在则修改最后进入时间和累加进入次数
                $saveMap[] = ['type', '=', $value['type']];
                $saveMap[] = ['entrance_type', '=', $value['entrance_type']];
                if (!empty($value['entrance_link_user'])) {
                    $saveMap[] = ['entrance_link_user', '=', $value['entrance_link_user']];
                }
                if (!empty($value['goods_sn'])) {
                    $saveMap[] = ['goods_sn', '=', $value['goods_sn']];
                }
                if (!empty($save['uid'])) {
                    $saveMap[] = ['uid', '=', $save['uid']];
                } else {
                    $saveMap[] = ['unionId', '=', $save['unionId']];
                }
                $saveMap[] = ['enter_time', '>=', strtotime(date('Y-m-d', time()))];
                $saveMap[] = ['enter_time', '<=', strtotime(date('Y-m-d', time())) + 24 * 3600];

                $enterNumber = self::where($saveMap)->value('enter_number');
                if (empty(doubleval($enterNumber))) {
                    $res[] = self::create($save)->getData();
                } else {
                    $update['enter_time'] = $value['enter_time'] ?? (time() . '000');
                    $update['enter_number'] = $enterNumber + 1;
                    $res[] = self::update($update, $saveMap)->getData();
                }
            }
            return $res ?? [];
        });

        return judge($DBRes);
    }

    public function goods()
    {
        return $this->hasOne('GoodsSpu', 'goods_sn', 'goods_sn');
    }

    public function sku()
    {
        return $this->hasMany('GoodsSku', 'goods_sn', 'goods_sn')->where(['status' => [1, 2]]);
    }

    public function user()
    {
        return $this->hasOne('User', 'uid', 'uid');
    }

    public function actualUser()
    {
        return $this->hasOne('User', 'uid', 'link_superior_user');
    }

    public function entranceUser()
    {
        return $this->hasOne('User', 'uid', 'entrance_link_user');
    }

    public function wxUser()
    {
        return $this->hasOne('WxUser', 'openid', 'openid');
    }

}