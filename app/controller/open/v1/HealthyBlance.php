<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 健康豆模块]
// +----------------------------------------------------------------------



namespace app\controller\open\v1;


use app\BaseController;
use app\lib\models\User;
use app\lib\models\HealthyBalanceConver;
use app\lib\models\HealthyBalanceDetail;
use app\lib\models\HealthyBalance as HealthyBalanceModel;
use think\facade\Db;
use think\facade\Validate;
use app\lib\exceptions\OpenException;
use app\lib\models\ConverAmount;
use app\lib\services\Log;

class HealthyBlance extends BaseController
{
    protected $middleware = [
        'checkOpenUser',
        'openRequestLog',
    ];
    //转换健康豆
    public function conver()
    {
        $data = $this->requestData;
        $data['user_password'] = privateDecrypt($data['user_password']);
        $data['user_buy_password'] = privateDecrypt($data['user_buy_password']);
        //表单验证
        $rule = [
            'so_sn' => 'require',
            'user_mobile_phone' => 'require',
            'user_password' => 'require',
            'user_buy_password' => 'require',
            'user_conver_number' => 'require|float',
            'user_for_uid' => 'require',
            'user_for_name' => 'require',
            'user_for_phone' => 'require',
            'type' => 'require',
            'appId' => 'require',
        ];
        $message = [
            'so_sn.require' => '订单号不能为空！',
            'user_mobile_phone.require' => '手机号码不能为空！',
            'user_password.require' => '登录密码不能为空！',
            'user_buy_password.require' => '支付密码不能为空！',
            'user_conver_number.require' => '转入数目不能为空！',
            'user_for_uid.require' => '用户UID不能为空！',
            'user_for_name.require' => '用户不能为空！',
            'user_for_phone.require' => '用户手机号码不能为空！',
            'type.require' => '请选择转换渠道！',
            'appId.require' => '请选择转换来源公司！',
        ];
        //记录日记
        (new Log())->setChannel('healthyBalanceConver')->record($data);
        $validate = Validate::rule($rule)->message($message);
        if (!$validate->check($data)) {
            throw new OpenException(['msg' => $validate->getError()]);
        }
        $userInfo = User::field('id,uid,pwd,pay_pwd,phone,healthy_balance,name')->where(['phone' => $data['user_mobile_phone'], 'status' => '1'])->findOrEmpty()->toArray();
        if (empty($userInfo)) {
            //记录操作日记
            $addConverData['remark'] = '账号不存在';
            (new Log())->setChannel('healthyBalanceConver')->record($data);
            // (new HealthyBalanceConver)->new($addConverData);
            throw new OpenException(['msg' => '账号不存在，请确认！']);
        }
        //转出人信息
        $data['uid'] = $userInfo['uid'];
        $data['user_info'] =[
            'so_sn' => $data['so_sn'],
            'phone' => $data['user_mobile_phone'],
            'transfer_for_uid' => $data['user_for_uid'],
            'transfer_for_name' => $data['user_for_name'],
            'transfer_for_user_phone' => $data['user_for_phone'],
            'transfer_from_uid' => $userInfo['uid'],
            'transfer_from_name' => $userInfo['name'],
            'transfer_from_user_phone' => $userInfo['phone'],
        ];
        // (new Log())->setChannel('healthyBalanceConver')->record($data);
        //组合操作日记数据
        $addConverData = $data['user_info'];
        $addConverData['order_sn'] = $data['so_sn'];
        $addConverData['uid'] = $data['uid'];
        $addConverData['conver_status'] = -1;
        $addConverData['balance'] = $data['user_conver_number'];
        //判断登录密码
        if (md5($data['user_password']) != $userInfo['pwd']) {
            //记录操作日记
            $addConverData['remark'] = '账号信息异常';
            (new HealthyBalanceConver)->new($addConverData);
            throw new OpenException(['msg' => '账号信息异常，请确认！']);
        }
        //判断支付密码
        if (md5($data['user_buy_password']) != $userInfo['pay_pwd']) {
            //记录操作日记
            $addConverData['remark'] = '账号有误';
            (new HealthyBalanceConver)->new($addConverData);
            throw new OpenException(['msg' => '账号信息异常，请确认！']);
        }
        //判断三表健康豆是否一致
        $hbd_balance = HealthyBalanceDetail::where(['uid' => $userInfo['uid'], 'status' => 1])->sum('price');
        $hb_balance = HealthyBalanceModel::where(['uid' => $userInfo['uid'], 'status' => 1])->sum('balance');
        if ($hbd_balance != $hb_balance || $hbd_balance != $userInfo['healthy_balance']) {
            //记录操作日记
            $addConverData['remark'] = '健康豆流水异常';
            (new HealthyBalanceConver)->new($addConverData);
            throw new OpenException(['msg' => '健康豆流水异常！']);
        }
        //判断健康豆总额是否足够
        if ($data['type'] == 999) {
            if ((string)$userInfo['healthy_balance'] < (string)$data['user_conver_number']) {
                //记录操作日记
                $addConverData['remark'] = '健康豆不足';
                (new HealthyBalanceConver)->new($addConverData);
                throw new OpenException(['msg' => '健康豆不足！']);
            }
        } else {
            $hb_balance = HealthyBalanceModel::where(['uid' => $userInfo['uid'], 'status' => 1, 'channel_type' => $data['type']])->value('balance');
            if ((string)$hb_balance < (string)$data['user_conver_number']) {
                //记录操作日记
                $addConverData['remark'] = '健康豆不足';
                (new HealthyBalanceConver)->new($addConverData);
                throw new OpenException(['msg' => '健康豆不足！']);
            }
        }
        // (new Log())->setChannel('healthyBalanceConver')->record($data);
        //通过渠道进行扣除对应健康金
        $hbRes = (new HealthyBalanceModel)->conver($data);
        if ($hbRes) {
            //返回结果
            $returnData = [
                'order_conver_no' => $hbRes['order_sn'],
                'conver_mobile_phone' => $userInfo['phone'],
                'conver_user_name' => $userInfo['name'],
                'conver_user_id' => $data['uid'],
                'healthy_balance_data' => $hbRes['healthy_balance_data'],
            ];
            return returnData($returnData);
        } else {
            //判断是否已存在
            $orderDetail = (new HealthyBalanceDetail)->field('uid,order_sn,healthy_channel_type,price')->where(['order_sn' => $data['so_sn'], 'conver_type' => 3, 'status' => 1])->select();
            $deductDetailData = [];
            if (!$orderDetail->isEmpty()) {
                foreach ($orderDetail as $k => $v) {
                    $deductDetailData[] = [
                        'balance_change_type' => $v['healthy_channel_type'],
                        'balance_price' => priceFormat(-1 * $v['price']),
                    ];
                }
            }
            if (!empty($deductDetailData)) {
                //返回结果
                $returnData = [
                    'order_conver_no' => $data['so_sn'],
                    'conver_mobile_phone' => $userInfo['phone'],
                    'conver_user_name' => $userInfo['name'],
                    'conver_user_id' => $data['uid'],
                    'healthy_balance_data' => $deductDetailData,
                ];
                return returnData($returnData);
            }
            //记录操作日记
            $addConverData['remark'] = '转换失败！';
            (new HealthyBalanceConver)->new($addConverData);
            throw new OpenException(['msg' => '转换失败！']);
        }
        return returnData($hbRes);
    }

