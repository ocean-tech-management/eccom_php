<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 积分明细模块Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\ServiceException;
use app\lib\services\CodeBuilder;
use think\facade\Db;

class IntegralDetail extends BaseModel
{
//    protected $field = ['order_sn', 'uid', 'integral', 'status', 'type', 'change_type', 'crowd_code', 'crowd_round_number', 'crowd_period_number', 'remark'];

    /**
     * @title 积分(美丽豆)列表
     * @param array $sear
     * @return array
     */
    public function list(array $sear): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('d.phone|d.name|a.order_sn|a.integral', $sear['keyword']))];
        }
        $map[] = ['a.status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        if (!empty($sear['change_type'])) {
            if (is_array($sear['change_type'])) {
                $map[] = ['a.change_type', 'in', $sear['change_type']];
            } else {
                $map[] = ['a.change_type', '=', $sear['change_type']];
            }
        }

        if (!empty($sear['type'])) {
            $map[] = ['a.type', '=', $sear['type']];
        }

        if (!empty($sear['uid'])) {
            $map[] = ['a.uid', '=', $sear['uid']];
        }


        if ($this->module == 'api') {
            $map[] = ['a.uid', '=', $sear['uid']];
        }

        if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
            $map[] = ['a.create_time', 'between', [strtotime($sear['start_time']), strtotime($sear['end_time'])]];
        }

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($sear['pageNumber'] ?? null)) {
            $this->pageNumber = intval($sear['pageNumber'] ?? 0);
        }
        if (!empty($page)) {
            $aTotal = $this->alias('a')
                ->join('sp_user d','a.uid = d.uid','left')->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }
        if ($this->module == 'api') {
            $list = $this->alias('a')
                ->join('sp_user d', 'a.uid = d.uid', 'left')
                ->field('a.*,a.integral as price,d.name as user_name,d.phone as user_phone,d.avatarUrl')->where($map)->when($page, function ($query) use ($page) {
                    $query->page($page, $this->pageNumber);
                })->order('a.create_time desc,a.id asc')->select()->toArray();
        } else {
            $list = $this->alias('a')
                ->join('sp_user d', 'a.uid = d.uid', 'left')
                ->field('a.id,a.uid,a.order_sn,a.type,a.integral as price,a.change_type,a.status,a.create_time,a.remark,d.name as user_name,d.phone as user_phone,d.avatarUrl,a.crowd_code,a.crowd_round_number,a.crowd_period_number')->where($map)->when($page, function ($query) use ($page) {
                    $query->page($page, $this->pageNumber);
                })->order('a.create_time desc,a.id asc')->select()->toArray();
        }


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

    /**
     * @title  获取用户的总美丽券(一般用于校验用户可用美丽券)
     * @param string $uid
     * @param array $type 类型
     * @return float
     */
    public function getAllBalanceByUid(string $uid, array $type = [])
    {
        $map[] = ['uid', '=', $uid];
        $map[] = ['status', '=', 1];
        if (!empty($type)) {
            $map[] = ['change_type', 'in', $type];
        }
        $price = $this->where($map)->sum('integral');
        return priceFormat($price ?? 0);
    }

    /**
     * @title  积分明细列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function listOld(array $sear): array
    {
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        if ($this->module == 'api') {
            $map[] = ['uid', '=', $sear['uid']];
        }
        if (!empty($sear['change_type'])) {
            if (is_array($sear['change_type'])) {
                $map[] = ['a.change_type', 'in', $sear['change_type']];
            } else {
                $map[] = ['a.change_type', '=', $sear['change_type']];
            }
        }
        if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
            $map[] = ['a.create_time', 'between', [strtotime($sear['start_time']), strtotime($sear['end_time'])]];
        }

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->with(['divide'])->where($map)->withoutField('id,update_time,status')->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('create_time desc')->select()->toArray();

        if (!empty($list)) {
            $allOrderUid = array_column($list, 'order_uid');
            $allOrderUser = User::where(['uid' => $allOrderUid])->column('name', 'uid');
            foreach ($list as $key => $value) {
                foreach ($allOrderUser as $k => $v) {
                    if ($k == $value['order_uid']) {
                        $list[$key]['order_user_name'] = $v;
                    }
                }
            }
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

        if ($this->module == 'api') {
            $info['total_integral'] = User::where(['uid' => $sear['uid'], 'status' => 1])->value('integral');
        }

        if (!empty($info)) {
            $msg = '成功';
            if (empty($list)) {
                $msg = '暂无数据';
            }
            return ['error_code' => 0, 'msg' => $msg, 'data' => ['integral' => $info, 'pageTotal' => $pageTotal ?? 0, 'list' => $list]];
        }

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  新增积分明细
     * @param array $data
     * @return mixed
     */
    public function new(array $data)
    {
        $res = Db::transaction(function () use ($data) {
            $detailRes = $this->baseCreate($data);
            if ($detailRes) {
                $userRes = User::where(['uid' => $data['uid']])->inc('integral', doubleval($data['integral']))->update();
            }
            return $detailRes;
        });

        return $res;
    }

    /**
     * @title 批量新增积分(后台充值)
     * @param array $data
     * @return mixed
     */
    public function newBatch(array $data)
    {
        $allUser = $data['all_user'];
        $type = $data['type'] ?? 1;
        $userPhone = array_unique(array_column($allUser, 'user_phone'));
        $userList = User::where(['phone' => $userPhone, 'status' => 1])->column('uid', 'phone');
        if (empty($userList)) {
            throw new ServiceException(['msg' => '查无有效用户']);
        }

        foreach ($userPhone as $key => $value) {
            if (!in_array(trim($value), array_keys($userList))) {
                throw new ServiceException(['msg' => '手机号码' . $value . '不存在平台, 请仔细检查!']);
            }
        }

        $DBRes = DB::transaction(function () use ($allUser, $userList, $type, $data) {
            $res = false;
            $number = 0;
            $balanceNew = [];
            $CodeBuilder = (new CodeBuilder());

            foreach ($allUser as $key => $value) {
                if (priceFormat($value['price']) > 1000000) {
                    throw new ServiceException(['msg' => '单次充值不能超过1000000']);
                }
                if (!empty($userList[$value['user_phone']] ?? null) && doubleval($value['price'] ?? 0) != 0) {
                    $remarkMsg = null;
                    $balanceNew[$number]['order_sn'] = $CodeBuilder->buildSystemRechargeIntegralSn();
                    $balanceNew[$number]['uid'] = $userList[$value['user_phone']];
                    $balanceNew[$number]['type'] = 1;
                    if (priceFormat($value['price']) < 0) {
                        $balanceNew[$number]['type'] = 2;
                    }
                    $balanceNew[$number]['integral'] = priceFormat($value['price']);
                    $balanceNew[$number]['change_type'] = 8;
                    $remarkMsg = '后台系统充值';
                    if ($balanceNew[$number]['type'] == 2) {
                        $remarkMsg = '后台系统扣除';
                    }
                    if (!empty($value['remark'] ?? null)) {
                        $remarkMsg .= trim($value['remark']);
                    }
                    $balanceNew[$number]['remark'] = $remarkMsg;
                    $number += 1;
                }
            }
            if (!empty($balanceNew)) {
                $batchSqlIntegralData['list'] = $balanceNew;
                $batchSqlIntegralData['db_name'] = 'sp_user';
                $batchSqlIntegralData['id_field'] = 'uid';
                $batchSqlIntegralData['operate_field'] = 'integral';
                $batchSqlIntegralData['value_field'] = 'integral';
                $batchSqlIntegralData['operate_type'] = 'inc';
                $batchSqlIntegralData['sear_type'] = 1;
                $batchSqlIntegralData['other_map'] = 'status = 1';
                (new CommonModel())->DBBatchIncOrDec($batchSqlIntegralData);

                //批量新增明细
                $batchSqlIntegralDetailData['list'] = $balanceNew;
                $batchSqlIntegralDetailData['db_name'] = 'sp_integral_detail';
                $batchSqlIntegralDetailData['notValidateValueField'] = ['uid'];
                $res = (new CommonModel())->DBSaveAll($batchSqlIntegralDetailData);
            }
            unset($allUser);
            unset($balanceNew);
            return $res ?? false;
        });
        return judge($DBRes);
    }

    /**
     * @title 批量自增/自减-原生sql
     * @param array $data
     * @return mixed
     */
    public function DBBatchIncOrDecBySql(array $data)
    {
        try {
            $DBRes = Db::transaction(function () use ($data) {
                $res = $this->batchIncOrDecBySql($data);
                return $res;
            });
        } catch (\app\lib\BaseException $baseE) {
            $msg = $baseE->msg;
            $code = $baseE->errorCode;
            $DBRes = false;
        } catch (\Exception $e) {
            $msg = $e->getMessage();
            $code = $e->getCode();
            $DBRes = false;
        }
        return $DBRes;
    }

    /**
     * @title 批量新增-原生sql
     * @param array $data
     * @return mixed
     */
    public function DBSaveAll(array $data)
    {
        try {
            $DBRes = Db::transaction(function () use ($data) {
                $data['notValidateValueField'] = ['uid'];
                $res = $this->batchCreateBySql($data);
                return $res;
            });
        } catch (\app\lib\BaseException $baseE) {
            $msg = $baseE->msg;
            $code = $baseE->errorCode;
            $DBRes = false;
        } catch (\Exception $e) {
            $msg = $e->getMessage();
            $code = $e->getCode();
            $DBRes = false;
        }
        return $DBRes;
    }

    public function divide()
    {
        return $this->hasOne('Divide', 'order_sn', 'order_sn')->where(['status' => [1]])->bind(['order_uid', 'total_price', 'divide_type' => 'type']);
    }

}