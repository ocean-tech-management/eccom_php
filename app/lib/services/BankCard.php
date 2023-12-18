<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------



namespace app\lib\services;


use app\lib\exceptions\FinanceException;
use app\lib\exceptions\JoinPayException;
use app\lib\exceptions\SandPayException;
use app\lib\exceptions\ServiceException;
use app\lib\exceptions\UserException;
use app\lib\models\User;
use app\lib\models\UserBankCard;
use think\facade\Cache;

class BankCard
{
    /**
     * @title  签约短信下发
     * @param array $data
     * @return mixed
     */
    public function signSms(array $data)
    {
        $userInfo = $this->getUserInfo($data['uid']);
        if (!is_numeric($data['bank_card_no'])) {
            throw new ServiceException(['msg' => '输入的银行卡有误, 请保证银行卡一定是纯数字']);
        }

        if (!is_numeric($data['bank_phone'])) {
            throw new ServiceException(['msg' => '输入的手机号码有误, 请保证手机号码一定是纯数字']);
        }
        $data['bank_card_no'] = str_replace(' ', '', $data['bank_card_no']);
        if (!empty($data['expire_date'] ?? null)) {
            $data['expire_date'] = str_replace(' ', '', $data['expire_date']);
            if (!is_numeric(str_replace('/', '', $data['expire_date']))) {
                throw new ServiceException(['msg' => '输入的信用卡有效期有误, 请校验格式']);
            }
        }

        //判断银行卡是否已存在
        $checkExist[] = ['status', 'in', [1, 2]];
        $checkExist[] = ['contract_status', 'in', [1, 2]];
        $checkExist[] = ['card_type', '=', $data['card_type']];
        $checkExist[] = ['bank_card', '=', $data['bank_card_no']];
        $checkExist[] = ['channel', '=', ($data['channel'] ?? (config('system.thirdPayType') ?? 2))];
        if ($data['card_type'] == 2) {
            //判断是否允许协议支付的信用卡通道
            if (!in_array(config('system.bankCardSignType'), [2, 3])) {
                throw new FinanceException(['msg' => '此类型暂停绑定~感谢您的理解与支持']);
            }
            switch (config('system.thirdPayType') ?? 2) {
                case 2:
                    if (strlen($data['expire_date']) != 5 || empty(strstr($data['expire_date'], '/'))) {
                        throw new ServiceException(['msg' => '输入的信用卡有效期有误, 请仔细校验格式, 注意年、月份不要写反哦']);
                    }
                    break;
                case 3:
                    if (strlen($data['expire_date']) != 4) {
                        throw new ServiceException(['msg' => '输入的信用卡有效期有误, 请仔细校验格式, 注意年、月份不要写反哦,格式为年份月份, 可参考: 2301']);
                    }
                    break;
                case 4:
                    if (strlen($data['expire_date']) != 4) {
                        throw new ServiceException(['msg' => '输入的信用卡有效期有误, 请仔细校验格式, 注意年、月份不要写反哦,格式为月份年份, 可参考: 0623']);
                    }
                    break;
                default:
                    throw new FinanceException(['msg' => '不支持的商户通道']);
            }
            $checkExist[] = ['expire_date', '=', $data['expire_date']];
            $checkExist[] = ['cvv', '=', $data['cvv']];
        }
        $exist = UserBankCard::where($checkExist)->findOrEmpty()->toArray();
        if (!empty($exist)) {
            if ($exist['uid'] != $data['uid']) {
                if ($exist['allow_cover'] != 1 && $exist['contract_status'] == 1) {
                    throw new ServiceException(['msg' => '此卡已被绑定, 请选择其他卡']);
                }
                //如果此条记录允许覆盖并且未签约允许删除此条存在的记录并且重新生成一条新的
                if ($exist['allow_cover'] == 1 && $exist['contract_status'] == 2) {
                    UserBankCard::update(['status' => -1, 'contract_status' => -1, 'coder_remark' => '他人重新签约, 此记录作废'], ['card_sn' => $exist['card_sn'], 'channel' => ($data['channel'] ?? (config('system.thirdPayType') ?? 2))]);
                }
            } else {
                if ($exist['contract_status'] == 1) {
                    throw new ServiceException(['msg' => '此卡已被绑定, 请选择其他卡']);
                }
                UserBankCard::update(['status' => -1, 'contract_status' => -1, 'coder_remark' => '本人重新签约, 此记录作废'], ['card_sn' => $exist['card_sn'], 'channel' => ($data['channel'] ?? (config('system.thirdPayType') ?? 2))]);
            }
        }

        if (!empty($data['card_sn'] ?? null)) {
            $newSms['out_trade_no'] = trim($data['card_sn']);
        } else {
            $newSms['out_trade_no'] = (new CodeBuilder())->buildUserCardSn();
        }

        $newSms['real_name'] = $data['real_name'];
        $newSms['id_card'] = $data['id_card'];
        $newSms['bank_card_no'] = $data['bank_card_no'];
        $newSms['bank_phone'] = $data['bank_phone'];
        $newSms['card_type'] = $data['card_type'] ?? 1;
        if ($newSms['card_type'] == 2) {
            $newSms['expire_date'] = $data['expire_date'];
            $newSms['cvv'] = $data['cvv'];
        }
        $newSms['order_create_time'] = time();
        $newSms['uid'] = $data['uid'];

        //发送签约短信
        switch (config('system.thirdPayType') ?? 2) {
            case 1:
                throw new FinanceException(['msg' => '暂不支持的支付商通道']);
                break;
            case 2:
                $sendSms = (new JoinPay())->agreementSignSms($newSms);
                break;
            case 3:
                $sendSms = (new SandPay())->agreementSignSms($newSms);
                if (!empty($sendSms['applyNo'] ?? null)) {
                    $newSms['out_trade_no'] = $sendSms['applyNo'];
                }
                break;
            case 4:
                $sendSms = (new YsePay())->agreementSignSms($newSms);
                if (!empty($sendSms['applyNo'] ?? null)) {
                    $newSms['out_trade_no'] = $sendSms['applyNo'];
                }
                break;
            default:
                throw new FinanceException(['msg' => '未知支付商通道']);
        }

        $newRes = false;
        if (!empty($sendSms) && !empty($sendSms['res'])) {
            $newCard = $newSms;
            $newCard['card_sn'] = $newSms['out_trade_no'];
            $newCard['bank_card'] = $newSms['bank_card_no'];
            $newCard['uid'] = $data['uid'];
            $newCard['user_phone'] = $userInfo['phone'] ?? null;
            $newCard['contract_status'] = 2;
            $newCard['is_default'] = $data['is_default'] ?? 1;
            $newCard['create_time'] = $newSms['order_create_time'];
            $newCard['channel'] = $data['channel'] ?? (config('system.thirdPayType') ?? 2);
            $newRes = (new UserBankCard())->DBNew($newCard);
        } else {
            //订单号重复,新建一个重新下发
            if (!empty($sendSms['biz_code'] ?? null) && $sendSms['biz_code'] == 'JS100016') {
                $newCardSn = $this->updateCardSn($newSms['out_trade_no']);
                $newData = $data;
                $newData['card_sn'] = $newCardSn;
                $againRes = $this->signSms($newData);
                return $againRes;
            }
            throw new ServiceException(['msg' => $sendSms['errorMsg'] ?? '签约短信下发出错啦~']);
        }
        return ['smsRes' => judge($sendSms), 'newRes' => judge($newRes), 'card_sn' => $newSms['out_trade_no']];
    }

