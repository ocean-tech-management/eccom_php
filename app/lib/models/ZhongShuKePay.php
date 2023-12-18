<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------



namespace app\lib\models;


use app\lib\exceptions\FinanceException;
use app\lib\exceptions\ServiceException;
use app\lib\exceptions\WithdrawException;
use app\lib\models\KsConfig;
use app\lib\models\LetfreeExemptUser;
use app\lib\models\User;
use app\lib\models\WxConfig;
use app\lib\services\BankCard;
use app\lib\validates\CheckUser as checkUserValidate;
use app\lib\validates\Postage;
use think\facade\Db;

class ZhongShuKePay
{
    /**
     * @title 获取中数科签约状态
     * @param array $params
     * @return UserPrivacy|array|mixed|\think\Model|null
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function info(array $params = [])
    {
        (new checkUserValidate())->goCheck();
        $uid = $params['uid'] ?? null;
        $info = UserPrivacy::where(['uid' => $uid, 'status' => 1])->withoutField('id,create_time,deleted_time,status')->find();
        if (empty($info)) {
            throw new WithdrawException(['errorCode' => 3000114]);
        }
        $info['name'] = $info['realname'];
        $info['mobile'] = $info['phone'];
        $info['no'] = privateDecrypt($info['user_no']) ? encryptBankNew(privateDecrypt($info['user_no'])) : null;
        $info['bank_account'] = (string)privateDecrypt($info['bank_no']);
        //签约状态 1为未签约 2为已签约, 是为了兼容其他第三方渠道的状态值
        $info['status'] = 2;
        unset($info['realname']);
        unset($info['phone']);
        unset($info['user_no']);
        unset($info['bank_no']);

        $returnInfo['bankcard'] = $info['bank_account'] ?? null;
        $returnInfo['username'] = $info['name'] ?? null;
        $returnInfo['status'] = $info['status'];
        $returnInfo['updatedTime'] = $info['update_time'];

        $finallyData['returnData'] = $returnInfo;
        $finallyData['verifyRes'] = true;
        $finallyData['related_user'] = null;
        return $finallyData;
    }

    /**
     * @title 中数科签约
     * @param array $params
     * @return bool
     * @throws \Exception
     */
    public function add(array $params = [])
    {
        (new checkUserValidate())->goCheck();
        (new \app\lib\validates\UserPrivacy())->goCheck($params, 'create');

        $data['uid'] = trim($params['uid']);
        $data['realname'] = trim($params['name']);
        $data['phone'] = trim($params['mobile']);
        $data['user_no'] = publicEncrypt(str_replace(' ', '', trim($params['no'])));
//        $data['bank_no'] = publicEncrypt(trim($params['bank_account']));
        $bankCard = str_replace(" ", "", trim($params['bank_account']));
        $checkCard = (new BankCard())->getBankInfoByBankCard($bankCard);
        if (!$checkCard['validated']) {
            throw new FinanceException(['errorCode' => 1700105]);
        }
        $data['bank_no'] = publicEncrypt($bankCard);
        $data['status'] = 1;
        $data['create_time'] = time();
        $data['update_time'] = time();
        $res = Db::name('user_privacy')->insertGetId($data);
        if (!$res) {
            throw new WithdrawException(['errcode' => 3000111]);
        } else {
            return true;
        }
    }

    /**
     * @title 中数科修改银行卡
     * @param array $params
     * @return bool
     * @throws \think\db\exception\DbException
     */
    public function edit(array $params = [])
    {

        (new \app\lib\validates\UserPrivacy())->goCheck($params, 'edit');

        $data['bank_no'] = publicEncrypt($params['bank_account']);
        $data['update_time'] = time();
        $res = Db::name('user_privacy')->where(['uid' => $params['uid'],'status'=>1])->update($data);
        if (!$res) {
            throw new WithdrawException(['errcode' => 3000111]);
        } else {
            return true;
        }
    }

    /**
     * @title 中数科解约
     * @param array $params
     * @return bool
     * @throws \think\db\exception\DbException
     */
    public function remove(array $params = [])
    {
        $exist = Db::name('user_privacy')->where(['uid' => $params['uid'], 'status' => [1,0]])->find();
        if (!$exist) {
            throw new WithdrawException(['errorCode' => 3000114]);
        }
        $data['deleted_time'] = time();
        $data['update_time'] = time();
        $data['status'] = -1;
        $res = Db::name('user_privacy')->where(['id' => $exist['id']])->update($data);
        if (!$res) {
            throw new WithdrawException(['errorCode' => 3000113]);
        } else {
            return true;
        }
    }
}