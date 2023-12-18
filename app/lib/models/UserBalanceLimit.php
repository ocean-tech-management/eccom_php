<?php

namespace app\lib\models;

use app\BaseModel;
use app\lib\exceptions\ServiceException;
use app\lib\services\CodeBuilder;
use think\facade\Db;

class UserBalanceLimit extends BaseModel
{
    /**
     * @title 限制用户账户操作 记录列表
     * @param array $sear
     * @return array
     */
    public function list(array $sear): array
    {
        if (!empty($sear['keyword'])) {
            $uMap[] = ['', 'exp', Db::raw($this->getFuzzySearSql('phone|name', $sear['keyword']))];
            $uMap[] = ['status', '=', 1];
            $uids = User::where($uMap)->column('uid');
            if (!empty($uids)) {
                $map[] = ['uid', 'in', $uids];
            }
        }
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        if (!empty($sear['type'])) {
            if (is_array($sear['type'])) {
                $map[] = ['type', 'in', $sear['type']];
            } else {
                $map[] = ['type', '=', $sear['type']];
            }
        }

        if (!empty($sear['limit_type'])) {
            if (is_array($sear['limit_type'])) {
                $map[] = ['limit_type', 'in', $sear['limit_type']];
            } else {
                $map[] = ['limit_type', '=', $sear['limit_type']];
            }
        }

        if (!empty($sear['uid'])) {
            $map[] = ['a.uid', '=', $sear['uid']];
        }

        if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
            $map[] = ['create_time', 'between', [strtotime($sear['start_time']), strtotime($sear['end_time'])]];
        }

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($sear['pageNumber'] ?? null)) {
            $this->pageNumber = intval($sear['pageNumber'] ?? 0);
        }
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }
        $list = $this->withoutField('update_time')->where($map)->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('create_time desc, id asc')->select()->toArray();


        if (!empty($list)) {
            $allUserId = array_unique(array_column($list, 'uid'));
            if (!empty($allUserId)) {
                $allUserList = User::where(['uid' => $allUserId])->field('uid,name,phone,avatarUrl')->select()->toArray();
                if (!empty($allUserList)) {
                    foreach ($allUserList as $key => $value) {
                        $allUserInfo[$value['uid']] = $value;
                    }
                    foreach ($list as $key => $value) {
                        if (!empty($allUserInfo[$value['uid']] ?? null)) {
                            $list[$key]['user_name'] = $allUserInfo[$value['uid']]['name'] ?? '未知用户';
                            $list[$key]['user_phone'] = $allUserInfo[$value['uid']]['phone'] ?? null;
                            $list[$key]['user_avatarUrl'] = $allUserInfo[$value['uid']]['avatarUrl'] ?? null;
                        }
                    }
                }
            }
        }

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title 批量新增
     * @param array $data
     * @return mixed
     */
    public function DBNew(array $data)
    {
        $allUser = $data['all_user'];
        //操作类型 1为全额禁止 2为超出额度部分可操作 3 累计额度内可操作
        $type = $data['type'] ?? 1;
        //账户类型 1商城分润钱包 2为广宣奖钱包 3为团队钱包 4为区代钱包 5为股东奖钱包 6为众筹钱包 7为美丽豆钱包 8为健康豆钱包 9为美丽券钱包 10为团长端总余额钱包 88全部钱包
        $balanceType = $data['balance_type'] ?? 88;

        //判断钱包类型, 值同限制账户类型
        $judgeBalanceType = $data['judge_balance_type'] ?? 88;

        //判断方式 判断条件方式 1为并(and) 2为或(or)
        $judgeMethod = $data['judge_method'] ?? 1;

        //限制操作类型 1为提现 2为转赠 3为转化 4为提现+转赠
        $limitType = $data['limit_type'] ?? 1;


        if (in_array($data['judge_balance_type'], [11, 12]) && in_array($data['type'], [3])) {
            throw new ServiceException(['msg' => '当前选择的判断账户类型不允许设置限制类型为 累计额度内可操作']);
        }

        $userPhone = array_unique(array_column($allUser, 'user_phone'));
        $userList = User::where(['phone' => $userPhone, 'status' => 1])->column('uid', 'phone');
        if (empty($userList)) {
            throw new ServiceException(['msg' => '查无有效用户']);
        }
        foreach ($userList as $key => $value) {
            $userPhoneInfo[$value] = $key;
        }

        foreach ($userPhone as $key => $value) {
            if (!in_array(trim($value), array_keys($userList))) {
                throw new ServiceException(['msg' => '手机号码' . $value . '不存在平台, 请仔细检查!']);
            }
        }
        if (in_array($type, [2, 3])) {
            foreach ($allUser as $key => $value) {
                if (doubleval($value['price'] ?? 0) <= 0) {
                    throw new ServiceException(['msg' => '手机号码' . $key . '的额度需要大于0哦']);
                }
            }
        }

        //判断同类型是否存在
        $existLimit = self::where(['uid' => $userList, 'balance_type' => $balanceType, 'status' => 1, 'judge_balance_type' => $judgeBalanceType])->column('uid');
        if (!empty($existLimit)) {
            foreach ($existLimit as $key => $value) {
                if (!empty($userPhoneInfo[$value] ?? null)) {
                    $existUserPhone[] = $userPhoneInfo[$value];
                }
            }
            if (!empty($existUserPhone)) {
                throw new ServiceException(['msg' => '手机号码为' . implode(' , ', $existUserPhone) . '的用户已经存在该类型记录,请勿重复录入']);
            }
        }


        $codeBuilder = (new CodeBuilder());
        $number = 0;
        foreach ($allUser as $key => $value) {
            if (!empty($userList[$value['user_phone']] ?? null)) {
                $remarkMsg = null;
                $balanceNew[$number]['limit_sn'] = $codeBuilder->buildUserBalanceLimitSn();
                $balanceNew[$number]['uid'] = $userList[$value['user_phone']];
                $balanceNew[$number]['type'] = $type;
                $balanceNew[$number]['balance_type'] = $balanceType;
                $balanceNew[$number]['limit_type'] = $limitType;
                $balanceNew[$number]['judge_balance_type'] = $judgeBalanceType;
                $balanceNew[$number]['judge_method'] = $judgeMethod;
                if (in_array($type, [2, 3])) {
                    $balanceNew[$number]['limit_price'] = priceFormat($value['price']);
                }
                if (!empty($value['remark'] ?? null)) {
                    $remarkMsg .= trim($value['remark']);
                }
                $balanceNew[$number]['remark'] = $remarkMsg;
                $number += 1;
            }
        }
        $res = false;
        if (!empty($balanceNew ?? [])) {
            $batchSqlData['list'] = array_values($balanceNew);
            $batchSqlData['db_name'] = 'sp_user_balance_limit';
            $batchSqlData['sear_type'] = 1;
            $batchSqlData['auto_fill_status'] = true;
            $batchSqlData['notValidateValueField'] = ['uid'];
            $res = (new CommonModel())->DBSaveAll($batchSqlData);
        }
        return $res;
    }

    /**
     * @title  详情
     * @param string $limitSn 编号
     * @return mixed
     */
    public function info(string $limitSn)
    {
        return $this->where(['limit_sn' => $limitSn, 'status' => $this->getStatusByRequestModule()])->findOrEmpty()->toArray();
    }

    /**
     * @title  编辑
     * @param array $data
     * @return mixed
     */
    public function DBEdit(array $data)
    {
        $existLimit = self::where(['uid' => $data['uid'], 'balance_type' => $data['balance_type'], 'status' => 1, 'judge_balance_type' => $data['judge_balance_type']])->column('limit_sn');
        if (count($existLimit) > 1) {
            throw new ServiceException(['msg' => '已经存在该类型记录,请勿重复录入']);
        }
        if (in_array($data['judge_balance_type'], [11, 12]) && in_array($data['type'], [3])) {
            throw new ServiceException(['msg' => '当前选择的判断账户类型不允许设置限制类型为 累计额度内可操作']);
        }
        $save = $data;
        unset($save['limit_sn']);
        return $this->baseUpdate(['limit_sn' => $data['limit_sn']], $save);
    }

    /**
     * @title  删除
     * @param string $limitSn 编号
     * @return mixed
     */
    public function del(string $limitSn)
    {
        return $this->baseDelete(['limit_sn' => $limitSn]);
    }


    /**
     * @title 检查用户是否被限制钱包的操作额度(老方法)
     * @param array $data
     * @return bool
     */
    public function checkUserBalanceLimitOld(array $data)
    {
        $uid = $data['uid'] ?? null;
        $price = $data['price'] ?? 0;
        if (empty($uid)) {
            throw new ServiceException(['msg' => '参数有误']);
        }
        //操作类型 1为提现 2为转赠 3为转化
        $operType = $data['oper_type'] ?? 1;
        switch ($operType){
            case 1:
                switch ($data['withdraw_type']){
                    case 1:
                        $balanceType = 1;
                        break;
                    case 5:
                        $balanceType = 10;
                        break;
                    case 7:
                        $balanceType = 6;
                        break;
                    default:
                        throw new ServiceException(['msg'=>'未知的操作类型哦']);
                }
                break;
            case 2:
                //转赠默认美丽金钱包
                $balanceType = $data['balance_type'] ?? 6;
                break;
            case 3:
                //转换默认团队余额钱包
                $balanceType = $data['balance_type'] ?? 3;
                break;
            default:
                $this->clearUserWithdrawCache(['uid' => $uid]);
                throw new ServiceException(['msg'=>'未知的操作类型哦~']);
        }

        $map[] = ['uid', '=', $uid];
        $map[] = ['balance_type', '=', $balanceType];

        switch ($operType) {
            case 1:
                $map[] = ['limit_type', 'in', [1, 4]];
                break;
            case 2:
                $map[] = ['limit_type', 'in', [2, 4]];
                break;
            case 3:
                $map[] = ['limit_type', '=', 3];
                break;
            default:
                $this->clearUserWithdrawCache(['uid' => $uid]);
                throw new ServiceException(['msg' => '未知的操作类型哦~']);

        }
        $map[] = ['status', '=', 1];
        //取最小价格的符合条件的限制记录
        $recordList = self::where($map)->withoutField('id,create_time,update_time')->order('limit_price asc')->limit(1)->findOrEmpty()->toArray();
        if (empty($recordList)) {
            return true;
        }
        //如果禁止类型为全禁止, 则本类型不允许操作
        if ($recordList['type'] == 1) {
            $this->clearUserWithdrawCache(['uid' => $uid]);
            throw new ServiceException(['msg' => '[风控拦截] 不允许的操作哦']);
        }
        switch ($recordList['type']) {
            case 2:
                switch ($operType) {
                    case 1:
                    case 2:
                        //查询当前钱包余额是否超过限制额度
                        switch ($balanceType) {
                            case 1:
                                $userBalance = (new BalanceDetail())->getAllBalanceByUid($data['uid'], [1, 2, 7, 11, 13, 23, 24]);
                                break;
                            case 10:
                                $userBalance = (new BalanceDetail())->getAllBalanceByUid($data['uid'], [12, 15, 16, 17, 18, 19, 20, 21, 27, 28, 29, 30, 31, 32, 33, 34]);
                                break;
                            case 6:
                                $userBalance = (new CrowdfundingBalanceDetail())->getAllBalanceByUid($data['uid']);
                                break;
                        }
                        if ((string)($userBalance + $price) <= (string)$recordList['limit_price']) {
                            $this->clearUserWithdrawCache(['uid' => $uid]);
                            throw new ServiceException(['msg' => '[风控拦截] 余额还不够哦']);
                        }
                        break;
                    case 3:
                        //转化类型由于逻辑复杂, 先不做此功能
                        return false;
                }

            case 3:
                switch ($operType) {
                    case 1:
                        $wMap[] = ['uid', '=', $uid];
                        $wMap[] = ['withdraw_type', '=', $balanceType];
                        $wMap[] = ['check_status', 'in', [1, 3]];
                        $wMap[] = ['status', '=', 1];
                        $WithdrawPrice = Withdraw::where($wMap)->sum('total_price');
                        if ((string)($WithdrawPrice + $price) > (string)$recordList['limit_price'] ) {
                            $this->clearUserWithdrawCache(['uid' => $uid]);
                            throw new ServiceException(['msg' => '[风控拦截] 不可以再提了哦']);
                        }
                        break;
                    case 2:
                        //转赠的情况, 直转给财务号的不计入额度
                        $financeUid = SystemConfig::where(['id' => 1])->value('finance_uid');
                        $tMap[] = ['uid', '=', $uid];
                        $tMap[] = ['change_type', '=', 7];
                        $tMap[] = ['is_transfer', '=', 1];
                        $tMap[] = ['transfer_type', '=', 1];
                        $withdrawFinanceAccount = !empty($financeUid) ? explode(',', $financeUid) : ['notFinanceUid'];
                        $tMap[] = ['transfer_for_real_uid', 'not in', $withdrawFinanceAccount];
                        $tMap[] = ['status', '=', 1];
                        $WithdrawPrice = CrowdfundingBalanceDetail::where($tMap)->sum('price');
                        if ((string)(abs($WithdrawPrice) + $price) > (string)$recordList['limit_price']) {
                            $this->clearUserWithdrawCache(['uid' => $uid]);
                            throw new ServiceException(['msg' => '[风控拦截] 不可以再操作了哦']);
                        }
                        break;
                    case 3:
                        //转化类型由于逻辑复杂, 先不做此功能
                        return false;
                }
                break;
        }
        return true;
    }

    /**
     * @title 检查用户是否被限制钱包的操作额度
     * @param array $data
     * @return bool
     */
    public function checkUserBalanceLimit(array $data)
    {
        $uid = $data['uid'] ?? null;
        $price = $data['price'] ?? 0;
        if (empty($uid)) {
            throw new ServiceException(['msg' => '参数有误']);
        }
        //操作类型 1为提现 2为转赠 3为转化
        $operType = $data['oper_type'] ?? 1;
        $limitChangeType = $data['limit_change_type'] ?? [];
        switch ($operType){
            case 1:
                switch ($data['withdraw_type']){
                    case 1:
                        $balanceType = 1;
                        break;
                    case 5:
                        $balanceType = 10;
                        break;
                    case 7:
                        $balanceType = 6;
                        break;
                    default:
                        throw new ServiceException(['msg'=>'未知的操作类型哦']);
                }
                break;
            case 2:
                //转赠默认美丽金钱包
                $balanceType = $data['balance_type'] ?? 6;
                break;
            case 3:
                //转换默认团长端余额钱包
                $balanceType = $data['balance_type'] ?? 10;
                //现在强制默认转化成美丽金的钱包类型都为团长端总余额, 不包含商城余额; 美丽金转商城余额的除外
                if (intval($balanceType) != 6) {
                    $balanceType = 10;
                }
                break;
            default:
                $this->clearUserWithdrawCache(['uid' => $uid]);
                throw new ServiceException(['msg'=>'未知的操作类型哦~']);
        }

        $map[] = ['uid', '=', $uid];
        $map[] = ['balance_type', '=', $balanceType];

        switch ($operType) {
            case 1:
                $map[] = ['limit_type', 'in', [1, 4]];
                break;
            case 2:
                $map[] = ['limit_type', 'in', [2, 4]];
                break;
            case 3:
                $map[] = ['limit_type', '=', 3];
                break;
            default:
                $this->clearUserWithdrawCache(['uid' => $uid]);
                throw new ServiceException(['msg' => '未知的操作类型哦~']);

        }
        $map[] = ['status', '=', 1];
        //取最小价格的符合条件的限制记录
        $recordList = self::where($map)->withoutField('id,create_time,update_time')->select()->toArray();
        if (empty($recordList)) {
            return true;
        }
        //按照判断钱包类型分类, 若同样的类型则取最小价格的符合条件的限制记录
        foreach ($recordList as $key => $value) {
            $recordCate[$value['judge_balance_type']][] = $value;
        }
        foreach ($recordCate as $key => $value) {
            if(count($value) > 1){
                array_multisort(array_column($value,'limit_price'), SORT_ASC, $recordCate[$key] );
            }
        }
        foreach ($recordCate as $key => $value) {
            $newRecordList[] = array_shift($value);
        }

        $conditionAndArray = [];
        $conditionOrArray = [];
        $conditionAndErrorArray = [];
        $conditionOrErrorArray = [];
        foreach ($newRecordList as $key => $value) {
            //判断哪些条件属于and哪些属于or
            switch ($value['judge_method']){
                case 1:
                    $conditionAndArray[] = $value['limit_sn'];
                    break;
                case 2:
                    $conditionOrArray[] = $value['limit_sn'];
                    break;
            }


            switch ($value['type']) {
                case 1:
                    $this->clearUserWithdrawCache(['uid' => $uid]);
                    $errorMsg[$value['limit_sn']] = '[风控拦截] 不允许的操作哦';
                    switch ($value['judge_method']){
                        case 1:
                            $conditionAndErrorArray[] = $value['limit_sn'];
                            break;
                        case 2:
                            $conditionOrErrorArray[] = $value['limit_sn'];
                            break;
                    }
                    break 2;
                case 2:
                    switch ($operType) {
                        case 1:
                        case 2:
                        case 3:
                            //查询当前钱包余额是否超过限制额度
                            switch ($value['judge_balance_type']) {
                                    //1商城分润钱包 2为广宣奖钱包 3为团队钱包 4为区代钱包 5为股东奖钱包 统一用此种方法判断
                                case in_array($value['judge_balance_type'], [1, 2, 3, 4, 5]):
                                    if (empty($limitChangeType)) {
                                        throw new ServiceException(['msg' => '系统出错啦']);
                                    }
                                    $userBalance = (new BalanceDetail())->getAllBalanceByUid($data['uid'], $limitChangeType);
                                    break;
                                    //团长端汇总钱包
                                case 10:
                                    $userBalance = (new BalanceDetail())->getAllBalanceByUid($data['uid'], [12, 15, 16, 17, 18, 19, 20, 21, 27, 28, 29, 30, 31, 32, 33, 34]);
                                    break;
                                    //众筹钱包
                                case 6:
                                    $userBalance = (new CrowdfundingBalanceDetail())->getAllBalanceByUid($data['uid']);
                                    break;
                                    //虚拟钱包-众筹参与冻结总金额
                                case 11:
                                    $duMap[] = ['order_uid', '=', $data['uid']];
                                    $duMap[] = ['link_uid', '=', $data['uid']];
                                    $duMap[] = ['type', '=', 8];
                                    $duMap[] = ['is_grateful', '=', 2];
                                    $duMap[] = ['arrival_status', '=', 2];
                                    $duMap[] = ['status', '=', 1];
                                    $userBalance = Divide::where($duMap)->sum('total_price');

                                    //此类型不允许加上当前操作金额
                                    $price = 0;
                                    break;
                                    //虚拟钱包(众筹钱包可用余额 + 参与冻结总金额)
                                case 12:
                                    $crowdBalance = (new CrowdfundingBalanceDetail())->getAllBalanceByUid($data['uid']);

                                    $duMap[] = ['order_uid', '=', $data['uid']];
                                    $duMap[] = ['link_uid', '=', $data['uid']];
                                    $duMap[] = ['type', '=', 8];
                                    $duMap[] = ['is_grateful', '=', 2];
                                    $duMap[] = ['arrival_status', '=', 2];
                                    $duMap[] = ['status', '=', 1];
                                    $joinBalance = Divide::where($duMap)->sum('total_price');

                                    $userBalance = priceFormat(($crowdBalance ?? 0) + ($joinBalance ?? 0));

                                    //此类型不允许加上当前操作金额
                                    $price = 0;
                                    break;
                                default:
                                    $userBalance = 0;
                                    break;
                            }
                            if ((string)($userBalance + $price) <= (string)$value['limit_price']) {
                                $this->clearUserWithdrawCache(['uid' => $uid]);
                                $errorMsg[$value['limit_sn']] = '[风控拦截] 余额还不够哦';
                                switch ($value['judge_method']){
                                    case 1:
                                        $conditionAndErrorArray[] = $value['limit_sn'];
                                        break;
                                    case 2:
                                        $conditionOrErrorArray[] = $value['limit_sn'];
                                        break;
                                }
                            }
                            break;
//                        case 3:
//                            //转化类型由于逻辑复杂, 先不做此功能
//                            return false;
                    }
                    break;
                case 3:
                    switch ($operType) {
                        case 1:
                        case 3:
                            $wMap[] = ['uid', '=', $uid];
                            if (is_array($data['withdraw_type'])) {
                                $wMap[] = ['withdraw_type', 'in', $data['withdraw_type']];
                            } else {
                                $wMap[] = ['withdraw_type', '=', $data['withdraw_type']];
                            }
                            $wMap[] = ['check_status', 'in', [1, 3]];
                            $wMap[] = ['status', '=', 1];
                            $WithdrawPrice = Withdraw::where($wMap)->sum('total_price');
                            if ((string)($WithdrawPrice + $price) > (string)$value['limit_price'] ) {
                                $this->clearUserWithdrawCache(['uid' => $uid]);
                                $errorMsg[$value['limit_sn']] = '[风控拦截] 不可以再提了哦';
                                switch ($value['judge_method']){
                                    case 1:
                                        $conditionAndErrorArray[] = $value['limit_sn'];
                                        break;
                                    case 2:
                                        $conditionOrErrorArray[] = $value['limit_sn'];
                                        break;
                                }
                            }
                            break;
                        case 2:
                            //转赠的情况, 直转给财务号的不计入额度
                            $financeUid = SystemConfig::where(['id' => 1])->value('finance_uid');
                            $tMap[] = ['uid', '=', $uid];
                            $tMap[] = ['change_type', '=', 7];
                            $tMap[] = ['is_transfer', '=', 1];
                            $tMap[] = ['transfer_type', '=', 1];
                            $withdrawFinanceAccount = !empty($financeUid) ? explode(',', $financeUid) : ['notFinanceUid'];
                            $tMap[] = ['transfer_for_real_uid', 'not in', $withdrawFinanceAccount];
                            $tMap[] = ['status', '=', 1];
                            $WithdrawPrice = CrowdfundingBalanceDetail::where($tMap)->sum('price');
                            if ((string)(abs($WithdrawPrice) + $price) > (string)$value['limit_price']) {
                                $this->clearUserWithdrawCache(['uid' => $uid]);
                                $errorMsg[$value['limit_sn']] = '[风控拦截] 不可以再操作了哦';
                                switch ($value['judge_method']){
                                    case 1:
                                        $conditionAndErrorArray[] = $value['limit_sn'];
                                        break;
                                    case 2:
                                        $conditionOrErrorArray[] = $value['limit_sn'];
                                        break;
                                }
                            }
                            break;
//                        case 3:
//                            //转化类型由于逻辑复杂, 先不做此功能
//                            return false;
                    }
                    break;
            }
        }

        $andRes = false;
        $orRes = false;
        $finallyRes = false;
        //判断所有限制记录条件是否为同时成功或部分成功即可
        if (!empty($conditionAndArray ?? [])) {
            if (empty($conditionAndErrorArray ?? [])) {
                $andRes = true;
            }
        } else {
            $andRes = true;
        }
        if (!empty($conditionOrArray ?? [])) {
            if (!empty($conditionOrErrorArray ?? [])) {
                $orRes = true;
            }
        } else {
            $orRes = true;
        }

        //同时成功的and的条件需要全部都为true才能判断成功, 部分成功or的条件有一个成功则判断成功
        if (empty($errorMsg ?? [])) {
            $finallyRes = true;
        } else {
            if (empty($conditionOrArray)) {
                if (!empty($andRes)) {
                    $finallyRes = true;
                }
            } else {
                if (!empty($orRes)) {
                    $finallyRes = true;
                }
            }
        }

        if (empty($finallyRes ?? false)) {
            $this->clearUserWithdrawCache(['uid' => $uid]);
            throw new ServiceException(['msg' => '[规则限制] 暂不符合操作条件']);
        }
        $this->clearUserWithdrawCache(['uid' => $uid]);

        return true;
    }

    /**
     * @title 清除用户提现操作锁
     * @param array $data
     * @return bool
     */
    public function clearUserWithdrawCache(array $data)
    {
        cache('withdrawBalanceIng-' . $data['uid'],null);
        return true;
    }
}