    /**
     * @title  签约短信校验, 正式签约
     * @param array $data
     * @return bool
     */
    public function signContract(array $data)
    {
//        $userInfo = $this->getUserInfo($data['uid']);
        $newSms['out_trade_no'] = $data['card_sn'];
        $newSms['sms_code'] = $data['sms_code'];
        if (!is_numeric($data['sms_code']) || strlen($data['sms_code']) != 6) {
            throw new ServiceException(['msg' => '验证码必须为六位数字']);
        }
        //验证签约短信
        switch (config('system.thirdPayType') ?? 2) {
            case 1:
                throw new FinanceException(['msg' => '暂不支持的支付商通道']);
                break;
            case 2:
                $sendSms = (new JoinPay())->agreementContract($newSms);
                break;
            case 3:
                $newSms['uid'] = $data['uid'];
                $sendSms = (new SandPay())->agreementContract($newSms);
                break;
            case 4:
                $newSms['uid'] = $data['uid'];
                $sendSms = (new YsePay())->agreementContract($newSms);
                break;
            default:
                throw new FinanceException(['msg' => '未知支付商通道']);
        }

        $newRes = false;
        if (!empty($sendSms) && !empty($sendSms['res'])) {
            unset($newSms['out_trade_no']);
            $update['bank_code'] = $sendSms['bankCode'];
            $update['bank_name'] = $sendSms['bankName'];
            $update['sign_no'] = $sendSms['sign_no'];
            $update['contract_status'] = 1;
            $newRes = UserBankCard::update($update, ['card_sn' => $data['card_sn'], 'contract_status' => 2, 'status' => 1]);
        } else {
            throw new ServiceException(['msg' => $sendSms['errorMsg'] ?? '出错啦~']);
        }
        return judge($newRes);
    }


