<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 提现表Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use app\lib\BaseException;
use app\lib\constant\WithdrawConstant;
use app\lib\exceptions\FileException;
use app\lib\exceptions\ServiceException;
use app\lib\exceptions\WithdrawException;
use app\lib\services\Code;
use app\lib\services\Office;
use think\Exception;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Log;

class Withdraw extends BaseModel
{
    protected $field = ['order_sn', 'uid', 'user_phone', 'user_real_name', 'price', 'remark', 'check_status', 'status', 'check_time', 'payment_no', 'payment_time', 'total_price', 'handing_scale', 'handing_fee', 'type', 'bank_account', 'payment_status', 'payment_remark', 'fail_status', 'divide_price', 'ppyl_price', 'withdraw_type', 'ad_price', 'team_price', 'shareholder_price', 'area_price', 'related_user','pay_channel','ticket_price','user_no','is_special_fee','special_fee_type'];
    protected $validateFields = ['order_sn', 'uid', 'price'];

    /**
     * @title  用户提现申请列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear = []): array
    {
        if ($this->module != 'api') {
            functionLimit(WithdrawConstant::WITHDRAW_EXCEL_LOCK_KEY, WithdrawConstant::WITHDRAW_EXCEL_LOCK_TIME);
        }
        try {
            $map = [];

            if (!empty($sear['keyword'])) {
                $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('b.name|a.user_real_name|b.phone', $sear['keyword']))];
            }

            if (!empty($sear['order_sn'])) {
                $map[] = ['a.order_sn', '=', $sear['order_sn']];
            }
            if (!empty($sear['check_status'])) {
                $map[] = ['a.check_status', '=', $sear['check_status']];
            }
            if (!empty($sear['payment_status'])) {
                $map[] = ['a.payment_status', '=', $sear['payment_status']];
            }

            if (!empty($sear['uid'])) {
                $map[] = ['a.uid', '=', $sear['uid']];
            }
            if (!empty($sear['time_type'] ?? null)) {
                switch ($sear['time_type'] ?? 1) {
                    case 1:
                        $map[] = ['a.create_time', '>=', 1677081600];
                        break;
                    case 2:
                        $map[] = ['a.create_time', '<', 1677081600];
                        break;
                }
            }

            if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
                $map[] = ['a.create_time', 'between', [substr($sear['start_time'], 0, 10), substr($sear['end_time'], 0, 10)]];
            }else{
                if ($this->module != 'api') {
                    throw new ServiceException(['msg'=>'请选择时间范围']);
                }
            }

            $page = intval($sear['page'] ?? 0) ?: null;
            if (!empty($sear['pageNumber'] ?? null)) {
                $this->pageNumber = intval($sear['pageNumber']);

            }

            if (!empty($page)) {
                $aTotal = Db::name('withdraw')->alias('a')
                    ->join('sp_user b', 'a.uid = b.uid', 'left')
                    ->where($map)->count();
                $pageTotal = ceil($aTotal / $this->pageNumber);
            } else {
                $pageTotal = 1;
            }

            $map[] = ['a.status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
            //withdraw_type 提现类型 1为本金提现 2为混合(商城佣金+拼拼)提现 3为商城佣金提现 4为拼拼提现 5为H5提现 7为福利提现
            $list = Db::name('withdraw')->alias('a')
                ->join('sp_user b', 'a.uid = b.uid', 'left')
                ->where($map)
                ->field('a.id,a.order_sn,a.handing_fee,a.bank_account,a.payment_no,a.uid,a.user_phone,a.user_real_name,a.price,a.remark,a.check_status,a.status,a.create_time,a.check_time,a.payment_time,b.name as user_name,b.avatarUrl,a.payment_status,a.payment_remark,a.divide_price,a.ppyl_price,a.withdraw_type,a.ad_price,a.total_price,a.team_price,a.related_user,b.create_time as user_create_time,a.user_no,a.pay_channel')
                ->when($page, function ($query) use ($page) {
                    $query->page($page, $this->pageNumber);
                })
                ->order('a.create_time desc')->select()
                ->each(function ($item, $key) {
                    if (!empty($item['create_time'])) {
                        $item['create_time'] = timeToDateFormat($item['create_time']);
                    }
                    if (!empty($item['user_create_time'])) {
                        $item['user_create_time'] = timeToDateFormat($item['user_create_time']);
                    }
                    if (!empty($item['check_time'])) {
                        $item['check_time'] = timeToDateFormat($item['check_time']);
                    }
                    if (!empty($item['payment_time'])) {
                        $item['payment_time'] = timeToDateFormat($item['payment_time']);
                    }
                    if (!empty($item['withdraw_type'])) {
                        $item['withdraw_type_cn'] = $this->getWithdrawTypeText($item['withdraw_type']);
                    }

                    if (empty($item['user_name'])) {
                        $item['user_name'] = '未知用户';
                    }
                    if ($this->module == 'admin') {
                        $item['check_status'] = $this->getCheckStatusText($item['check_status']);
                    }
                    if (!empty($item['related_user'] ?? null)) {
                        $item['relatedUserInfo'] = User::where(['uid' => $item['related_user'], 'status' => [1, 2]])->field('uid,name,avatarUrl,phone,vip_level,team_vip_level')->findOrEmpty()->toArray();
                    }
                    $item['re_user_real_name'] = $item['user_real_name'];
                    $item['re_user_phone'] = $item['user_phone'];
                    $item['contract_letfree'] = false;
                    $item['contract_exempt_letfree'] = false;
                    $item['key'] = $key + 1;
                    return $item;
                })->toArray();

            if (!empty($list)) {
                $allFetFreeUserList = LetfreeUser::where(['uid' => array_unique(array_column($list, 'uid')), 'status' => 1])->column('uid');
                $allExemptFetFreeUserList = LetfreeExemptUser::where(['uid' => array_unique(array_column($list, 'uid')), 'status' => 1])->column('uid');
                if (!empty($allFetFreeUserList)) {
                    foreach ($list as $key => $value) {
                        if (in_array($value['uid'], $allFetFreeUserList)) {
                            $list[$key]['contract_letfree'] = true;
                        }
                    }
                }
                if (!empty($allExemptFetFreeUserList)) {
                    foreach ($list as $key => $value) {
                        if (in_array($value['uid'], $allExemptFetFreeUserList)) {
                            $list[$key]['contract_exempt_letfree'] = true;
                        }
                    }
                }
            }
            if ($this->module != 'api') {
                $code_no = getRandomString();
                $key = WithdrawConstant::WITHDRAW_EXCEL_DATA_KEY . $code_no;
                $ttl = WithdrawConstant::WITHDRAW_EXCEL_DATA_TIME;
                Cache::store('redis')->set($key, json_encode($list), $ttl);
                $search_key = WithdrawConstant::WITHDRAW_EXCEL_SEARCH_DATA_KEY . $code_no;
                $search_ttl = WithdrawConstant::WITHDRAW_EXCEL_SEARCH_DATA_TIME;
                Cache::store('redis')->set($search_key, json_encode($sear), $search_ttl);
                /** 最后输出数据的处理 */
                foreach ($list as $k => $v) {
                    $list[$k]['bank_account'] = encryptBankNew($v['bank_account']);
                    $list[$k]['user_no'] = $v['user_no'] ? encryptBankNew($v['user_no']) : $v['user_no'];
                }
            }
        } catch (BaseException $e) {
            functionLimitClear(WithdrawConstant::WITHDRAW_EXCEL_LOCK_KEY);
            throw new ServiceException(['msg' => $e->msg]);
        } catch (Exception $e) {
            functionLimitClear(WithdrawConstant::WITHDRAW_EXCEL_LOCK_KEY);
            throw new ServiceException(['msg' => $e->getMessage()]);
        }
        if ($this->module != 'api') {
            functionLimitClear(WithdrawConstant::WITHDRAW_EXCEL_LOCK_KEY);
        }
        return ['list' => $list, 'code_no' => ($code_no ?? null), 'pageTotal' => $pageTotal];
    }

    /**
     * @title  导出用户提现申请列表
     * @param array $params
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function export(array $params = []): array
    {
        //验证是否有code_no参数 如果没有抛异常
        if (!isset($params['code_no'])) {
            throw new FileException(['errorCode' => 11004]);
        }
        // 获取code_no的缓存数据
        // 验证code_no的缓存是否存在 不存在抛异常
        $key = WithdrawConstant::WITHDRAW_EXCEL_DATA_KEY . $params['code_no'];
        $data = Cache::store('redis')->get($key);
        if (empty($data)) {
            throw new FileException(['errorCode' => 11005]);
        }
        //获取数据查询条件

        $search_key = WithdrawConstant::WITHDRAW_EXCEL_SEARCH_DATA_KEY . $params['code_no'];
        $search_data = Cache::store('redis')->get($search_key) ?? "";
        $list = json_decode($data, true);
        if (empty($list)) {
            throw new FileException(['errorCode' => 11006]);
        }
        //字段特殊显示处理
        foreach ($list as $key => $value) {
            $list[$key]['payment_status'] = $this->getPaymentText($value['payment_status'] ?? null);
        }
        // 验证是否有ids参数 如果有则筛选对应的数据整合到一个数组然后导出
        if (isset($params['ids'])) {
            $ids = explode(",", $params['ids']);
        } else {
            $ids = [];
        }
        // 验证是否有template字段  1为微信支付 2为汇聚支付 3为杉德支付 4为快商 5为中数科 88为线下打款 默认4
        $template = $params['template'] ?? 0;
        switch ($template) {
            case WithdrawConstant::WITHDRAW_PAY_TYPE_SHANDE:
                $constant_data = WithdrawConstant::WITHDRAW_EXPORT_SHANDE_DATA;
                $pay_type = WithdrawConstant::WITHDRAW_PAY_SHANDE;
                break;
            case WithdrawConstant::WITHDRAW_PAY_TYPE_ZSK:
                $constant_data = WithdrawConstant::WITHDRAW_EXPORT_ZSK_DATA;
                $pay_type = WithdrawConstant::WITHDRAW_PAY_ZSK;
                break;
            case WithdrawConstant::WITHDRAW_PAY_TYPE_WX:
            case WithdrawConstant::WITHDRAW_PAY_TYPE_JOIN:
            case WithdrawConstant::WITHDRAW_PAY_TYPE_OFFLINE:
                throw new FileException(['errorCode' => 11007]);
            case WithdrawConstant::WITHDRAW_PAY_TYPE_KUAISHANG:
            default:
                $constant_data = WithdrawConstant::WITHDRAW_EXPORT_KUAISHANG_DATA;
                $pay_type = WithdrawConstant::WITHDRAW_PAY_KUAISHANG;
                break;
        }
        $value = [];
        $title = [];
        $width = [];
        foreach ($constant_data as $constant_datum) {
            $value[] = $constant_datum['value'];
            $title[] = $constant_datum['title'];
            $width[] = $constant_datum['width'] ?? 5;
        }


        //根据value组成新的导出数据
        $export_data = [];
        foreach ($list as $item) {
            $local = [];
            //如果有勾选个别几条
            if (!empty($ids)) {
                if (in_array($item['id'], $ids)) {
                    //只导出勾选的条数
                    foreach ($value as $str) {
                        if ($str == "pay_type") {
                            $local[$str] = $pay_type;
                        } else {
                            if (isset($item[$str])) {
                                $local[$str] = $item[$str];
                            } else {
                                $local[$str] = "";
                            }
                        }
                    }
                }
            } else {
                //没有勾选某些条数,导出全部
                foreach ($value as $str) {
                    if ($str == "pay_type") {
                        $local[$str] = $pay_type;
                    } else {
                        if (isset($item[$str])) {
                            $local[$str] = $item[$str];
                        } else {
                            $local[$str] = "";
                        }
                    }
                }
            }
            $export_data[] = $local;
        }
        /** 导出文件  */
        $res_data = (new Office())->exportExcelNew($pay_type . "打款提现列表" . date('YmdHis', time()), $title,
            $export_data, $width, ['admin_uid' => (int)$params['admin_id'], 'search_data' => $search_data, 'can_resend' => 1, 'max_send_num' => 2, 'model_name' => 'withdraw'], 1, 1, 'Excel5');
        $res = ['url' => $res_data['url'], 'file_id' => $res_data['file_id'], 'sendSmsRes' => $res_data['sendSmsRes'] ?? false, 'sendSmsErrorMsg' => $res_data['sendSmsErrorMsg'] ?? null];
        return $res;
    }

    /**
     * @title  提现详情
     * @param array $sear
     * @return array
     */
    public function detail(array $sear)
    {
        if (!empty($sear['order_sn'])) {
            $map[] = ['a.order_sn', '=', $sear['order_sn']];
        } else {
            return [];
        }

        $map[] = ['a.status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        $page = intval($sear['page'] ?? 0) ?: null;

        $row = $this->alias('a')
            ->join('sp_user b', 'a.uid = b.uid', 'left')
            ->where($map)
            ->field('a.id,a.order_sn,a.handing_fee,a.payment_no,a.uid,a.user_phone,a.user_real_name,a.price,a.bank_account,a.user_no,a.remark,a.check_status,a.status,a.create_time,a.check_time,a.payment_time,b.name as user_name,b.avatarUrl,b.avatarUrl,a.payment_status,a.payment_remark,a.total_price,a.divide_price,a.ppyl_price,a.withdraw_type,a.ad_price,a.team_price,a.related_user,b.team_vip_level,b.vip_level')
            ->when($page, function ($query) use ($page) {
                $query->page($page, $this->pageNumber);
            })->order('a.create_time desc')->find();

        if (!empty($row)) {
            if (!empty($row['related_user'] ?? null)) {
                $row['relatedUserInfo'] = User::where(['uid' => $row['related_user'], 'status' => [1, 2]])->field('uid,name,avatarUrl,phone,vip_level,team_vip_level')->findOrEmpty()->toArray();
            }
            $row['team_vip_name'] = '非团队会员';
            if (!empty(doubleval($row['team_vip_level'] ?? null))) {
                $row['team_vip_name'] = TeamMemberVdc::where(['level' => $row['team_vip_level']])->value('name');
            }
            $row['vip_name'] = '非会员';
            if (!empty(doubleval($row['vip_level'] ?? null))) {
                $row['vip_name'] = MemberVdc::where(['level' => $row['vip_level']])->value('name');
            }
            //补齐关于乐小活是否签约的信息
            $row['contract_letfree'] = false;
            $row['contract_exempt_letfree'] = false;
            $allFetFreeUserList = LetfreeUser::where(['uid' => $row['uid'], 'status' => 1])->value('uid');
            $allExemptFetFreeUserList = LetfreeExemptUser::where(['uid' => $row['uid'], 'status' => 1])->value('uid');
            if (!empty($allFetFreeUserList)) {
                $row['contract_letfree'] = true;
            }
            if (!empty($allExemptFetFreeUserList)) {
                $row['contract_exempt_letfree'] = true;
            }
            //查看用户累计提现金额
            $historyWithdraw = self::where(['uid' => $row['uid'], 'payment_status' => 1, 'check_status' => 1, 'status' => 1])->sum('total_price');
            $row['historyWithdraw'] = $historyWithdraw;

            //财务号
            $financeUid = SystemConfig::where(['id' => 1])->value('finance_uid');
            $withdrawFinanceAccount = !empty($financeUid) ? explode(',', $financeUid) : ['notFinanceUid'];

            //查看用户累计充值金额
            //如果是财务号将别人直接转给他的记录也作为充值本金, 其他人的则仅记录财务直传给他的和后台充值的记录
            $rMap[] = ['uid', '=', $row['uid']];
            $rMap[] = ['type', '=', 1];
            $rMap[] = ['status', '=', 1];
            $historyRecharge = CrowdfundingBalanceDetail::where(function ($query) use ($withdrawFinanceAccount, $row){
                $map1[] = ['change_type', '=', 1];
                $map2[] = ['is_transfer', '=', 1];
                $map2[] = ['transfer_from_real_uid', 'in', $withdrawFinanceAccount];
                if (!empty($withdrawFinanceAccount) && in_array($row['uid'], $withdrawFinanceAccount)) {
                    $map3[] = ['is_transfer', '=', 1];
                    $map3[] = ['is_finance', '=', 2];
                    $map3[] = ['transfer_for_uid', '=', $row['uid']];
                    $query->whereOr([$map1, $map2, $map3]);
                } else {
                    $query->whereOr([$map1, $map2]);
                }
            })->where($rMap)->sum('price');
            $row['historyRecharge'] = $historyRecharge;
            /** 隐藏银行卡号和身份证号 */
            $row['bank_account'] = !empty($row['bank_account']) ? encryptBankNew($row['bank_account']) : $row['bank_account'];
            $row['user_no'] = !empty($row['user_no']) ? encryptBankNew($row['user_no']) : $row['user_no'];

        }
        return $row;
    }

    /**
     * @title  申请详情
     * @param string $id
     * @return mixed
     */
    public function info(string $id)
    {
        return $this->where(['id' => $id, 'status' => [1]])->findOrEmpty()->toArray();
    }

    /**
     * @title  新建提现审核
     * @param array $data
     * @return mixed
     */
    public function new(array $data)
    {
        return $this->validate()->baseCreate($data);
    }

    /**
     * @title  金额统计
     * @param array $sear
     * @return float
     */
    public function amountTotal(array $sear = [])
    {
        if (!empty($sear['start_time'])) {
            $map[] = ['create_time', '>=', strtotime($sear['start_time'] . ' 00:00:00')];
        }
        if (!empty($sear['end_time'])) {
            $map[] = ['create_time', '<=', strtotime($sear['end_time'] . ' 23:59:59')];
        }
        $map[] = ['check_status', '=', $sear['check_status'] ?? 1];
        $map[] = ['status', '=', $sear['status'] ?? 1];
        $sumField = $sear['sumField'] ?? 'price';
        $info = $this->where($map)->sum($sumField);
        return $info;
    }

    /**
     * @title  提现信息汇总面板
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function summary(array $sear = []): array
    {
        //商城分润奖金情况
        $map[] = ['a.status', '<>', -1];
        $map[] = ['a.arrival_status', 'in', [1, 2, 4]];
        $divideList = Db::name('divide')->alias('a')
            ->where($map)
            ->field('SUM( IF ( a.arrival_status = 1, a.real_divide_price, 0 ) ) AS confirm_price,SUM( IF ( a.arrival_status in (2,4), a.real_divide_price, 0 ) ) AS freeze_price,SUM(real_divide_price) as all_price')
            ->order('a.create_time desc')->findOrEmpty();

        //拼拼分润奖金情况
        $map[] = ['a.status', '<>', -1];
        $map[] = ['a.arrival_status', 'in', [1, 2, 3]];
        $ppylRewardList = Db::name('ppyl_reward')->alias('a')
            ->where($map)
            ->field('SUM( IF ( a.arrival_status = 1, a.real_reward_price, 0 ) ) AS confirm_price,SUM( IF ( a.arrival_status in (2), a.real_reward_price, 0 ) ) AS freeze_price,SUM( IF ( a.arrival_status in (3), a.real_reward_price, 0 ) ) AS wait_receive_price,SUM(real_reward_price) as all_price')
            ->order('a.create_time desc')->findOrEmpty();

        //提现记录情况
        $wMap[] = ['payment_status', '=', 1];
        $wMap[] = ['status', '=', 1];
        $wMap[] = ['check_status', '=', 1];
        $withdrawList = self::where($wMap)->field('sum(divide_price) as total_price,sum(handing_fee) as total_handing_fee,sum(divide_price) as real_price,sum(ppyl_price) as ppyl_total_price,sum(ppyl_price) as ppyl_real_price')->findOrEmpty()->toArray();

        $finally = [
            'needPayBonus' => priceFormat(($divideList['all_price'] ?? 0) - ($withdrawList['total_price'] ?? 0)),
            'allWithdrawBonus' => priceFormat(($withdrawList['total_price'] ?? 0)),
            'realWithdrawBonus' => priceFormat(($withdrawList['real_price'] ?? 0)),
            'canWithdrawBonus' => priceFormat(($divideList['confirm_price'] ?? 0) - ($withdrawList['total_price'] ?? 0)),
            'freezeBonus' => priceFormat(($divideList['freeze_price'] ?? 0)),

            'ppyl_needPayBonus' => priceFormat(($ppylRewardList['all_price'] ?? 0) - ($withdrawList['ppyl_total_price'] ?? 0)),
            'ppyl_allWithdrawBonus' => priceFormat(($withdrawList['ppyl_total_price'] ?? 0)),
            'ppyl_realWithdrawBonus' => priceFormat(($withdrawList['ppyl_real_price'] ?? 0)),
            'ppyl_canWithdrawBonus' => priceFormat(($ppylRewardList['confirm_price'] ?? 0) - ($withdrawList['ppyl_total_price'] ?? 0)),
            'ppyl_freezeBonus' => priceFormat(($ppylRewardList['freeze_price'] ?? 0)),
            'ppyl_waitReceiveBonus' => priceFormat(($ppylRewardList['wait_receive_price'] ?? 0)),
        ];
        return $finally ?? [];
    }

    /**
     * 根据管理员密码获取导出文件的密码
     * @param array $params
     * @return mixed
     * @throws \think\db\exception\DbException
     */
    public function getFilePassword(array $params = [])
    {
//        $e = publicEncrypt("2133441");
//        $d = privateDecrypt($e);
        if ($params['admin_pwd'] != systemConfig("safe_file_pwd")) {
            throw new WithdrawException(['errorCode' => 3000115]);
        }
        $export_file = Export::where(['id' => $params['file_export_id'] ?? 0])->find();
//        return [$e,$d,$export_file,privateDecrypt($export_file['file_pwd'])];
        if (empty($export_file)) {
            throw new FileException(['errorCode' => 11009]);
        }
        if (empty($export_file['is_encrypt'])) {
            throw new FileException(['errorCode' => 11012]);
        }
        if ($export_file['max_send_num']-$export_file['send_num'] <= 0) {
            throw new FileException(['errorCode' => 11011]);
        }
        Db::table("sp_export")->where(['id' => $params['file_export_id'] ?? 0])->inc('send_num')->update();
        return privateDecrypt($export_file['file_pwd']);
    }


    public function getCheckTimeAttr($value)
    {
        return !empty($value) ? date('Y-m-d H:i:s', $value) : null;
    }

    public function getPaymentTimeAttr($value)
    {
        return !empty($value) ? date('Y-m-d H:i:s', $value) : null;
    }

    /**
     * @title 获取提现记录状态值字典
     * @param int $status
     * @return string
     */
    public function getStatusText(int $status)
    {
        switch ($status) {
            case -1:
                $text = '已删除';
                break;
            case 1:
                $text = '提交申请';
                break;
            case 2:
                $text = '用户已取消';
                break;
            default:
                $text = '数据错误';

        }

        return $text;
    }

    /**
     * @title 获取提现审核状态值字段
     * @param int $checkStatus
     * @return string
     */
    public function getCheckStatusText(int $checkStatus)
    {
        switch ($checkStatus) {
            case -1:
                $text = '超时未审核';
                break;
            case 1:
                $text = '通过';
                break;
            case 2:
                $text = '不通过';
                break;
            case 3:
                $text = '待审核';
                break;
            default:
                $text = '数据错误';
        }

        return $text;
    }

    /**
     * @title 获取提现类型字段
     * @param int $withdrawType
     * @return string
     */
    public function getWithdrawTypeText(int $withdrawType)
    {
        switch ($withdrawType) {
            case 2:
                $text = '商城提现';
                break;
            case 5:
                $text = 'H5提现';
                break;
            case 7:
                $text = '福利提现';
                break;
            case 8:
                $text = '健康提现';
                break;
            default:
                $text = '数据错误';
        }

        return $text;
    }

    /**
     * @title 获取提现到账状态值字段
     * @param int $paymentStatus
     * @return string
     */
    public function getPaymentText(int $paymentStatus)
    {
        switch ($paymentStatus) {
            case 1:
                $text = '已到账';
                break;
            case 2:
                $text = '';
                break;
            case -1:
                $text = '失败';
                break;
            default:
                $text = '';
        }

        return $text;
    }
}