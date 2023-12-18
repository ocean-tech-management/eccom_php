<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 直推获得赠送的异常订单模块Model]
// +----------------------------------------------------------------------



namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\ServiceException;
use app\lib\services\Divide;
use app\lib\services\Log;
use think\facade\Db;

class HandselStandardAbnormal extends BaseModel
{
    /**
     * @title  用户奖励订单异常列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear = []): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('order_sn', $sear['keyword']))];
        }
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->with(['user'])->where($map)->withoutField('id,update_time')->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('create_time desc,id desc')->select()->each(function($item){
            if(!empty($item['operate_time'])){
                $item['operate_time'] = timeToDateFormat($item['operate_time']);
            }
        })->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  运营人员操作异常订单后增送指定数量的条件
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    public function operate(array $data)
    {
        $adminInfo = $data['adminInfo'] ?? [];
        $abnormalSn = $data['abnormal_sn'] ?? null;
        if (empty($abnormalSn)) {
            throw new ServiceException(['msg' => '请管理员选择有效的异常订单']);
        }
        $operateInfo = HandselStandardAbnormal::where(['abnormal_sn' => $abnormalSn, 'operate_status' => 2])->findOrEmpty()->toArray();
        if (empty($operateInfo)) {
            throw new ServiceException(['msg' => '查无待操作的异常订单']);
        }
        $operateNumber = $data['operate_number'] ?? 0;
        if (intval($operateNumber) < 0 || !is_numeric(intval($operateNumber))) {
            throw new ServiceException(['msg' => '请填写有效的条件数量']);
        }
        if (intval($operateNumber) < 0 || intval($operateNumber) > $operateInfo['order_goods_number']) {
            throw new ServiceException(['msg' => '数量必须大于等于0且小于订单商品总数']);
        }
        //如果填0则等于不发放条件
        if (intval($operateNumber) == 0) {
            $res = Db::transaction(function () use ($adminInfo, $abnormalSn) {
                $update['record_number'] = 0;
                $update['operate_status'] = 1;
                $update['admin_id'] = $adminInfo['id'] ?? null;
                $update['admin_name'] = $adminInfo['name'] ?? '未知管理员';
                $update['operate_time'] = time();
                $update['remark'] = $data['remark'] ?? null;
                $updateRes = self::update($update, ['abnormal_sn' => $abnormalSn, 'operate_status' => 2]);
                return ['updateRes' => $updateRes, 'handselRes' => $handselRes ?? []];
            });
        } else {

            //查找订单
            $aOrder = Db::name('order')->alias('a')
                ->join('sp_user b', 'a.uid = b.uid', 'left')
                ->join('sp_member c', 'a.uid = c.uid', 'left')
                ->field('a.*,b.name as user_name,b.vip_level,b.member_card,b.avaliable_balance,b.total_balance,b.link_superior_user,c.child_team_code,c.team_code,c.parent_team,c.level,c.type')
                ->where(['a.order_sn' => $operateInfo['order_sn'], 'a.pay_status' => 2])
                ->findOrEmpty();

            //订单商品
            $orderGoods = OrderGoods::where(['order_sn' => $operateInfo['order_sn']])->select()->toArray();

            $topLinkUser = User::where(['uid' => $aOrder['link_superior_user'], 'status' => 1])->findOrEmpty()->toArray();
            $res = Db::transaction(function () use ($aOrder, $topLinkUser, $orderGoods, $adminInfo, $abnormalSn, $operateNumber, $data) {
                $update['record_number'] = intval($operateNumber);
                $update['operate_status'] = 1;
                $update['admin_id'] = $adminInfo['id'];
                $update['admin_name'] = $adminInfo['name'] ?? '未知管理员';
                $update['operate_time'] = time();
                $update['remark'] = $data['remark'] ?? null;
                $updateRes = self::update($update, ['abnormal_sn' => $abnormalSn, 'operate_status' => 2]);
                if (!empty($topLinkUser) && !empty($topLinkUser['vip_level'] ?? 0)) {
                    $param = ['orderInfo' => $aOrder, 'topLinkUser' => $topLinkUser, 'orderGoods' => $orderGoods, 'searType' => 1, 'adminInfo' => $adminInfo, 'operate_type' => 2, 'abnormal_sn' => $abnormalSn];
                    $handselRes = (new Divide())->topUserHandsel($param);

                    //记录日志
                    $log['param'] = $param;
                    $log['returnData'] = $handselRes;
                    $errorLevel = 'info';
                    if (empty($handselRes) || (!empty($handselRes) && empty($handselRes['res'] ?? false))) {
                        $errorLevel = 'error';
                    }

                    (new Log())->setChannel('handsel')->record($log, $errorLevel);

                } else {
                    throw new ServiceException(['msg' => '订单直属上级不符合赠送要求']);
                }
                return ['updateRes' => $updateRes, 'handselRes' => $handselRes ?? []];
            });
        }

        return $res;
    }

    public function user()
    {
        return $this->hasOne('User','uid','order_uid');
    }


}