    /**
     * @title  解约
     * @param array $data
     * @return bool
     */
    public function unSign(array $data)
    {
//        $userInfo = $this->getUserInfo($data['uid']);
        $newSms['out_trade_no'] = $data['card_sn'] . '_1';
        $cardInfo = UserBankCard::where(['card_sn' => $data['card_sn'], 'uid' => $data['uid'], 'contract_status' => 1])->findOrEmpty()->toArray();
        if (empty($cardInfo)) {
            throw new ServiceException(['msg' => '此卡信息不符合解约条件!']);
        }
        $newSms['sign_no'] = $cardInfo['sign_no'];
        $newSms['order_create_time'] = strtotime($cardInfo['create_time']);

        //解约
        switch (config('system.thirdPayType') ?? 2) {
            case 1:
                throw new FinanceException(['msg' => '暂不支持的支付商通道']);
                break;
            case 2:
                $sendSms = (new JoinPay())->agreementUnSign($newSms);
                break;
            case 3:
                $newSms['uid'] = $data['uid'];
                $sendSms = (new SandPay())->agreementUnSign($newSms);
                break;
            case 4:
                $newSms['uid'] = $data['uid'];
                $sendSms = (new YsePay())->agreementUnSign($newSms);
                break;
            default:
                throw new FinanceException(['msg' => '未知支付商通道']);
        }

        $newRes = false;
        if (!empty($sendSms) && !empty($sendSms['res'])) {
            $update['contract_status'] = 3;
            $newRes = UserBankCard::update($update, ['card_sn' => $data['card_sn'], 'contract_status' => 1, 'status' => 1]);
        } else {
            throw new ServiceException(['msg' => $sendSms['errorMsg'] ?? '出错啦~']);
        }
        return judge($newRes);
    }

    /**
     * @title  获取用户信息
     * @param string $uid
     * @return array
     */
    public function getUserInfo(string $uid)
    {
        $userInfo = User::where(['uid' => $uid, 'status' => 1])->field('uid,phone,name,pay_pwd')->findOrEmpty()->toArray();
        if (empty($userInfo)) {
            throw new UserException();
        }
        return $userInfo;
    }

    /**
     * @title  获取新的卡编号, 因为不可重复
     * @param string $cardSn
     * @param int $number
     * @return string
     */
    public function updateCardSn(string $cardSn, int $number = 0)
    {
        $length = strlen($cardSn);
        if (empty($number)) {
            if ($length == 20) {
                $cardNewNumber = '01';
            } else {
                $cardNewNumber = sprintf("%02d", (intval(substr($cardSn, 20, 2)) + 1));
            }
        } else {
            $cardNewNumber = sprintf("%02d", $number);
        }
        $newCardSn = substr($cardSn, 0, 20) . $cardNewNumber;
        $exist = UserBankCard::where(['card_sn' => $newCardSn])->count();
        if (!empty($exist)) {
            $finally = $this->updateCardSn($cardSn, (intval($cardNewNumber) + 1));
        } else {
            $finally = $newCardSn;
        }
        return $finally;
    }

