<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 众筹熔断分期返回计划明细]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\models;


use app\BaseModel;
use think\facade\Db;

class CrowdfundingFuseRecord extends BaseModel
{

    /**
     * @title  冻结美丽金汇总列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('order_sn', trim($sear['keyword'])))];
        }
        if (!empty($sear['user_keyword'])) {
            $uMap[] = ['', 'exp', Db::raw($this->getFuzzySearSql('name|phone', trim($sear['user_keyword'])))];
            $uids = User::where($uMap)->column('uid');
            if (!empty($uids)) {
                $map[] = ['uid', 'in', $uids];
            }
        }

        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];

        if (!empty($sear['uid'])) {
            $map[] = ['uid', '=', $sear['uid']];
        }

        if ($this->module == 'api') {
            $map[] = ['uid', '=', $sear['uid']];
        }

        if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
            $map[] = ['create_time', 'between', [strtotime($sear['start_time']), strtotime($sear['end_time'])]];
        }

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($sear['pageNumber'] ?? null)) {
            $this->pageNumber = intval($sear['pageNumber']);
        }

        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }
        $list = $this->with(['userInfo'])->withoutField('id,update_time')->where($map)->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('create_time desc')->select()->toArray();

        if (!empty($list)) {
            $allCrowdActivity = array_unique(array_column($list, 'crowd_code'));
            if (!empty($allCrowdActivity)) {
                $allCrowdActivityName = CrowdfundingActivity::where(['activity_code' => $allCrowdActivity])->column('title', 'activity_code');
                if (!empty($allCrowdActivityName)) {
                    foreach ($list as $key => $value) {
                        if (!empty($value['crowd_code'] ?? null)) {
                            $list[$key]['activity_name'] = $allCrowdActivityName[$value['crowd_code']] ?? '未知福利区';
                        }
                    }
                }
            }
        }
        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    public function detail()
    {
        return $this->hasMany('CrowdfundingFuseRecordDetail', 'order_sn', 'order_sn')->where(['status' => [1]]);
    }

    public function userInfo()
    {
        return $this->hasOne('User', 'uid', 'uid')->bind(['user_name' => 'name','user_phone' => 'phone']);
    }
}