    /**
     * @description: 查询订单
     * @param {Type} $var
     * @return {*}
     */    
    public function checkConverOrder(HealthyBalanceConver $model)
    {
        // (new Log())->setChannel('healthyBalanceConver')->record($this->requestData);exit;
        $res = $model->checkOrder($this->requestData);
        return returnData($res);
    }

    /**
     * @description: 查询订单
     * @param {Type} $var
     * @return {*}
     */    
    public function checkUser(HealthyBalanceConver $model)
    {
        $data = $this->requestData;
        $data['user_password'] = privateDecrypt($data['user_password']);
        $data['user_buy_password'] = privateDecrypt($data['user_buy_password']);
        //表单验证
        $rule = [
            'user_mobile_phone' => 'require',
            'user_password' => 'require',
            'user_buy_password' => 'require',
            'user_conver_number' => 'require|float',
            'user_for_uid' => 'require',
            'user_for_name' => 'require',
            'user_for_phone' => 'require',
            'type' => 'require',
            'appId' => 'require',
        ];
        $message = [
            'user_mobile_phone.require' => '手机号码不能为空！',
            'user_password.require' => '登录密码不能为空！',
            'user_buy_password.require' => '支付密码不能为空！',
            'user_conver_number.require' => '转入数目不能为空！',
            'user_for_uid.require' => '用户UID不能为空！',
            'user_for_name.require' => '用户不能为空！',
            'user_for_phone.require' => '用户手机号码不能为空！',
            'type.require' => '请选择转换渠道！',
            'appId.require' => '请选择转换来源公司！',
        ];
        //记录日记
        (new Log())->setChannel('healthyBalanceConver')->record($data);
        $validate = Validate::rule($rule)->message($message);
        if (!$validate->check($data)) {
            throw new OpenException(['msg' => $validate->getError()]);
        }
        $userInfo = User::field('id,uid,pwd,pay_pwd,phone,healthy_balance,name')->where(['phone' => $data['user_mobile_phone'], 'status' => '1'])->findOrEmpty()->toArray();
        if (empty($userInfo)) {
            throw new OpenException(['msg' => '账号不存在，请确认！']);
        }
        //转出人信息
        $data['uid'] = $userInfo['uid'];
        $data['user_info'] =[
            'so_sn' => $data['so_sn'],
            'phone' => $data['user_mobile_phone'],
            'transfer_for_uid' => $data['user_for_uid'],
            'transfer_for_name' => $data['user_for_name'],
            'transfer_for_user_phone' => $data['user_for_phone'],
            'transfer_from_uid' => $userInfo['uid'],
            'transfer_from_name' => $userInfo['name'],
            'transfer_from_user_phone' => $userInfo['phone'],
        ];
        // (new Log())->setChannel('healthyBalanceConver')->record($data);
        //组合操作日记数据
        $addConverData = $data['user_info'];
        $addConverData['order_sn'] = $data['so_sn'];
        $addConverData['uid'] = $data['uid'];
        $addConverData['conver_status'] = -1;
        $addConverData['balance'] = $data['user_conver_number'];
        //判断登录密码
        if (md5($data['user_password']) != $userInfo['pwd']) {
            //记录操作日记
            $addConverData['remark'] = '账号有误';
            (new HealthyBalanceConver)->new($addConverData);
            throw new OpenException(['msg' => '账号信息异常，请确认！']);
        }
        //判断支付密码
        if (md5($data['user_buy_password']) != $userInfo['pay_pwd']) {
            //记录操作日记
            $addConverData['remark'] = '账号有误';
            (new HealthyBalanceConver)->new($addConverData);
            throw new OpenException(['msg' => '账号信息异常，请确认！']);
        }
        //判断三表健康豆是否一致
        $hbd_balance = HealthyBalanceDetail::where(['uid' => $userInfo['uid'], 'status' => 1])->sum('price');
        $hb_balance = HealthyBalanceModel::where(['uid' => $userInfo['uid'], 'status' => 1])->sum('balance');
        if ($hbd_balance != $hb_balance || $hbd_balance != $userInfo['healthy_balance']) {
            //记录操作日记
            $addConverData['remark'] = '健康豆流水异常';
            (new HealthyBalanceConver)->new($addConverData);
            throw new OpenException(['msg' => '健康豆流水异常！']);
        }
        //判断健康豆总额是否足够
        if ($data['type'] == 999) {
            if ((string)$userInfo['healthy_balance'] < (string)$data['user_conver_number']) {
                //记录操作日记
                $addConverData['remark'] = '健康豆不足';
                (new HealthyBalanceConver)->new($addConverData);
                throw new OpenException(['msg' => '健康豆不足！']);
            }
        } else {
            $hb_balance = HealthyBalanceModel::where(['uid' => $userInfo['uid'], 'status' => 1, 'channel_type' => $data['type']])->value('balance');
            if ((string)$hb_balance < (string)$data['user_conver_number']) {
                //记录操作日记
                $addConverData['remark'] = '健康豆不足';
                (new HealthyBalanceConver)->new($addConverData);
                throw new OpenException(['msg' => '健康豆不足！']);
            }
        }
        return returnData('成功！');
    }

    /**
     * @description: 修改总额度
     * @param {*} $data
     * @return {*}
     */    
    public function amount(ConverAmount $model)
    {
        $data = $this->requestData;
        $addData = [
            'app_id' => $data['appId'],
            'order_sn' => $data['amount_osn'],
            'amount' => $data['amount_num'],
            'change_type' => $data['amount_upd_type'],
            'type' => $data['amount_num_type'],
            'all_amount' => $data['amount_all_num'],
        ];
        $res = $model->new($addData);
        if ($res) {
            return returnMsg(true);
        }
        return returnMsg(false);
    }
}