    //银行卡类型
    private static $cardType = [
        'CC' => '信用卡',
        'DC' => '储蓄卡',
    ];
    //银行卡中文名
    private static $bankInfo = [
        "ABC"       => "中国农业银行",
        "ARCU"      => "安徽省农村信用社",
        "ASCB"      => "鞍山银行",
        "AYCB"      => "安阳银行",
        "BANKWF"    => "潍坊银行",
        "BGB"       => "广西北部湾银行",
        "BHB"       => "河北银行",
        "BJBANK"    => "北京银行",
        "BJRCB"     => "北京农村商业银行",
        "BOC"       => "中国银行",
        "BOCD"      => "承德银行",
        "BOCY"      => "朝阳银行",
        "BOD"       => "东莞银行",
        "BODD"      => "丹东银行",
        "BOHAIB"    => "渤海银行",
        "BOJZ"      => "锦州银行",
        "BOP"       => "平顶山银行",
        "BOQH"      => "青海银行",
        "BOSZ"      => "苏州银行",
        "BOYK"      => "营口银行",
        "BOZK"      => "周口银行",
        "BSB"       => "包商银行",
        "BZMD"      => "驻马店银行",
        "CBBQS"     => "城市商业银行资金清算中心",
        "CBKF"      => "开封市商业银行",
        "CCB"       => "中国建设银行",
        "CCQTGB"    => "重庆三峡银行",
        "CDB"       => "国家开发银行",
        "CDCB"      => "成都银行",
        "CDRCB"     => "成都农商银行",
        "CEB"       => "中国光大银行",
        "CGNB"      => "南充市商业银行",
        "CIB"       => "兴业银行",
        "CITIC"     => "中信银行",
        "CMB"       => "招商银行",
        "CMBC"      => "中国民生银行",
        "COMM"      => "交通银行",
        "CQBANK"    => "重庆银行",
        "CRCBANK"   => "重庆农村商业银行",
        "CSCB"      => "长沙银行",
        "CSRCB"     => "常熟农村商业银行",
        "CZBANK"    => "浙商银行",
        "CZCB"      => "浙江稠州商业银行",
        "CZRCB"     => "常州农村信用联社",
        "DAQINGB"   => "龙江银行",
        "DLB"       => "大连银行",
        "DRCBCL"    => "东莞农村商业银行",
        "DYCB"      => "德阳商业银行",
        "DYCCB"     => "东营市商业银行",
        "DZBANK"    => "德州银行",
        "EGBANK"    => "恒丰银行",
        "FDB"       => "富滇银行",
        "FJHXBC"    => "福建海峡银行",
        "FJNX"      => "福建省农村信用社联合社",
        "FSCB"      => "抚顺银行",
        "FXCB"      => "阜新银行",
        "GCB"       => "广州银行",
        "GDB"       => "广东发展银行",
        "GDRCC"     => "广东省农村信用社联合社",
        "GLBANK"    => "桂林银行",
        "GRCB"      => "广州农商银行",
        "GSRCU"     => "甘肃省农村信用",
        "GXRCU"     => "广西省农村信用",
        "GYCB"      => "贵阳市商业银行",
        "GZB"       => "赣州银行",
        "GZRCU"     => "贵州省农村信用社",
        "H3CB"      => "内蒙古银行",
        "HANABANK"  => "韩亚银行",
        "HBC"       => "湖北银行",
        "HBHSBANK"  => "湖北银行黄石分行",
        "HBRCU"     => "河北省农村信用社",
        "HBYCBANK"  => "湖北银行宜昌分行",
        "HDBANK"    => "邯郸银行",
        "HKB"       => "汉口银行",
        "HKBEA"     => "东亚银行",
        "HNRCC"     => "湖南省农村信用社",
        "HNRCU"     => "河南省农村信用",
        "HRXJB"     => "华融湘江银行",
        "HSBANK"    => "徽商银行",
        "HSBK"      => "衡水银行",
        "HURCB"     => "湖北省农村信用社",
        "HXBANK"    => "华夏银行",
        "HZCB"      => "杭州银行",
        "HZCCB"     => "湖州市商业银行",
        "ICBC"      => "中国工商银行",
        "JHBANK"    => "金华银行",
        "JINCHB"    => "晋城银行JCBANK",
        "JJBANK"    => "九江银行",
        "JLBANK"    => "吉林银行",
        "JLRCU"     => "吉林农信",
        "JNBANK"    => "济宁银行",
        "JRCB"      => "江苏江阴农村商业银行",
        "JSB"       => "晋商银行",
        "JSBANK"    => "江苏银行",
        "JSRCU"     => "江苏省农村信用联合社",
        "JXBANK"    => "嘉兴银行",
        "JXRCU"     => "江西省农村信用",
        "JZBANK"    => "晋中市商业银行",
        "KLB"       => "昆仑银行",
        "KORLABANK" => "库尔勒市商业银行",
        "KSRB"      => "昆山农村商业银行",
        "LANGFB"    => "廊坊银行",
        "LSBANK"    => "莱商银行",
        "LSBC"      => "临商银行",
        "LSCCB"     => "乐山市商业银行",
        "LYBANK"    => "洛阳银行",
        "LYCB"      => "辽阳市商业银行",
        "LZYH"      => "兰州银行",
        "MTBANK"    => "浙江民泰商业银行",
        "NBBANK"    => "宁波银行",
        "NBYZ"      => "鄞州银行",
        "NCB"       => "南昌银行",
        "NHB"       => "南海农村信用联社",
        "NHQS"      => "农信银清算中心",
        "NJCB"      => "南京银行",
        "NXBANK"    => "宁夏银行",
        "NXRCU"     => "宁夏黄河农村商业银行",
        "NYBANK"    => "广东南粤银行",
        "ORBANK"    => "鄂尔多斯银行",
        "PSBC"      => "中国邮政储蓄银行",
        "QDCCB"     => "青岛银行",
        "QLBANK"    => "齐鲁银行",
        "SCCB"      => "三门峡银行",
        "SCRCU"     => "四川省农村信用",
        "SDEB"      => "顺德农商银行",
        "SDRCU"     => "山东农信",
        "SHBANK"    => "上海银行",
        "SHRCB"     => "上海农村商业银行",
        "SJBANK"    => "盛京银行",
        "SPABANK"   => "平安银行",
        "SPDB"      => "上海浦东发展银行",
        "SRBANK"    => "上饶银行",
        "SRCB"      => "深圳农村商业银行",
        "SXCB"      => "绍兴银行",
        "SXRCCU"    => "陕西信合",
        "SZSBK"     => "石嘴山银行",
        "TACCB"     => "泰安市商业银行",
        "TCCB"      => "天津银行",
        "TCRCB"     => "江苏太仓农村商业银行",
        "TRCB"      => "天津农商银行",
        "TZCB"      => "台州银行",
        "URMQCCB"   => "乌鲁木齐市商业银行",
        "WHCCB"     => "威海市商业银行",
        "WHRCB"     => "武汉农村商业银行",
        "WJRCB"     => "吴江农商银行",
        "WRCB"      => "无锡农村商业银行",
        "WZCB"      => "温州银行",
        "XABANK"    => "西安银行",
        "XCYH"      => "许昌银行",
        "XJRCU"     => "新疆农村信用社",
        "XLBANK"    => "中山小榄村镇银行",
        "XMBANK"    => "厦门银行",
        "XTB"       => "邢台银行",
        "XXBANK"    => "新乡银行",
        "XYBANK"    => "信阳银行",
        "YBCCB"     => "宜宾市商业银行",
        "YDRCB"     => "尧都农商行",
        "YNRCC"     => "云南省农村信用社",
        "YQCCB"     => "阳泉银行",
        "YXCCB"     => "玉溪市商业银行",
        "ZBCB"      => "齐商银行",
        "ZGCCB"     => "自贡市商业银行",
        "ZJKCCB"    => "张家口市商业银行",
        "ZJNX"      => "浙江省农村信用社联合社",
        "ZJTLCB"    => "浙江泰隆商业银行",
        "ZRCBANK"   => "张家港农村商业银行",
        "ZYCBANK"   => "遵义市商业银行",
        "ZZBANK"    => "郑州银行",
    ];

    public static function getBankList()
    {
        return self::$bankInfo;
    }

    public static function getBankNameList()
    {
        return array_values(self::$bankInfo);
    }

    public static function getBankImg($bank)
    {
        return "https://apimg.alipay.com/combo.png?d=cashier&t={$bank}";
    }

    /**
     * @title 根据银行卡号获取银行卡名称
     * @param string $cardNum 银行卡号
     * @return array
     */
    public function getBankInfoByBankCard(string $cardNum)
    {
        $cardNum = str_replace(' ', '', trim($cardNum));
        if (empty($cardNum)) {
            return ['validated' => false];
        }
        if (!is_numeric($cardNum)) {
            return ['validated' => false];
        }
        $result = file_get_contents("https://ccdcapi.alipay.com/validateAndCacheCardInfo.json?_input_charset=utf-8&cardNo={$cardNum}&cardBinCheck=true");
        $result = json_decode($result);

        if (!$result->validated) {
            $bankInfo = array(
                'validated' => $result->validated
            );
        } else {
            $bankInfo = array(
                'validated'    => $result->validated,              // 是否验证通过
                'bank'         => $result->bank,                        // 银行代码
                'bankName'     => isset(self::$bankInfo[$result->bank]) ? self::$bankInfo[$result->bank] : '未知银行',   // 银行名称
//                'bankImg'      => self::getBankImg($result->bank),
                //暂时不需要银行卡图片, 加快速度
                'bankImg'      => null,
                'cardType'     => $result->cardType,                // 银行卡类型, CC 信用卡, DC 储蓄卡
                'cardTypeName' => self::$cardType[$result->cardType],
            );
        }

        return $bankInfo;
    }

}