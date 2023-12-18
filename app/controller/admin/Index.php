<?php

namespace app\controller\admin;

use AlibabaCloud\Client\AlibabaCloud;
use AlibabaCloud\Client\Clients\RsaKeyPairClient;
use AlibabaCloud\Client\Exception\ClientException;
use AlibabaCloud\Client\Exception\ServerException;
use AlibabaCloud\Sts\Sts;
use app\api\controller\v1\PayCallback;
use app\BaseController;
use app\controller\api\v1\Pt;
use app\controller\callback\v1\JoinPayCallback;
use app\controller\callback\v1\SandPayCallback;
use app\controller\callback\v1\ShippingCallback;
use app\lib\BaseException;
use app\lib\constant\PayConstant;
use app\lib\exceptions\AuthException;
use app\lib\exceptions\CrowdFundingActivityException;
use app\lib\exceptions\FinanceException;
use app\lib\exceptions\OpenException;
use app\lib\exceptions\PpylException;
use app\lib\exceptions\ServiceException;
use app\lib\exceptions\ShipException;
use app\lib\exceptions\UserException;
use app\lib\job\Divide;
use app\lib\job\DividePrice;
use app\lib\job\MemberDivide;
use app\lib\job\PpylAuto;
use app\lib\job\PpylLottery;
use app\lib\job\TeamMemberUpgrade;
use app\lib\job\TimerForPpyl;
use app\lib\job\TimerForPpylOrder;
use app\lib\job\TimerForPtOrder;
use app\lib\models\Activity;
use app\lib\models\ActivityGoods;
use app\lib\models\AdvanceCardDetail;
use app\lib\models\AfterSale as AfterSaleModel;
use app\lib\models\BalanceDetail;
use app\lib\models\Behavior as BehaviorModel;
use app\lib\models\Chapter;
use app\lib\models\City;
use app\lib\models\CommonModel;
use app\lib\models\CrowdfundingBalanceDetail;
use app\lib\models\CrowdfundingDelayRewardOrder;
use app\lib\models\CrowdfundingFuseRecord;
use app\lib\models\CrowdfundingPeriod;
use app\lib\models\Device;
use app\lib\models\DeviceOrder;
use app\lib\models\Divide as DivideModel;
use app\lib\models\File;
use app\lib\models\GoodsImages;
use app\lib\models\GoodsSku;
use app\lib\models\GoodsSkuVdc;
use app\lib\models\GoodsSpu;
use app\lib\models\GrowthValueDetail;
use app\lib\models\HealthyBalanceConver;
use app\lib\models\HealthyBalanceDetail;
use app\lib\models\IntegralDetail;
use app\lib\models\Member;
use app\lib\models\Member as MemberModel;
use app\lib\models\MemberIncentives;
use app\lib\models\MemberTest;
use app\lib\models\MemberVdc;
use app\lib\models\OpenConnect;
use app\lib\models\Order as OrderModel;
use app\lib\models\Order;
use app\lib\models\OrderCoupon;
use app\lib\models\OrderGoods;
use app\lib\models\PostageTemplate;
use app\lib\models\PpylBalanceDetail;
use app\lib\models\PpylOrder;
use app\lib\models\PpylReward;
use app\lib\models\PpylWaitOrder;
use app\lib\models\PtActivity;
use app\lib\models\PtGoodsSku;
use app\lib\models\PtOrder;
use app\lib\models\RechargeLink;
use app\lib\models\RechargeLinkDetail;
use app\lib\models\RechargeTopLinkRecord;
use app\lib\models\RefundDetail;
use app\lib\models\Reward;
use app\lib\models\RewardFreed;
use app\lib\models\ShipOrder;
use app\lib\models\ShippingAddress;
use app\lib\models\ShippingDetail;
use app\lib\models\ShippingDetail as ShippingDetailModel;
use app\lib\models\ShopCart;
use app\lib\models\SystemConfig;
use app\lib\models\TeamMember as TeamMemberModel;
use app\lib\models\TeamMemberVdc;
use app\lib\models\TeamPerformance;
use app\lib\models\User;
use app\lib\models\UserCertificate;
use app\lib\models\UserCoupon;
use app\lib\models\UserExtraWithdraw;
use app\lib\models\UserTest;
use app\lib\models\Withdraw;
use app\lib\models\WxConfig;
use app\lib\models\ZhongShuKePay;
use app\lib\services\AdminLog;
use app\lib\services\AfterSale;
use app\lib\services\AlibabaCos;
use app\lib\services\AlibabaOSS;
use app\lib\services\AreaDivide;
use app\lib\services\Auth;
use app\lib\services\BaiduCensor;
use app\lib\services\BankCard;
use app\lib\services\Code;
use app\lib\services\CodeBuilder;
use app\lib\services\CrowdFunding;
use app\lib\services\CrowdFunding as CrowdFundingService;
use app\lib\services\FileUpload;
use app\lib\services\GrowthValue;
use app\lib\services\Incentives;
use app\lib\services\JoinPay;
use app\lib\services\KuaiShangPay;
use app\lib\services\Live;
use app\lib\services\Mail;
use app\lib\services\Mqtt;
use app\lib\services\Office;
use app\lib\services\Open;
use app\lib\services\Pay;
use app\lib\services\Ppyl;
use app\lib\services\PropagandaReward;
use app\lib\services\SandPay;
use app\lib\services\Ship;
use app\lib\services\Shipping;
use app\lib\services\Team;
use app\lib\services\TeamMember as TeamMemberService;
use app\lib\services\TeamMember;
use app\lib\services\TencentDataAnalysis;
use app\lib\services\Token;
use app\lib\services\UserSummary;
use app\lib\services\Wx;
use app\lib\services\WxPayService;
use app\lib\services\YsePay;
use app\lib\subscribe\Timer;
use app\lib\validates\UserPrivacy;
use Endroid\QrCode\QrCode;
use PHPMailer\PHPMailer\PHPMailer;
use PhpOffice\PhpSpreadsheet\Exception;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use think\db\Query;
use think\facade\Console;
use function Stringy\create;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Log;
use think\facade\Request;
use think\queue\Job;
use think\View;
use think\facade\Queue;
use Swoole\Server;
use Darabonba\OpenApi\Models\Config;

class Index extends BaseController
{
    protected $middleware = [
        'checkAdminToken',
    ];
    protected $topUserTeamStartNumber = [];
    protected $lastMemberCount = 0;
    protected $notTopUserStartUser = 0;

    public function index()
    {
        return '<style type="text/css">*{ padding: 0; margin: 0; } div{ padding: 4px 48px;} a{color:#2E5CD5;cursor: pointer;text-decoration: none} a:hover{text-decoration:underline; } body{ background: #fff; font-family: "Century Gothic","Microsoft yahei"; color: #333;font-size:18px;} h1{ font-size: 100px; font-weight: normal; margin-bottom: 12px; } p{ line-height: 1.6em; font-size: 42px }</style><div style="padding: 24px 48px;"> <h1>:) </h1><p> ThinkPHP V6<br/><span style="font-size:30px">13载初心不改 - 你值得信赖的PHP框架</span></p></div><script type="text/javascript" src="https://tajs.qq.com/stats?sId=64890268" charset="UTF-8"></script><script type="text/javascript" src="https://e.topthink.com/Public/static/client.js"></script><think id="eab4b9f840753f8e7"></think>';
    }

    /**
     * @title  测试连接
     * @return string
     */
    public function connect()
    {
        $mysql = WxConfig::where(['type' => 1])->value('create_time');
        cache('testRedis', $mysql, '10');
        $redis = cache('testRedis');
        return returnMsg(true);
    }

    public function testC()
    {
        set_time_limit(0);
        ini_set('memory_limit', '3072M');
        ini_set('max_execution_time', '0');
        $orderSn = $this->request->param('order_sn');
        $orderInfo = Order::where(['order_sn' => $orderSn])->findOrEmpty()->toArray();
        $res = false;
        if (!empty($orderSn)) {
            $res = (new CrowdFundingService())->completeCrowFunding( ['dealType' => 1, 'order_sn' => $orderSn, 'orderInfo' => $orderInfo]);
//            $res = Queue::push('app\lib\job\CrowdFunding', ['dealType' => 1, 'order_sn' => $orderSn, 'orderInfo' => $orderInfo], config('system.queueAbbr') . 'CrowdFunding');
        }
        return returnData($res);
    }

    public function testB()
    {
        set_time_limit(0);
        ini_set('memory_limit', '3072M');
        ini_set('max_execution_time', '0');
        $data = $this->requestData;
        $res = false;
//        //判断N-3轮是否能够成功释放奖金
//        $res = (new CrowdFundingService())->checkSuccessPeriod(['dealType' => 3, 'activity_code' => 'C20220613425297020', 'round_number' => 1, 'period_number' => 89, 'searType' => 2]);
//        dump($res);die;
        $res = Queue::push('app\lib\job\CrowdFunding', ['dealType' => 3, 'activity_code' => $data['activity_code'], 'round_number' => $data['round_number'], 'period_number' => $data['period_number'], 'searType' => 2], config('system.queueAbbr') . 'CrowdFunding');
        return returnData($res);
    }

    public function test7(array $data = [])
    {
        $phone = $this->request->param('phone') ?? ($data['phone'] ?? null);
        if (empty($phone)) {
            return false;
        }
        $user = [];
        $user = User::where(['phone' => $phone, 'status' => 1])->select()->toArray();
        if (count($user) != 2) {
            throw new UserException(['msg' => '此手机号码信息异常']);
        }
        $oldUser = [];
        $nowUser = [];
        foreach ($user as $key => $value) {
            if ($value['old_sync'] == 5) {
                if ($value['id'] >= 32750 && $value['id'] <= 180024) {
                    $oldUser = $value;
                    continue;
                } else {
                    throw new UserException(['msg' => '虽然是待同步的老用户信息,但是数据区块不对跑到了系统原用户的区块!']);
                }
            }
            if ($value['old_sync'] == 2) {
                if ($value['id'] < 32750 || $value['id'] > 180024) {
                    $nowUser = $value;
                    continue;
                } else {
                    throw new UserException(['msg' => '虽然是不确定的用户信息,但是数据区块不对,跑到了导入用户的区块!']);
                }
            }

        }
        if (empty($oldUser) || empty($nowUser)) {
//            throw new UserException(['msg'=>'两个信息缺少一个都不行,请调试分析吧']);
            return '两个信息缺少一个都不行,请调试分析吧';
        }
        $dirUser = [];
        $dirUser = Member::where(['link_superior_user' => $oldUser['uid'], 'status' => 1])->column('uid');
        $uid = array_column($user, 'uid');
        $member = Member::where(['uid' => $uid, 'status' => 1])->select()->toArray();
        if (count($member) != count($user)) {
//            throw new UserException(['msg'=>'会员两个信息缺少一个都不行,如果新用户没有可以先指定哦']);
            return '会员两个信息缺少一个都不行,如果新用户没有可以先指定哦';
        }
        foreach ($member as $key => $value) {
            if ($value['uid'] == $oldUser['uid']) {
                $oldMember = $value;
            }
            if ($value['uid'] == $nowUser['uid']) {
                $nowMember = $value;
            }
        }
        if ($nowMember['level'] != $oldMember['level']) {
//            throw new UserException(['msg'=>'两个会员等级不一致,无法继续']);
            return '两个会员等级不一致,无法继续';
        }

        $oldUserUpdate['id'] = $nowUser['id'];
        $oldUserUpdate['address'] = '导入数据同步给原系统用户,原始id为' . $oldUser['id'];
        $oldUserUpdate['old_sync'] = 3;
        $oldUserUpdate['status'] = -1;
        $nowUserUpdate['id'] = $oldUser['id'];
        $nowUserUpdate['old_sync'] = 1;
        $nowUserUpdate['link_superior_user'] = $oldUser['link_superior_user'];

        $oldMemberUpdate['id'] = $nowMember['id'];
        $oldMemberUpdate['status'] = -1;
        $nowMemberUpdate['id'] = $oldMember['id'];
        $nowMemberUpdate['link_superior_user'] = $oldMember['link_superior_user'];
//        dump($oldUser);
//        dump($nowUser);
//        dump($oldMember);
//        dump($nowMember);
//        dump($oldUserUpdate);
//        dump($nowUserUpdate);
//        dump($oldMemberUpdate);
//        dump($nowMemberUpdate);
//        if($oldUser['id'] == '180103' || $nowUser['id'] == '180103'){
//            dump($phone);
//            dump(123123);die;
//        }
        $DBRes = Db::transaction(function () use ($oldUser, $nowUser, $oldUserUpdate, $nowUserUpdate, $dirUser, $oldMemberUpdate, $nowMemberUpdate) {
            $NUIdDB = Db::name('user')->where(['uid' => $nowUser['uid']])->update(['id' => 189999]);
            $OUDB = Db::name('user')->where(['uid' => $oldUser['uid']])->update($oldUserUpdate);
            $NUDB = Db::name('user')->where(['uid' => $nowUser['uid']])->update($nowUserUpdate);
            if (!empty($dirUser)) {
                $DUDB = User::update(['link_superior_user' => $nowUser['uid']], ['uid' => $dirUser]);
            }


            $NMIdDB = Db::name('member')->where(['uid' => $nowUser['uid']])->update(['id' => 169999]);
            $OMDB = Db::name('member')->where(['uid' => $oldUser['uid']])->update($oldMemberUpdate);
            $NMDB = Db::name('member')->where(['uid' => $nowUser['uid']])->update($nowMemberUpdate);
            if (!empty($dirUser)) {
                $DMDB = Member::update(['link_superior_user' => $nowUser['uid']], ['uid' => $dirUser]);
            }

            return true;
        });
        return true;
        dump('好了');
        die;
    }


    //查看某个人的下级是否有购买商品
    public function test5()
    {
        //同步熔本期的众筹活动所有订单到发货订单
        $syncOrder['searCrowdFunding'] = true;
        $syncOrder['order_type'] = 6;
        if (!empty($DBRes['orderGoods'] ?? [])) {
            $syncOrder['start_time'] = "2023-01-16 00:00:00";
        } else {
            $syncOrder['start_time'] = "2023-01-18 00:00:00";
        }
        $syncOrder['end_time'] = "2023-01-18 23:59:59";
        $syncOrder['searCrowdKey'] = "C202209241980911899-1-58";
        $test = (new ShipOrder())->sync($syncOrder);
        return returnData($test);
//        dump($test);die;
//        //立马判断是否可以升级
//        $upgradeQueue = Queue::push('app\lib\job\MemberUpgrade', ['uid' => ''], config('system.queueAbbr') . 'MemberUpgrade');
        //判断N-3轮是否能够成功释放奖金
//        $res = (new CrowdFundingService())->checkSuccessPeriod(['dealType' => 3, 'activity_code' => 'C20220613425297020', 'round_number' => 1, 'period_number' => 89, 'searType' => 2]);
        $url = sprintf('https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid=%s&secret=%s', "wxca137825906d0201", "38edf47204d312130f86474e66b00e1a");
        dump(curl_get($url));die;
//        $data['periodList'][0]['crowd_code'] = 'C202211256710961399';
//        $data['periodList'][0]['crowd_round_number'] = 2;
//        $data['periodList'][0]['crowd_period_number'] = 1;
//        $test = (new CrowdFunding())->crowdFusePlanDivide($data);
//        dump($test);die;
//        $pWhere[] = ['status', '=', 1];
//        $pWhere[] = ['grant_status', 'in', [2,3]];
//        $pWhere[] = ['last_total_price', '>', 0];
//        $fuseRecordList = CrowdfundingFuseRecord::withSum('detail','price')->where($pWhere)->buildSql();
//        $orderUserUid = Order::where(['crowd_key'=>'C202211256710961399-2-1'])->field('DISTINCT uid')->buildSql();
//        $list = Db::query("( SELECT *,(SELECT SUM(`price`) AS think_sum FROM `sp_crowdfunding_fuse_record_detail` `sum_table` WHERE  `status` = 1  AND ( `sum_table`.`order_sn` =sp_crowdfunding_fuse_record.order_sn )) AS `detail_sum` FROM `sp_crowdfunding_fuse_record` WHERE  `status` = 1  AND `grant_status` IN (2,3)  AND `last_total_price` > '0' and uid in $orderUserUid");
//        dump("( SELECT *,(SELECT SUM(`price`) AS think_sum FROM `sp_crowdfunding_fuse_record_detail` `sum_table` WHERE  `status` = 1  AND ( `sum_table`.`order_sn` =sp_crowdfunding_fuse_record.order_sn )) AS `detail_sum` FROM `sp_crowdfunding_fuse_record` WHERE  `status` = 1  AND `grant_status` IN (2,3)  AND `last_total_price` > '0' and uid in $orderUserUid )");
////        dump($fuseRecordList);die;
//        $map[]  = ['device_sn','=','862167052941204'];
//        $map[]  = ['create_time','>=','1669651200'];
//        $list = DeviceOrder::where($map)->select()->toArray();
//        dump($list);die;
//        $orderInfo['order_sn'] = 'D202211280339018591';
//        $orderInfo['device_sn'] = '862167052941204';
//        foreach ($list as $pkey => $pvalue) {
//            $test[] = Queue::push('app\lib\job\Auto', ['order_sn' => $pvalue['order_sn'], 'device_sn' => $pvalue['device_sn'], 'autoType' => 6], config('system.queueAbbr') . 'Auto');
//        }
//        $test = Queue::push('app\lib\job\Auto', ['order_sn' => $orderInfo['order_sn'], 'device_sn' => $orderInfo['device_sn'], 'autoType' => 6], config('system.queueAbbr') . 'Auto');
//        dump($test);die;
//        $test = (new Team())->divideTeamOrderForExp(['order_sn'=>$orderSn,'orderInfo'=>['uid'=>'c9gVqPFvXm','order_type'=>6]]);
//        dump($test);die;
        $map[] = ['arrival_status', '=', 2];
        $map[] = ['create_time', '>=', 1669737600];
        $map[] = ['status', '=', 1];
        $map[] = ['is_exp', '=', 1];
//        $map[] = ['remark', '=', '体验中心奖励'];
        $map[] = ['team_shareholder_level', '>', 0];
//        $map[] = ['level', '=', 0];
        $map[] = ['real_divide_price', '=', 0];
//        $map[] = ['vdc', '=', '0.005'];
//        $map[] = ['order_sn', '=', '202211220413393969'];
        $divideInfo = DivideModel::where($map)->page(1,500)->select()->toArray();

        foreach ($divideInfo as $pkey => $pvalue) {
            DivideModel::update(['level'=>4,'vdc'=>'0.005','divide_price'=>priceFormat($pvalue['total_price'] * 0.005),'real_divide_price'=>priceFormat($pvalue['total_price'] * 0.005)],['id'=>$pvalue['id']]);
        }
        dump($divideInfo);die;
        $exist = [];
        foreach ($divideInfo as $pkey => $pvalue) {
            if(!isset($exist[$pvalue['order_sn']])){
                $exist[$pvalue['order_sn']] = $pvalue;
                $existId[] = $pvalue['id'];
            }else{
                $notexist[] = $pvalue['id'];
            }
        }
        dump(count($existId));
        if(!empty($existId)){
            DivideModel::update(['team_shareholder_level'=>0],['id'=>$existId,'status'=>1]);
        }

//        if(!empty($notexist)){
//            DivideModel::update(['remark'=>'团队股东奖励'],['id'=>$notexist,'status'=>1]);
//        }
        dump($notexist);die;
        dump(12313);die;
//        dump($divideInfo[0]);
//        dump($divideInfo[100]);
//        dump($divideInfo[1000]);
//        dump(array_unique(array_column($divideInfo,'link_uid')));
        foreach (array_unique(array_column($divideInfo,'link_uid')) as $key => $value) {
            if(empty($userLink[$value] ?? null)){
                $otherMapSql = '';
                $searUid = null;
                $searUid = $value;
                $userLink[$value] = Db::query("SELECT u2.uid,u2.phone as user_phone,u2.vip_level as level,u2.status,u2.link_superior_user,u1.LEVEL AS divide_level,u2.exp_level,u2.team_shareholder_level FROM(SELECT @id c_ids,(SELECT @id := GROUP_CONCAT( link_superior_user ) FROM sp_user,(SELECT @id := " . "'" . $searUid . "'" . ",@l := 0 ) b WHERE status in (1,2) AND FIND_IN_SET( uid, @id COLLATE utf8mb4_unicode_ci )) p_ids,@l := @l + 1 AS LEVEL FROM sp_user WHERE @id IS NOT NULL ) u1 JOIN sp_user u2 ON u1.c_ids = u2.uid COLLATE utf8mb4_unicode_ci WHERE u2.status = 1 and (u2.exp_level > 0 or u2.team_shareholder_level > 0) " . $otherMapSql . " ORDER BY u1.LEVEL ASC;");
            }

        }

        foreach ($userLink as $pkey => $pvalue) {
            $teamExistLevel = [];
            foreach ($pvalue as $key => $value) {
                if ($value['team_shareholder_level'] > 0) {
                    if (empty($teamExistLevel[$value['team_shareholder_level']] ?? false)) {
                        $finally[$pkey] = $value['uid'];
                        $value['is_team_shareholder'] = true;
                        $newLinkUserParent[] = $value;
                        $teamUserExpLevel = $value['team_shareholder_level'] ?? 4;
                        $teamExistLevel[$value['team_shareholder_level']] = $value;
                        break;
                    }
                }
            }
        }

        $newData = [];
        foreach ($divideInfo as $key => $value) {
            if(!empty($finally[$value['link_uid']] ?? null)){
                unset($value['id']);
                unset($value['update_time']);
                $value['remark'] = '团队股东奖励';
                $value['level'] = 3;
                $value['team_shareholder_level'] = 4;
                $value['create_time'] = strtotime($value['create_time']);
                $value['link_uid'] = $finally[$value['link_uid']];
                $newData[$key] = $value;
            }
        }
        if(!empty($newData)){
            dump($newData);
            (new DivideModel())->saveAll($newData);
        }
        dump(count($divideInfo));die;
        Queue::push('app\lib\job\Auto', ['order_sn' => 'D202208191659175601', 'device_sn' => 'sdfsdf', 'autoType' => 6],config('system.queueAbbr') . 'Auto');
        $test = (new Team())->divideTeamOrderForExp(['order_sn'=>$orderSn,'orderInfo'=>$orderInfo]);
        dump($test);die;
        $searUid = 'test5';
        $otherMapSql  = '';
        $linkUserParent = Db::query("SELECT u2.uid,u2.phone as user_phone,u2.vip_level as level,u2.status,u2.link_superior_user,u1.LEVEL AS divide_level,u2.exp_level FROM(SELECT @id c_ids,(SELECT @id := GROUP_CONCAT( link_superior_user ) FROM sp_user WHERE status in (1,2) AND FIND_IN_SET( uid, @id COLLATE utf8mb4_unicode_ci )) p_ids,@l := @l + 1 AS LEVEL FROM sp_user,(SELECT @id := " . "'" . $searUid . "'" . ",@l := 0 ) b WHERE @id IS NOT NULL ) u1 JOIN sp_user u2 ON u1.c_ids = u2.uid COLLATE utf8mb4_unicode_ci WHERE u2.status = 1 and u2.exp_level > 0" . $otherMapSql . " ORDER BY u1.LEVEL ASC;");

        if (count($linkUserParent) >= 1) {
            $newLinkUserParent = [];
            foreach ($linkUserParent as $key => $value) {
//                if ($value['exp_level'] > 0) {
//                    $newLinkUserParent[] = $value;
//                    $UserExpLevel = $value['exp_level'] ?? 4;
//                    break;
//                }
                if ($value['exp_level'] > 0) {
                    if(empty($existLevel[$value['exp_level']] ?? null)){
                        $newLinkUserParent[] = $value;
                        $existLevel[$value['exp_level']] = $value;
                    }
                }
            }
            if (empty($newLinkUserParent)) {
                return $this->recordError($log, ['msg' => '筛选后查无可记录的有效上级']);
            }
            $linkUserParent = $newLinkUserParent;
        }
        dump($linkUserParent);die;
        $map[]  = ['create_time','>=',1667836800];
        $map[]  = ['uid','=','J863LxOVNj'];
        $map[]  = ['check_status','=',1];
        $map[]  = ['status','=',1];
        $map[]  = ['withdraw_type','=',7];
//        $map[]  = ['order_sn','=','W202211077806671046'];
        $test = Withdraw::with(['crow'])->where($map)
//            ->page(1,1000)
            ->select()->toArray();
        if(empty($test)){
            dump('没有了');die;
        }
        dump($test);
        foreach ($test as $key => $value) {
            if (!empty($value['crow'] ?? [])) {
                $moreOrder[]  = $value['order_sn'];
            }
        }
        dump($moreOrder);die;
        CrowdfundingBalanceDetail::update(['status'=>-1],['order_sn'=>$moreOrder]);
        dump($moreOrder);
        dump(count($moreOrder));die;
//        dump($test);die;
//        $notBalanceOrder = CrowdfundingBalanceDetail::where(['order_sn'=>array_unique(array_column($test,'order_sn'))])->column('order_sn');
//        dump(count($notBalanceOrder));die;
//        dump(count($notBalanceOrder));
        $number = 0;
        foreach ($test as $key => $value) {
            if(empty($value['crow'] ?? [])){
                $crowdBalanceDetail[$number]['uid'] = $value['uid'];
                $crowdBalanceDetail[$number]['order_sn'] = $value['order_sn'];
                $crowdBalanceDetail[$number]['belong'] = 1;
                $crowdBalanceDetail[$number]['type'] = 2;
                $crowdBalanceDetail[$number]['price'] = '-' . $value['total_price'];
                $crowdBalanceDetail[$number]['change_type'] = 6;
                $crowdBalanceDetail[$number]['remark'] = '提现';
                $number++;
            }
        }
        if(!empty($crowdBalanceDetail ?? [])){
            $t = (new CrowdfundingBalanceDetail())->saveAll($crowdBalanceDetail);
        };
        dump($crowdBalanceDetail ?? []);die;
        dump(12313);die;
//        User::where(['uid'=>'x6Ym634FEV'])->inc('area_balance',1)->update();
//        $map4[] = ['uid', '=', 'DcZsCzGCSn'];
        $map4[] = ['area_price', '<>', 0];
        $map4[] = ['status', 'in', [1]];
        $map4[] = ['withdraw_type', '=', 5];
        $map4[] = ['uid', '=', 'JGcsNbyrEE'];
        $map4[] = ['check_status', 'in', [1]];
//        $map4[] = ['uid', 'in', ['yVpVbqyPm6','r5qtzPZFcI','WS79XD34CE','jomOigtm7c','JGcsNbyrEE','BVoZRuNq4s','DcZsCzGCSn']];
        $allDivideList = Withdraw::where($map4)->select()->toArray();
        dump($allDivideList);
        $order = BalanceDetail::where(['order_sn'=>array_unique(array_column($allDivideList,'order_sn')),'status'=>1,'change_type'=>[21]])->field('uid,price,order_sn')->select()->toArray();
        dump($order);
        $orderXX = [];
        foreach ($order as $item) {
            if($item['price'] == 0){
                $orderXX[] = $item['order_sn'];
            }
        }
        dump($order_sn ?? []);
        $t = BalanceDetail::where(['uid'=>array_unique(array_column($allDivideList,'uid')),'status'=>1,'change_type'=>[20,21,33,34]])->field('uid,sum(price) as area_price')->group('uid')->select()->toArray();
        dump($t);
        dump(array_sum(array_column($allDivideList,'area_price')));die;
        Db::transaction(function() use ($allDivideList){

//            $allUid = BalanceDetail::where(['uid'=>array_unique(array_column($allDivideList,'uid')),'status'=>1,'change_type'=>[20,21,33,34]])->field('uid,sum(price) as area_price')->group('uid')->select()->toArray();
//            foreach ($allUid as $key=>$value) {
//                User::update(['area_price' => $value['area_price']], ['uid' => $value['uid'], 'status' => 1]);
//            }
//            dump($allUid);die;
            $allPrice = 0;
            foreach ($allDivideList as $value) {
                if(!empty($value['area_price'])){
                    BalanceDetail::update(['price'=>'-'.$value['area_price']],['uid'=>$value['uid'],'change_type'=>21,'order_sn'=>$value['order_sn'],'status'=>1]);
                    $allPrice += $value['area_price'];
                }
            }
            if(!empty(doubleval($allPrice))){
                User::where(['uid'=>$value['uid'],'status'=>1])->dec('area_balance',$allPrice)->update();
            }
            dump($allPrice);
        });

        dump($allDivideList);die;
        foreach ($allDivideList as $value) {
            $userInfo[$value['uid']] = $value['user_phone'];
            if(!isset($allDivideInfo[$value['uid']])){
                $allDivideInfo[$value['uid']] = 0;
            }
            if(!isset($allTeamInfo[$value['uid']])){
                $allTeamInfo[$value['uid']] = 0;
            }
            if(!isset($allAreaInfo[$value['uid']])){
                $allAreaInfo[$value['uid']] = 0;
            }
            $allDivideInfo[$value['uid']] += $value['total_price'];
            $allTeamInfo[$value['uid']] += $value['team_price'];
            $allAreaInfo[$value['uid']] += $value['area_price'];
        }
        dump($allDivideInfo);
        dump($allTeamInfo);
        dump($allAreaInfo);
        $allUid = array_unique(array_keys($allDivideInfo));

//        foreach ($allUid as $item) {
//            $res[$item] = Db::query('select *,(total_income - total_withdraw)as chajia,CEILING(total_withdraw_area_price * 0.93) as shuihou  from ((select sum(price) as total_income FROM sp_balance_detail where (type = 1 or change_type in(24,28,30,34)) and uid = "'.$item.'"  and status = 1 ) a , (select sum(price) as total_withdraw,uid FROM sp_withdraw where status = 1 and uid = "'.$item.'" and check_status in (1,3) and withdraw_type = 5 ) b,(select sum(area_price) as total_withdraw_area_price FROM sp_withdraw where status = 1 and uid = "'.$item.'" and check_status in (1,3) and withdraw_type = 5 ) d,(select name,phone FROM sp_user where  uid = "'.$item.'"  ) c)');
//        }
//        $this->exportExcel(array_values($res),1,1);
//        dump($res);die;
        $allUserInfo = User::where(['uid'=>$allUid])->column('name','uid');
        $balanceList = BalanceDetail::where(['uid'=>$allUid,'status'=>1,'type'=>1])->field('change_type,uid,price')->select()->toArray();

        foreach ($balanceList as $value) {
            if(!isset($allBalanceInfo[$value['uid']])){
                $allBalanceInfo[$value['uid']] = 0;
            }
            if(in_array($value['change_type'],[20])){
                if(!isset($allBalanceAreaInfo[$value['uid']])){
                    $allBalanceAreaInfo[$value['uid']] = 0;
                }
                $allBalanceAreaInfo[$value['uid']] += $value['price'];
            }
            if(in_array($value['change_type'],[16])){
                if(!isset($allBalanceTeamInfo[$value['uid']])){
                    $allBalanceTeamInfo[$value['uid']] = 0;
                }
                $allBalanceTeamInfo[$value['uid']] += $value['price'];
            }

            $allBalanceInfo[$value['uid']] += $value['price'];
        }
        dump($allBalanceInfo);
        foreach ($allBalanceInfo as $key => $value) {
            foreach ($allDivideInfo as $aKey => $aValue) {
                if($key == $aKey){
                    $all[$key] = '用户'.$userInfo[$key].' '.$allUserInfo[$key].' 总收入 '. $value.', 总提现：'.$aValue.'计算得出亏 '.priceFormat(($value - $aValue));
                    $kui[$key] =   priceFormat(($value - $aValue));
                }

            }

        }
        asort($kui);
        dump($all);
        dump($kui);
        dump(array_sum($kui));

        foreach ($kui as $key => $value) {
        $finalltKui[$key] = '用户'.$userInfo[$key].' '.$allUserInfo[$key].'金额 '.$value;
        }
         dump($finalltKui);
        $kuiAll = 0;
        foreach ($kui as $key => $value) {
            if($value <= 0){
                $kuiAll += $value;
            }
        }
        dump($kuiAll);
        die;
        $param =  array (
            'uid' => 'SxClLFT6xZ',
            'avatarUrl' => 'https://oss-cm.andyoudao.cn/wxAvatar/ocZpe42anPlAWVK8DGfsDzKwRfvE.png',
            'name' => '陈映君',
            'version' => 'v1',
        );
        foreach ($param as $item) {
           dump( checkParam($item));
        }
        dump(checkParam('https://oss-cm.andyoudao.cn/wxAvatar/ocZpe42anPlAWVK8DGfsDzKwRfvE.png'));
        dump(checkParam('SxClLFT6xZ'));
        dump(123123);
        die;
//        $list = \app\lib\models\Divide::where(['crowd_code'=>'C202211082311372621','arrival_status'=>1])->select()->toArray();
        $test = array (
            'uid' => '8KD6HcBqIO',
            'avatarUrl' => 'https://oss-cm.andyoudao.cn/wxAvatar/ocZpe47qpxdpW4k3CdwJ48whaiVU.png',
            'name' => '逆风飞翔',
            'version' => 'v1',
        );
        foreach ($test as $key => $value) {
            dump(checkParam($value));
        }

        dump(1231322);die;
        $map[] = ['', 'exp', Db::raw('(a.device_divide_type = 2 or a.device_divide_type is null)')];
        $map[] = ['a.type','=',7];
        $list = \app\lib\models\Divide::alias('a')->where($map)->buildSql();
        dump($list);die;
        $test = (new AreaDivide())->divideForDevice(['order_sn'=>'D202211125698724660','grantNow'=>true]);
//        $test = (new \app\lib\services\TeamDivide())->divideForTopUser(['order_sn'=>'202211087497801118','searType'=>1]);
        dump($test);die;
//        foreach ($list as $key => $value) {
//            if(!in_array($value['link_uid'],['uBwSu4UoKF','8q3Mn4dWDb'])){
//                CrowdfundingBalanceDetail::update(['status' => -1], ['uid' => $value['link_uid'], 'order_sn' => $value['order_sn']]);
//                TeamPerformance::update(['status' => -1], ['link_uid' => $value['link_uid'], 'order_sn' => $value['order_sn']]);
//                User::where(['uid' => $value['link_uid']])->dec('crowd_balance',$value['real_divide_price'])->update();
//                \app\lib\models\Divide::update(['status'=>-1,'arrival_status'=>3],['id' => $value['id'], 'order_sn' => $value['order_sn']]);
//            }
//        }
        dump(12313);die;
//        $map[] = ['order_sn', '=', '202210255357193513'];

//        $test = (new CrowdFunding())->checkExpireUndonePeriod();
//        dump($test);die;
//        $map[] = ['shipping_address_detail', 'like', '%"City":"贵阳市","Area":"白云区"%'];
//        $map[] = ['order_status', '=', 3];
//        $map[] = ['create_time', '<', 1666921265];
//        $map[] = ['delivery_time', '>=', 1662357306];
//        (new CrowdFunding())->checkExpireUndonePeriodDeal(['activity_code' => 'C20220613425297020', 'round_number' => 1, 'period_number' => 72]);
//        dump(12313);die;
//        $list = \app\lib\models\Order::where($map)->select()->toArray();
        dump((new CrowdFunding())->crowdFusePlanDivide(['periodList'=>['0'=>['crowd_code'=>'C202211082311372621','crowd_round_number'=>2,'crowd_period_number'=>4]]]));die;
        dump(1231313);die;
        dump(count($list));
        dump(array_sum(array_column($list,'real_pay_price')));die;
//        dump($list[0] ?? []);
//        foreach ($list as $key => $value) {
//            $areaDivideQueue = Queue::push('app\lib\job\AreaDividePrice', ['order_sn' => $value['order_sn'], 'searNumber' => 1], config('system.queueAbbr') . 'AreaOrderDivide');
//        }
        dump(123131);die;
        foreach ($list as $key => $value) {
            $newList[$key] = $value;
            $newList[$key]['vdc'] = $value['vdc'] * 0.5;
            $newList[$key]['divide_price'] = priceFormat($value['divide_price'] * 0.5);
            $newList[$key]['real_divide_price'] = $newList[$key]['divide_price'];
            $newList[$key]['is_allot'] = 1;
            $newList[$key]['allot_scale'] = 0.5;
            $newList[$key]['type'] = 9;
            if($value['type'] == 7){
                $newList[$key]['allot_type'] = 2;
                $newList[$key]['remark'] = '股票奖励(来自区代)';
                if (!empty($value['crowd_code'] ?? null)) {
                    $newList[$key]['remark'] = '福利活动股票奖励(来自区代)';
                }
            }else{
                $newList[$key]['allot_type'] = 1;
                if($value['is_grateful'] == 1){
                    $newList[$key]['remark'] = '股票奖励感恩奖';
                    if (!empty($value['crowd_code'] ?? null)) {
                        $newList[$key]['remark'] = '福利活动股票奖励感恩奖';
                    }
                }else{
                    $newList[$key]['remark'] = '股票奖励(来自团队业绩)';
                    if (!empty($value['crowd_code'] ?? null)) {
                        $newList[$key]['remark'] = '福利活动股票奖励(来自团队业绩)';
                    }
                }
            }
            $newList[$key]['coder_remark'] = '切割';
            $newList[$key]['create_time'] = '1667059199';
            $newList[$key]['update_time'] = '1667059199';
            unset($newList[$key]['id']);
        }
        dump(count($newList));
        if(!empty($list)){
            $res = Db::transaction(function() use ($list,$newList){
                foreach ($list as $key => $value) {
                    $update = [];
                    $update['vdc'] = $value['vdc'] * 0.5;
                    $update['divide_price'] = priceFormat($value['divide_price'] * 0.5);
                    $update['real_divide_price'] = $newList[$key]['divide_price'];
                    $update['is_allot'] = 1;
                    $update['allot_scale'] = 0.5;
                    \app\lib\models\Divide::update($update, ['id' => $value['id'], 'arrival_status' => 2, 'order_sn' => $value['order_sn'], 'status' => 1, 'is_allot' => 2]);
                }
                (new \app\lib\models\Divide())->saveAll($newList);
                return true;
            });
        }


        dump(count($newList));
        dump(12313);die;
        $map2[] = ['type','=',7];
        $map2[] = ['arrival_status','=',2];
        $map2[] = ['is_allot','=',1];
        $list2 = \app\lib\models\Divide::where($map2)->select()->toArray();
        dump(count($list2));
        Db::transaction(function() use ($list,$list2){
            foreach ($list2 as $key => $value) {
                if(!in_array($value['order_sn'],array_column($list,'order_sn'))){
                    dump($value);
                    continue;
                }
//                \app\lib\models\Divide::update(['vdc' => $value['vdc'] * 2, 'divide_price' => priceFormat($value['divide_price'] * 2), 'real_divide_price' => priceFormat($value['real_divide_price'] * 2), 'is_allot' => 2], ['id' => $value['id'], 'type' => 7, 'order_sn' => $value['order_sn'], 'arrival_status' => 2]);
            }
        });

        dump(1111111111);;die;
//        dump((new TeamMember())->memberUpgrade('jWc6gBIfAQ',false));
        //同步熔本期的众筹活动所有订单到发货订单
        $test = (new Mqtt())->publish(['type'=>$this->request->param('type') ?? 1]);
        return returnMsg($test);
        dump(json_decode($json,true)['params']);die;
        dump(key([6=>1]));;die;
        $res = Queue::push('app\lib\job\CrowdFunding', ['order_sn' => '202209059854955826', 'dealType' => 1],config('system.queueAbbr') . 'CrowdFunding');
        return returnData($res);
//        dump(explode('/', '/SUB/8621670528255555')[2]);die;
        $test = (new Mqtt())->publish(['type'=>0,'power_number'=>[1=>1,2=>0]]);
        dump($test);die;
        $orderInfo = Order::where(['order_sn'=>$orderSn])->findOrEmpty()->toArray();
        $orderGoods = OrderGoods::where(['order_sn'=>$orderSn])->select()->toArray();
//        $test = (new Ship())->noShippingCode(['order_sn' => [$orderInfo['order_sn']]]);
        $test = (new Pay())->checkExchangeAutoShip(['orderInfo'=>$orderInfo,'orderGoods'=>$orderGoods,'operType'=>3]);
////        $test =  (new Pay())->checkExchangeOrderGoods(['orderInfo' => $orderInfo, 'orderGoods' => $orderGoods]);
//        $test = (new \app\lib\services\Ship())->userConfirmReceiveGoods(['order_sn' =>$orderSn, 'notThrowError' => true, 'uid' => 'x6Ym634FEV']);
        dump($test);die;
//        try {
//            throw new ServiceException(['msg'=>'1231231312313']);
//        }catch (BaseException $baseException) {
//            return json(['error_code' => 30010, 'msg' => '业务处理异常']);
//        } catch (\Exception $thinkException) {
//            return json(['error_code' => 30010, 'msg' => '接口异常, 请联系运维']);
//        }
        dump(111111);die;
//        dump(strlen('c34388007325cde8f8d395c10abbdbab'));die;
        dump(Queue::push('app\lib\job\Auto', ['order_sn' => 'D202208191659175601', 'device_sn' => 'sdfsdf', 'autoType' => 6],config('system.queueAbbr') . 'Auto'));die;
        dump((new \app\lib\models\Device())->divideForDevice(['order_sn'=>'D202208197012464499']));die;
        $res = (new CrowdFundingService())->completeCrowFunding(['order_sn'=>'202208174648589219']);
//        Queue::Push( 'app\lib\job\CrowdFunding', ['order_sn' => '202208135944576386', 'orderInfo' => [], 'dealType' => 1, 'operateType' => 1], config('system.queueAbbr') . 'CrowdFunding');
        return returnMsg($res);
        $list = Db::query("select a.*,b.name,b.phone from (select count(id) as number, order_sn,price as price, uid FROM sp_crowdfunding_balance_detail where  status = 1 and change_type = 3  GROUP BY order_sn,change_type,uid
) a LEFT JOIN sp_user b on a.uid = b.uid where a.number >= 2 and a.order_sn is not null");
        if (!empty($list)) {
//            $res = Db::transaction(function () use ($list) {
//                foreach ($list as $key => $value) {
//                    $balance[$key]['uid'] = $value['uid'];
//                    $balance[$key]['order_sn'] = $value['order_sn'];
//                    $balance[$key]['type'] = 2;
//                    $balance[$key]['change_type'] = 7;
//                    $balance[$key]['price'] = '-' . $value['price'];
//                    $balance[$key]['remark'] = '异常返本金, 系统扣除';
//                    User::where(['uid' => $value['uid'], 'status' => 1])->dec('crowd_balance', $value['price'])->update();
//                }
////                dump($balance);die;
//                (new CrowdfundingBalanceDetail())->saveAll($balance);
//                return true;
//            });
        }
        dump(12312313);die;
        //19397738231
        dump((new TeamMemberService())->becomeMember(['uid'=>'tYSoPquk8P','user_phone'=>'19397738231']));
//        $test = (new AdvanceCardDetail())->sendAdvanceBuyCard(['send_type'=>6,'userList'=>[['uid'=>'7g6pCDjLA6','number'=>20]]]);
//        $list = TeamPerformance::where(['status'=>1,'record_team'=>2,'type'=>1,'order_type'=>6])->column('order_uid','order_sn');
//        $list = array_unique($list);
//        foreach ($list as $key => $value) {
//            dump((new \app\lib\services\TeamDivide())->getTopUserRecordTeamPerformance(['order_sn'=>$key,'uid'=>$value]));
//        }
//        $map[] = ['','exp',Db::raw('crowd_balance > 0')];
//
//        $userLists = User::where($map)->field('crowd_balance,uid,crowd_fronzen_balance')->select()->toArray();
//        foreach ($userLists as $key => $value) {
//            $userList[$value['uid']] = $value['crowd_balance'] + $value['crowd_fronzen_balance'];
//        }
//        $userBalanceDetaild = CrowdfundingBalanceDetail::where(['status'=>1,'uid'=>array_keys($userList)])->field('uid,sum(price) as total_price')->group('uid')->select()->toArray();
//        foreach ($userBalanceDetaild as $key => $value) {
//            if(doubleval($value['total_price']) != doubleval($userList[$value['uid']])){
//                dump($value,$userList[$value['uid']]);
//            }
//        }
        dump(123131);
        die;
//        dump($test);die;
        $orderSn = '光明区';
        $map[] = ['', 'exp', Db::raw('LOCATE("' . $orderSn . '",`shipping_address_detail`) > 0 and delivery_time > 1658455285')];
        $map[] = ['order_status','=',3];
        $map[] = ['pay_status','=',2];
        $orderList = Order::where($map)->column('order_sn');
//        foreach ($orderList as $key => $value) {
//            $res[$value] = judge((new \app\lib\services\AreaDivide())->divideForTopUser(['order_sn' => $value, 'searType' => 1]));
//       }
        dump($res ?? []);die;
        $allOrderList = Order::where(['order_type'=>6,'order_status'=>[2,3,8]])->select()->toArray();
        foreach ($allOrderList as $key => $value) {
            $crowdKey = explode('-',$value['crowd_key']);
            $allOrderInfo[$crowdKey[0]][$crowdKey[2]][$value['uid']] = 1;

            if(!isset($userAllOrderCount[$value['user_phone']])){
                $userAllOrderCount[$value['user_phone']] = 0;
            }
            $userAllOrderCount[$value['user_phone']] += 1;
        }
        foreach ($allOrderInfo as $key => $value) {
            foreach ($value as $cKey => $cValue) {
                if($cKey == 1){
                    $allOrderInfos[$key][$cKey]['newNumber'] = count($cValue);
                }else{
                    $allOrderInfos[$key][$cKey]['newNumber'] = count(array_diff_key($cValue,$value[$cKey - 1]));
                }
            }
        }
        arsort($userAllOrderCount);
        dump($userAllOrderCount);
        dump($allOrderInfos);die;
//        $oMap[] = ['order_type', '=', 6];
//        $oMap[] = ['pay_status', 'in', [2]];
//        $oMap[] = ['order_status', 'in', [2, 3, 6, 8]];
//        $oMap[] = ['uid', 'in', ['x0GwAKBlJI','9K5GupldKu','x6Ym634FEV']];
//        $existOtherOrder = Order::where($oMap)->field('order_sn,uid,count(id) as all_order_number,sum(real_pay_price) as all_order_price')->group('uid')->buildSql();
//        dump($existOtherOrder);die;
        $crowdGoods['C202206122603672769-1-1'] = ['C202206122603672769-1-1'];
        $crowdGoods['C202206122603672769-1-2'] = ['C202206122603672769-1-1'];
        $gWhere[] = ['status', '=', 1];
        $gWhere[] = ['pay_status', '=', 2];
        $periodOrderPrice = OrderGoods::where(function ($query) use ($crowdGoods) {
            $number = 0;
            foreach ($crowdGoods as $key => $value) {
                $crowdQ = explode('-', $key);
                ${'where' . ($number + 1)}[] = ['crowd_code', '=', $crowdQ[0]];
                ${'where' . ($number + 1)}[] = ['crowd_round_number', '=', $crowdQ[1]];
                ${'where' . ($number + 1)}[] = ['crowd_period_number', '=', $crowdQ[2]];
                $number++;
            }

            for ($i = 0; $i < count($crowdGoods); $i++) {
                $allWhereOr[] = ${'where' . ($i + 1)};
            }
            $query->whereOr($allWhereOr);
        })->where($gWhere)->field('crowd_code,crowd_round_number,crowd_period_number,sum(real_pay_price - total_fare_price) as crowd_total_price')->group('crowd_code,crowd_round_number,crowd_period_number')->select()->toArray();
        dump($periodOrderPrice);die;
//        $test = (new \app\lib\services\Divide())->divideForTopUser(['order_sn'=>'202104221336486513','searType'=>1]);

//        $skusn = '4888004901';
//        $map[] = ['a.sku_sn','=',$skusn];
//        $map[] = ['a.status','=',1];
//        $map[] = ['a.pay_status','=',2];
//        $map[] = ['a.shipping_status','=',2];
//        $map[] = ['a.create_time','>',1615184664];
//        $orderGoods = OrderGoods::alias('a')->join('sp_order b','a.order_sn = b.order_sn','left')->where($map)->select()->toArray();
//
//        $uid = array_unique(array_filter(array_column($orderGoods,'uid')));
//        dump($uid);die;
//        $uid = ['tRZ28OnM2f','w9r4sfzd76','kQ9frjAKdx','2B1zhQq9ci','SgPxmdbfdw','t2z9qV6fIH'];

//        dump($map);
//        dump($uid ?? []);
        dump((new TeamMemberService())->checkUserLevel(['uid'=>'xsxtLBlyaX']));die;
        dump('确认一下咯');
//        $test = (new Timer())->onGoodsStatusTimer();
        $userPhone = "13510429482";
        $userPhone = explode(',', $userPhone);

        dump(count($userPhone));
        dump(count(array_unique($userPhone)));
        die;
//        dump(array_diff(array_unique($userPhone),$userPhone));die;
        $list = User::where(['phone' => $userPhone, 'status' => 1])->column('phone', 'uid');
        $notAllow = ['13510429482' => 7, '13980415941' => 2, '13910506050' => 2, '13069083336' => 2, '18565739274' => 2, '18696211128' => 2, '13925052909' => 2, '13988144998' => 2, '13609433557' => 2];
        foreach ($userPhone as $key => $value) {
            if (!in_array($value, $list)) {
                dump($value);
            }
        }

        $receiveRes = Db::transaction(function () use ($list) {
            $CouponService = (new \app\lib\services\Coupon());
            foreach ($list as $key => $value) {
                $uid[$key] = 1;
            }

//            $uid = ['2l5y0RsHvw' => 4, 'sGIpMUKIv8' => 1, 'DcDROGefYK' => 1, 'FodAlI4KyP' => 1, 'siXN5ZT5YV' => 3, 'VmeYJ8Rcij' => 2];
            $coupon = ['1003202107015450002' => 7];
            foreach ($coupon as $cKey => $cValue) {
                foreach ($uid as $key => $value) {
                    for ($i = 1; $i <= ($cValue * ($value ?? 1)); $i++) {
                        $receiveRes[$cKey][$key][$i] = $CouponService->userReceive(['uid' => $key, 'code' => $cKey, 'notThrowError' => true]);
                    }
                }
            }
            return $receiveRes;
        });

        dump($receiveRes ?? []);
        dump('成功');
        die;
    }

    //补全没有计算分润的订单
    public function test4()
    {
        set_time_limit(0);
        ini_set('memory_limit', '3072M');
        ini_set('max_execution_time', '0');
        $aa = Db::query("select phone,uid from sp_user where phone in (SELECT
	`phone` 
FROM
	`sp_user` 
WHERE
	`create_time` >= 1624687169 
	AND  openid is null and old_sync = 5) and old_sync = 2 and openid is not null and create_time >= 1624687169");

        if (!empty($aa)) {
            $aaPhone = array_column($aa, 'phone');
//            $aaPhone = ['19971498370'];
            $order = Order::where(['user_phone' => $aaPhone])->field('uid,user_phone,order_sn')->group('user_phone')->select()->toArray();
            if (!empty($order)) {
                foreach ($order as $key => $value) {
                    if (in_array($value['user_phone'], $aaPhone)) {
                        $orderS[] = $value;
                    }
                }
            }
//            $uid = User::where(['uid'=>array_column($aa,'uid'),'old_sync'=>5])->field('vip_level,count(*) as number')->group('vip_level')->select()->toArray();
//
//            $memberLink = Member::where(['link_superior_user'=>$uid,'status'=>1])->group('link_superior_user')->column('link_superior_user');
//            dump($memberLink ?? []);
        }
        dump($orderS);
        die;
        if (!empty($orderS ?? [])) {

            $oldAccount = User::where(['phone' => array_column($orderS, 'user_phone'), 'old_sync' => 5])->field('uid,phone,vip_level,link_superior_user')->select()->toArray();
//            dump($oldAccount);die;
            if (!empty($oldAccount)) {
                foreach ($oldAccount as $key => $value) {
                    $oldAccountInfo[$value['phone']]['vip_level'] = $value['vip_level'];
                    $oldAccountInfo[$value['phone']]['link_superior_user'] = $value['link_superior_user'];
                }
            }

            $service = (new \app\lib\services\Member());
            foreach ($orderS as $key => $value) {
                if (!empty($oldAccountInfo[$value['user_phone']] ?? [])) {
                    $me['uid'] = $value['uid'];
                    $me['link_user'] = $oldAccountInfo[$value['user_phone']]['link_superior_user'];
                    $me['level'] = $oldAccountInfo[$value['user_phone']]['vip_level'];
//                    $m = $service->assignUserLevel($me);
                    $Ces[$value['user_phone']] = $this->test7(['phone' => $value['user_phone']]);
                }
            }
        }


//        dump($aa ?? []);
        dump($orderS ?? []);
        dump($Ces ?? []);
        die;
        die;
        die;
        die;
        die;
        $orderCoupon = OrderCoupon::where(['used_status' => 2])->select()->toArray();

        foreach ($orderCoupon as $key => $value) {
            $orderCouponInfo[$value['order_sn']] = $value;
        }
        $orderSn = array_unique(array_column($orderCoupon, 'order_sn'));
        $orderGoods = OrderGoods::where(['order_sn' => $orderSn])->select()->toArray();
        foreach ($orderGoods as $key => $value) {
            if (!isset($orderGoodsCouponDis[$value['order_sn']])) {
                $orderGoodsCouponDis[$value['order_sn']] = 0;
            }
            $orderGoodsCouponDis[$value['order_sn']] += $value['coupon_dis'] ?? 0;
        }
        foreach ($orderGoodsCouponDis as $key => $value) {
            if ($value != $orderCouponInfo[$key]['used_amount']) {
                dump($key);
            }
        }
        die;
        $map[] = ['level', '=', 3];
        $map[] = ['growth_value', '<', 2];
        $map[] = ['status', '=', 1];
        $map[] = ['create_time', '>=', 1616428800];
        $memberList = Member::where($map)->column('growth_value', 'uid');
        $userList = User::where(['uid' => array_keys($memberList)])->column('growth_value', 'uid');
        dump($memberList);
        dump($userList);
        foreach ($userList as $key => $value) {
            if ($value != $memberList[$key]) {
                $new[$key] = $value;
            }
        }

        foreach ($new as $key => $value) {
            $res[] = Member::update(['growth_value' => $value], ['status' => 1, 'uid' => $key])->getData();
        }
        dump($res);
        die;
        die;
        set_time_limit(0);
        ini_set('memory_limit', '3072M');
        ini_set('max_execution_time', '0');

        $orders = ['202102027281483630',
        ];
        $shipOrderList = ShipOrder::where(['order_sn' => $orders])->select()->toArray();
        //全部修改为待备货状态
//        foreach ($shipOrderList as $key => $value) {
//            ShipOrder::where(['order_sn'=>$value['order_sn']])->save(['shipping_status'=>1]);
//            if(!empty($value['parent_order_sn'])){
//                $orderSn = $value['parent_order_sn'];
//            }else{
//                $orderSn = $value['order_sn'];
//            }
//            $gRes[] = OrderGoods::where(['order_sn'=>$orderSn,'sku_sn'=>$value['goods_sku'],'status'=>1,'pay_status'=>2])->save(['shipping_status'=>1]);
//        }
        //提起售后
//        foreach ($shipOrderList as $key => $value) {
//            if(!empty($value['parent_order_sn'])){
//                $orderSn = $value['parent_order_sn'];
//            }else{
//                $orderSn = $value['order_sn'];
//            }
//            $goods = OrderGoods::where(['order_sn'=>$orderSn,'sku_sn'=>$value['goods_sku'],'status'=>1,'pay_status'=>2])->findOrEmpty()->toArray();
//            if(!empty($goods) &&  $value['order_status']==2){
//                $af['order_sn'] = $orderSn;
//                $af['type'] = 1;
//                $af['apply_reason'] = '平台退款';
//                $af['received_goods_status'] = 2;
//                $af['goods'][0]['goods_sn'] = $goods['goods_sn'];
//                $af['goods'][0]['sku_sn'] = $goods['sku_sn'];
//                $af['goods'][0]['uid'] = $value['uid'];
//                $af['goods'][0]['apply_price'] = $goods['real_pay_price'];
//                (new AfterSaleModel())->initiateAfterSale($af);
//            }
//
//        }
//        dump($shipOrderList);

        //删除售后记录
//        foreach ($shipOrderList as $key => $value) {
//            if(!empty($value['parent_order_sn'])){
//                $orderSn = $value['parent_order_sn'];
//            }else{
//                $orderSn = $value['order_sn'];
//            }
//            $gRes[] = AfterSaleModel::where(['order_sn'=>$orderSn,'sku_sn'=>$value['goods_sku'],'status'=>1])->save(['status'=>-1]);
//        }


        return returnMsg(true);
    }

    //查询订单分润情况
    public function test3()
    {
        set_time_limit(0);
        ini_set('memory_limit', '3072M');
        ini_set('max_execution_time', '0');
//        $list = \app\lib\models\Supplier::select();
//        $overTimePtOrder = ['PL202108185064179639'];
//        $res = (new TimerForPpylOrder())->overTimePtOrder($overTimePtOrder ?? []);
//        dump($res);die;
        $res = (new \app\lib\services\Ppyl())->completePpylRefund(['out_trade_no'=>'202108185250624414','out_refund_no'=>'444444']);
        dump($res);die;
        $info['content'] = '12313';
        $info['content'] = str_replace('商家提交留言: ', '', $info['content']);
        $divideService = (new \app\lib\services\Divide());
        //检查是否有存在余额明细和订单分润对不上的订单
        $map[] = ['create_time', '>=', 1624550400];
        $map[] = ['type', '=', 1];
        $ba2 = BalanceDetail::where($map)->field('order_sn,count(id) as number,sum(price) as price')->group('order_sn')->select()->each(function ($item) {
//            if($item['number'] > 1){
//                dump($item->getData());
//            }
        })->toArray();
//        $map2[] = ['create_time','>=',1624550400];
        $map2[] = ['order_sn', 'in', array_column($ba2, 'order_sn')];
        $di2 = \app\lib\models\Divide::where($map2)->field('order_sn,count(id) as number,sum(real_divide_price) as price')->group('order_sn')->select()->toArray();
        dump($ba2);
        dump($di2);
        die;
        foreach ($ba2 as $key => $value) {
            foreach ($di2 as $k => $va) {
                if ($value['order_sn'] == $va['order_sn'] && ($va['number'] != $value['number'])) {
                    dump($va);
                }
            }
        }
        dump('查完了');
        die;
//        //确认分润
//        $divideRes = $divideService->payMoneyForDivideByOrderSn('202104183401949748',['order_sn'=>'202104183401949748']);

        $user = \app\lib\models\Divide::alias('a')->where(['a.link_uid' => 'AMCob2nORB', 'a.arrival_status' => 1])->field('order_sn,sum(real_divide_price) as real_divide_price')->group('order_sn')
//            ->join('balance_detail b','a.order_sn = b.order_sn','left')->where(['a.link_uid'=>'AMCob2nORB','a.arrival_status'=>1])
//->field('a.*,b.order_sn as divide_order')
//                ->column('order_sn');
            ->select()
////        ->each(function($item){
////            if(empty($item['divide_order'])){
////                dump($item->getData());
////            }
////        })
            ->toArray();
        $ba1 = BalanceDetail::where(['uid' => 'AMCob2nORB', 'type' => 1])->field('order_sn,sum(price) as price')->group('order_sn')->select()->toArray();
        $all = 0;
        foreach ($user as $key => $value) {
            foreach ($ba1 as $k => $v) {
                if ($value['order_sn'] == $v['order_sn'] && $value['real_divide_price'] != $v['price']) {
                    dump('分润' . $value['real_divide_price'] . '-实际' . $v['price']);
                    $all += ($v['price'] - $value['real_divide_price']);
                }
            }
        }
        dump($all);
        die;
        $ba = BalanceDetail::where(['uid' => 'AMCob2nORB'])->group('order_sn')->column('order_sn');
//            ->field('order_sn')->select()->toArray();
//        dump($user);
//        dump($ba);
        foreach ($ba as $key => $value) {
            if (!in_array($value, $user)) {
                dump($value);
            }
        }
        dump(count($user));
        dump(count($ba));
        dump(5555);
        die;
        $user = BalanceDetail::alias('a')->join('divide b', 'a.order_sn = b.order_sn', 'left')->where(['a.uid' => 'AMCob2nORB'])->field('a.*,b.order_sn as divide_order')->select()->each(function ($item) {
            if (empty($item['divide_order'])) {
                dump($item->getData());
            }
        })->toArray();
        dump(12313);
        die;
        dump($user);
        die;

        //先给用户涨回对应的成长值
        $skusn = '4888004901';
        $map[] = ['a.sku_sn', '=', $skusn];
        $map[] = ['a.status', '=', 1];
        $map[] = ['a.after_status', '=', 4];
        $map[] = ['a.refund_price', '<>', 0];
        $map[] = ['a.count', '>', 1];
        $map[] = ['a.shipping_status', '=', 2];
//        $map[] = ['a.order_sn','=','202103086066483630'];
        $orderGoods = OrderGoods::alias('a')->join('sp_order b', 'a.order_sn = b.order_sn', 'left')->where($map)->select()->toArray();

        if (!empty($orderGoods)) {
            foreach ($orderGoods as $key => $value) {
                if ($value['count'] > 1) {
                    $user[$value['uid']]['growth'] = 0.09 * intval(($value['count'] - 1));
                    $user[$value['uid']]['order_sn'] = $value['order_sn'];
                }
            }

//            //补齐成长值
//            if(!empty($user)){
//                $res = Db::transaction(function() use ($user){
//                    $GrowthValueDetail = (new GrowthValueDetail());
//                    $userModel = (new User());
//                    $MemberModel = (new Member());
//                    foreach ($user as $key => $value) {
//                        //修改成长值记录
//                        $GrowthValueDetail->where(['order_sn'=>$value['order_sn'],'type'=>1,'order_uid'=>$key])->inc('growth_value',$value['growth'])->update();
//                        $GrowthValueDetail->where(['order_sn'=>$value['order_sn'],'type'=>1,'order_uid'=>$key])->inc('surplus_growth_value',$value['growth'])->update();
//
//                        //修改用户成长值
//                        $allRes[$key]['user'] = $userModel->where(['uid'=>$key])->inc('growth_value',$value['growth'])->update();
//                        $allRes[$key]['member'] = $MemberModel->where(['uid'=>$key])->inc('growth_value',$value['growth'])->update();
//                    }
//                    return $allRes;
//                });
//
//            dump($res);die;
//
//            }
//            die;
//            $afterOrder = [];
//            $count = 0;
//
//            foreach ($orderGoods as $key => $value) {
//                $afterOrder[$count]['order_sn'] = $value['order_sn'];
//                $afterOrder[$count]['uid'] = $value['uid'];
//                $afterOrder[$count]['type'] = 1;
//                $afterOrder[$count]['apply_reason'] = '误拍';
//                $afterOrder[$count]['received_goods_status'] = 2;
//                $afterOrder[$count]['goods'][0]['sku_sn'] = $value['sku_sn'];
//                $afterOrder[$count]['goods'][0]['goods_sn'] = $value['goods_sn'];
//                $afterOrder[$count]['goods'][0]['apply_price'] = $value['total_price'] - 8.8;
//                $count ++;
        }
//
//            if(!empty($afterOrder)){
//                $afterRes = Db::transaction(function() use ($afterOrder) {
//                    $afterModel = (new \app\lib\models\AfterSale());
//                    foreach ($afterOrder as $key => $value) {
//                        $afterSale[] = $afterModel->initiateAfterSale($value);
//                    }
//                    return $afterSale ?? [];
//                });
//            }
//        }
        //修改订单为发货状态
//        $allOrder = array_unique(array_filter(array_column($orderGoods,'order_sn')));
//
//        $orderRes = Db::transaction(function() use ($user,$allOrder){
//            $orderModel = (new Order());
//            $ShippingModel = (new ShipOrder());
//            foreach ($allOrder as $key => $value) {
//                $orderRes[$value]['order'] = $orderModel->where(['order_sn'=>$value])->save(['order_status'=>2]);
//                $orderRes[$value]['shipOrder'] = $ShippingModel->where(['order_sn'=>$value])->save(['order_status'=>2]);
//            }
//            return $orderRes ?? [];
//        });
//        dump($orderRes);die;
        die;
        $all['user'] = $user ?? [];
        $all['afterRes'] = $afterRes ?? [];
        return returnData($all);
//        $uid = ['hlrvIsSM6J'];
//        $uid = ['S8r0K9Iew4'];
//        $uid = ['STaT0utRYB','zSF8KmEpEI','hlrvIsYYY1','JGKO3woXNX'];
//        foreach ($uid as $key => $value) {
//            $map = [];
//            $user = (new \app\lib\services\Divide())->getTeamAllUserGroupByLevel($value, ',u2.create_time desc');
////        dump($user['allUser']['onlyUidList']);
//            //1月
////            $map[] = ['create_time','>=',1609430400];
////            $map[] = ['create_time','<=',1612108799];
//            //12月
////            $map[] = ['create_time','>=',1606752000];
////            $map[] = ['create_time','<=',1609430399];
//            $map[] = ['uid','in',$user['allUser']['onlyUidList']];
//            $map[] = ['order_status','in',[2,3,8]];
//            $allOrder[$value] = Order::where($map)->sum('total_price');
//        }
//
//        dump($allOrder);
//        dump(array_sum($allOrder));die;
////        $uid = ['LGXIrXeOkk'=>151.92];
////        $userList = User::where(['uid'=>array_keys($uid)])->field('uid,phone,growth_value,vip_level')->select()->toArray();
////        foreach ($userList as $key => $value) {
////            $user[$value['uid']] = $value;
////        }
////        $res =  Db::transaction(function () use ($user, $userList,$uid) {
////            $model = (new GrowthValueDetail());
////            foreach ($uid as $key => $value) {
////                if((string)$user[$key]['growth_value'] < (string)$value){
////                    throw new UserException(['msg' => $key . '的成长值就没有这么多,只有' . $user[$key]['growth_value'] . ', 不要扣' . $value . '这么多啦']);
////                }
////                $memberRes[] = Member::update(['growth_value'=>$value],['uid'=>$key])->toArray();
////                $userRes[] = User::update(['growth_value'=>$value],['uid'=>$key])->toArray();
////                $detail['type'] = 3;
////                $detail['uid'] = $key;
////                $detail['user_phone'] = $user[$key]['phone'];
////                $detail['user_level'] = $user[$key]['vip_level'];
////                $detail['growth_value'] = '-'.($user[$key]['growth_value'] - $value);
////                $detail['surplus_growth_value'] = 0;
////                $detail['remark'] = '系统减少';
////                $detail['arrival_status'] = 1;
////                $detail['create_time'] = time();
////                $detail['update_time'] = time();
////                if(!empty($detail['growth_value'])){
////                    $GrowthValueRes[] = $model->insert($detail);
////                }
////
////            }
////            return ['memberRes'=>$memberRes ?? [],'userRes'=>$userRes ?? [],'GrowthValueRes'=>$GrowthValueRes ?? []];
////        });
//        dump($res);die;
//        $data['out_trade_no'] = '202101205372441749';
//        $data['out_refund_no'] = 'T'.$data['out_trade_no'];
//        $data['refund_fee'] = 10;
//        $data['notify_url'] = 'http://api.test.mten.andyoudao.cn/callback/v1/wx/memberCallback';
//        $test = (new JoinPay())->refund($data);
//        dump($test);die;
////        $test = (new \app\lib\services\Divide())->divideForTopUser(['order_sn'=>'202101198757083127','searType'=>1]);
////        dump($test);die;
//        return json(Request::server());
//        dump(date('Y-m-01 00:00:00',strtotime('-1 month')));
//        dump (date("Y-m-d 23:59:59", strtotime(-date('d').'day')));
//        die;
//        $oMap[] = ['create_time','>=',1609898400];
//        $oMap[] = ['pay_status','=',1];
//        $orderList = Order::where($oMap)->select()->toArray();
//        if(!empty($oMap)){
//            foreach ($orderList as $key => $value) {
//
//            }
//        }
//        $province = '广东省广西壮族自治区广州市番禺区厦滘启梦创业广场2B150美TEN广东省广州市';
//        if(empty($province)) {
//            return '';
//        }
//
//        $checkArr = ["省","市","自治区","特别行政区"];
//
//        for($i = 0; $i < count($checkArr); $i++) {
//            if(strpos($province, $checkArr[$i]) === false) {
//                continue;
//            } else {
//                dump($checkArr[$i]);
//                dump(strlen($checkArr[$i]));
//                dump((strpos($province, $checkArr[$i]) + $checkArr[$i]));
//                $province = mb_strcut($province, 0, (strpos($province, $checkArr[$i]) + 3));
////                break;
//            }
//        }
//
//        dump($province);die;
////       for($i=1;$i<=5;$i++){
////
////        }
////        Db::transaction(function() use ($goodsSku){
//////            dump('这是新的');
//////            $info = $goodsSku->where(['goods_sn'=>'0054000634575','sku_sn'=>'3457581201'])->value('id');
//////            $goodsSku->where(['id'=>$info])->lock(true)->findOrEmpty()->toArray();
//////               $tes = $goodsSku->where(['goods_sn'=>'0054000634575','sku_sn'=>'3457581201'])->inc('stock',intval(1))->update();
////
////        });
//        $tes = $goodsSku->where(['goods_sn'=>'0054000634575','sku_sn'=>'3457581201'])->inc('stock',intval(1))->update();
////            sleep(10);
//        dump($tes);
//        dump('结束了');
////        $list = User::where(['status'=>1])->limit(10)->select()->toArray();
////        dump($list);
///


    }

    public function test6()
    {
//        $data['activity_sn'] = Request::param('activity_sn');
//        $test = (new Ppyl())->completePpylOrder(['activity_sn' => $data['activity_sn']]);
//        return returnData($test);
//        dump(User::where(['status'=>1])->withoutField('id')->buildSql());die;
        set_time_limit(0);
        ini_set('memory_limit', '3072M');
        ini_set('max_execution_time', '0');
        $data = $this->requestData;
        $orderSn = $data['order_sn'];
//        $skuSn = $data['sku_sn'];
        if ($data['type'] == 1) {
            $orderSns = [$orderSn];
            foreach ($orderSns as $key => $value) {
                $test[] = (new Pay())->completePay(['out_trade_no' => $value, 'transaction_id' => 999999]);
            }
        } elseif ($data['type'] == 2) {
            $out_refund_no = ['666666'];
            foreach ($out_refund_no as $key => $value) {
                $test = (new Pay())->completeRefund(['out_trade_no' => $orderSn, 'out_refund_no' => $value]);
            }
        }elseif ($data['type'] == 3){
             $orderSns = [$orderSn];
            foreach ($orderSns as $key => $value) {
                $test[] = (new Pay())->completeMember(['out_trade_no' => $value, 'transaction_id' => 999999]);
            }
        }


//        $aa = array (
//            'order_sn' => '202012256663744223',
//            'cancel_type' => 2,
//            'price_part' => '15.50',
//        );
//        $test = (new GrowthValue())->cancelGrowthValue(2,$aa);
        return returnData($test);

//        return json(['msg'=>'测试一下dev分支的提交']);
//        $list = (new GoodsSku())->with(['category','shopSkuVdc'])->where(['sku_sn'=>'5874209301','status'=>1])->field('goods_sn,sku_sn,title,sale_price,member_price,fare,stock,title,image,specs,postage_code')->lock(true)->buildSql();
//        $test = (new Pay())->completePay(['out_trade_no'=>'202012157319296739','transaction_id'=>'test123123123']);
//        $test = (new JoinPay())->closeOrder(['order_sn'=>'202012213518291065']);
        dump($test);
        die;
        $goodsSku = (new GoodsSku());

        Db::transaction(function () use ($goodsSku) {
            for ($i = 1; $i <= 5; $i++) {
                $info = $goodsSku->where(['goods_sn' => '0054000634575', 'sku_sn' => '3457581201'])->value('id');
                $goodsSku->where(['id' => $info])->lock(true)->findOrEmpty()->toArray();
//               $tes = $goodsSku->where(['goods_sn'=>'0054000634575','sku_sn'=>'3457581201'])->inc('stock',intval(1))->update();
                $tes = $goodsSku->where(['goods_sn' => '0054000634575', 'sku_sn' => '3457581201'])->dec('stock', intval(1))->update();
                sleep(3);
                dump($tes);
            }
        });


        dump('结束了');
        die;
//        if (intval(date('H', time())) == 16) {
//            $list = MemberIncentives::where(['status' => 1])->field('level,settlement_date')->select()->toArray();
//
//            if (!empty($list)) {
//                foreach ($list as $key => $value) {
//                    //判断今天是否为该等级的结算/清算日,是则开始进入清算/结算流程
//                    if (intval(date('d', time())) == $value['settlement_date']) {
//                        $needArrival[] = $value['level'];
//                    }
//                }
//
//                if (!empty($needArrival)) {
//                    $service = (new Incentives());
//                    if (count($needArrival) > 1) {
//                        $res = 1231231;
//                    } else {
//                        $res = 123123131312312313123123123;
//                    }
//
//                }
//            }
//        }
        dump($list);
        die;
//        $list = User::where(['status'=>1])->field('aaaa')->limit(10)->select()->toArray();
//        if(!empty($list)){
//            dump($list);
//            dump(12313);
//        }else{
//            dump('关闭日志咯');
////            Log::close('file');
//        }

//        $res = (new \app\lib\services\Log())->record(['msg'=>'jhshshshsh']);
        $list = Queue::push('app\lib\job\TimerForPtOrder', ['activity_sn' => [], 'type' => 1], config('system.queueAbbr') . 'TimeOutPtOrder');
        dump($list);
        die;
//        $tes = (new AfterSale())->autoVerify(json_decode($data,1));
        $refundRes = md5('qq111111');
        dump($refundRes);
        die;
        //dump(trim('202012028684382583'));die;
        $test = base64_encode('watermark/watermark.png');
        $test = str_replace('+', '-', $test);
        $test = str_replace('/', '_', $test);
        $test = str_replace('=', '', $test);
        dump($test);
        die;
        $ship[0]['order_sn'] = '202011296706663155';
        $ship[0]['company'] = '中通快递';
        $ship[0]['company_code'] = 'zhongtong';
        $ship[0]['shipping_code'] = '123123131';
        $test = (new \app\lib\services\Ship())->ship(['order_ship' => $ship]);
        $goodsTitle = '测试商品';
        $value['company'] = '中通快递';
        $value['shipping_code'] = '1231313';
        //组装消息模板通知数组
        $template['uid'] = 'FIUxvsvGpr';
        $template['openid'] = 'oTNxM5NwGI-HE4XW-iXVKRmVAliM';
        $template['type'] = 'ship';
        $template['page'] = '/page/index/index?redirect=%2Fpages%2Freturn-detail%2Freturn-detail%3Fsn%3D' . '123133213313123131';
        //$goodsTitle = implode(',',array_column($value['goods'],'title'));
        $length = mb_strlen($goodsTitle);
        if ($length >= 17) {
            $goodsTitle = mb_substr($goodsTitle, 0, 17) . '...';
        }
        $template['template'] = ['character_string1' => '1231313', 'thing6' => $goodsTitle, 'thing20' => trim($value['company']), 'character_string5' => $value['shipping_code'], 'thing13' => '您的商品已发货,感谢您对' . config('system.projectName') . '的支持'];

//        $template['uid'] = 'FIUxvsvGpr';
//        $template['openid'] = 'oTNxM5NwGI-HE4XW-iXVKRmVAliM';
//        $template['type'] = 'orderSuccess';
//        $template['template'] = ['character_string1' => '123123213', 'amount2' => '36.66', 'thing3' => '测试商品', 'time5' => '2020-11-28 21:54:30', 'thing4' => '感谢您对' . config('system.projectName') . '的支持'];
        $test = (new Wx())->sendTemplate($template, 1);
        dump($test, '成功了');
        die;

//       $test = \app\lib\models\Divide::where($map)->field('sum(purchase_price) as purchase_price,sum(real_divide_price) as real_divide_price')->findOrEmpty()->toArray();
        //$test = \app\lib\models\Divide::where($map)->select()->toArray();
        dump($test);
        die;
        //重新计算团队人数,仅计算直推----start
//        $allUser = User::where(['status'=>1])->select()->toArray();
//        foreach ($allUser as $key => $value) {
//            $number[$value['uid']] = 0;
//        }
//        foreach ($allUser as $key => $value) {
//            if(!empty($value['link_superior_user'])){
//                $number[$value['link_superior_user']] += 1;
//            }
//        }
//
//        foreach ($allUser as $key => $value) {
//            $all[$key]['uid'] = $value['uid'];
//            $all[$key]['team_number'] = $number[$value['uid']];
//        }
//        dump(count($all));
//        foreach ($all as $key => $value) {
//            if(empty($value['team_number'])){
//                unset($all[$key]);
//            }
//        }
//        dump(count($all));
        //重新计算团队人数,仅计算直推----end

        //重新计算团队业绩
        //$allDivide = \app\lib\models\Divide::where(['status'=>1])->group('order_sn')->select()->toArray();
        Db::transaction(function () use ($allDivide, $all) {
//            $userModel = (new \app\lib\services\Divide());
//            foreach ($allDivide as $key => $value) {
//                $orderInfo['order_sn'] = $value['order_sn'];
//                $orderInfo['uid'] = $value['order_uid'];
//                $userRes = $userModel->getTopUserRecordTeamPerformance($orderInfo);
//            }
//            foreach ($all as $key => $value) {
//                User::update(['team_number'=>$value['team_number']],['uid'=>$value['uid']]);
//            }

        });
        dump('成功了');
        die;
        dump($allDivide);
        die;
        $map[] = ['vip_level', '<>', 0];
        $map[] = ['id', '>=', 5623];
        $map[] = ['status', '=', 1];
        $list = User::where($map)->select()->toArray();
        $res = Db::transaction(function () use ($list) {
            $userModel = (new User());
            $memberModel = (new Member());
            foreach ($list as $key => $value) {
                $growth_value = 300;
                if ($value['vip_level'] == 1) {
                    dump($value);
                    $userModel->where(['uid' => $value['uid'], 'vip_level' => $value['vip_level']])->inc('growth_value', $growth_value)->update();
                    $memberModel->where(['uid' => $value['uid'], 'level' => $value['vip_level']])->inc('growth_value', $growth_value)->update();
                }
            }
        });

        //dump($list);die;
    }
    /*
       * $content 文章内容
       * $order 要获取哪张图片，ALL所有图片，0第一张图片
       */
    function getImgs($content,$order='ALL')
    {
        $pattern ="/<img .*?src=[\'|\"](.*?(?:[\.gif|\.jpg]))[\'|\"].*?[\/]?>/";
        preg_match_all($pattern,$content,$match);
        if(isset($match[1])&&!empty($match[1])){
            if($order==='ALL'){
                return $match[1];
            }
            if(is_numeric($order)&& isset($match[1][$order])){
                return $match[1][$order];
            }
        }
        return'';
    }

    /**
     * 扫描目录下所有文件,
     * @var array
     */
    protected static $files = [];

    public static $ret = [];

    static function scan1($path, $options = [])
    {
        $options = array_merge([
            'callback'  => null, // 对查找到的文件进行操作
            'filterExt' => [], // 要过滤的文件后缀
        ], $options);

        $scanQueue = [$path];

        while (count($scanQueue) != 0) {
            $rootPath = array_pop($scanQueue);

            // 过滤['.', '..']目录
            $paths = array_filter(scandir($rootPath), function ($path) {
                return !in_array($path, ['.', '..']);
            });

            foreach ($paths as $path) {
                // 拼接完整路径
                $fullPath = $rootPath . DIRECTORY_SEPARATOR . $path;
                // 如果是目录的话,合并到扫描队列中继续进行扫描
                if (is_dir($fullPath)) {
                    array_unshift($scanQueue, $fullPath);
                    continue;
                }

                // 如果不是空,进行过滤
                if (!empty($options['filterExt'])) {
                    $pathInfo = pathinfo($fullPath);
                    $ext = $pathInfo['extension'] ?? null;
                    if (in_array($ext, $options['filterExt'])) {
                        continue;
                    }
                }

                if ($options['callback'] instanceof Closure) {
                    // 经过callback处理之后为空的数据不作处理
                    $fullPath = $options['callback']($fullPath);
                    // 返回的只要不是字符串路径,不作处理
                    if (!is_string($fullPath)) {
                        continue;
                    }
                }

                array_push(static::$files, $fullPath);
            }
        }

        return static::$files;
    }

    function scanFile($path) {
        global $result;
        $files = scandir($path);
        foreach ($files as $file) {
            if ($file != '.' && $file != '..' && $files != 'Index.php') {
                if (is_dir($path . DIRECTORY_SEPARATOR . $file)) {
                    $this->scanFile($path . DIRECTORY_SEPARATOR . $file);
                } else {
                    $fullPath = $path.DIRECTORY_SEPARATOR.$file;
                    $pathInfo = pathinfo($fullPath);
                    $ext = $pathInfo['extension'] ?? null;
                    if (!in_array($ext, ['php'])) {
                        continue;
                    }
                    $result[] = $path.DIRECTORY_SEPARATOR.$file;
                }
            }
        }
        return $result;
    }

    /**
     * @title 重跑团队业绩
     * @return void
     * @throws \Exception
     */
    public function reRunTeamDivide()
    {
        $order_sn = Request::param('order_sn');
        if (empty($order_sn)) {
            dump('参数有毛病');
            die;
        }
        DivideModel::update(['arrival_status' => 3, 'status' => -1], ['order_sn' => $order_sn, 'arrival_status' => 2, 'status' => 1, 'type' => 5]);
        $data['order_sn'] = $order_sn;
        $data['searType'] = 1;
        $test = (new \app\lib\services\TeamDivide())->divideForTopUser($data);
        dump($test);
        die;
    }

    public function parseUpdate($data, $field,$table)
    {
        $sql = "update {$table} set ";
        $keys = array_keys(current($data));
        foreach ($keys as $column) {
            $sql .= sprintf("`%s` = CASE `%s`", $column, $field);
            foreach ($data as $line) {
                $sql .= sprintf("WHEN '%s' THEN '%s'", $line[$field], $line[$column]);
            }
            $sql .= "END,";
        }
        $fanhui = implode(',',array_column($data,'id'));
        return rtrim($sql, ',')."    where id  in ({$fanhui})";
    }

    public function test()
    {
        set_time_limit(0);
        ini_set('memory_limit', '3072M');
        ini_set('max_execution_time', '0');
        $map[] = ['result_status', 'in', [2, 4]];
        $map[] = ['sales_price', '>', 1000];
        $map[] = ['status', 'in', [1, 2]];
//        dump('啥都没处理, 看看注释呗');die;
//        dump('啥都没处理, 看看注释呗');die;
//        dump('啥都没处理, 看看注释呗');die;
//        dump('啥都没处理, 看看注释呗');die;
//
//        $m[] = ['order_sn','like','uop%'];
//        $list = CrowdfundingFuseRecord::where($m)->select()->toArray();
//        foreach ($list as $key => $value) {
//            $orderS = (new CodeBuilder())->buildOrderNo();
//
//            $newBalance[$key]['order_sn'] = $orderS;
//            $newBalance[$key]['uid'] = $value['uid'];
//            $newBalance[$key]['type'] = 2;
//            $newBalance[$key]['price'] = '-'.$value['original_price'];
//            $newBalance[$key]['change_type'] = 2;
//            $newBalance[$key]['remark'] = '数据迁移';
//            $newBalance[$key]['is_grateful'] = 2;
//            $newBalance[$key]['pay_type'] = 88;
//            $newBalance[$key]['status'] = 1;
//        }
//        dump($newBalance);die;

//        $res = Db::transaction(function () use ($newBalance){
//            /////////////////////(new CrowdfundingBalanceDetail())->saveAll(array_values($newBalance));
//        });
//        dump($newBalance);
//        dump(12312313);die;
        $list = (new Office())->ReadExcel('0925.xlsx',12);

//        dump($list);die;
        $userAgreement = \app\lib\models\UserAgreement::where(['uid'=>array_column($list,'uid'),'status'=>1,'ag_sn'=>'AG202309212608354869','sign_status'=>1])->column('uid');
        $ma[] = ['','exp',Db::raw("find_in_set('GIzR29szwf',team_chain)")];
        $ma[] = ['status','=',1];
        $notOperUser = Member::where($ma)->column('uid');

        $m[] = ['order_sn','like','uop%'];
        $m[] = ['status','=',1];
        $existUser = CrowdfundingFuseRecord::where($m)->column('uid');
        foreach ($list as $key => $value) {
            if(in_array($value['uid'],$notOperUser)){
                $new[] = $value;
            }
        }
//        dump($list);die;
//        (new Office())->exportExcel('treqwe','Xlsx',$new);
//        dump(12312313);die;
        foreach ($list as $key => $value) {
            if(!in_array($value['uid'],$userAgreement)){
                unset($list[$key]);
            }
            if(in_array($value['uid'],$notOperUser)){
                unset($list[$key]);
            }
            if(in_array($value['uid'],$existUser)){
                unset($list[$key]);
            }
        }
//        dump($list);die;
//        (new Office())->exportExcel('treqwe','Xlsx',$list);
        foreach ($list as $key => $value) {
            $orderS = (new CodeBuilder())->buildOrderNo();
            $newRecord[$key]['order_sn'] = 'uop'.$orderS;
            $newRecord[$key]['uid'] = $value['uid'];
            $newRecord[$key]['original_price'] = $value['kou_price'];
            $newRecord[$key]['scale'] = 1;
            $newRecord[$key]['total_price'] = $value['kou_price'];
            $newRecord[$key]['last_total_price'] = $value['kou_price'];
            $newRecord[$key]['crowd_code'] = "C202304284472511911";
            $newRecord[$key]['crowd_round_number'] = 1;
            $newRecord[$key]['crowd_period_number'] = 2;
            $newRecord[$key]['grant_status'] = 3;
            $newRecord[$key]['status'] = 1;

            $newBalance[$key]['order_sn'] = $newRecord[$key]['order_sn'];
            $newBalance[$key]['uid'] = $value['uid'];
            $newBalance[$key]['type'] = 2;
            $newBalance[$key]['price'] = '-'.$value['kou_price'];
            $newBalance[$key]['change_type'] = 2;
            $newBalance[$key]['remark'] = '数据迁移';
            $newBalance[$key]['is_grateful'] = 2;
            $newBalance[$key]['pay_type'] = 88;
            $newBalance[$key]['status'] = 1;
        }

        dump($newRecord);
        dump($newBalance);die;
//        $res = Db::transaction(function () use ($newRecord,$list,$newBalance){
////            $withdrawList = Withdraw::where(['uid'=>array_column($list,'uid'),'status'=>1,'check_status'=>3])->select()->toArray();
////        if(!empty($withdrawList)){
////            foreach ($withdrawList as $key => $value) {
////                (new \app\lib\services\Finance())->checkUserWithdraw(['id'=>$value['id'],'check_status'=>2]);
////            }
////        }
////
////        foreach ($list as $key => $value) {
////            User::where(['uid' => $value['uid']])->dec('crowd_balance', $value['kou_price'])->update();
////        }
////        (new CrowdfundingFuseRecord())->saveAll(array_values($newRecord));
////        (new CrowdfundingBalanceDetail())->saveAll(array_values($newBalance));
//    });
        dump('处理数据完成咯');die;
        $periodList = CrowdfundingPeriod::where($map)->select()->toArray();
//        $orderList = OrderGoods::with(['orderInfo'])->where(function ($query) use ($periodList) {
//            foreach ($periodList as $key => $value) {
//                ${'where' . ($key + 1)}[] = ['crowd_code', '=', $value['activity_code']];
//                ${'where' . ($key + 1)}[] = ['crowd_round_number', '=', $value['round_number']];
//                ${'where' . ($key + 1)}[] = ['crowd_period_number', '=', $value['period_number']];
//            }
//            for ($i = 0; $i < count($periodList); $i++) {
//                $allWhereOr[] = ${'where' . ($i + 1)};
//            }
//            $query->whereOr($allWhereOr);
//        })->where(['pay_status' => 2, 'order_type' => 6,'status'=>1])->select()->toArray();

//        dump($orderList);die;
        //退款处理
//        Db::transaction(function () use ($orderList,$periodList){
//            foreach ($orderList as $key => $value) {
//                if (!empty($value['crowd_code']) && !empty($value['crowd_round_number']) && !empty($value['crowd_period_number'])) {
//                    Order::update(['order_status' => -3, 'after_status' => 4, 'close_time' => time(), 'coder_remark' => '运营要求退款'], ['order_sn' => $value['order_sn'], 'order_type' => $value['order_type'], 'crowd_key' => ($value['crowd_code'] . '-' . $value['crowd_round_number'] . '-' . $value['crowd_period_number'])]);
//                    OrderGoods::update(['status' => -2, 'pay_status' => 3, 'after_status' => 4], ['order_sn' => $value['order_sn'], 'order_type' => $value['order_type'], 'crowd_code' => $value['crowd_code'], 'crowd_round_number' => $value['crowd_round_number'], 'crowd_period_number' => $value['crowd_period_number']]);
//                    CrowdfundingBalanceDetail::update(['status' => -1], ['order_sn' => $value['order_sn'], 'status' => 1]);
//                    DivideModel::update(['status' => -1, 'arrival_status' => 3,'coder_remark'=>'运营要求退款'], ['order_sn' => $value['order_sn'], 'arrival_status' => 2]);
//                    if (!empty($value['orderInfo'] ?? []) && !empty($value['orderInfo']['uid'] ?? null)) {
//                        User::where(['uid' => $value['orderInfo']['uid']])->inc('crowd_balance', $value['total_price'])->update();
//                    }
//                }
//            }
//            //将所有期修改为已完成
//            foreach ($periodList as $key => $value) {
//                CrowdfundingPeriod::where(['activity_code'=>$value['activity_code'],'round_number'=>$value['round_number'],'period_number'=>$value['period_number'],'result_status'=>[2,4]])->save(['result_status'=>1]);
//            }
//        });


//        dump('处理完成了');die;
        $userDetail = CrowdfundingBalanceDetail::where(['status'=>1])->field("uid,sum( IF ((change_type IN ( 1 )) OR ( change_type = 9 AND transfer_from_real_uid = 'ecmJyV1Ffu' ), price, 0 )) as recharge_price, sum(IF( change_type IN (  7 ), price, 0 )) as crowd_transfer_price")->group('uid')->select()->toArray();
        $userWithdraw = Withdraw::where(['status'=>1,'check_status'=>1])->field("uid,sum(total_price) as all_withdraw_price")->group('uid')->select()->toArray();
        foreach ($userWithdraw as $key => $value) {
            $userWithdrawInfo[$value['uid']]  = $value['all_withdraw_price'];
        }
        $userBalance = User::column('crowd_balance','uid');
        foreach ($userDetail as $key => $value) {
            if($value['uid'] == 'ecmJyV1Ffu'){
                unset($userDetail[$key]);
                continue;
            }
            $userDetail[$key]['all_withdraw_price'] = ($userWithdrawInfo[$value['uid']] ?? 0);
            $userDetail[$key]['now_crowd_balance'] = ($userBalance[$value['uid']] ?? 0);
        }
        foreach ($userDetail as $key => $value) {
            $userDetail[$key]['is_huiben'] = 2;
            if((string)$value['recharge_price'] < (string)($value['all_withdraw_price'] + abs($value['crowd_transfer_price']))){
                $userDetail[$key]['is_huiben'] = 1;
                if ((($value['all_withdraw_price'] + abs($value['crowd_transfer_price'])) - $value['recharge_price'] >= 0)) {
                    $userDetail[$key]['kou_price'] = $value['now_crowd_balance'];
                } else {
                    if ($value['now_crowd_balance'] - (($value['all_withdraw_price'] + abs($value['crowd_transfer_price'])) - $value['recharge_price']) >= 0) {
                        $userDetail[$key]['kou_price'] = $value['now_crowd_balance'] - (($value['all_withdraw_price'] + abs($value['crowd_transfer_price'])) - $value['recharge_price']);
                    } else {
                        $userDetail[$key]['kou_price'] = $value['now_crowd_balance'];
                    }
                }
            }else{

                if((string)$value['now_crowd_balance'] > ($value['recharge_price'] - ($value['all_withdraw_price'] + abs($value['crowd_transfer_price'])))){
                    $userDetail[$key]['is_huiben'] = 3;
                    if($value['now_crowd_balance'] - ($value['recharge_price'] - ($value['all_withdraw_price'] + abs($value['crowd_transfer_price']))) >= 0){
                        $userDetail[$key]['kou_price'] = $value['now_crowd_balance'] - ($value['recharge_price'] - ($value['all_withdraw_price'] + abs($value['crowd_transfer_price'])));
                    }
                }
            }
        }
        foreach ($userDetail as $key => $value) {
            if (in_array($value['is_huiben'], [1, 3])) {
                $huiben[] = $value;
            }
//            if($value['uid'] == 'ZoGCD8Vitu'){
//                dump($value);
//            }
        }
//        die;
//        dump($userDetail);die;
        array_multisort(array_column($huiben,'kou_price'), SORT_DESC, $huiben);
        $userList = User::where(['uid'=>array_column($huiben,'uid')])->field('uid,name,phone')->select()->toArray();
        $userAgreement = \app\lib\models\UserAgreement::where(['uid'=>array_column($huiben,'uid'),'status'=>1,'ag_sn'=>'AG202309212608354869'])->column('uid');
        foreach ($userList as $key => $value) {
            $userInfo[$value['uid']] = $value;
        }
        foreach ($huiben as $key => $value) {
            $huiben[$key]['user_name'] = $userInfo[$value['uid']]['name'] ?? '未知用户';
            $huiben[$key]['user_phone'] = $userInfo[$value['uid']]['phone'] ?? '暂无手机号码';
            $huiben[$key]['is_huiben'] =$value['is_huiben'] == 1 ? '是' : '否';
            $huiben[$key]['crowd_transfer_price'] = abs($value['crowd_transfer_price']);
            $huiben[$key]['agreement'] = '否';
            if(in_array($value['uid'],$userAgreement)){
                $huiben[$key]['agreement'] = '是';
            }
            if($value['now_crowd_balance'] < 0 || $value['kou_price'] < 0){
                unset($huiben[$key]);
                continue;
            }
        }
        (new Office())->exportExcel('treqwe','Xlsx',$huiben);
        dump($huiben ?? []);die;
        dump($orderList);die;
        $data['user_buy_password'] = 'ankATqSue8EMWBTqGe6ksUWvr1z1BEqDcw+5oVelwuyOZEMqFzXW6YeeHEuCCvBRF/UUwQpfjbL5tRxvYoNGIseVg60pxYt+e6T7gd82l5dt/TBrrO3YdpiS157fqKl37dl5IHXYnsLyxXWTI/X1OGftB3woN7oOTsaJBXpv4EQ=';
        $data['user_password'] = 'qPHj9EEBX8fISBZQtmy2jmNKRU+lkcEHy9maQb5sIGcpxr/qvyw3Z56lIxNpncxIo/7P8ITx/ItjNEUCNnuqSKKynKaQVwChje1nftkbPyqODBrv5XkHlQ+aGZFy9sC7ep482f99alarBJLz//BEbWFRrGLH/p32/WXy2ZW3/0E=';
        dump(privateDecrypt($data['user_password']));
        dump(privateDecrypt($data['user_buy_password']));
        $data['user_password'] = privateDecrypt($data['user_password']);
        $data['user_buy_password'] = privateDecrypt($data['user_buy_password']);
        $userInfo['pay_pwd'] = "43bbd5029beb7defe472cb01a88b6ad2";
        $userInfo['pwd'] = "ac6b3cce8c74b2e23688c3e45532e2a7";

        if (md5($data['user_password']) != $userInfo['pwd']) {
            //记录操作日记
            $addConverData['remark'] = '账号有误';
//            (new HealthyBalanceConver)->new($addConverData);
            throw new OpenException(['msg' => '账号信息异常，请确认！~!!!!!']);
        }
        dump(md5($data['user_buy_password']));
        dump($userInfo['pay_pwd']);
        //判断支付密码
        if (md5($data['user_buy_password']) != $userInfo['pay_pwd']) {
            //记录操作日记
            $addConverData['remark'] = '账号有误';
            dump($data);die;
//            (new HealthyBalanceConver)->new($addConverData);
            throw new OpenException(['msg' => '账号信息异常，请确认！@#@#@#@#']);
        }
        dump(12313);die;
        $allCrowdActivityList[0]['activity_code'] = 'C202308014410692553';
        $allCrowdActivityList[0]['auto_create_advance_limit'] = 3;
        //汇总查询所有期的超前提前购期数
        $advanceDelayOrderGroupCount = CrowdfundingDelayRewardOrder::where(['crowd_code' => array_unique(array_column($allCrowdActivityList, 'activity_code')), 'arrival_status' => 3, 'status' => 1])->field('crowd_code,crowd_round_number,crowd_period_number,count(id) as number')->group('crowd_code,crowd_round_number,crowd_period_number')->select()->toArray();
        dump($advanceDelayOrderGroupCount);die;
        if (!empty($advanceDelayOrderGroupCount)) {
            foreach ($advanceDelayOrderGroupCount as $key => $value) {
                if (!isset($advanceCount[$value['crowd_code']])) {
                    $advanceCount[$value['crowd_code']] = 0;
                }
                //每个区总超前提前购次数, 同一期多次参与算一次
                $advanceCount[$value['crowd_code']] += 1;
            }
            foreach ($allCrowdActivityList as $key => $value) {
                if (doubleval($value['auto_create_advance_limit'] ?? 0) > 0 && ((string)($advanceCount[$value['activity_code']] ?? 0) >= (string)($value['auto_create_advance_limit'] ?? 0))) {
                    throw new CrowdFundingActivityException(['msg' => '当前区提前购期数已达到上限, 请耐心等待前置活动完成后再参与, 感谢您的理解和支持']);
                }
            }
        }
        dump($advanceDelayOrderGroupCount);die;
        $arr[0] = 12313;
        $arr[1] = '1231231231313';
        $arr[2] = 'zsadse1';
        ksort($arr);
        $arr = json_encode($arr,256);
        $arr = privateEncrypt(123123);
//        $arr = "TcQW5P6OeSt8PAhjNFddVnFNhXXYM23mcM92/YRE/ES0yQEVMv9n3tDd064J7Agv/KH1MC5ApKydMkB/ViaR2KzDVfnLFuihD3ttZsq1epabG3NHN+XGsbhwg3QPBC/iU+uerlsQMnzrwQE2CEI0TllDdF2W/LlroNNdqKPdd+4=";
        dump($arr);
        dump(strlen($arr));

        dump(privateEncrypt($arr));
        die;
        $key = '123123123';
        $string = '123123123asdasd123哈哈哈哈哈123123123123123sdfsdfsar123414zdvsfdh4756';
        dump(str_replace("`",'',"CREATE TABLE `sp_user_agreement` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uag_sn` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '签约协议编号',
  `ag_sn` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '协议编号',
  `uid` char(10) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '用户uid',
  `title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '协议标题',
  `order_sn` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '订单号',
  `type` tinyint(2) NOT NULL COMMENT '协议类型 1为基础协议',
  `content` longtext COLLATE utf8mb4_unicode_ci COMMENT '协议内容',
  `sign_status` tinyint(2) NOT NULL DEFAULT '1' COMMENT '签约状态 1为同意 2为拒绝',
  `remark` text COLLATE utf8mb4_unicode_ci COMMENT '备注',
  `snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT '协议快照(图片, 签名等)',
  `status` tinyint(2) NOT NULL DEFAULT '1' COMMENT '状态 1为正常 2为禁用 -1为删除',
  `create_time` int(11) DEFAULT NULL COMMENT '创建时间',
  `update_time` int(11) DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户协议签署表';"));die;
//        $en = $this->byte_wise_ord($this->encrypt($string,$key));
        dump(strlen("f2f15RkGRWn05ioTUmrnW8MqjTxE4P1Bla3hp0uuN-TD3NTuWfdYYYTuuaQkpfLbzLc7"));die;
        $key = pack('H*', "bcb04b7e103a0cd8b54763051cef08bc55abe029fdebae5e1d417e2ffb2a00a3");
        echo 'key::'.$key.'<Br><Br>';
        //看下二进制数据长度
        $key_size =  strlen($key);
        echo "Key size: " . $key_size . "<br><br>\n";

        $plaintext = "This string was AES-256 / CBC / ZeroBytePadding encrypted.";

        # create a random IV to use with CBC encoding
        $iv_size = mcrypt_get_iv_size(MCRYPT_RIJNDAEL_128, MCRYPT_MODE_CBC);
        $iv = mcrypt_create_iv($iv_size, MCRYPT_RAND);

        $ciphertext = mcrypt_encrypt(MCRYPT_RIJNDAEL_128, $key, $plaintext, MCRYPT_MODE_CBC, $iv);

        $ciphertext = $iv . $ciphertext;

        $ciphertext_base64 = base64_encode($ciphertext);
        //输出密文（每次都不一样，更安全）
        echo  '<Br><br>jia mi:::'.$ciphertext_base64 . "\n";



//解密：
        $ciphertext_dec = base64_decode($ciphertext_base64);
        $iv_dec = substr($ciphertext_dec, 0, $iv_size);
        $ciphertext_dec = substr($ciphertext_dec, $iv_size);
        $plaintext_dec = mcrypt_decrypt(MCRYPT_RIJNDAEL_128, $key, $ciphertext_dec, MCRYPT_MODE_CBC, $iv_dec);

        echo  '<Br><br>jie mi:::'.$plaintext_dec . "\n";
        die;
        $en = $this->encrypt($string,$key);
        dump($en);
        dump($this->decrypt($en,$key));die;
        $where[] = ['team_vip_level','>',0];
        $where[] = ['status','=',1];
        $list = User::where($where)->field('uid,name,phone,crowd_balance')->select()->toArray();
        $mode = (new RechargeLink());
//        foreach ($list as $key => $value) {
//            $list[$key]['team_withdraw'] = $mode->userSubordinateRechargeRate(['time_type'=>1,'uid'=>$value['uid']])['totalCanWithdrawAmount'] ?? 0;
//        }
//        $test = (new Office())->exportExcel('treqwe','Xlsx',$list);
        dump(1231313);die;
        //处理重复发放奖金问题
//        $map['crowd_code'] = "C202304284472511911";
//        $map['crowd_round_number'] = 1;
//        $map['crowd_period_number'] = 35;
//        $map['type'] = 1;
//        $map['status'] = 1;
////       $map['order_sn'] = "202212255729450727";
//        $list = CrowdfundingBalanceDetail::where($map)->select()->toArray();
//        foreach ($list as $key => $value) {
//            $exist = $value['order_sn'].$value['price'].$value['remark'];
//            if(!isset($existOrder[$exist])){
//                $existOrder[$exist] = $value;
//            }else{
//                $chongfu[$exist] = $value;
////                CrowdfundingBalanceDetail::update(['status'=>-1],['id'=>$value['id']]);
////                User::where(['uid'=>$value['uid']])->dec('crowd_balance',$value['price'])->update();
//            }
//        }
//        dump($chongfu ?? []);
//        dump($existOrder ?? []);die;
        //处理延迟抽奖礼发放不对的问题

        dump(123131313);die;
        $userId = null;
        $test = User::with(['memberVdcAuth'])->where(['uid' => $userId, 'status' => [1, 2]])->field('openid,uid,name,avatarUrl,is_new_user,vip_level,phone,old_sync,c_vip_level,c_vip_time_out_time')->findOrEmpty()->toArray();
        dump($test);die;
        $class = '1123';
//        dump(array_unique(array_column($divideList ?? [], 'order_sn')));die;
        $rdWhere[] = ['order_sn', 'in', ['202306168789337704','202305100134828018']];
        $rdWhere[] = ['change_type', '=', 3];
        $rdWhere[] = ['status', '=', 1];
//                $rdWhere[] = ['', 'exp', Db::raw('order_uid = link_uid and type = 8 and is_grateful = 2 and arrival_status = 1 and status = 1')];
//                $realDivideList = Divide::where($rdWhere)->column('order_sn');
        $realDivideList = CrowdfundingBalanceDetail::where($rdWhere)->column('create_time','order_sn');
//                if ($value['order_uid'] == $value['link_uid'] && $value['type'] == 8 && $value['is_grateful'] == 2) {
        //如果查询出来的待发放本/奖金的订单总数不等于分润查询出来的总订单, 有可能是因为直接发放了奖励, 所以需要将已经发放奖励分润并且延迟发放标准红状态为待发放的订单修改为已发放
        if (!empty($realDivideList ?? [])) {
            $drWhere[] = ['order_sn', 'in', array_keys($realDivideList)];
            $drWhere[] = ['arrival_time', '<=', time()];
            $drWhere[] = ['arrival_status', '=', 3];
            $drWhere[] = ['status', '=', 1];
            $delayOrderList = CrowdfundingDelayRewardOrder::where($drWhere)->column('order_sn');
            if(!empty($delayOrderList)){
                foreach ($delayOrderList as $key => $value) {
                    $updateDataList[$key]['order_sn'] = $value;
                    $updateDataList[$key]['arrival_status'] = 1;
                    $updateDataList[$key]['real_arrival_time'] = $realDivideList[$value] ?? time();
                }
                $updateData['list'] = array_values($updateDataList);
                $updateData['id_field'] = 'order_sn';
                $updateData['db_name'] = 'sp_crowdfunding_delay_reward_order';
                $updateData['auto_fill_time'] = true;
                $updateData['other_map'] = 'arrival_status = 3 and status = 1';
                $updateData['sear_type'] = '2';
                $test = (new CommonModel())->getSql(true)->DBUpdateAll($updateData);
                dump($test);
            }

        }
        dump(123132);die;
        $data = [0=>['title'=>"CONCAT(`name`,'12312313')",'name'=>'44444','uid'=>1],1=>['title'=>55555,'uid'=>'2']];
        $a['list'] = $data;
        $a['db_name'] = 'sp_test';
        $a['sear_type'] = '2';
        $a['sear_type'] = '2';
        $a['other_map'] = 'status = 1';
        $a['raw_fields'] = ['title'];
        $test = (new CommonModel())->setThrowError(true)->DBUpdateAll($a);
        dump($test);die;
//        $list = Db::query("select u.name as '用户昵称',u.uid as '三方id',u.phone as '用户手机号码',IFNULL(t.price,\"0.00\") as '福利参与中未返本金额',u.crowd_balance as '福利钱包可用余额',u.crowd_fronzen_balance as '提现冻结金额',IFNULL((t.price + u.crowd_balance + u.crowd_fronzen_balance),\"0.00\") as '当前福利钱包总持有',from_unixtime(u.create_time) as '注册时间',current_timestamp() as '统计时间' from (select a.uid,abs(sum(if(a.count_number >=3,a.number,0))) as price from (select a.uid,a.order_sn,count(b.id) as count_number,a.price as number from sp_crowdfunding_balance_detail a  LEFT JOIN sp_crowdfunding_balance_detail b on a.order_sn = b.order_sn and b.status = 1 and b.change_type = 3 and b.type = 1 and a.crowd_code is not  null and a.uid  = 'uBwSu4UoKF' GROUP BY a.order_sn) a GROUP BY uid) t right join sp_user u on t.uid = u.uid where u.uid = 'uBwSu4UoKF' ORDER BY u.create_time desc");
        $list = Db::query("(select a.uid,a.order_sn,count(b.id) as count_number,a.price as number from sp_crowdfunding_balance_detail a  LEFT JOIN sp_crowdfunding_balance_detail b on a.order_sn = b.order_sn and b.status = 1 and b.change_type = 3 and b.type = 1 and a.crowd_code is not null GROUP BY a.order_sn)");
        foreach ($list as $key => $value) {
            if($value['uid'] == 'uBwSu4UoKF'){
                dump($value);
            }
        }
        dump($list);die;
//        dump((new Mail())->send_email(['subject'=>'测试一下','title'=>'俺老孙来也','content'=>[0=>['content'=>'西游记大闹天宫','boldTitle'=>'三打白骨精']],'email_list'=>['853671221@qq.com'],'forUserName'=>'东土大唐','from_name'=>'哈哈哈哈哈哈哈哈']));die;
//        $method = '5555';
//        $res = Console::call('tool',['bakLog','--date','202305'])->fetch();
//        dump($res);
////        dump([$class, $method] = $method);die;
//        $list = Db::query("SELECT
//	*
//FROM
//	(
//	SELECT
//		a.uid,
//	IF
//		(
//			sum( b.price ) IS NULL,
//			0,
//		sum( b.price )) AS sum_price,
//		a.team_balance,
//		a.team_fronzen_balance
//	FROM
//		sp_user a
//		LEFT JOIN sp_balance_detail b ON a.uid = b.uid COLLATE utf8mb4_general_ci
//	WHERE
//		a.team_balance > 0
//		AND (( b.STATUS = 1 AND b.change_type IN ( 16, 17, 29, 30 )) OR b.change_type IS NULL )
//	GROUP BY
//		a.uid
//	) c
//WHERE
//	c.sum_price != ( c.team_balance + c.team_fronzen_balance )");
        dump(1231312);die;
//        foreach ($list as $key => $value) {
//            User::update(['team_balance'=>$value['sum_price']],['uid'=>$value['uid'],'status'=>1]);
//        }
//        dump(1231313);die;
//        $ddl = "ALTER TABLE `sp_crowdfunding_period`
// ADD COLUMN `advance_buy_reward_send_time` int(11) DEFAULT '0' COMMENT '提前购发放奖励时间, 秒为单位';";
        dump(str_replace("`",'',$ddl));die;
        dump((new CrowdFundingService())->checkExpireUndonePeriodDeal(['activity_code'=>'C202303277114762577','round_number'=>1,'period_number'=>11]));die;
        dump(json_encode($balance ?? [], 256));die;
        $test = CrowdfundingDelayRewardOrder::where(['uid' => 'hsL07mAYGc', 'arrival_status' => 3, 'status' => 1])->field('uid,crowd_code,crowd_period_number,count(id) as number')->group('crowd_code,crowd_period_number')->select()->toArray();
        if(!empty($test)){
            foreach ($test as $key => $value) {
                if(!isset($userAdvanceCount[$value['crowd_code']])){
                    $userAdvanceCount[$value['crowd_code']] = 0;
                }
                $userAdvanceCount[$value['crowd_code']] += 1;
            }
        }
        dump($userAdvanceCount);
        dump(array_sum([]));
        dump($test);die;
        $test =  (new CrowdFundingService())->delayRewardOrderCanRelease(['searType'=>1]);
        dump($test);die;
//        Cache::store('redis')->set('testtest',[12313]);
//        Cache::store('redis')->push('testtest',78787878);
//        Cache::store('redis')->push('testtest',666666666666666);
        $q = "'12313','3456456','567567'";
//        $res = call_user_func_array([Cache::store('redis'),'lpush'],['qqq','12331','57567','89089098','456342','6786789']);
//        dump($res);
        $qqq = Cache::store('redis')->lpush('qqq',...['6666666','77777777','89089098','5555555','6786789']);
        dump($qqq);
//        Cache::store('redis')->lpush('qqq','6666666');
//        Cache::store('redis')->lpush('qqq','999999999999');
        Cache::store('redis')->handler()->lrem('qqq','777777');
//        $overTimeList = Cache::store('redis')->handler()->key('qqq');
            $pattern= 'test*';
        $cursor = 0;
        $overTimeList = Cache::store('redis')->handler()->scan($cursor, $pattern, 1000);

        dump($overTimeList);
        dump(Cache::store('redis')->handler()->lrange('qqq',0,-1));
        dump(Cache::store('redis')->handler()->lrange('{queues:cmsubcoTeamMemberUpgrade}',0,-1));
        dump(cache('qqq'));
        dump(config('test.ysePay.publicKeyPath'));die;
//        $test = (new CrowdfundingBalanceDetail())->getUserNormalShoppingSendCrowdBalance(['uid'=>'FlsRjHovHv']);
        dump($test);die;
        $teamMember = \app\lib\models\TeamMember::with(['pUser'])->select()->toArray();
        foreach ($teamMember as $key => $value) {
            if(!empty($value['pUser'] ?? []) && $value['pUser']['team_vip_level'] == $value['level']){
                $sameLevelUser[] = $value;
                $sameLevelUid[] = $value['uid'];
            }
        }
//        dump($sameLevelUid ?? []);
        if (!empty($sameLevelUid ?? [])) {
            $map[] = ['create_time', '>=', 1687531620];
            $map[] = ['create_time', '<=', 1687766460];
            $map[] = ['order_uid', 'in', $sameLevelUid];
            $map[] = ['type', '=', 5];
            $map[] = ['arrival_status', '=', 2];
            $map[] = ['status', '=', 1];
            $divideList = DivideModel::where($map)->group('order_sn')->column('order_sn');
            dump($divideList);die;
            if (!empty($divideList)) {
                foreach ($divideList as $key => $value) {
                    $data = [];
                    DivideModel::update(['arrival_status' => 3, 'status' => -1], ['order_sn' => $value, 'arrival_status' => 2, 'status' => 1, 'type' => 5]);
                    $data['order_sn'] = $value;
                    $data['searType'] = 1;
                    $test = (new \app\lib\services\TeamDivide())->divideForTopUser($data);;
                }
            }
            dump($sameLevelUser ?? []);
            dump($teamMember);
            die;
            $data['order_sn'] = '202306248130978221';
            $data['searType'] = 2;
//        $test = (new \app\lib\services\TeamDivide())->divideForTopUser($data);;
            dump($test);
            die;
        }
        dump('12312312312331');die;
        $map[] = ['create_time', '>=', 1687531620];
        $map[] = ['create_time', '<=', 1687766460];
        $map[] = ['order_uid', 'in', $sameLevelUid];
        $map[] = ['type', '=', 5];
        $map[] = ['arrival_status', '=', 2];
        $map[] = ['status', '=', 1];
        $divideList = DivideModel::where($map)->group('order_sn')->column('order_sn');
        dump($divideList);die;
        dump($sameLevelUser ?? []);
        dump($teamMember);die;
        $data['order_sn'] = '202306248130978221';
        $data['searType'] = 2;
//        $test = (new \app\lib\services\TeamDivide())->divideForTopUser($data);;
        dump($test);die;
        $array['uid'] = 'FQMgoUYtd6';
        $array['price'] = 500;
        $array['oper_type'] = 1;
        $array['withdraw_type'] = 5;
        $test = (new \app\lib\models\UserBalanceLimit())->checkUserBalanceLimit($array);
        dump($test);die;
        dump(json_encode(array (
            'extend' => '',
            'charset' => 'UTF-8',
            'data' => '{"head":{"version":"1.0","respTime":"20230616161030","respCode":"000000","respMsg":"成功"},"body":{"mid":"68888TS121602","orderCode":"2023006167854845199","tradeNo":"2023006167854845199","clearDate":"20230616","totalAmount":"000000010000","orderStatus":"1","payTime":"20230616161027","settleAmount":"000000010000","buyerPayAmount":"000000010000","discAmount":"000000000000","txnCompleteTime":"20201019233102","payOrderCode":"0616033149j10008","accLogonNo":"621691******0074","accNo":"621691******0074","midFee":"000000000060","extraFee":"000000000000","specialFee":"000000000000","plMidFee":"000000000000","bankserial":"","externalProductCode":"00000018","cardNo":"621691******0074","creditFlag":"","bid":"SDSMP0068888TS12160220230616040829736352","benefitAmount":"000000000000","remittanceCode":"","extend":""}}',
            'sign' => 'uUi6aGzHZplEUFFthDgvtj0EJRUAJpQpxileI8jN5SNJeqojwfEPu700hCftjK9NtXacodgEjybfDF1cBvEC07TTqmm+p4lyeJMXJsnDB3KWD6oYoJPUukL2o/IjaxbOAkf/qqYPJUBvSSKMga1DEB2P/S2qc+TKbBiy7No6k5EWmr7v35R9cHd5FMZroTcwr589J17jXJrEj7urZDJB4nUyfRHrBKNPDOmr1vD+e3BDFWPEkc4XcGRZa/wjw+SN61jsEFzmeWeqiQhoqpZCwvmocuFbvGF8QXvvcqXHeIIU1pKyjFspbxyb22PDvVYoqNUOOfXb1xIPOrfMdM+rMA==',
            'signType' => '01',
        )));die;
        $map[] = ['uid','=','lNR7ySvTrs'];
        $map[] = ['status','=','1'];
        $recordList = \app\lib\models\UserBalanceLimit::where($map)->withoutField('id,create_time,update_time')->select()->toArray();
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
        dump($newRecordList);
        $data['uid'] = $uid = 'lNR7ySvTrs';
        $price = 80;
        $operType = 1;
        $balanceType = 10;
        $data['withdraw_type'] = 5;
        $conditionAndArray = [];
        $conditionOrArray = [];
        $conditionAndErrorArray = [];
        $conditionOrErrorArray = [];
        foreach ($newRecordList as $key => $value) {
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
                            //查询当前钱包余额是否超过限制额度
                            switch ($value['judge_balance_type']) {
                                case 1:
                                    $userBalance = (new BalanceDetail())->getAllBalanceByUid($data['uid'], [1, 2, 7, 11, 13, 23, 24]);
                                    break;
                                case 10:
                                    $userBalance = (new BalanceDetail())->getAllBalanceByUid($data['uid'], [12, 15, 16, 17, 18, 19, 20, 21, 27, 28, 29, 30, 31, 32, 33, 34]);
                                    break;
                                case 6:
                                    $userBalance = (new CrowdfundingBalanceDetail())->getAllBalanceByUid($data['uid']);
                                    break;
                                default:
                                    $userBalance = 0;
                                    break;
                            }
                            if ((string)($userBalance + $price) <= (string)$value['limit_price']) {
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
                        case 3:
                            //转化类型由于逻辑复杂, 先不做此功能
                            break;
                    }
                    break;
                case 3:
                    switch ($operType) {
                        case 1:
                            $wMap[] = ['uid', '=', $uid];
                            $wMap[] = ['withdraw_type', '=', $data['withdraw_type']];
                            $wMap[] = ['check_status', 'in', [1, 3]];
                            $wMap[] = ['status', '=', 1];
                            $WithdrawPrice = Withdraw::where($wMap)->sum('total_price');
                            if ((string)($WithdrawPrice + $price) > (string)$value['limit_price']) {
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
                        case 3:
                            //转化类型由于逻辑复杂, 先不做此功能
                            break;
                    }
                    break;
            }
        }
        dump($conditionAndArray);
        dump($conditionOrArray);
        dump($conditionAndErrorArray);
        dump($conditionOrErrorArray);
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
        if (empty($conditionOrArray)) {
            if (!empty($andRes)) {
                $finallyRes = true;
            }
        } else {
            if (!empty($orRes)) {
                $finallyRes = true;
            }
        }
        if (empty($finallyRes ?? false)) {
//            throw new ServiceException(['msg' => '[风控拦截] 暂不符合操作条件']);
            dump(['msg' => '[风控拦截] 暂不符合操作条件']);
        }
        dump($finallyRes);
        dump($errorMsg ?? []);die;
        dump((new Mail())->send_email(['subject'=>'测试一下','title'=>'俺老孙来也','content'=>[0=>['content'=>'西游记大闹天宫','boldTitle'=>'三打白骨精']],'email_list'=>['853671221@qq.com'],'forUserName'=>'东土大唐']));die;
        try {
            throw new ServiceException(['errorCode'=>'400101','placeholder'=>['hahah']]);
        } catch (\app\lib\BaseException $baseE) {
            $msg = $baseE->msg;
            $code = $baseE->errorCode;
        } catch (\Exception $e) {
            $msg = $e->getMessage();
            $code = $e->getCode();
        }
        dump($msg);
        dump($code);die;
        dump(lang('测试测试测试%s在测试测试%s',['hahah','nonono']));die;
        $res = false;
        $test = AlibabaCloud::stsClient('LTAI5tCSt6ECvKKYezuhCYPZ','G8BMipbOqjUEWoQAVHr7t3NYlpzi2F')->regionId('cn-shenzhen')->asDefaultClient();
//        $test = AlibabaCloud::stsClient('LTAI5t6QihvGWexf9HA16HNn','4JNXlWO10w0vVeuRt0JlGGHY4uJgfR')->regionId('cn-shenzhen')->asDefaultClient();
        try {
            $request = Sts::v20150401()->assumeRole();
            $result = $request
                ->withRoleArn("acs:ram::1221858396215983:role/stsservice")
                ->withRoleSessionName("test")
                ->debug(true) // Enable the debug will output detailed information

                ->request();
            dump($result->toArray());
        } catch (ClientException $exception) {
            echo $exception->getMessage() . PHP_EOL;
        } catch (ServerException $exception) {
            echo $exception->getMessage() . PHP_EOL;
            echo $exception->getErrorCode() . PHP_EOL;
            echo $exception->getRequestId() . PHP_EOL;
            echo $exception->getErrorMessage() . PHP_EOL;
        }
        dump(123131);die;
//        dump((new Mail())-
//>send('主体','1231321',['471256548@qq.com']));die;
        dump((new Mail())->send_email(['subject'=>'测试一下','title'=>'俺老孙来也','contentList'=>[0=>['content'=>'西游记大闹天宫','boldTitle'=>'三打白骨精']],'email_list'=>['853671221@qq.com'],'forUserName'=>'东土大唐']));die;
        dump($res['withdrawInfo']['create_time']);die;
//        throw new ServiceException();
//        dump(lang('测试测试测试%s在测试测试%s',['hahah','nonono']));die;
//        throw new ServiceException(['msg'=>'测试测试测试%s在测试测试%s','placeholder'=>[88888888888,0]]);
//        dump(lang('用户不存在有效授权信息',[]));die;
////        throw new ServiceException(['errorCode'=>400106]);
//        $test = include("D:\phpstudy_pro\WWW\php-subco\app\lang\zh-cn\common.php");
//        $exceptionCode = include("D:\phpstudy_pro\WWW\php-subco\config\/exceptionCode.php");
//        foreach ($exceptionCode as $key => $value) {
//            if(is_array($value)){
//                $exceptionStart[$key]['start'] = array_key_first($value ?? []);
//                $exceptionStart[$key]['allNumber'] = count($value);
//            }
//        }
//        foreach ($test as $key => $value) {
//            if(!empty($exceptionStart[$key] ?? [])){
//                if(!isset($newNumber[$key])){
//                    $newNumber[$key] = $exceptionStart[$key]['allNumber'];
//                }
//                foreach ($value as $cKey => $cValue) {
//                    $exceptionCode[$key][$exceptionStart[$key]['start'].sprintf("%02d", $newNumber[$key])] = $cValue;
//                    $newNumber[$key] += 1;
//                }
//            }
//        }
////        dump($exceptionStart);
////        dump($test);
//        dump($exceptionCode);
//        $inputTxt = "<?php\r\n";
//        $inputTxt .= "return [\r\n";
//        dump($inputTxt);
//        foreach ($exceptionCode as $key => $value) {
//            $inputTxt .= "  '".$key."' => [\r\n";
//            if (is_array($value)) {
//                foreach ($value as $cKey => $cValue) {
//                    $inputTxt .= "      " . $cKey . " => '" . $cValue . "',\r\n";
//                }
//            }
//
//            $inputTxt .= "  ],\r\n";
//            $inputTxt .= "\r\n";
//        }
//        $inputTxt .= '];';
//        dump($inputTxt);
////        die;
//        file_put_contents("D:\phpstudy_pro\WWW\php-subco\config\/exceptionCode.php",$inputTxt);
////        dump($exceptionCode);die;
//        foreach ($test as $key => $value) {
//            if(strstr($value,"' => '")){
//                $t =  explode("' => '",$value)[0] ?? '';
//                if(!empty($t)){
//                    $number[] = str_replace(" ",'',str_replace("'",'',$t));
//                }
//            }
//        }
//        dump($number);
//        dump($test);die;
//        dump($this->translate('用户余额不足','auto','ara'));die;
//        dump(lang('DbException.非法参数注入, 您的IP已被记录, 请立即停止您的行为!'));die;
//        dump(json_decode(json_encode("['msg' => '请填写不为空的合规关键词']",256),true));die;
        $allField = $this->scanFile("D:\phpstudy_pro\WWW\php-subco\app");
        foreach ($allField as $key => $value) {
            $body = @file($value);


            foreach ($body as $ckey => $cValue) {
                if(strstr($cValue,'throw new')){
                    $thrNewMsg[$value][$ckey + 1] = $cValue;
                }
            }
        }

        $thrNewDealMsg = $thrNewMsg;
        //剔除throw之前的所有数据判断, 目的是为了抽取实际的报错的msg和errorCode
        foreach ($thrNewDealMsg as $key => $value) {
            foreach ($value as $cKey => $cValue) {
                $thrNewDealMsg[$key][$cKey] = strstr($cValue,'throw');
           }
        }

        foreach ($thrNewDealMsg as $key => $value) {
            foreach ($value as $cKey => $cValue) {
                $exceptionName = explode(" ",$cValue)[2] ?? '';
                if(!empty($exceptionName) && empty(strstr($cValue,'\Exception'))){
                    $start = stripos($cValue,'[');
                    $end = strripos($cValue,']');
                    if(empty($start)){
                        $notMsg[$key][$cKey] = $cValue;
                        continue;
                    }
                    $allMsg[$key][$cKey]['exception'] = strstr($exceptionName,'(',true);
                    $allMsg[$key][$cKey]['error'] = substr($cValue,$start,(($end - $start) + 1));
                }else{
                    $cantFind[$key][$cKey] = $cValue;
                }
            }
        }
        dump($notMsg);
        dump($cantFind);die;
        //根据文件模块分类
        foreach ($allMsg as $key => $value) {
            $baseName = basename($key);
            $mode = strstr($key,$baseName,true);
            //公共目录
            $publicPath = "D:\phpstudy_pro\WWW\php-subco";
            $mode = trim(str_replace($publicPath,'',$mode),'\\');
            $explodeMode = explode('\\',$mode);
            if(count($explodeMode) < 3){
                $newAllMsg['common'][$key] = $value;
            }else{
                $newAllMsg[$explodeMode[2]][$key] = $value;
            }
        }
        foreach ($newAllMsg as $tkey => $tvalue) {
            foreach ($tvalue as $key => $value) {
                foreach ($value as $cKey => $cValue) {
                    $errorArray = null;
                    $errorArray = trim($cValue['error'],'[');
                    $errorArray = trim($errorArray,']');
                    $errorArray = explode('=>',$errorArray);
                    foreach ($errorArray as $vkey => $value) {
                        if($vkey % 2 == 0){
                            $value = str_replace(" ",'',str_replace("'","",$value));
                            $errorArrayAfter[$value] = str_replace(" ",'',str_replace("'","",$value));
                            $errorArrayAfter[$value] = $errorArray[$vkey + 1] ?? null;
                        }
                    }
                    if (!empty($errorArrayAfter['msg'] ?? null)) {
                        //按模块按文件分组的所有报错信息
                        $MsgArray[$tkey][$key][$cKey][$cValue['exception']] = $errorArrayAfter['msg'];
                        //按照异常类分组的所有报错信息
                        $allExcepiton[$cValue['exception']][] = $errorArrayAfter['msg'];
                        //按照模块分组的所有报错信息
                        $modeAllException[$tkey][] = $errorArrayAfter['msg'];
                    }
                }
            }
        }
//        dump($allExcepiton);
//        dump($modeAllException);die;
        if(!empty($allExcepiton ?? [])){
            foreach ($allExcepiton as $key => $value) {
                foreach ($value as $cKey => $cValue) {
                    if(strstr($cValue,'$')){
                        unset($allExcepiton[$key][$cKey]);
                    }
                }
            }
            foreach ($allExcepiton as $key => $value) {
                $allExcepiton[$key] = array_unique($value);
            }
        }

        if(!empty($modeAllException ?? [])){
            foreach ($modeAllException as $key => $value) {
                foreach ($value as $cKey => $cValue) {
                    if(strstr($cValue,'$')){
                        unset($modeAllException[$key][$cKey]);
                    }
                }
            }
            foreach ($modeAllException as $key => $value) {
                $modeAllException[$key] = array_unique($value);
            }
        }
        //写入数据

//        $inputTxt = "<?php\r\n";
//        $inputTxt .= "return [\r\n";

        foreach ($modeAllException as $key => $value) {
            if(!isset($inputTxt[$key])){
                $inputTxt[$key] = "<?php\r\n";
                $inputTxt[$key] .= "return [\r\n";
            }

            foreach ($value as $cKey => $cValue) {
                $inputTxt[$key] .= "    ".$cValue." =>".$cValue.",\r\n";
            }
            $inputTxt[$key] .= "\r\n";
        }
        foreach ($inputTxt as $key => $value) {
            $inputTxt[$key] .= '];';
        }
        $lang = '\zh-cn';
        foreach ($inputTxt as $key => $value) {
            $publicPath = "D:\phpstudy_pro\WWW\php-subco\app\lang".$lang;
            $filePath = $publicPath.'\\'.$key.'.php';
            dump($filePath);
            file_put_contents($filePath,$value);
        }
        dump($inputTxt);die;
        $test = @file("D:\phpstudy_pro\WWW\php-subco\app\lang\zh-cn\common.php");
        dump($test);
        $inputTxt = "<?php\r\n";
        $inputTxt .= "return [\r\n";
        dump($inputTxt);
        foreach ($allExcepiton as $key => $value) {
            $inputTxt .= "  '".$key."' => [\r\n";
            foreach ($value as $cKey => $cValue) {
                $inputTxt .= "      ".$cValue." =>".$cValue.",\r\n";
            }
            $inputTxt .= "  ],\r\n";
            $inputTxt .= "\r\n";
        }
        $inputTxt .= '];';
        dump($inputTxt);
        file_put_contents("D:\phpstudy_pro\WWW\php-subco\app\lang\zh-cn\common.php",$inputTxt);
        dump(1231233);die;
        dump($MsgArray ?? []);
        dump($allExcepiton ?? []);die;
        dump($cantFind ?? []);
        dump($notMsg ?? []);
        dump($allMsg ?? []);die;
        $body = @file("D:\phpstudy_pro\WWW\php-subco\app\controller\api\/v1\Test.php");
        foreach ($body as $key => $value) {
            dump($value);
        }
        die;
//        dump( file_get_contents("D:\phpstudy_pro\WWW\php-subco\app\controller\api\/v1\Test.php"));die;
        $orderSn = Request::param('order_sn');
        $string = "CREATE TABLE `sp_user_balance_limit` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `limit_sn` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '编号',
  `uid` char(10) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '用户uid',
  `type` tinyint(2) NOT NULL DEFAULT '1' COMMENT '禁止类型 1为全额禁止 2为超出额度部分可操作 3 累计额度内可操作',
  `limit_type` tinyint(2) DEFAULT '1' COMMENT '限制操作类型 1为提现 2为转赠 3为转化 4为提现+转赠',
  `balance_type` int(5) DEFAULT '88' COMMENT '账户类型 1商城分润钱包 2为广宣奖钱包 3为团队钱包 4为区代钱包 5为股东奖钱包 6为众筹钱包 7为美丽豆钱包 8为健康豆钱包 9为美丽券钱包 10为团长端总余额钱包 88全部钱包',
  `limit_price` decimal(10,3) DEFAULT '0.000' COMMENT '限制额度',
  `remark` longtext COLLATE utf8mb4_unicode_ci COMMENT '备注说明',
  `status` tinyint(2) NOT NULL DEFAULT '1' COMMENT '状态 1为正常 2为禁用 -1为删除',
  `create_time` int(11) DEFAULT NULL COMMENT '创建时间',
  `update_time` int(11) DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='限制用户账户余额操作表';";
        dump($string);
        dump(str_replace("`","",str_replace("\r\n","",$string)));die;
//        $des = (new YsePay())->DESEncrypt('123134444444444444444','hahahah');
//
//        dump($des);
//        dump((new YsePay())->decrypt($des,'hahahah'));die;
        if (!in_array(config('system.bankCardSignType111'), [2, 3])) {
            throw new FinanceException(['msg' => '此类型暂停绑定~感谢您的理解与支持']);
        }

        dump(json_encode(array (
            'charset' => 'UTF-8',
            'requestId' => '0E757ABBF95AB0A120DA1B89494662A6',
            'sign' => 'GpGt7UlctMWxLP/s8RrhsN8HZ9cyD8D65Bms4vsjJwB+AxQmnpjkn7anEzNuTUK1JqXIlnAvzbivvkUcpH6lfm5R1jBhe02J9lEpPdLA4R6Z+tpMrFgvS6F69cgyVsa4KgXrdt8wfI3OxoYdY9KiN/C/Uxj7BtcJhK8xEenY4do=',
            'signType' => 'RSA',
            'serviceNo' => 'protocolPayNotify',
            'bizResponseJson' => '{"amount":"0.01","cardType":"","channelRecvSn":"","channelSendSn":"0526143213566132","extendParams":"","openId":"","orderDesc":"","payMode":"","payTime":"","payeeFee":"0","payeeMerchantNo":"","payerFee":"0","remark":"","requestNo":"202305263424627342","settlementAmt":"","srcFee":"","state":"SUCCESS","tradeDate":"20230526","tradeSn":"02O230526400359880","userId":""}'
        )),256);die;
        $data['order_sn'] = '202305159486531634';
        dump(priceFormat('-200'));die;
        $gMap[] = ['status', '=', 1];
        $gMap[] = ['pay_status', '=', 2];
        $gMap[] = ['gift_type', '=', 3];
        $gMap[] = ['gift_number', '>', 0];
        $gMap[] = ['create_time', '<=', 1683857820];
        $allCrowdOrder = OrderGoods::where($gMap)->column('order_sn');
//        RechargeLinkDetail::update(['start_time_period'=>'1684080000','end_time_period'=>'1684166399'],['order_sn'=>$allCrowdOrder,'status'=>1]);
        dump($allCrowdOrder);die;
        if (!empty($allCrowdOrder)) {
            //将充值的订单推入队列计算团队上级充值业绩冗余明细
            foreach ($allCrowdOrder as $key => $value) {
                if (!empty($value ?? null)) {
                    $upgradeQueue = Queue::push('app\lib\job\TeamMemberUpgrade', ['order_sn' => $value, 'type' => 5], config('system.queueAbbr') . 'TeamMemberUpgrade');
                }
            }
//
//            //将充值的订单推入队列计算直推上级充值业绩冗余明细
//            foreach ($allCrowdOrder as $key => $value) {
//                if (!empty($value ?? null)) {
//                    Queue::push('app\lib\job\TeamMemberUpgrade', ['order_sn' => $value, 'type' => 6], config('system.queueAbbr') . 'TeamMemberUpgrade');
//                }
//            }
        }

        dump($allCrowdOrder);die;
//        $test = (new CrowdFundingService())->completeCrowFundingNew($data);
        dump($test);die;
        dump(json_encode(array (
            'extend' => '',
            'charset' => 'UTF-8',
            'data' => '{"head":{"version":"1.0","respTime":"20230511180710","respCode":"000000","respMsg":"成功"},"body":{"mid":"68888TS120926","orderCode":"2023003167854845199","tradeNo":"2023003167854845199","clearDate":"20230511","totalAmount":"000000000100","orderStatus":"1","payTime":"20230511180701","settleAmount":"000000000100","buyerPayAmount":"000000000100","discAmount":"000000000000","txnCompleteTime":"20201019233102","payOrderCode":"0511033138j10003","accLogonNo":"621691******0074","accNo":"621691******0074","midFee":"000000000010","extraFee":"000000000000","specialFee":"000000000000","plMidFee":"000000000000","bankserial":"","externalProductCode":"00000018","cardNo":"621691******0074","creditFlag":"","bid":"SDSMP0068888TS12092620230511060649963284","benefitAmount":"000000000000","remittanceCode":"","extend":""}}',
            'sign' => 'Frdn8Reb8wjScQb0E3yXBDrl54xITvWWABKp9PS6HQCe23uJ8mYiD0YkTZ7+x/lyB7zqliKFpQ0uMAFLywUDakgvAarTy2s56KZwTVml/X4WBWK/Irgdx2+dKZEbM5EWw5ln0+Zrr8qOlYMipJhQVLBtaCnItNGdJNGKmKKJmxjyKrT99hUKC8K4Uo6+22K7Cfgz6ShYMTmTBt5DGoBkvdMND9Slz5u6f2Wtif9hITloTsYhTSJ01XssAUTFYChZfT/XICTIv3hEATHc9WykuR0VD/nH8IZ1GiF8mGJDrIF33svsnk01fmqg1iRwzKJq7v9i9nl61hhTS9+dHThbGA==',
            'signType' => '01',
        ),256));die;
//        $successPeriod = array (
//            0 =>
//                array (
//                    'activity_code' => 'C202304088726181159',
//                    'round_number' => 1,
//                    'period_number' => 1,
//                ),
//        );
//        $oWhere[] = ['pay_status', '=', 2];
//        $oWhere[] = ['status', '=', 1];
//        $oWhere[] = ['after_status', 'in', [1, -1]];
//        $orderGoods = OrderGoods::with(['orderInfo'])->where(function ($query) use ($successPeriod) {
//            foreach ($successPeriod as $key => $value) {
//                ${'where' . ($key + 1)}[] = ['crowd_code', '=', $value['activity_code']];
//                ${'where' . ($key + 1)}[] = ['crowd_round_number', '=', $value['round_number']];
//                ${'where' . ($key + 1)}[] = ['crowd_period_number', '=', $value['period_number']];
//            }
//            for ($i = 0; $i < count($successPeriod); $i++) {
//                $allWhereOr[] = ${'where' . ($i + 1)};
//            }
//            $query->whereOr($allWhereOr);
//        })->where($oWhere)->select()->toArray();
        foreach ($orderGoods as $key => $value) {
            $crowdKey = $value['crowd_code'] . '-' . $value['crowd_round_number'] . '-' . $value['crowd_period_number'];
            $divideData[$key]['order_sn'] = $value['order_sn'];
            $divideData[$key]['order_uid'] = $value['orderInfo']['uid'];
            $divideData[$key]['goods_sn'] = $value['goods_sn'];
            $divideData[$key]['sku_sn'] = $value['sku_sn'];
            $divideData[$key]['crowd_code'] = $value['crowd_code'];
            $divideData[$key]['crowd_round_number'] = $value['crowd_round_number'];
            $divideData[$key]['crowd_period_number'] = $value['crowd_period_number'];
            $divideData[$key]['type'] = 8;
            $divideData[$key]['vdc'] = $periodInfos[$crowdKey]['reward_scale'] ?? 0;
            $divideData[$key]['level'] = $value['user_level'] ?? 0;
            $divideData[$key]['link_uid'] = $value['orderInfo']['uid'];
            $divideData[$key]['divide_price'] = priceFormat(($value['real_pay_price'] - $value['total_fare_price']) * $divideData[$key]['vdc']);
            $divideData[$key]['real_divide_price'] = $divideData[$key]['divide_price'];
            $divideData[$key]['arrival_status'] = 2;
            $divideData[$key]['total_price'] = priceFormat(($value['real_pay_price'] - $value['total_fare_price']));
            $divideData[$key]['purchase_price'] = priceFormat(($value['real_pay_price'] - $value['total_fare_price']));
            $divideData[$key]['price'] = $value['price'];
            $divideData[$key]['count'] = $value['count'];
            $divideData[$key]['vdc_genre'] = 2;
            $divideData[$key]['divide_type'] = 2;
            $divideData[$key]['remark'] = '福利活动奖励(' . $crowdKey . ')';
            //上级感恩奖奖励
            if (!empty($linkUser[$value['orderInfo']['uid']] ?? [])) {
                $topDivideData[$key]['order_sn'] = $value['order_sn'];
                $topDivideData[$key]['order_uid'] = $value['orderInfo']['uid'];
                $topDivideData[$key]['goods_sn'] = $value['goods_sn'];
                $topDivideData[$key]['sku_sn'] = $value['sku_sn'];
                $topDivideData[$key]['crowd_code'] = $value['crowd_code'];
                $topDivideData[$key]['crowd_round_number'] = $value['crowd_round_number'];
                $topDivideData[$key]['crowd_period_number'] = $value['crowd_period_number'];
                $topDivideData[$key]['type'] = 8;
                $topDivideData[$key]['is_grateful'] = 1;
                $topDivideData[$key]['vdc'] = $gratefulVdcOne ?? 0;
                $topDivideData[$key]['level'] = $linkUserInfo[$value['orderInfo']['uid']]['vip_level'] ?? 0;
                $topDivideData[$key]['link_uid'] = $linkUser[$value['orderInfo']['uid']];
                $topDivideData[$key]['divide_price'] = priceFormat(($divideData[$key]['real_divide_price']) * $topDivideData[$key]['vdc']);
                $topDivideData[$key]['real_divide_price'] = $topDivideData[$key]['divide_price'];
                $topDivideData[$key]['arrival_status'] = 2;
                $topDivideData[$key]['total_price'] = priceFormat(($divideData[$key]['real_divide_price']));
                $topDivideData[$key]['purchase_price'] = priceFormat(($divideData[$key]['real_divide_price']));
                $topDivideData[$key]['price'] = $value['price'];
                $topDivideData[$key]['count'] = $value['count'];
                $topDivideData[$key]['vdc_genre'] = 2;
                $topDivideData[$key]['divide_type'] = 2;
                $topDivideData[$key]['remark'] = '福利活动感恩奖奖励(' . $crowdKey . ')';
            }
            //赠送美丽豆明细
            if ($value['gift_type'] > -1 && doubleval($value['gift_number']) > 0) {
                switch ($value['gift_type']) {
                    case 1:
                        $integralDetail[$key]['order_sn'] = $value['order_sn'];
                        $integralDetail[$key]['uid'] = $value['orderInfo']['uid'];
                        $integralDetail[$key]['goods_sn'] = $value['goods_sn'] ?? null;
                        $integralDetail[$key]['sku_sn'] = $value['sku_sn'] ?? null;
                        $integralDetail[$key]['crowd_code'] = $value['crowd_code'] ?? null;
                        $integralDetail[$key]['crowd_round_number'] = $value['crowd_round_number'] ?? null;
                        $integralDetail[$key]['crowd_period_number'] = $value['crowd_period_number'] ?? null;
                        $integralDetail[$key]['change_type'] = 6;
                        $integralDetail[$key]['remark'] = '福利活动参与赠送(' . $crowdKey . ')';
                        $integralDetail[$key]['integral'] = $value['gift_number'];
                        break;
                    case 2:
                        $healthyDetail[$key]['order_sn'] = $value['order_sn'];
                        $healthyDetail[$key]['uid'] = $value['orderInfo']['uid'];
                        $healthyDetail[$key]['goods_sn'] = $value['goods_sn'] ?? null;
                        $healthyDetail[$key]['sku_sn'] = $value['sku_sn'] ?? null;
                        $healthyDetail[$key]['crowd_code'] = $value['crowd_code'] ?? null;
                        $healthyDetail[$key]['crowd_round_number'] = $value['crowd_round_number'] ?? null;
                        $healthyDetail[$key]['crowd_period_number'] = $value['crowd_period_number'] ?? null;
                        $healthyDetail[$key]['change_type'] = 1;
                        $healthyDetail[$key]['remark'] = '健康活动参与赠送(' . $crowdKey . ')';
                        $healthyDetail[$key]['price'] = $value['gift_number'];
                        $healthyDetail[$key]['pay_type'] = 77;
                        //判断渠道, 非福利渠道的都是商城渠道, 只有充值的是消费型股东渠道
                        switch ($value['orderInfo']['order_type'] ?? 6) {
                            case '6':
                                $healthyDetail[$key]['healthy_channel_type'] = PayConstant::HEALTHY_CHANNEL_TYPE_CROWD;
                                break;
                            default:
                                $healthyDetail[$key]['healthy_channel_type'] = PayConstant::HEALTHY_CHANNEL_TYPE_SHOP;
                                break;
                        }
                        //福利活动健康豆渠道默认为2
                        $healthyChannelType = PayConstant::HEALTHY_CHANNEL_TYPE_CROWD;
                        break;
                }
            }
        }
        //插入积分明细
        $sqls = null;
        if (!empty($integralDetail ?? [])) {
            //检查是否有已经赠送的, 如有则直接剔除
            $eiWhere[] = ['status', '=', 1];
            $existIntegralRecord = IntegralDetail::where(function ($query) use ($successPeriod) {
                foreach ($successPeriod as $key => $value) {
                    ${'where' . ($key + 1)}[] = ['crowd_code', '=', $value['activity_code']];
                    ${'where' . ($key + 1)}[] = ['crowd_round_number', '=', $value['round_number']];
                    ${'where' . ($key + 1)}[] = ['crowd_period_number', '=', $value['period_number']];
                }
                for ($i = 0; $i < count($successPeriod); $i++) {
                    $allWhereOr[] = ${'where' . ($i + 1)};
                }
                $query->whereOr($allWhereOr);
            })->where($eiWhere)->column('order_sn');
            if (!empty($existIntegralRecord)) {
                foreach ($integralDetail as $key => $value) {
                    if (in_array($value['order_sn'], $existIntegralRecord)) {
                        unset($integralDetail[$key]);
                    }
                }
            }
        }
        if (!empty($integralDetail ?? [])) {
            $integralDetail = array_values($integralDetail);

            //批量自增
            $batchSqlIntegralData['list'] = $integralDetail;
            $batchSqlIntegralData['db_name'] = 'sp_user';
            $batchSqlIntegralData['id_field'] = 'uid';
            $batchSqlIntegralData['operate_field'] = 'integral';
            $batchSqlIntegralData['value_field'] = 'integral';
            $batchSqlIntegralData['operate_type'] = 'inc';
            $batchSqlIntegralData['sear_type'] = 2;
            $batchSqlIntegralData['other_map'] = 'status = 1';
            $test1=(new IntegralDetail())->DBBatchIncOrDecBySql($batchSqlIntegralData);

            //批量新增明细
            $batchSqlIntegralDetailData['list'] = $integralDetail;
            $batchSqlIntegralDetailData['db_name'] = 'sp_integral_detail';
            $batchSqlIntegralDetailData['sear_type'] = 2;
            $test2=(new IntegralDetail())->DBSaveAll($batchSqlIntegralDetailData);
//                (new IntegralDetail())->saveAll(array_values($integralDetail));
        }
        dump($test1);
        dump($test2);die;
//        $test = (new UserPrivacy())->user_no_check('36072519910719342X');
        $list = IntegralDetail::where(['status' => 1, 'change_type' => 7])->field('id,uid,integral')->select()->toArray();
//        dump($list);die;
        if(!empty($list)){
            foreach ($list as $key => $value) {
                User::where(['uid' => $value['uid'], 'status' => 1])->dec('integral', $value['integral'])->update();
                IntegralDetail::where(['id' => $value['id'], 'status' => 1])->save(['status' => -1]);
            }
        }
        dump($list ?? '并没有充值赠送哦');die;
        dump($test ?? []);die;
        $test = array (
            'uid' => 'NYugskKSbD',
            'name' => '梁真真',
            'mobile' => '13928494931',
            'no' => 'H04272140',
            'bank_account' => '623058221392849491',
            'code' => '1234',
            'version' => 'v1',
        );
        $userGiftCrowdBalance = (new ZhongShuKePay())->add($test);
        dump($userGiftCrowdBalance);die;
        $data['total_price'] = 2000;
        if (empty($userGiftCrowdBalance['res'] ?? false)) {
            //如果加上本次刚好超过则允许超过的部分赠送
            $morePrice = $userGiftCrowdBalance['gift_price'] - ($userGiftCrowdBalance['crowd_price'] + $data['total_price']);
            if ($morePrice < 0) {
                $addGoods['gift_number'] = priceFormat(abs($morePrice));
            } else {
                $addGoods['gift_number'] = 0;
            }
        }
        dump($addGoods ?? []);die;
            dump($test);die;
//        $list = HealthyBalanceDetail::where(['create_time' => 1681717420, 'status' => 1])->select()->toArray();
//        foreach ($list as $key => $value) {
//            User::where(['uid' => $value['uid']])->dec('healthy_balance', $value['price'])->update();
//        }
//        dump($list);;die;
        $data['total_price'] = 200;
        if(((string)$data['total_price'] % 100) !== 0){
            dump('不是100的整数倍');
        }else{
            dump('是100的整数倍');
        }
        dump(1231313113);die;
//        dump((new BankCard())->getBankInfoByBankCard('6226220326140182'));die;
        $map[] = ['status','=',1];
        $list = TeamPerformance::where($map)->field('id,link_uid')->select()->toArray();
        foreach ($list as $key => $value) {
            $exist = $value['link_uid'];
            if(!isset($existOrder[$exist])){
                $existOrder[$exist] = $value;
            }else{
                $chongfu[$exist] = $value;
                TeamPerformance::update(['status'=>-1],['id'=>$value['id']]);
            }
        }
        dump($chongfu ?? []);die;
        $map[] = ['','exp',Db::raw('goods_sn in (SELECT `goods_sn` FROM `sp_goods_spu` WHERE `create_time` >= 1680688374 and status = 1 )')];
        $map[] = ['status','=',1];
        $orderGoods = GoodsSku::where($map)->withoutField('id')->buildSql();
        dump($orderGoods);die;
        $allGoodsImages = GoodsImages::where(['goods_sn'=>$orderGoods,'status'=>1])->withoutField('id')->buildSql();
        dump($allGoodsImages);die;
//        $allGoodsImages[] = '/image/20230324181002163491.jpg';
////        $allGoodsImages[] = '/image/20230404152701455956.jpg';
        foreach ($allGoodsImages as $item) {
            $url = 'https://oss-qilai.mlhcmk.com'.$item;
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // 信任任何证书
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
            $file = curl_exec($ch);
            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($statusCode != 200) {
                dump($item . '文件有问题咯');
                continue;
            }
            curl_close($ch);
            $filename = pathinfo($url, PATHINFO_BASENAME);

            $resource = fopen("E:/qianyi/images/" . $filename, 'a');
            fwrite($resource, $file);
            fclose($resource);
        }
        dump('都下载好咯');die;
        dump(config('system.defaultAllowPayType') ?? [1, 6]);die;
        dump(env('LIMIT.whiteIpList') ?? null);die;
        $string = 'https://oss-cm.mlhcmk.com/excel/%E5%BF%AB%E5%95%86%E6%89%93%E6%AC%BE%E6%8F%90%E7%8E%B0%E5%88%97%E8%A1%A820230330152746uA07ax58gl.xlsx';
        dump(addslashes($string));die;
        //财务号
        $withdrawFinanceAccount = ['WbzBXu6Bhy', '8NhXjx7TGr', '08bHybNaW1', 'npuh1syedM', '8q3Mn4dWDb', '32Po7PAmOq', 'bnNtLgLO0l', 'SD55DQSPPr', 'OQ3dxIHWn9'];

        //查看用户累计充值金额
        $rMap[] = ['type', '=', 1];
        $rMap[] = ['status', '=', 1];
        $rMap[] = ['create_time','>=',1677081600];
        $userTotalRecharge = CrowdfundingBalanceDetail::where($rMap)->where(function ($query) use ($withdrawFinanceAccount) {
            $map1[] = ['change_type', '=', 1];
            $map2[] = ['is_transfer', '=', 1];
            $map2[] = ['transfer_from_uid', 'in', $withdrawFinanceAccount];
            $query->whereOr([$map1, $map2]);
        })->group('uid')->field('uid,sum(price) as price')->select()->toArray();
//        dump($userTotalRecharge);die;
        //查看用户总提现金额
        $hwMap[] = ['withdraw_type', '=', 7];
        $hwMap[] = ['payment_status', 'in', [1,2]];
        $hwMap[] = ['check_status', 'in', [1,3]];
        $hwMap[] = ['status', '=', 1];
        $hwMap[] = ['create_time','>=',1677081600];
        $userTotalWithdraw = Withdraw::where($hwMap)->group('uid')->field('uid,sum(total_price) as price')->select()->toArray();
        foreach ($userTotalRecharge as $key => $value) {
            $userTotalRechargeInfo[$value['uid']] = $value['price'];
        }
        foreach ($userTotalWithdraw as $key => $value) {
            $userTotalWithdrawInfo[$value['uid']] = $value['price'];
        }
        $totalPrice = 0;
        foreach ($userTotalRechargeInfo as $key => $value) {
            if($value - ($userTotalWithdrawInfo[$key] ?? 0) > 0){
                $userList[$key] = $value - ($userTotalWithdrawInfo[$key] ?? 0);
                $totalPrice += ($value - ($userTotalWithdrawInfo[$key] ?? 0));
            }
        }
//        dump(count($userList));die;
        $uMap[] = ['uid','in',array_keys($userList)];
        $uMap[] = ['status','=',1];
        $uMap[] = ['can_buy','=',1];
        $uMap[] = ['uid','not in',['BOgkHzHzqA','xsxtLBlyaX']];
        $userInfo = User::where($uMap)->field('uid,name,phone,team_vip_level,create_time')->select()->toArray();
        $teamMemberVdc = TeamMemberVdc::where(['status'=>1])->column('name','level');
        $number = 0;
        foreach ($userInfo as $key => $value) {
            if(!empty($userList[$value['uid']] ?? [])){
                $export[$number]['uid'] = $value['uid'];
                $export[$number]['name'] = $value['name'];
                $export[$number]['phone'] = $value['phone'];
                $export[$number]['price'] = $userList[$value['uid']];
                $export[$number]['vip_name'] = $teamMemberVdc[$value['team_vip_level']] ?? '非团队会员';
                $export[$number]['create_time'] = $value['create_time'];
                $number ++;
            }
        }
//        dump(count($export));die;
        (new Office())->exportExcel('ttt','Xlsx',$export);
        dump($totalPrice);
        dump($export);die;
        dump($userTotalRecharge);die;
        dump($list);die;
        //财务号
        $withdrawFinanceAccount = ['WbzBXu6Bhy', '8NhXjx7TGr', 'SD55DQSPPr','OQ3dxIHWn9'];
        dump($test ?? 123132);die;
        $test = (new RechargeTopLinkRecord())->record(['order_sn'=>'CZ202206127809496777']);
        dump($test);die;
//        $test = (new \app\lib\services\TeamDivide())->divideForTopUser(['order_sn'=>$orderSn,'searType'=>2]);
//        $add['order_sn'] = (new CodeBuilder())->buildWithdrawOrderNo();
//        $add['uid'] = '123123';
//        $add['user_phone'] = '12312312';
//        $add['user_real_name'] = '11111111111';
//        $add['withdraw_type'] = 1;
//        $add['total_price'] = priceFormat($data['total_price'] ?? 1);
//        $add['handing_scale'] = priceFormat($data['handing_scale'] ?? 0);
//        $add['handing_fee'] = priceFormat($data['handing_fee'] ?? 0);
//        $add['price'] = priceFormat($data['price'] ?? 1);
//        $add['type'] = $data['type'] ?? 2;
//
//        //是否用了关联帐号的身份信息
//        $add['related_user'] = $data['related_user'] ?? null;
//        $test = Db::name('withdraw')->insertGetId($add);
//        dump($test);die;
//        $param['uid'] = 'vqAMmusGS3';
//        $param['bank_card_no'] = '';
//        $param['real_name'] = '';
//        $param['id_card'] = '';
//        $param['bank_phone'] = '';
//        $param['card_type'] = '1';
//        $test = (new SandPay())->agreementSignSms($param);
//        dump($test);die;

//        $param['uid'] = 'vqAMmusGS3';
//        $param['sms_code'] = '182246';
//        $test = (new SandPay())->agreementContract($param);
//
//        $param['uid'] = 'vqAMmusGS3';
//        $param['out_trade_no'] = '1343463345668888';
//        $param['sign_no'] = 'SDSMP00688880111878620230219083155240530';
//        $test = (new SandPay())->agreementUnSign($param);
//        dump($test);die;

//        $param['uid'] = 'vqAMmusGS3';
//        $param['out_trade_no'] = '202302265736777777';
//        $param['bank_phone'] = '';
//        $param['sign_no'] = 'SDSMP00688880111878620230219083934051956';
//        $test = (new SandPay())->agreementPaySms($param);
//
//        $param['uid'] = 'vqAMmusGS3';
//        $param['out_trade_no'] = '202302265736777777';
//        $param['bank_phone'] = '';
//        $param['sign_no'] = 'SDSMP00688880111878620230219083934051956';
//        $param['sms_code'] = '918461';
//        $param['order_create_time'] = time();
//        $param['total_fee'] = 1;
//        $test = (new SandPay())->agreementSmsPay($param);

//        //退款
        for ($i = 1; $i <= 2; $i++) {
            $param['uid'] = 'vqAMmusGS3';
            $param['out_trade_no'] = '202302265736777777';
            $param['out_refund_no'] = '2028999999999900776'.$i;
            $param['refund_fee'] = 0.5;
            $test = (new SandPay())->agreementRefund($param);
            dump($test);
//            $i++;
        }
        dump(12313);die;

//        $param['out_trade_no'] = '202302195736900316';
//        $test = (new SandPay())->agreementNotice($param);
//          $param = array (
//              'extend' => '',
//              'charset' => 'UTF-8',
//              'data' => '{"head":{"version":"1.0","respTime":"20230219205559","respCode":"000000","respMsg":"成功"},"body":{"mid":"6888801118786","orderCode":"202302195736900316","tradeNo":"202302195736900316","clearDate":"20230219","totalAmount":"000000000100","orderStatus":"1","payTime":"20230219205559","settleAmount":"000000000100","buyerPayAmount":"000000000100","discAmount":"000000000000","txnCompleteTime":"20230219205559","payOrderCode":"2023021900028701000245850201401","accLogonNo":"621691******0074","accNo":"621691******0074","midFee":"000000000010","extraFee":"000000000000","specialFee":"000000000000","plMidFee":"000000000000","bankserial":"5T620202302200386582956038658295","externalProductCode":"00000018","cardNo":"621691******0074","creditFlag":"","bid":"SDSMP00688880111878620230219083934051956","benefitAmount":"000000000000","remittanceCode":"","extend":""}}',
//              'sign' => 'ETjmbfHL/KPMznrSVtEcZcEVrKVQEtwH2UCHyWl4KpWFASf+gBNKrXpAxIfNcrY+Av0omrB0FbLnyncVAKzBJ9ONozwODkUuu4+wLPyLaEcEqBWxhoSyW6iirmUERwuG8YBMpXH4ZNYW1JpgV+QUopJENV8dW9FghIB5+jeT7ihmMcy6UJxFLfjU6pVlKFNfiks45+gEK8kbEhHQ1IymUEf6ZXPCAD+SyWMjjHXFQc8/NIaaZXpyGnC9KJcjJ1bECUMH6sbL/VxNXx4Mfx789wSfhtxpA/qUcDSR5kVdyS8tnHaitx3foJvc+41/Pi4uloy6V2D+2aXeKUx1DHdzng==',
//              'signType' => '01',
//              'version' => 'v1',
//          );
//        $test = (new SandPayCallback())->agreementPayCallBack($param);
//        $test = (new CrowdFunding())->checkSuccessPeriod(['activity_code' => 'C20220613425297020', 'round_number' => 1, 'period_number' => 124, 'searType' => 2]);
//
//        $test = (new SandPayCallback())->agreementRefundCallback(array (
//            'extend' => '',
//            'charset' => 'UTF-8',
//            'data' => '{"head":{"version":"1.0","respTime":"20230226155224","respCode":"000000","respMsg":"成功"},"body":{"mid":"6888801118786","orderCode":"20288888888888900316","tradeNo":"20288888888888900316","clearDate":"20230226","totalAmount":"000000000100","orderStatus":"1","payTime":"20230226155223","refundAmount":"000000000100","refundMarketAmount":"000000000000","surplusAmount":"000000000000","buyerPayAmount":"000000000000","txnCompleteTime":"20230226155224","midFee":"000000000000","plMidFee":"000000000000","discAmount":"000000000000","payOrderCode":"2023022600028941000169840111408","oriOrderCode":"202302265736784815","extend":"","hadRefundFee":"000000000010"}}',
//            'sign' => 'KHrbVdDShrxwt2rTX6UiNz22Yyt4O0bjoeTl0bJR2PrWoQVkbdtq5YDcYS7BhT0qUAzasW6BWhiRQi6UJ+b9HPu4q4YzXMkWoxP7SWJT50b3MHBW8RFwJoWmAcBsyr6q6noCtdvOGKgvSDqGwq+srDmLA5lx9f+sjUT9M8IQIjxkvaZ0bPM87K/PTrcCw5BbsK9dcUQ6aiQg9krSsV0V12rpZZINNqArnntsKKRtPSw0tKCGNDFAWLTkozSiBRaxZaCPjOejIKOw0QXiGljjTIO7+7dfZ4zJc2F4LEeVVeKPeJNO+GEAHmKixMbMs/qjffjiRzDu+N2CUe2o1rAu7A==',
//            'signType' => '01',
//            'version' => 'v1',
//        ));
        dump($test ?? false);die;
        dump(1231);die;
        $key = 0;
        $aBalance[$key]['order_sn'] = '123123123';
        //100%返本金
        $aBalance[$key]['price'] = priceFormat(1 * 1);
        $aBalance[$key]['type'] = 1;
        $aBalance[$key]['uid'] = '12312313';
        $aBalance[$key]['change_type'] = 3;
        $aBalance[$key]['crowd_code'] = "C20220613425297020";
        $aBalance[$key]['crowd_round_number'] = 1;
        $aBalance[$key]['crowd_period_number'] = 121;
        $aBalance[$key]['remark'] = '福利活动成功返本金(' . 123123132 . ')';
        $chunkaBalance = array_chunk($aBalance, 500);
        foreach ($chunkaBalance as $key => $value) {
            $sqls = '';
            $itemStrs = '';
            $sqls = sprintf("INSERT INTO sp_crowdfunding_balance_detail (uid,order_sn,pay_no,belong,type,price,change_type,status,remark,create_time,update_time,crowd_code,crowd_round_number,crowd_period_number,is_grateful) VALUES ");
            $createTime = time();
            foreach ($value as $items) {
                $itemStrs = '( ';
//                $itemStrs .= sprintf("'%s', %d", $items['uid'], $items['order_sn'], 'system', $items['belong'] ?? 1, $items['type'], $items['price'], $items['change_type'], 1, $items['remark'], $createTime, $createTime, $items['crowd_code'], $items['crowd_round_number'], $items['crowd_period_number'], $items['is_grateful'] ?? 2);
                $itemStrs .= ("'" . $items['uid'] . "'," . "'" . $items['order_sn'] . "'," . "''" . "," . ($items['belong'] ?? 1) . "," . $items['type'] . "," . $items['price'] . "," . $items['change_type'] . "," . "1" . "," . "'" . $items['remark'] . "'" . "," . $createTime . "," . $createTime . "," . "'" . $items['crowd_code'] . "'" . "," . "'" . $items['crowd_round_number'] . "'" . "," . "'" . $items['crowd_period_number'] . "'" . "," . ($items['is_grateful'] ?? 2));
                $itemStrs .= '),';
                $sqls .= $itemStrs;
            }

            // 去除最后一个逗号，并且加上结束分号
            $sqls = rtrim($sqls, ',');
            $sqls .= ';';
            dump($sqls);die;
            if (!empty($itemStrs ?? null)) {
                $test = Db::query($sqls);
            }
        }
        dump($test ?? false);die;
        dump(1231);die;
//        $test = (new CrowdFunding())->checkSuccessPeriod(['activity_code' => 'C20220613425297020', 'round_number' => 1, 'period_number' => 124, 'searType' => 2]);
        dump($test);die;
        dump(12331);die;
        $dWhere[] = ['crowd_code', '=', "C20220613425297020"];
        $dWhere[] = ['crowd_round_number', '=', 1];
        $dWhere[] = ['crowd_period_number', '=', 121];
        $dWhere[] = ['type', 'in', [8, 5, 7]];
        $dWhere[] = ['arrival_status', '=', 2];
        $dWhere[] = ['status', '=', 1];
        $notSuccessPeriod = [];
        $divideListSql = \app\lib\models\Divide::field('id')->where($dWhere)->when(!empty($notSuccessPeriod), function ($query) use ($notSuccessPeriod) {
            $notSuccessPeriod = array_values($notSuccessPeriod);
            foreach ($notSuccessPeriod as $key => $value) {
                ${'where' . ($key + 1)}[] = ['activity_code', '=', $value['crowd_code']];
                ${'where' . ($key + 1)}[] = ['round_number', '=', $value['crowd_round_number']];
                ${'where' . ($key + 1)}[] = ['period_number', '=', $value['crowd_period_number']];
            }
            for ($i = 0; $i < count($notSuccessPeriod); $i++) {
                $allWhereOr[] = ${'where' . ($i + 1)};
            }
            $query->whereOr($allWhereOr);
        })->buildSql();
//        $acWhere[] = ['', 'exp', Db::raw("id in (select id from $divideListSql a)")];
//        $acWhere[] = ['arrival_status', '=', 2];
//        $acWhere[] = ['status', '=', 1];
        dump($acWhere);die;
        Divide::update(['arrival_status' => 1, 'arrival_time' => time()], $acWhere);
        $param['uid'] = 'vqAMmusGS3';
        $param['bank_card_no'] = '';
        $param['real_name'] = '';
        $param['id_card'] = '';
        $param['bank_phone'] = '';
        $param['card_type'] = '1';
        $test = (new SandPay())->agreementSignSms($param);
        dump($test);die;
        dump(12313);die;
        //是否存在会员上级倒挂, 头尾相接情况
        $allMember  = \app\lib\models\TeamMember::where(['status'=>1])->field('uid,team_chain,link_superior_user')->select()->toArray();
        foreach ($allMember as $key => $value) {
            $allMemberInfo[$value['uid']] = $value;
        }
        foreach ($allMemberInfo as $key => $value) {
            if(!empty($value['link_superior_user']) && !empty($allMemberInfo[$value['link_superior_user']] ?? null)){
                if(strpos($allMemberInfo[$value['link_superior_user']]['team_chain'], $value['uid']) !== false){
                    $errorUid[] = $value;
                }
            }
        }
        dump($errorUid ?? []);die;
        if (!empty($userInfo['member']['link_superior_user'] ?? null) && $topUser != ($userInfo['member']['link_superior_user'])) {
            if (!empty($topUserInfo['member']['team_chain'] ?? null) && (strpos($topUserInfo['member']['team_chain'], $uid) !== false)) {
                throw new MemberException(['errorCode' => 1600106]);
            }
        }
        $info['create_time'] = '2023-02-01 17:18:05';
        $info['uid'] = '12313';
        $rMap[] = ['start_time_period', '=', strtotime(date('Y-m-d', strtotime($info['create_time'])) . ' 00:00:00')];
        $rMap[] = ['status', '=', 1];
        $rMap[] = ['uid', '=', $info['uid']];;
        dump($rMap); die;
//        $test = (new RechargeLink())->summaryAllUserTodayLastRechargeRate([]);
        dump($test);die;
//        dump(explode(' ', "2023-2-4 16:54:54")[1]);die;
//        $data['uid'] = 'YDksJ1elFd';
        $userTodayWithdrawLimit = (new RechargeLink())->userSubordinateRechargeRate(['uid' => $data['uid'], 'clearCache' => true, 'time_type' => 1]);
        dump($userTodayWithdrawLimit);die;
        $extraWithdrawAmount = UserExtraWithdraw::where(function ($query) {
            $map1[] = ['valid_type', '=', 1];
            $map2[] = ['valid_type', '=', 2];
            $map2[] = ['valid_start_time', '<=', time()];
            $map2[] = ['valid_end_time', '>', time()];
            $query->whereOr([$map1, $map2]);
        })->where(['status' => 1,'uid'=>$data['uid']])->sum('price');
        dump($extraWithdrawAmount);die;
//        $data['uid'] = 'npuh1syedM';
//        $data['total_price'] = '3000';
//        $userTodayWithdrawLimit = (new RechargeLink())->userSubordinateRechargeRate(['uid' => $data['uid'], 'clearCache' => true, 'time_type' => 1]);
//        dump($userTodayWithdrawLimit);die;
        $test = (new RechargeLink())->summaryAllUserTodayLastRechargeRate([]);
        dump($test);die;
//        $param['uid'] = '123132123';
//        $param['bank_card_no'] = '';
//        $param['real_name'] = '';
//        $param['id_card'] = '';
//        $param['bank_phone'] = '';
//        $param['card_type'] = '1';
//        $test = (new SandPay())->agreementSignSms($param);
//        dump((string)date('H:i:s', 1675094399) == '23:59:59');die;
//        $test = (new RechargeLink())->summaryAllUserTodayLastRechargeRate([]);
//        $data['order_sn'] = 'D202302028469777576';
//        $data['device_sn'] = '865328067948728';
//        $test = (new Device())->divideForDevice($data);
//        dump($test);die;
        $allTeam = (new \app\lib\services\TeamDivide())->getTeamAllUserGroupByLevel('npuh1syedM');
        if (!empty($allTeam['dirUser'] ?? [])) {
            foreach ($allTeam['dirUser'] as $key => $value) {
                foreach ($value as $cKey => $cValue) {
                    if(!isset($dirUserLevel[$cKey])){
                        $dirUserLevel[$cKey] = 0;
                    }
                    $dirUserLevel[$cKey] += 1;
                }
            }
        }
        dump($dirUserLevel);
        $aNextLevel['recommend_level'] = '["2"]';
        $aNextLevel['recommend_number'] = '["3"]';
        if(!empty($dirUserLevel ?? [])){
            $recommendLevel = json_decode($aNextLevel['recommend_level'], true);
            $recommendNumber = json_decode($aNextLevel['recommend_number'], true);
            $directAccessNumber = 0;
            $directConditionNumber = count($recommendLevel);
            if (!empty($recommendLevel)) {
                foreach ($recommendLevel as $key => $value) {
                    dump($value);
                    if (isset($dirUserLevel[$value])) {
                        if ($recommendNumber[$key] <= ($dirUserLevel[$value] ?? 0)) {
                            $directAccessNumber += 1;
                        }
                    }
                }
                dump($directAccessNumber);
                if ($directAccessNumber == $directConditionNumber) {
                    $log['directMsg'] = '直推团队升级指定的等级和人数符合要求';
                    $directRes = true;
                }
            } else {
                if (isset($directTeam['userLevel'][$aNextLevel['recommend_level']])) {
                    if ($aNextLevel['recommend_number'] <= $directTeam['userLevel'][$aNextLevel['recommend_level']]['count']) {
                        $log['directMsg'] = '直推团队升级指定的等级和人数符合要求';
                        $directRes = true;
                    }
                }
            }
        }
        dump($log);
        dump(123133);die;
        $dupMap[] = ['link_superior_user', '=', 'npuh1syedM'];
        $dupMap[] = ['status', '=', 1];
        $dupMap[] = ['level', '>', 0];
        $directTeamUserPfm = TeamMemberModel::where($dupMap)->column('team_sales_price', 'uid');
        dump($directTeamUserPfm);
        arsort($directTeamUserPfm);
        dump($directTeamUserPfm);
        dump(array_slice($directTeamUserPfm,2,1));die;
        dump($allTeam);die;
//        $startTime = "1673452800";
//        for ($i = 0; $i <= 15; $i++) {
//            $list = Db::query("select link_uid,sum(price) as price,start_time_period FROM sp_recharge_link_detail where link_uid in (select uid from sp_recharge_link where status = 1 and start_time_period  = '" . ($startTime + ($i * 86400)) . "') and start_time_period  = '" . ($startTime + ($i * 86400)) . "' and status = 1 group by link_uid");
//            foreach ($list as $key => $value) {
//                RechargeLink::update(['price' => $value['price']], ['uid' => $value['link_uid'], 'start_time_period' => $value['start_time_period'], 'status' => 1]);
//            }
//        }

        dump(12313);die;
        $data['uid'] = 'XozyAosJox';
        $data['total_price'] = '3000';
        $userTodayWithdrawLimit = (new RechargeLink())->userSubordinateRechargeRate(['uid' => $data['uid'], 'clearCache' => true, 'time_type' => 1]);
        if (empty($userTodayWithdrawLimit)) {
            throw new FinanceException(['msg' => '额度计算异常']);
        }
        if (!empty($userTodayWithdrawLimit['withdrawLimit'] ?? false)) {
            $userHistoryAmount = (new RechargeLink())->checkUserWithdrawAndTransfer(['uid' => $data['uid'], 'time_type' => 1]);
            dump($userHistoryAmount);
            if ((string)($data['total_price'] + $userHistoryAmount) > (string)($userTodayWithdrawLimit['totalCanWithdrawAmount'] ?? 0)) {
                throw new FinanceException(['msg' => '今日累计可以提现额度为' . ($userTodayWithdrawLimit['totalCanWithdrawAmount'] ?? 0) . ' 当前已操作的金额为' . (priceFormat($userHistoryAmount ?? 0)) . ' 你填写的额度已超过, 请减少您的提现额度']);
            }
        }
        dump(1231231);die;
        $map[] = ['status','=',1];
        $map[] = ['','exp',Db::raw('order_sn in (select order_sn from (select order_sn,count(id) as number FROM sp_ship_order where status = 1 GROUP BY order_sn order by number desc) a  where a.number > 1)')];
        $list = ShipOrder::where($map)->select()->toArray();
        foreach ($list as $key => $value) {
            $exist = $value['order_sn'].$value['split_status'].$value['goods_sku'].$value['split_number'];
            if(!isset($existOrder[$exist])){
                $existOrder[$exist] = $value;
            }else{
//                $chongfu[$exist] = $value;
                ShipOrder::update(['status'=>-1],['id'=>$value['id']]);
            }
        }
        dump($existOrder ?? []);die;
        dump(12313123);die;
//        $param = array (
////            'userList' =>
////                array (
////                    0 =>
////                        array (
////                            'uid' => '9K5GupldKu',
////                            'phone' => '13697498641',
////                            'price' => '1',
////                            'valid_type' => 1,
////                            'remark' => '三大队',
////                        ),
////                ));
////        $test = (new \app\lib\models\UserExtraWithdraw())->DBNew($param);
////        dump($test);die;
//        $data['unionId'] = 'oOYVd5iKYk6IBxiLLc7aw5pF4iz8';
//        $data['channel'] = 1;
//        $data['openid'] = "olM_y5a8EAYFt2NCJ8CfRA-sL87k";
//        $test = (new Wx())->getOtherPlatformUserInfoByUnionId($data);
////        dump($test);die;
//        $wList = Withdraw::where(['payment_status'=>-1,'check_status'=>1,'withdraw_type'=>7])->where('payment_remark is not null')->select()->toArray();
//        $bList = CrowdfundingBalanceDetail::where(['order_sn'=>array_column($wList,'order_sn'),'status'=>1])->column('id');
//        dump($wList);
//        dump($bList);die;
        $param['uid'] = '123132123';
        $param['bank_card_no'] = '';
        $param['real_name'] = '';
        $param['id_card'] = '';
        $param['bank_phone'] = '';
        $param['card_type'] = '1';
        $test = (new SandPay())->agreementSignSms($param);
//        $param['amount'] = '1.0';
//        $param['bank_account'] = '';
//        $param['re_user_name'] = '';
//        $param['partner_trade_no'] = '202212255375019020';
//        $test = (new SandPay())->enterprisePayment($param);
        dump($test);die;
        $res = CrowdfundingBalanceDetail::where(['order_sn' => "CZ202206127489092886", 'status' => 3])->save(['pay_no' => "111", 'status' => 1]);
        dump($res);die;
        $key = 0;
        $newDetail[$key] = [];
        $orderInfo['remark'] = '用户12312313-14745543825美丽金转入';
        switch ($orderInfo['remark']) {
            case "充值":
                $newDetail[$key]['recharge_type'] = 1;
                break;
            case strstr($orderInfo['remark'], '后台充值'):
                $newDetail[$key]['recharge_type'] = 2;
                break;
            case strstr($orderInfo['remark'], '用户自主提交线下付款申请后审核通过充值'):
                $newDetail[$key]['recharge_type'] = 3;
                break;
            case (strstr($orderInfo['remark'], '13840616567美丽金转入') || strstr($orderInfo['remark'], '14745543825美丽金转入') || strstr($orderInfo['remark'], '18529431349美丽金转入')):
                $newDetail[$key]['recharge_type'] = 4;
                break;
            default:
                $newDetail[$key]['recharge_type'] = 5;
                break;
        }
        dump($newDetail);die;
        $test = (new RechargeLink())->userSubordinateRechargeRate(['uid'=>'9K5GupldK8','time_type'=>1]);
        dump($test);die;
        dump(1231231323);die;
        //处理重复发放奖金问题
        $map['crowd_code'] = "C202212136282708432";
        $map['crowd_round_number'] = 1;
       $map['crowd_period_number'] = 11;
       $map['type'] = 1;
//       $map['order_sn'] = "202212255729450727";
      $list = CrowdfundingBalanceDetail::where($map)->select()->toArray();
        foreach ($list as $key => $value) {
            $exist = $value['order_sn'].$value['price'].$value['remark'];
            if(!isset($existOrder[$exist])){
                $existOrder[$exist] = $value;
            }else{
//                $chongfu[$exist] = $value;
                CrowdfundingBalanceDetail::update(['status'=>-1],['id'=>$value['id']]);
                User::where(['uid'=>$value['uid']])->dec('crowd_balance',$value['price'])->update();
            }
      }
        dump($existOrder ?? []);die;
        $map[] = ['crowd_balance','<>','0'];
        $list = User::where($map)->field('uid,phone,name,crowd_balance,(crowd_balance + crowd_fronzen_balance) as crowd_total_balance')->withSum('crowdBalance','price')->select()->toArray();
//        dump($list);
        foreach ($list as $key => $value) {
            if((string)$value['crowd_total_balance'] > (string)$value['crowd_balance_sum']){
                $proUser[] = $value;
            }
        }
        dump($proUser);die;
        dump(sprintf("%012d",(1 * 100)));die;
//        dump(Queue::push('app\lib\job\TimerForPpyl', ['plan_sn' => 'A202111111275839713', 'restartType' => 1, 'type' => 2, 'pay_no' => '111111', 'pay_order_sn' => '202111111271378071','channel'=>2], config('system.queueAbbr') . 'TimeOutPpyl'));die;
        $map[] = ['crowd_code','=','C202211085495686315'];
        $map[] = ['crowd_period_number','=','23'];
        $map[] = ['pay_status','=','2'];
        $teamOrderGoods = OrderGoods::where($map)->column('order_sn');

        foreach ($teamOrderGoods as $key => $value) {
            if ($key == 0) {
                $teamDivideQueue[$key] = Queue::push('app\lib\job\TeamDividePrice', ['order_sn' => $value, 'searNumber' => 1], config('system.queueAbbr') . 'TeamOrderDivide');
            } else {
                $teamDivideQueue[$key] = Queue::later((intval($key) * 1), 'app\lib\job\TeamDividePrice', ['order_sn' => $value, 'searNumber' => 1], config('system.queueAbbr') . 'TeamOrderDivide');
            }
        }
        dump($teamOrderGoods);die;
        dump(123131);die;
        $test = (new \app\lib\services\Divide())->divideForTopUser(['order_sn'=>'202111164812956374','searType'=>1]);
        dump($test);die;
        $test = (new Ppyl())->cancelAutoPlan(['order_sn'=>'202111102962432656','uid'=>'8BKsqKSGyn']);
        dump($test);die;
        $list = Db::query("SELECT
	order_sn,
	uid,
	change_type,
	count( 1 ) AS number 
FROM
	sp_ppyl_balance_detail 
GROUP BY
	order_sn,
	uid,
	change_type 
ORDER BY
	number desc");
        $new = [];
        $rttt = 0;
        if(!empty($list)){
            foreach ($list as $key => $value) {
               if($value['number'] == 2){
                   $new[$key] = $value;
               }
            }
        }

        if(!empty($new)){
            $res = Db::transaction(function () use ($new) {
                $number = [];
                $changUser = [];
                $userPrice = [];
                foreach ($new as $key => $value) {
                    $changePrice = PpylBalanceDetail::where(['order_sn'=>$value['order_sn'],'uid'=>$value['uid'],'change_type'=>$value['change_type']])->column('price','id');
                    $nowNumber = 0;
                    dump($value['order_sn']);
                    foreach ($changePrice as $vKey => $vValue) {
                        if($nowNumber == (count($changePrice) - 1)){
                            dump("delete from sp_ppyl_balance_detail where id = ".$vKey);
                            $number[] = Db::query("delete from sp_ppyl_balance_detail where id = ".$vKey);
                        }else{
                            $changUser[$vKey] = User::where(['uid'=>$value['uid']])->dec('ppyl_balance',$vValue)->update();
                            if(!isset($userPrice[$value['uid']])){
                                $userPrice[$value['uid']] = 0;
                            }
                            $userPrice[$value['uid']] += $vValue;
                            dump('修改id为'.$vKey.'价格为'.$vValue);
                        }
                        $nowNumber ++;
                    }
                }
                return ['number' => $number ?? [], 'changUser' => $changUser ?? [], 'userPrice' => $userPrice ?? []];
            });

        }
        dump($new ?? []);
        dump($res ?? []);die;
//        $test = (new PpylReward())->exportList(['page'=>1]);
////        dump($test);die;
////        $allPlan = ['A202110156119156630','A202110150124204780'];
////        $check['order_sn'] = 12331;
////        $check['uid'] = 2222;
////        $check['type'] = 1;
////        $check['status'] = 1;
////        if (($value['type'] ?? null) == 2) {
////            $check['change_type'] = 4;
////        } else {
////            $check['change_type'] = 1;
////        }
////        dump(http_build_query($check));
////        //加缓存锁
////        $cacheKey = md5(http_build_query($check));
////        dump($cacheKey);die;
//        $test = Queue::push('app\lib\job\PpylAuto', [ 'autoType' => 1], config('system.queueAbbr') . 'PpylAuto');
//    dump($test);die;
//        $test = (new PpylAuto())->arriveReward(['202110231865356618']);
//        dump($test);
////        $allPlan = array_column($data, 'plan_sn');
//        if (!empty($allPlan)) {
////                $returnData = PpylAutoModel::update(['status' => 3, 'fail_msg' => '超时自动停止', 'remark' => '超时自动停止'], ['plan_sn' => $allPlan, 'status' => 1])->getData();
//            $errMsg = '超时自动停止';
//            $updateTime = time();
//            $planSn = '';
//            foreach ($allPlan as $key => $value) {
//                if($key == 0){
//                    $planSn =  "('".$value."',";
//                }elseif($key != 0 && ($key != count($allPlan) - 1)){
//                    $planSn .= "'".$value."',";
//                }elseif($key == count($allPlan) - 1){
//                    $planSn .= "'".$value."')";
//                }
//            }
//
////            $planSn = '('.implode(',',$allPlan).')';
//            dump("update sp_ppyl_auto set fail_msg = IFNULL(concat('$errMsg',fail_msg),'$errMsg'),status = 3,update_time = '$updateTime' where plan_sn in $planSn and status = 1;");die;
//            $returnData =  Db::query("update sp_ppyl_auto set fail_msg = IFNULL(concat('$errMsg',fail_msg),'$errMsg'),status = 3,update_time = '$updateTime' where plan_sn in '$planSn' and status = 1;");
//            return $returnData ?? [];
//        }
//        dump(123131);die;
//        $test = (new TimerForPpyl())->overTimeWaitOrder(['202110281043461936'], 2);
//        dump($test);die;
//        $data['area_code'] = '5162636672';
//        $data['goods_sn'] = '0041007021802';
//        $data['dealType'] = 2;
//        $allOrder  =  (new Ppyl())->dealWaitOrder($data);
//        return returnData($allOrder);
//
//        $refundSn = (new CodeBuilder())->buildRefundSn();
//        $refund['out_trade_no'] = $orderSn;
//        $refund['out_refund_no'] = $refundSn;
//
//        $refund['total_fee'] = '49.90';
//        $refund['refund_fee'] = '49.90';
////                $refund['total_fee'] = 0.01;
////                $refund['refund_fee'] = 0.01;
//        $refund['refund_desc'] = !empty($data['refund_remark']) ? $data['refund_remark'] : '美好拼拼退款';
//        $refund['notThrowError'] = $data['notThrowError'] ?? false;
//        $refundTime = time();
//        $refund['notify_url'] = sprintf(config('system.callback.joinPayPpylRefundCallBackUrl'),'12312313');
////                    $refundRes = (new WxPayService())->refund($refund);
//        $test = (new JoinPay())->refund($refund);
//        dump($test);die;
//        $refundSn = (new CodeBuilder())->buildRefundSn();
//        $refund['out_trade_no'] = $orderSn;
//        $refund['out_refund_no'] = $refundSn;
//        $refund['type'] = 2;
//        $test = (new Ppyl())->submitPpylRefund($refund);
////        $test = (new Ppyl())->completePpylRefund(['out_trade_no'=>'202110256729262625','now'=>'202110256729262625']);
//        dump($test);die;
        $allOrder  =  PpylOrder::with(['orderGoods'])->where(['win_status'=>1,'status'=>1])->select()->toArray();
        $areaList = [];
        $areaNumber = [];
        foreach ($allOrder as $key => $value) {
            if(!isset($areaList[$value['area_code']]['number'])){
                $areaList[$value['area_code']]['number'] = 0;
            }
            $areaList[$value['area_code']]['number'] += 1;
            $areaList[$value['area_code']]['area_code'] = $value['area_code'];
            $areaList[$value['area_code']]['area_name'] = $value['area_name'];
            if(!isset( $areaList[$value['area_code']]['cost'])){
                $areaList[$value['area_code']]['cost'] = 0;
            }
            if(!isset( $areaList[$value['area_code']]['real'])){
                $areaList[$value['area_code']]['real'] = 0;
            }
            foreach ($value['orderGoods'] as $gK => $gV) {
                $areaList[$value['area_code']]['cost'] += $gV['cost_price'] ?? 0;
                $areaList[$value['area_code']]['real'] += $gV['real_pay_price'] ?? 0;
            }

        }
        $userMap[] = ['create_time','>=',1634918400];
        $userMap[] = ['create_time','<=',1635091199];
        $userMap[] = ['status','=',1];

        $allUser = User::where($userMap)->count();

        $ppylMap[] = ['create_time','>=',1634918400];
        $ppylMap[] = ['create_time','<=',1635091199];
        $ppylMap[] = ['status','=',1];

        $allPpylReward = PpylReward::where($ppylMap)->sum('real_reward_price');

        $finally['order'] = $areaList ?? [];
        $finally['allUser'] = $allUser ?? [];
        $finally['allPpylReward'] = $allPpylReward ?? [];
        dump($finally);die;
//        $test = Queue::push('app\lib\job\Auto', ['uid' => $orderSn, 'autoType' => 5], config('system.queueAbbr') . 'Auto');
        $data['plan_sn'] = 'A202110188747417673';
        $errorMsg = '订单创建失败';
        $planSn = $data['plan_sn'];
        $errMsg = ' 于'.date('Y-m-d H:i:s').'执行失败,失败原因为'.$errorMsg.' ';
//        dump("update sp_ppyl_auto set fail_msg = concat('$errMsg',fail_msg) where plan_sn = '$planSn';");die;
        $test = Db::query("update sp_ppyl_auto set fail_msg = IFNULL(concat('$errMsg',fail_msg),'$errMsg') where plan_sn = '$planSn';");
        dump($test);die;
        $test = (new Ppyl())->restartAutoPpylOrder(['plan_sn'=>'A202110205405910255']);
        dump($test);die;
        $refundSn = (new CodeBuilder())->buildRefundSn();
        $map[] = ['id','>=',7000];
        $map[] = ['status','=',1];
        $map[] = ['vip_level','=',3];
        $userList = User::where($map)->field('uid,vip_level,phone')->select()->toArray();

        foreach ($userList as $key => $value) {
            $add[$key]['uid'] = $value['uid'];
            $add[$key]['type'] = 4;
            $add[$key]['assign_level'] = 3;
            $add[$key]['arrival_status'] = 1;
            $add[$key]['user_phone'] = $value['phone'] ?? null;
            $add[$key]['growth_value'] = 0.01;
            $add[$key]['surplus_growth_value'] = 0.01;
            $add[$key]['remark'] = '系统代理升级赠送';
        }
        (new GrowthValueDetail())->saveAll($add);
        dump('成功了');
        $refund['out_trade_no'] = $orderSn;
        $refund['out_refund_no'] = $refundSn;

        $refund['total_fee'] = '99.90';
        $refund['refund_fee'] = '99.90';
//                $refund['total_fee'] = 0.01;
//                $refund['refund_fee'] = 0.01;
        $refund['refund_desc'] = !empty($data['refund_remark']) ? $data['refund_remark'] : '美好拼拼退款';
        $refund['notThrowError'] = $data['notThrowError'] ?? false;
        $refundTime = time();
        $refund['notify_url'] = sprintf(config('system.callback.joinPayPpylRefundCallBackUrl'),'12312313');
//                    $refundRes = (new WxPayService())->refund($refund);
        $test = (new JoinPay())->refund($refund);
//        $timeoutPpylOrderQueue = Queue::push('app\lib\job\TimerForPpylOrder', ['activity_sn' => [], 'type' => 1], config('system.queueAbbr') . 'TimeOutPpylOrder');
//        $test = (new Ppyl())->submitPpylWaitRefund($refund);
//        $test =  (new Ppyl())->submitPpylRefund(['out_trade_no' => $orderSn, 'out_refund_no' => $refundSn, 'refund_remark' => ($data['refund_remark'] ?? null)]);
//        $test =  (new Pay())->completePpylPay(['out_trade_no' => $orderSn, 'transaction_id' => '1111111111111']);

//        $test = (new JoinPayCallback())->ppylRefundCallback($callbakData);
//        $test = 1;
//        $data['activity_code'] = '202109132804636341';
//        $data['area_code'] = '0349724802';
//        $data['goods_sn'] = '0047004187033';
//        $data['sku_sn'] = '8703379801';
//        $data['notThrowError'] = 1;
//        $test = (new Ppyl())->submitPpylWaitRefund(['out_trade_no'=>'202110195319533234','out_refund_no'=>(new CodeBuilder())->buildRefundSn()]);
//        dump($test);die;
////        dump(substr('1632391863733',0,10));die;
//        $test = (new KuaiShangPay())->contractInfo(['uid'=>'12313']);
       dump($test);die;
//        $map[] = ['wait_status', '=', 1];
//        $map[] = ['status', '=', 1];
//        $map[] = ['timeout_time', '<=', time()];
//        $overTimePtOrder = PpylWaitOrder::where($map)->column('order_sn');

        $firstMap[] = ['wait_status', '=', 1];
        $firstMap[] = ['status', '=', 1];
        $firstMap[] = ['order_sn', 'in', $orderSn];

        $map[] = ['c_vip_level', '=', 0];
        $map[] = ['', 'exp', Db::raw('timeout_time is not null')];
        $map[] = ['timeout_time', '<=', time()];

        $mapOr[] = ['pay_status', '=', 1];
        $mapOr[] = ['create_time', '<=', time() - 900];

        $overTimePtOrder = (new PpylWaitOrder())->where($firstMap)->where(function ($query) use ($map, $mapOr) {
            $query->whereOr([$map, $mapOr]);
        })->field('activity_code,area_code,order_sn,goods_sn,sku_sn,user_role,activity_type,activity_status,pay_status,wait_status,timeout_time')->select()->toArray();
        dump($overTimePtOrder);die;
        //数据库查询
//       $data['area_code'] = '7800800921';
//       $data['uid'] = '8BKsqKSGyn';
//       $data['goods_sn'] = '0048000315148';
//       $data['activity_code'] = '202109097292179373';
//       $data['dealType'] = 1;
        $data['activity_sn'] = 'PL202109189316305328';
       $res = (new Ppyl())->completePpylOrder($data);
        dump($res);die;
//        $member = (new \app\lib\services\Member())->becomeMember(['link_superior_user' => 'hlrvIsYYY1', 'uid' => 'bDcJy47D4B', 'user_phone' => '13697498641']);
        $member = Queue::push('app\lib\job\MemberUpgrade', ['uid' => 'hun8AdhcOO', 'type' => 2], config('system.queueAbbr') . 'MemberUpgrade');
//        $member = (new \app\lib\services\Member())->memberUpgradeByPpyl('hun8AdhcOO');
        dump($member);die;
//        $timeoutPpylOrderQueue = (new PpylReward())->autoReceiveTopReward(['uid'=>$orderSn]);
//        $timeoutPpylOrderQueue = Queue::push('app\lib\job\PpylAuto', ['uid' => $orderSn, 'autoType' => 3], config('system.queueAbbr') . 'PpylAuto');
        $timeoutPpylOrderQueue = Queue::push('app\lib\job\TimerForPpyl', ['type' => 1], config('system.queueAbbr') . 'TimeOutPpyl');
        return returnData($timeoutPpylOrderQueue);
        $map[] = ['activity_status', 'in', [1,3]];
        $map[] = ['status', '=', 1];
        $map[] = ['pay_status', '=', 1];
        $map[] = ['create_time', '<=', (time()  - 15)];
        $test = PpylOrder::where($map)->column('order_sn');
        return returnData($test);
//        $test = (new \app\lib\services\Member())->memberUpgradeByPpyl('bDcJy47D4B');
//        dump(12313);die;
//        $map[] = ['status', '=', 1];
//        $map[] = ['c_vip_level', '<>', 0];
//        $map[] = ['c_vip_time_out_time', '<=', time()];
//        $timeOutCVIP = User::where($map)->field('uid')->select()->toArray();
//        dump($timeOutCVIP);die;
//        $timeoutPpylOrderQueue = Queue::push('app\lib\job\PpylAuto', ['autoType' => 4], config('system.queueAbbr') . 'PpylAuto');
        $timeoutPpylOrderQueue = Queue::push('app\lib\job\TimerForPpylOrder', ['type' => 1], config('system.queueAbbr') . 'TimeOutPpylOrder');
        dump($timeoutPpylOrderQueue);die;
        $ppylFefundRes = PpylOrder::update(['shipping_status' => 2, 'shipping_time' => time()], ['order_sn' => '202109028354564814', 'uid' => 'bDcJy47D4B']);
        $refundSn = (new CodeBuilder())->buildRefundSn();
        $refund['out_trade_no'] = '202109028354564814';
        $refund['out_refund_no'] = $refundSn;
        $refund['type'] = 3;
        $queueRes['202109028354564814'] = Queue::push('app\lib\job\TimerForPpyl', $refund, config('system.queueAbbr') . 'TimeOutPpyl');
        dump($queueRes);die;
//        $test = (new PpylReward())->autoReceiveTopReward(['uid' => 'bDcJy47D4B']);
//        dump($test);die;
////        dump($ppylDirectUser);die;
//        if (!empty($ppylDirectUser)) {
//            $ppylMap[] = ['activity_status', 'in', [1, 2, -3]];
//            $ppylMap[] = ['', 'exp', Db::raw('group_time is not null')];
//            $ppylMap[] = ['pay_status', 'in', [2, -2]];
//            $ppylMap[] = ['uid', 'in', $ppylDirectUser];
//            $ppylNumberSql = PpylOrder::where($ppylMap)->group('uid')->buildSql();
//            $ppylNumber = Db::table($ppylNumberSql . ' a')->count();
//
//            $log['ppylNumber'] = $ppylNumber;
//            $log['allMsg'] = '直推参与拼拼有礼的人数符合要求';
//
//            if ($ppylNumber >= $aNextLevel['recommend_ppyl_number']) {
//                $ppylRes = true;
//            }
//        }
//        dump(12313);die;
//       $res = json_decode("[100]", true);
//       dump($res);die;
//        $wait['area_code'] = '4033151526';
//        $wait['goods_sn'] = '0002000155491';
//        $wait['sku_sn'] = '5549107401';
//        $wait['dealType'] = 1;
//        $wait['activity_sn'] = 'PL202108264566235161';
//        $wait['notThrowError'] = 1;
//        if (!empty($wait)) {
//            $test = (new Ppyl())->dealWaitOrder($wait);
//        }
//        dump($test);die;
//        (new \app\lib\services\Ppyl())->notThrowError = 1;
        //人数不够,查看排队队伍中是否有可以符合条件的
//        $wait['area_code'] = '4033151526';
//        $wait['goods_sn'] = '0002000155491';
//        $wait['sku_sn'] = '5549107401';
//        $wait['dealType'] = 1;
//        $wait['activity_sn'] = '';
//        $wait['notThrowError'] = 1;
//        $timeoutPpylOrderQueue = Queue::push('app\lib\job\TimerForPpylOrder', ['type' => 1], config('system.queueAbbr') . 'TimeOutPpylOrder');
//        $redis = Cache::store('redis')->handler();
////        $overTimeList = $redis->keys('waitOrderTimeoutLists*');
////        if(!empty($overTimeList)){
////            foreach ($overTimeList as $key => $value) {
////                $explode = explode('-',$value);
////                if(empty($explode[1]) || (!empty($explode[1] && $explode[1] >= time()))){
////                    unset($overTimeList[$key]);
////                }
////            }
////        }
////        dump($overTimeList);
////        dump($overTimeList);die;
///
///
//        $redis = Cache::store('redis')->handler();
//        $overTimeList = $redis->keys('waitOrderTimeoutLists*');
//        dump($overTimeList);
//        if(!empty($overTimeList)){
//            foreach ($overTimeList as $key => $value) {
//                $explode = explode('-',$value);
//                if(empty($explode[1]) || (!empty($explode[1] && $explode[1] >= time()))){
//                    unset($overTimeList[$key]);
//                }
//            }
//        }
//        dump($overTimeList);
//        $test = (new TimerForPpyl())->fire(['type'=>1]);
//        dump($test);die;
        //排队超时
        $timeoutPpylOrderQueue = Queue::push('app\lib\job\TimerForPpyl', ['type' => 1], config('system.queueAbbr') . 'TimeOutPpyl');
        //未成团超时
//        $timeoutPpylOrderQueue = Queue::push('app\lib\job\TimerForPpylOrder', ['type' => 1], config('system.queueAbbr') . 'TimeOutPpylOrder');
        dump($timeoutPpylOrderQueue);die;
        $redis = Cache::store('redis')->handler();
//        $redis->lpush("waitOrderTimeoutListss", "testtest".rand(1,99999));
        $redis = Cache::store('redis')->handler();
        dump($redis->lrem("waitOrderTimeoutLists-" . 1111, 5555));
        dump($redis->lrange("waitOrderTimeoutListss", 0 ,-1));
        dump($redis->lrem('waitOrderTimeoutListss', 'testtest10998', 0));
        dump($redis->lrange("waitOrderTimeoutListss", 0 ,-1));
        dump(123131);die;
//        $list = ['11111','22222','66666'];
//        Cache::tag('qqq')->set('waitOrderTimeoutListssss',$list,3600);
////        cache('waitOrderTimeoutList',$list,3600,'qqq');
//        $cacheList = cache('waitOrderTimeoutListssss');
//        dump($cacheList);
//        dump(Cache::getTagItems('qqq'));die;
        dump(cache('waitOrderTimeoutListssss'));die;
//        $data['area_code'] = '4033151526';
//        $data['goods_sn'] = '0002000155491';
//        $data['sku_sn'] = '5549107401';
//        $data['dealType'] = 2;
//        $data['activity_sn'] = 'PL202108239433208162';
//        $test = (new \app\lib\services\Ppyl())->dealWaitOrder($data);
        dump($test);die;
//        $headimgurl = 'https://thirdwx.qlogo.cn/mmopen/vi_32/dHKPw72kVPCpHgeIspOBb8a418Oj8koXcxibLu5S3clggp4BVgK4tA0c06w5UnmXFH29e9MOvTVSJBIjWWHyQhQ/132';
//        $name = '66666.png';
//        $res = (new FileUpload())->fileDownload($headimgurl, 'wxAvatar', $name);
//        dump($res);
//        $res = (new AlibabaOSS())->uploadFile($res,'wxAvatar');
//        dump($res);die;
//        $test = (new FileUpload())->fileDownload('https://thirdwx.qlogo.cn/mmopen/vi_32/abcicotGWDZe0n1vjX7x2icThrTYWDcVCaOicJqzTHxLOxlTndLiav2LBgBqDticI5Jj4HnAibiclrL9xpqNuIXQWc6fQ/132');
        $redis = Cache::store('redis')->handler();
        dump($redis->lSet('{queues:mhppMemberChain}',4,'Del'));
//        $test = Queue::push('app\lib\job\MemberChain', ['order_sn' => '777777' ?? null, 'handleType' => 2], "mhppMemberChain");
        dump(12313);die;
//        dump(Cache::getTagItems('ApiHomeAllList') ?? 11111111111);die;
        dump($redis->lLen('{queues:test}'));
        dump($redis->lRange('{queues:test}',0,-1));
        dump($redis->lSet('{queues:test}',18,'Del'));
        dump($redis->lrem('{queues:test}','Del',0));
//        dump($redis->rpop('{queues:test}'));
        dump($redis->lRange('{queues:test}',0,-1));
            die;
        dump(cache('queues'));
        dump(cache('queues:mhppMemberChain'));
        dump(cache('queues:mhppMemberChain'));
        dump($test);die;
//        $test = (new \app\lib\services\Divide())->divideForTopUser(['order_sn'=>'202107309692241380','searType'=>2]);
//        dump($test);die;
        //Queue::push('app\lib\job\MemberChain', ['searUser' => 'test', 'handleType' => 1], 'mtenMemberChain');
        $test = (new \app\lib\services\Member())->refreshDivideChain();
//        $test = (new \app\lib\services\Member())->getOrderChain(['order_sn'=>'202107081316145624']);
//        $test2 = (new \app\lib\services\Member())->getOrderTeamTopUser(['order_sn'=>'202107081316145624']);
        $test3 = (new \app\lib\services\Member())->recordOrderChain(['order_sn' => '202107307943312449']);
//        dump($test);
//        dump($test2);
        dump($test3);
        die;
        dump(123123);
        die;
//        $timeoutPtOrderQueue = (new TimerForPtOrder())->overTimePtOrder(['P202012177648383578']);
        return returnData($timeoutPtOrderQueue);
    }

    /**
     * @title 衫德支付测试环境调试版
     * @return void
     */
    public function sandDev()
    {
        $param['uid'] = 'vqAMmusGS3';
        $type = $this->requestData['type'] ?? 1;
        $orderSn = $this->requestData['order_sn'] ?? '2023006167854845199';
        switch ($type){
            case 1:
                $param['bank_card_no'] = '';
                $param['real_name'] = '';
                $param['id_card'] = '';
                $param['bank_phone'] = '';
                $param['card_type'] = '1';
                $test = (new SandPay())->agreementSignSms($param);
                break;
            case 2:
                $param['uid'] = 'vqAMmusGS3';
                $param['sms_code'] = '111111';
                $test = (new SandPay())->agreementContract($param);
                if (!empty($test['sign_no'] ?? null)) {
                    cache('sign_no', $test['sign_no']);
                }
                break;
            case 3:
                $param['uid'] = 'vqAMmusGS3';
                $param['out_trade_no'] = $orderSn;
                $param['bank_phone'] = '13268097697';
                $param['sign_no'] = cache('sign_no');
                $test = (new SandPay())->agreementPaySms($param);
                break;
            case 4:
                $param['uid'] = 'vqAMmusGS3';
                $param['out_trade_no'] = $orderSn;
                $param['bank_phone'] = '13268097697';
                $param['sign_no'] = cache('sign_no');
                $param['sms_code'] = '111111';
                $param['order_create_time'] = time();
                $param['total_fee'] = 100;
                $test = (new SandPay())->agreementSmsPay($param);
                break;
            case 5:
                $param['out_trade_no'] = $orderSn;
                $test = (new SandPay())->agreementOrderQuery($orderSn);
                break;
            case 6:
                $param['out_trade_no'] = $orderSn;
                $test = (new SandPay())->agreementNotice($param);
                break;
            case 7:
                $param['uid'] = 'cClCprGc1r';
                $param['out_trade_no'] = $orderSn;
                $param['out_refund_no'] = '20230427367401745711111';
                $param['refund_fee'] = 1;
                $test = (new SandPay())->agreementRefund($param);
                break;
            default:
                $test = '没有的类型哦';
        }
        dump($test);die;

////        //退款
//        for ($i = 1; $i <= 2; $i++) {
//            $param['uid'] = 'vqAMmusGS3';
//            $param['out_trade_no'] = '202302265736777777';
//            $param['out_refund_no'] = '2028999999999900776'.$i;
//            $param['refund_fee'] = 0.5;
//            $test = (new SandPay())->agreementRefund($param);
//            dump($test);
////            $i++;
//        }
        dump(12313);die;

//        $param['out_trade_no'] = '202302195736900316';
//        $test = (new SandPay())->agreementNotice($param);
    }

    /**
     * @title 失败期的美丽豆和健康豆回滚
     * @return void
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\db\exception\DataNotFoundException
     */
    public function periodDeal()
    {
        $searType = Request::param('sear_type') ?? 2;
        $failPeriod = CrowdfundingPeriod::where(['result_status' => 3, 'status' => [1,2], 'buy_status' => 2])->field('title,activity_code,round_number,period_number')->select()->toArray();
        dump($failPeriod);
        $oWhere = [];
        $orderGoods = OrderGoods::with(['orderInfo'])->where(function ($query) use ($failPeriod) {
            foreach ($failPeriod as $key => $value) {
                ${'where' . ($key + 1)}[] = ['crowd_code', '=', $value['activity_code']];
                ${'where' . ($key + 1)}[] = ['crowd_round_number', '=', $value['round_number']];
                ${'where' . ($key + 1)}[] = ['crowd_period_number', '=', $value['period_number']];
            }
            for ($i = 0; $i < count($failPeriod); $i++) {
                $allWhereOr[] = ${'where' . ($i + 1)};
            }
            $query->whereOr($allWhereOr);
        })->field('order_sn')->where($oWhere)->buildSql();
        $DBRes = Db::transaction(function () use ($orderGoods,$searType) {
            $iWhere[] = ['', 'exp', Db::raw('order_sn in ' . $orderGoods)];
            $iWhere[] = ['status', '=', 1];
            $existIntegral = IntegralDetail::where($iWhere)->field('order_sn,uid,integral,crowd_code,crowd_round_number,crowd_period_number')->select()->toArray();

            //美丽豆
            if (!empty($existIntegral)) {
                $integralDec['list'] = $existIntegral;
                $integralDec['db_name'] = 'sp_user';
                $integralDec['id_field'] = 'uid';
                $integralDec['operate_field'] = 'integral';
                $integralDec['value_field'] = 'integral';
                $integralDec['operate_type'] = 'dec';
                $integralDec['other_map'] = 'status = 1';
                $integralDec['sear_type'] = $searType;
                $sql1 = (new CommonModel())->setThrowError(true)->DBBatchIncOrDec($integralDec);

//            //添加明细
                foreach ($existIntegral as $key => $value) {
                    $crowdKey = ($value['crowd_code'] ?? '') . '-' . ($value['crowd_round_number'] ?? '') . '-' . ($value['crowd_period_number'] ?? '');
                    $decIntegralDetail[$key]['order_sn'] = 'DEC-' . $value['order_sn'];
                    $decIntegralDetail[$key]['uid'] = $value['uid'];
                    $decIntegralDetail[$key]['integral'] = '-' . $value['integral'];
                    $decIntegralDetail[$key]['remark'] = $crowdKey . ' 期失败预赠送扣除';
                    $decIntegralDetail[$key]['type'] = $searType;
                    $decIntegralDetail[$key]['change_type'] = 6;
                }
                $integralDetailNew['list'] = $decIntegralDetail;
                $integralDetailNew['db_name'] = 'sp_integral_detail';
                $integralDetailNew['sear_type'] = $searType;
                $sql2 = (new CommonModel())->setThrowError(true)->DBSaveAll($integralDetailNew);
            }

            //健康豆
            $hWhere[] = ['', 'exp', Db::raw('order_sn in ' . $orderGoods)];
            $hWhere[] = ['status', '=', 1];
            $existHealthy = HealthyBalanceDetail::where($hWhere)->field('order_sn,uid,price,crowd_code,crowd_round_number,crowd_period_number')->select()->toArray();
            if (!empty($existHealthy)) {
                $healthyDec['list'] = $existHealthy;
                $healthyDec['db_name'] = 'sp_user';
                $healthyDec['id_field'] = 'uid';
                $healthyDec['operate_field'] = 'healthy_balance';
                $healthyDec['value_field'] = 'price';
                $healthyDec['operate_type'] = 'dec';
                $healthyDec['other_map'] = 'status = 1';
                $healthyDec['sear_type'] = $searType;
                $sql3 = (new CommonModel())->setThrowError(true)->DBBatchIncOrDec($healthyDec);

                $healthyBDec['list'] = $existHealthy;
                $healthyBDec['db_name'] = 'sp_healthy_balance';
                $healthyBDec['id_field'] = 'uid';
                $healthyBDec['operate_field'] = 'balance';
                $healthyBDec['value_field'] = 'price';
                $healthyBDec['operate_type'] = 'dec';
                $healthyBDec['other_map'] = 'status = 1 and channel_type = 2';
                $healthyBDec['sear_type'] = $searType;
                $sql4 = (new CommonModel())->setThrowError(true)->DBBatchIncOrDec($healthyBDec);

//            //添加明细
                foreach ($existHealthy as $key => $value) {
                    $crowdKey = ($value['crowd_code'] ?? '') . '-' . ($value['crowd_round_number'] ?? '') . '-' . ($value['crowd_period_number'] ?? '');
                    $decHealthyDetail[$key]['order_sn'] = 'DEC-' . $value['order_sn'];
                    $decHealthyDetail[$key]['uid'] = $value['uid'];
                    $decHealthyDetail[$key]['price'] = '-' . $value['price'];
                    $decHealthyDetail[$key]['remark'] = $crowdKey . ' 期失败预赠送扣除';
                    $decHealthyDetail[$key]['type'] = 2;
                    $decHealthyDetail[$key]['change_type'] = 3;
                    $decHealthyDetail[$key]['healthy_channel_type'] = 2;
                }
                $healthyDetailNew['list'] = $decHealthyDetail;
                $healthyDetailNew['db_name'] = 'sp_healthy_balance_detail';
                $healthyDetailNew['sear_type'] = $searType;
                $sql5 = (new CommonModel())->setThrowError(true)->DBSaveAll($healthyDetailNew);
            }
            return ['sql1' => ($sql1 ?? null), 'sql2' => ($sql2 ?? null), 'sql3' => ($sql3 ?? null), 'sql4' => ($sql4 ?? null), 'sql5' => ($sql5 ?? null),'existIntegral'=>$existIntegral ?? [],'existHealthy'=>$existHealthy ?? []];
        });
        dump($DBRes);
        dump($failPeriod);
        dump($orderGoods);die;
        dump(12312313);die;
    }

    /**
     * @title  创建虚拟用户
     * @return void
     * @throws \Exception
     */
    public function createNewUser()
    {
        $phone = ['13560008532', '13826102287', '13668996451', '18620015010', '13808815600', '13719420627', '13632290509', '13711078287', '17621260811'];
        $userModel = (new User());
        $service = (new CodeBuilder());
        foreach ($phone as $key => $value) {
            $existPhone = $userModel->where(['phone' => $value])->count();
            if (empty($existPhone)) {
                $newUser[$key]['uid'] = getUid();
                $newUser[$key]['name'] = '游客' . ($key + 1);
                $newUser[$key]['phone'] = $value;
                $newUser[$key]['vip_level'] = 2;
                $newUser[$key]['integral'] = 0;
                $newUser[$key]['is_new_user'] = 1;
                $newUser[$key]['is_new_vip'] = 1;
                $newUser[$key]['parent_team'] = '00111';
                $newUser[$key]['link_superior_user'] = 'hryP4iGzrA';
                $newUser[$key]['old_sync'] = 5;
                $newUser[$key]['growth_value'] = 30;

                $member[$key]['member_card'] = $service->buildMemberNum();
                $member[$key]['uid'] = $newUser[$key]['uid'];
                $member[$key]['user_phone'] = $newUser[$key]['phone'];
                $member[$key]['team_code'] = '00025';
                $member[$key]['child_team_code'] = (new Member())->buildMemberTeamCode(2, $newUser[$key]['parent_team']);
                $member[$key]['parent_team'] = $newUser[$key]['parent_team'];
                $member[$key]['level'] = $newUser[$key]['vip_level'];
                $member[$key]['type'] = 2;
                $member[$key]['link_superior_user'] = $newUser[$key]['link_superior_user'];
                $member[$key]['growth_value'] = 30;
            }
        }
        $test1 = (new User())->saveAll($newUser);
        $test2 = (new MemberModel())->saveAll($member);
        dump($newUser, $member);
        dump($test1, $test2);
        die;
    }

    public function testM()
    {
        echo 'respCode=000000';
    }
    public function test2()
    {
        $ma[] = ['','exp',Db::raw("find_in_set('GIzR29szwf',team_chain)")];
        $ma[] = ['status','=',1];
        $notOperUser = Member::where($ma)->column('uid');
        $user = CrowdfundingBalanceDetail::where(['uid'=>$notOperUser])->column('id','uid');
        dump($user);
        foreach ($notOperUser as $key => $value) {
            if(!in_array($value,array_keys($user))){
                $jiang[] = $value;
            }
        }
        Member::where(['uid'=>$jiang])->save(['level'=>0]);
//        User::where(['uid'=>$jiang])->save(['level'=>0]);
        dump(123132);die;
        $userSql = User::where(['uid'=>$jiang])->field('name,phone')->buildSql();
        dump($userSql);die;
        dump($jiang);die;
//        $list1 = Db::query("select uid,team_chain from sp_member where locate('hlrvIsSM6J',team_chain)>0 and status = 1;");
//        dump(count($list1));
//        $list2 = Db::query("SELECT uid,team_chain  from sp_member where team_chain is not null;");
//        dump(count($list2));die;
//        $all = array_unique(array_column($list1,'uid'));
//        foreach ($list2 as $key => $value) {
//            if(!in_array($value['uid'],$all)){
//                dump($value['uid']);
//            }
//        }
//        die;
        $test = (new \app\lib\services\Member())->refreshDivideChain();
//        $test = (new MemberTest())->test(['page' => 1]);
        dump($test);
        die;
        $list = Db::query("SELECT
	u2.uid,u2.user_phone,u2.id

FROM
	(
	SELECT
		@ids AS p_ids,
		( SELECT @ids := GROUP_CONCAT( uid ) FROM sp_member WHERE FIND_IN_SET( link_superior_user, @ids COLLATE utf8mb4_unicode_ci )) AS c_ids,
		@l := @l + 1 AS team_level 
	FROM
		sp_member,
		( SELECT @ids := \"hlrvIsSM6J\", @l := 0 ) b 
	WHERE
		@ids IS NOT NULL
	) u1
	JOIN sp_member u2 ON FIND_IN_SET( u2.uid, u1.p_ids COLLATE utf8mb4_unicode_ci )
ORDER BY
	u1.team_level ASC");
        $ids = array_unique(array_column($list, 'id'));
        Member::update(['update_time' => 1624862907], ['id' => $ids]);
        dump($ids);
        die;
        $order['uid'] = 'sX6Qm91HFj';
        $order['user_phone'] = '13266651913';
        $price = 100;
        $order['order_type'] = 1;
        $order['activity_id'] = null;
        $order['total_price'] = $price;
        $order['order_amount'] = $order['total_price'];
        $order['fare_price'] = 0;
        $order['discount_price'] = 0;
        $order['real_pay_price'] = $price;
        $order['pay_type'] = 2;
        $order['belong'] = 1;
        $order['used_integral'] = 0;
        $order['goods'][0]['goods_sn'] = '0003000197369';
        $order['goods'][0]['sku_sn'] = '9736956402';
//        $order['goods'][0]['goods_sn'] = '0022000710600';
//        $order['goods'][0]['sku_sn'] = '1060005701';
        $order['goods'][0]['number'] = 1;
        $order['goods'][0]['price'] = $price;
        $order['goods'][0]['couponDis'] = 0;
        $order['goods'][0]['integralDis'] = 0;
        $order['goods'][0]['allDisPrice'] = 0;
        $order['goods'][0]['realPayPrice'] = $price;
        $order['goods'][0]['memberDis'] = 0;
        $order['uc_code'] = [];
        $order['need_pay'] = true;
        $order['address_id'] = 999;
        $order['shipping_address'] = '广州市测试地址';
        $order['shipping_name'] = '广州市测试收货人';
        $order['shipping_phone'] = '020-123456';
        $order['order_link_user'] = '5tpuJBTdT2';
        $order['order_remark'] = '测试订单';

        $newOrder = Db::transaction(function () use ($order) {
            $newOrder = (new OrderModel())->new($order);
            //完成支付
            $freeOrder['out_trade_no'] = $newOrder['order_sn'];
            $freeOrder['transaction_id'] = 'notRealPay';
            (new \app\controller\callback\v1\PayCallback())->completePay($freeOrder);
//            (new \app\lib\services\Divide())->divideForTopUser(['order_sn'=>$newOrder['order_sn']]);
            (new \app\lib\services\Ship())->userConfirmReceiveGoods(['order_sn' => $newOrder['order_sn'], 'uid' => $newOrder['uid']]);
            //升级会员
//        $userLink = (new User())->where(['uid'=>$order['uid']])->value('link_superior_user');
//        (new \app\lib\services\Member())->memberUpgrade($userLink);
            return $newOrder;
        });

        dump($newOrder);

    }

    public function qqq()
    {
        $fileUpload = 'uploads/123.xlsx';
        //$list = (new Office())->ReadExcel($fileUpload,2);
        //$topUserPhone = array_unique(array_column($list,'topUserPhone'));
        $map[] = ['id', '>', 34124];
        $map[] = ['old_sync', '=', 5];
//        $map[] = ['vip_level','=',3];
        $memberVdc = MemberVdc::where(['status' => 1])->column('demotion_growth_value', 'level');
        $topUserList = User::where($map)->field('uid,phone,vip_level,growth_value,link_superior_user')->select()->toArray();

        $number = 0;
        foreach ($topUserList as $key => $value) {
            $newMember[$key]['uid'] = $value['uid'];
            $newMember[$key]['user_phone'] = $value['phone'];
            $newMember[$key]['member_card'] = '000004500' . $number;
            $newMember[$key]['team_code'] = '01017';
            $newMember[$key]['parent_team'] = '01017030000';
            if ($value['vip_level'] == 1) {
                $newMember[$key]['child_team_code'] = '01017030000' . $number;
            } else {
                $newMember[$key]['child_team_code'] = '010170300003500' . $number;
            }

            $newMember[$key]['level'] = $value['vip_level'];
            $newMember[$key]['growth_value'] = $value['growth_value'];
            $newMember[$key]['demotion_growth_value'] = $memberVdc[$newMember[$key]['level']];
            $newMember[$key]['type'] = 1;
            $newMember[$key]['link_superior_user'] = $value['link_superior_user'] ?? null;
            $newMember[$key]['upgrade_time'] = time();
            $newMember[$key]['create_time'] = time();
            $newMember[$key]['update_time'] = time();
            $number++;
        }

        (new MemberModel())->saveAll($newMember);
    }

    //导入数据
    public function hello($name = 'ThinkPHP6')
    {
        $test = (new Pay())->bindDownUser('thfOY4RaJr', 'hV94evWsOL');
//        dump($test);
//        $test = (new \app\lib\services\Member())->importConfusionTeam();
        dump($test);
        die;
//        $orderSn = "202106213571983714,202106201672255900,202106206785292548,202106200531242002,202106190422748080,202106191201241729,202106191362893432,202106192964876920,202106198881679257,202106198949694708,202106191579976849,202106195459020688,202106194876127421,202106190557118593,202106196323232591,202106193130597646,202106191877317247,202106192771218009,202106194419673682,202106198635130194,202106197476376716,202106197283994766,202106194672504338,202106191880302636,202106191491786123,202106194770965542,202106190840091557,202106198218815995,202106192419702554,202106197970256185,202106194546325054,202106196541303560,202106192902674797,202106197388717519,202106196333863986,202106197396403089,202106194580457061,202106198745344100,202106193578178730,202106194219359548,202106193985051340,202106196428419180,202106196663110141,202106193370931140,202106214925491304";
        $orderSn = "202106214925491304";
        $orderSn = explode(',', $orderSn);
        //"202106214925491304,202106194672504338"-'已发货和售后中
        dump(count($orderSn));
        $map[] = ['order_sn', 'in', $orderSn];
        $map[] = ['order_status', '=', 3];
        $map[] = ['pay_status', '=', 2];
        $list = Order::with(['goods' => function ($query) {
            $query->where(['pay_status' => 2, 'status' => 1, 'goods_sn' => '0048009896007']);
        }])->where($map)->select()->toArray();
        dump(count($list));
        if (!empty($list)) {
            $number = 0;
            foreach ($list as $key => $value) {
                if (!empty($value['goods'])) {
                    foreach ($value['goods'] as $gKey => $gValue) {
                        if ($gValue['goods_sn'] == '0048009896007') {
                            $afterSale[$number]['apply_reason'] = '已与客服成功沟通退售后';
                            $afterSale[$number]['goods'][0]['sku_sn'] = $gValue['sku_sn'];
                            $afterSale[$number]['goods'][0]['apply_price'] = $gValue['real_pay_price'];
                            $afterSale[$number]['images'] = [];
                            $afterSale[$number]['order_sn'] = $gValue['order_sn'];
                            $afterSale[$number]['received_goods_status'] = 1;
                            $afterSale[$number]['type'] = 1;
                            $number++;
                        }
                    }
                }

            }
        }
        dump($afterSale);
        die;
        if (!empty($afterSale ?? [])) {
            $model = (new \app\lib\models\AfterSale());
            foreach ($afterSale as $key => $value) {
                $res[$value['order_sn']] = $model->initiateAfterSale($value);
            }
        }
        dump($list);
        dump($afterSale ?? []);
        dump($res ?? []);

        die;
//        $this->qqq();
//        dump(12313);die;
        set_time_limit(0);
        ini_set('memory_limit', '10072M');
        ini_set('max_execution_time', '0');
//        sleep(61);
//        dump(12313);die;
        $type = 2;
        $fileUpload = 'uploads/123.xlsx';
        $test = (new \app\lib\services\Member())->importConfusionTeam();
        dump($test);
        die;
        $list = (new Office())->ReadExcel($fileUpload, $type);

        $userModel = (new User());
        if (!empty($list)) {
            foreach ($list as $key => $value) {
                $userInfo[$value['userPhone']] = $value;
            }

            $existDBUser = User::where(['phone' => array_unique(array_column($list, 'userPhone')), 'status' => [1, 2]])->field('uid,phone,name,vip_level,openid')->select()->toArray();
            if (!empty($existDBUser)) {
                foreach ($existDBUser as $key => $value) {
                    $existDBUserInfo[$value['phone']] = $value;
                }
            }
            $memberVdcInfo = MemberVdc::where(['status' => 1])->field('level,name,growth_value')->select()->toArray();
            foreach ($memberVdcInfo as $key => $value) {
                $memberVdc[$value['level']] = $value['growth_value'];
                $memberLevel[str_replace(' ', '', $value['name'])] = $value['level'];;
            }
//            $memberLevel = ['合伙人'=>1,'高级分享官'=>2,'中级分享官'=>3,'VIP顾客'=>3];
//            $memberLevel = ['合伙人'=>1,'高级团长'=>2,'团长'=>3];
            $userPhone = array_column($list, 'userPhone');

            //导入用户信息
            foreach ($list as $key => $value) {
                if (!empty($existDBUserInfo[$value['userPhone']])) {
                    $newUser[$key]['uid'] = $existDBUserInfo[$value['userPhone']]['uid'];
                    $newUser[$key]['openid'] = $existDBUserInfo[$value['userPhone']]['openid'] ?? null;
                    $newUser[$key]['name'] = $existDBUserInfo[$value['userPhone']]['name'];
                    $newUser[$key]['phone'] = $existDBUserInfo[$value['userPhone']]['phone'];
                    $newUser[$key]['vip_level'] = $existDBUserInfo[$value['userPhone']]['vip_level'];
                    $newUser[$key]['notNewUser'] = true;
                } else {
                    $newUser[$key]['uid'] = getUid(10);
                    $newUser[$key]['openid'] = $value['openid'] ?? null;
                    $newUser[$key]['name'] = $value['userName'];
                    $newUser[$key]['phone'] = $value['userPhone'];
                    $newUser[$key]['vip_level'] = $memberLevel[$value['shenfen']];
                    $newUser[$key]['notNewUser'] = false;
                }
                $topUserInfo[$value['userPhone']]['uid'] = $newUser[$key]['uid'];
                $topUserInfo[$value['userPhone']]['name'] = $newUser[$key]['name'];
                $topUserInfo[$value['userPhone']]['phone'] = $newUser[$key]['phone'];
                if (!empty($value['topUserPhone'])) {
                    if (!empty($topUserInfo[$value['topUserPhone']])) {
                        if (!isset($topUserTeamNumber[$value['topUserPhone']])) {
                            $topUserTeamNumber[$value['topUserPhone']] = 0;
                        }
                        $newUser[$key]['link_superior_user'] = $topUserInfo[$value['topUserPhone']]['uid'];
                        $topUserTeamNumber[$value['topUserPhone']] += 1;
                    } else {
                        //如果找不到上级资料暂存之后继续添加
                        $notFoundTopUser[$key] = $value;
                    }
                }
                $newUser[$key]['old_sync'] = 5;
                $newUser[$key]['growth_value'] = $memberVdc[$newUser[$key]['vip_level']];
//                    }
            }

            if (!empty($notFoundTopUser)) {
                foreach ($notFoundTopUser as $key => $value) {
                    if (!empty($topUserInfo[$value['topUserPhone']])) {
                        if (!isset($topUserTeamNumber[$value['topUserPhone']])) {
                            $topUserTeamNumber[$value['topUserPhone']] = 0;
                        }
                        $newUser[$key]['link_superior_user'] = $topUserInfo[$value['topUserPhone']]['uid'];
                        $topUserTeamNumber[$value['topUserPhone']] += 1;
                    } else {
                        echo '此人还是找不到上级,~~~~~~~~~~~' . $value['userName'];
                        die;
                    }
                }
            }

            if (!empty($topUserTeamNumber)) {
                foreach ($newUser as $key => $value) {
                    if (!empty($topUserTeamNumber[$value['phone']]) && empty($existDBUserInfo[$value['phone']])) {
                        $newUser[$key]['team_number'] = $topUserTeamNumber[$value['phone']] ?? 0;
                    }
                }
            }
            foreach ($newUser as $key => $value) {
                if (!empty($value['notNewUser'])) {
                    $oldUser[] = $value;
                    unset($newUser[$key]);
                }
            }
            if (!empty($newUser)) {
                $res = $userModel->saveAll($newUser);
            } else {
                dump('没啥新用户导入的');
            }
            dump($newUser ?? []);
//                foreach ($newUser as $key => $value) {
//                    $value['create_time'] = time();
//                    $value['update_time'] = time();
//                    //dump($value);
//                    $userModel->insert($value);
        }
        /*------------------------------导入会员和结构---------------------------------------------*/

        $allUser = !empty($newUser) ? $newUser : ($oldUser ?? []);
        if (empty($allUser)) {
            dump('真的没啥好导入的');
        }
        //筛选出最顶级的用户
        foreach ($allUser as $key => $value) {
            if (empty($value['link_superior_user'])) {
                $topUser[] = $value['uid'];
                $sortAllUser[] = $value;
            }
        }
        //递归查询,查出团队中每一级的人,后续按照层级一层一层导入信息
        if (!empty($topUser)) {
            $all = $this->digui($allUser, $topUser, 2);
        }

        if (!empty($all)) {
            foreach ($all as $key => $value) {
                foreach ($value as $k => $v) {
                    $sortAllUser[] = $v;
                }
            }
        }
        $allUser = $sortAllUser ?? [];

//        //按照等级排序,vip_level越小等级越高
//        array_multisort(array_column($allUser,'vip_level'), SORT_ASC, $allUser);

        $existDBMember = Member::where(['user_phone' => array_unique(array_column($list, 'userPhone')), 'status' => [1, 2]])->field('uid,user_phone,level,team_code,child_team_code,parent_team')->select()->toArray();
        $topUser = [];
        if (!empty($existDBMember)) {
            foreach ($existDBMember as $key => $value) {
                $topUser[$value['uid']] = $value;
                $existMember[] = $value;
                $existMemberPhone[] = $value['user_phone'];
            }
        }

        if (!empty($allUser)) {

            $codeBuildService = (new CodeBuilder());
            $memberModel = (new MemberModel());
            $allMember = Member::count();
            $this->lastMemberCount = $allMember ?? 50000;
            $memberCard = sprintf("%010d", ($allMember + 1));

            foreach ($allUser as $key => $value) {
                $topUserTeamStartNumber[$value['uid']] = 1000;
                $this->topUserTeamStartNumber = $topUserTeamStartNumber;
            }
            $notMap[] = ['', 'exp', Db::raw('link_superior_user is null')];
            $notMap[] = ['status', 'in', [1, 2]];
            $notTopUserStartUser = Member::where($notMap)->count();
            $notTopUserStartUser += 1000;
            $this->notTopUserStartUser = $notTopUserStartUser;
            foreach ($allUser as $key => $value) {
                if (in_array($value['phone'], $existMemberPhone ?? [])) {
                    continue;
                }

                $newMember[$key]['member_card'] = sprintf("%010d", ($allMember + 1));
                $this->lastMemberCount = $allMember + 1;
                $newMember[$key]['uid'] = $value['uid'];
                $newMember[$key]['user_phone'] = $value['phone'];
                $newMember[$key]['create_time'] = time();
                $newMember[$key]['update_time'] = time();
                $newMember[$key]['upgrade_time'] = time();

                if (empty($value['link_superior_user'])) {
//                    $newMember[$key]['team_code'] = $memberModel->buildMemberTeamCode($value['vip_level']);
                    $newMember[$key]['team_code'] = $this->getTeamCode('', $value['vip_level'], $notTopUserStartUser);
                    $newMember[$key]['child_team_code'] = $newMember[$key]['team_code'];
                    $notTopUserStartUser++;
                    $this->notTopUserStartUser = $notTopUserStartUser;
                    $this->topUserTeamStartNumber[$value['uid']] = $notTopUserStartUser;

                } else {
                    $topUserInfo = $topUser[$value['link_superior_user']] ?? [];
                    if (!empty($topUserInfo)) {
                        $newMember[$key]['team_code'] = $topUserInfo['team_code'];
                        $newMember[$key]['child_team_code'] = $this->getTeamCode($topUserInfo['child_team_code'], $value['vip_level'], $this->topUserTeamStartNumber[$value['link_superior_user']] ?? 1000);

                        $newMember[$key]['parent_team'] = $topUserInfo['child_team_code'];
                        $topUserTeamStartNumber[$value['link_superior_user']]++;
                        $this->topUserTeamStartNumber[$value['link_superior_user']]++;
                        if (empty($topUser[$value['link_superior_user']]['link_superior_user'] ?? null)) {
                            $this->topUserTeamStartNumber[$value['uid']] += 2;
                        }
                    } else {
                        $topUserMember = $this->buildTopMember($value, ['allMember' => $topUser ?? [], 'allUser' => $userInfo ?? [], 'type' => 1]);
                        $newMember[$key]['team_code'] = $topUserMember['team_code'];
                        $newMember[$key]['child_team_code'] = $memberModel->buildMemberTeamCode($value['vip_level'], $topUserMember['child_team_code']);
                        $newMember[$key]['parent_team'] = $topUserMember['child_team_code'];
                    }

                }

                $topMember[$value['uid']]['team_code'] = $newMember[$key]['team_code'];
                $topMember[$value['uid']]['child_team_code'] = $newMember[$key]['child_team_code'];

                $newMember[$key]['level'] = $value['vip_level'];
                $newMember[$key]['growth_value'] = $memberVdc[$newMember[$key]['level']];
                $newMember[$key]['demotion_growth_value'] = $memberVdc[$newMember[$key]['level']];
                $newMember[$key]['type'] = 1;
                $newMember[$key]['link_superior_user'] = $value['link_superior_user'] ?? null;

                $allMember++;
                $topUser[$value['uid']] = $newMember[$key];

            }

            if (!empty($newMember)) {
                (new Member())->saveAll($newMember);
            }
            dump(12312313);
            die;
        }

    }

    protected $all = [];

    public function digui(array $allUser, array $topUser, int $level)
    {
        foreach ($allUser as $k1 => $v1) {
            if (!empty($v1['link_superior_user']) && in_array($v1['link_superior_user'], $topUser)) {
                $finally[] = $v1;
                $finallyUid[] = $v1['uid'];
            }
        }
        $this->all[$level] = $finally ?? [];
        if (!empty($finallyUid)) {
            $all = $this->digui($allUser, $finallyUid, $level + 1);
        }
        return $this->all;

    }

    public function getTeamCode(string $ParentTeam, int $level, int $number)
    {
        $pLength = strlen($ParentTeam);

        switch ($level) {
            case 1:
                $ParentTeam = null;
                break;
            case 2:
                if ($pLength >= 5) {
                    $ParentTeam = substr($ParentTeam, 0, 5);
                    $sqlMaxLength = 10;
                }
                break;
            case 3:
                if ($pLength >= 10) {
                    $ParentTeam = substr($ParentTeam, 0, 10);
                    $sqlMaxLength = 16;
                }
                break;
        }

        if ($level == 3 && !empty($ParentTeam)) {
            if (intval($ParentTeam) > 10) {
                $SPTFLen = "%06d";
            } else {
                $SPTFLen = "%05d";
            }
        } else {
            $SPTFLen = "%05d";
        }

        return trim($ParentTeam . sprintf($SPTFLen, ($number + 1)));
    }

    public function test8()
    {
        dump(priceFormat(-3475.400000000023));
        die;
        set_time_limit(0);
        ini_set('memory_limit', '30720M');
        ini_set('max_execution_time', '0');

        $allGoods = GoodsSku::column('cost_price', 'sku_sn');
        $map[] = ['', 'exp', Db::raw('cost_price is null')];
        $page = 11;
        $pageNumber = 1500;
        $allOrderGoods = OrderGoods::where($map)->field('id,sku_sn')->page($page, $pageNumber)->select()->toArray();

        if (!empty($allOrderGoods)) {
            foreach ($allOrderGoods as $key => $value) {
                if (!empty(doubleval($allGoods[$value['sku_sn']]))) {
                    $res[] = OrderGoods::update(['cost_price' => $allGoods[$value['sku_sn']]], ['id' => $value['id']]);
                }
            }
        }
        dump($res ?? []);
        die;
    }


    public function buildTopMember(array $value, array $data)
    {
        $allMember = $data['allMember'] ?? [];
        $allUser = $data['$allUser'] ?? [];
        $type = $data['type'] ?? 1;
        $codeBuildService = (new CodeBuilder());
        $memberModel = (new MemberModel());
//        if($type == 2){
//            $map[] = ['uid','=',$value['uid']];
//        }else{
//            $map[] = ['uid','=',$value['link_superior_user']];
//        }
        $map[] = ['uid', '=', $value['uid']];
//        dump('当前传入的人'.$value['name']);
        //$map[] = ['uid','=',$value['uid']];
//        $topUser = User::where($map)->findOrEmpty()->toArray();
        $topUser = $value;
//        dump('当前传入的人查出来的上级');
//        dump($topUser);
//        $newMember['member_card'] = $codeBuildService->buildMemberNum();
        $newMember['member_card'] = sprintf("%010d", ($this->lastMemberCount + 1));
        $newMember['uid'] = $topUser['uid'];
        $newMember['user_phone'] = $topUser['phone'];
        $newMember['level'] = $topUser['vip_level'];
        $newMember['link_superior_user'] = $topUser['link_superior_user'];
        $newMember['link_superior_user'] = $topUser['link_superior_user'];
        if (empty($topUser['link_superior_user'])) {
//            $newMember['team_code'] = $memberModel->buildMemberTeamCode($topUser['vip_level']);
            $newMember['team_code'] = $this->getTeamCode('', $topUser['vip_level'], $this->notTopUserStartUser ?? 1000);
            $newMember['child_team_code'] = $newMember['team_code'];
            $newMember['parent_team'] = null;
            $this->notTopUserStartUser += 1;
        } else {
            $topUserInfo = $allMember[$topUser['link_superior_user']] ?? [];
            if (!empty($topUserInfo)) {
                $newMember['team_code'] = $topUserInfo['team_code'];
//                $newMember['child_team_code'] = $memberModel->buildMemberTeamCode($topUser['vip_level'],$topUserInfo['child_team_code']);
                $newMember['child_team_code'] = $this->getTeamCode($topUserInfo['child_team_code'], $topUser['vip_level'], $this->topUserTeamStartNumber[$topUser['link_superior_user']] ?? 1000);
                $newMember['parent_team'] = $topUserInfo['child_team_code'];

                if (!empty($this->topUserTeamStartNumber[$topUser['link_superior_user']] ?? null)) {
                    $this->topUserTeamStartNumber[$topUser['link_superior_user']] += 1;
                }
            } else {
                $topTopUserInfo = UserTest::where(['uid' => $topUser['link_superior_user']])->findOrEmpty()->toArray();
//                dump('上上级');
//                dump($topTopUserInfo);
                if (!empty($topTopUserInfo)) {
                    $toptopUser = $this->buildTopMember($topTopUserInfo, ['allMember' => $allMember ?? [], 'allUser' => $allUser ?? [], 'type' => 2]);
                    $newMember['team_code'] = $toptopUser['team_code'];
//                    $newMember['child_team_code'] = $memberModel->buildMemberTeamCode($value['vip_level'],$toptopUser['child_team_code']);
                    $newMember['child_team_code'] = $this->getTeamCode($toptopUser['child_team_code'], $value['vip_level'], $this->topUserTeamStartNumber[$topUser['link_superior_user']] ?? 1000);
                    if (!empty($this->topUserTeamStartNumber[$topUser['link_superior_user']] ?? null)) {
                        $this->topUserTeamStartNumber[$topUser['link_superior_user']] += 1;
                    }
                    $newMember['parent_team'] = $toptopUser['child_team_code'];
                } else {
//                    dump('现在的数组是');
//                    dump($value);
                    echo '此人的上级有误,请查证' . $topUser['link_superior_user'] . ' 手机号码为 ' . $topUser['phone'] ?? '';
                    die;
                }
            }

        }
//        dump('需要新增的上级');
//        dump($newMember);
//        $memberInfo = (new MemberTest())->where(['uid'=>$newMember['uid'],'status'=>[1,2]])->findOrEmpty()->toArray();
        $memberInfo = [];
        if (empty($memberInfo)) {
            $res = (new MemberTest())->insert($newMember);
        } else {
            return $memberInfo;
        }
        return $newMember;
    }

    /**
     * @title  导出Excel
     * @param $fileName
     * @param $fileType
     * @return void
     * @throws \PhpOffice\PhpSpreadsheet\Writer\Exception
     */
    function exportExcel($data, $fileName, $fileType)
    {

        //文件名称校验
        $fileName  ='数据';
        $fileType  ='Xlsx';
        if (!$fileName) {
            trigger_error('文件名不能为空', E_USER_ERROR);
        }

        //Excel文件类型校验
        $type = ['Excel2007', 'Xlsx', 'Excel5', 'xls'];
        if (!in_array($fileType, $type)) {
            trigger_error('未知文件类型', E_USER_ERROR);
        }
        foreach ($data as $datum) {
            foreach ($datum as $item) {
                $finally[] = $item;
            }

        }
        $data = $finally;
//        $data = [[1, 'jack', 10],
//            [2, 'mike', 12],
//            [3, 'jane', 21],
//            [4, 'paul', 26],
//            [5, 'kitty', 25],
//            [6, 'yami', 60],];

        $title = ['总收入', '总提现', '用户uid','总提现区代金额','姓名','手机号码','差价','税后'];

        $spreadsheet = new Spreadsheet();
        $worksheet = $spreadsheet->getActiveSheet();

        //设置工作表标题名称
        $worksheet->setTitle('Sheet');
        //设置默认行高
        $worksheet->getDefaultRowDimension()->setRowHeight(18);

        //表头
        //设置单元格内容
        foreach ($title as $key => $value) {
            $worksheet->setCellValueByColumnAndRow($key + 1, 1, $value);
        }

        $row = 2; //从第二行开始
        foreach ($data as $item) {
            $column = 1;

            foreach ($item as $value) {
                $worksheet->setCellValueByColumnAndRow($column, $row, $value);
                $column++;
            }
            $row++;
        }


        $fileName = '学生信息123';
        $fileType = 'Xlsx';

        //1.下载到服务器
//        $writer = IOFactory::createWriter($spreadsheet, $fileType);
//        $res = $writer->save(app()->getRootPath()."public\storage\uploads\\".$fileName.'.xlsx');

        //2.输出到浏览器
        if ($fileType == 'Excel2007' || $fileType == 'Xlsx') {
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment;filename="' . $fileName . '.xlsx"');
            header('Cache-Control: max-age=0');
        } else { //Excel5
            header('Content-Type: application/vnd.ms-excel');
            header('Content-Disposition: attachment;filename="' . $fileName . '.xls"');
            header('Cache-Control: max-age=0');
        }
        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx'); //按照指定格式生成Excel文件
        $writer->save('php://output');

        //删除清空
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
    }

    /** 百度翻译入口

     * Created by PhpStorm.

     * User: Administrator

     * Date: 2020/8/27

     * Time: 9:02

     */

    function translate($query, $from='auto', $to='zh')

    {

        $fy_url = 'http://api.fanyi.baidu.com/api/trans/vip/translate';

        $app_id = '20230606001702790';

        $sec_key = 'GaGAJAYpGJwjVeHcFLsJ';

        $args = array(

            'q' => $query,

            'appid' => $app_id,

            'salt' => rand(10000,99999),

            'from' => $from,

            'to' => $to,

        );

        $args['sign'] = $this->buildSign($query, $app_id, $args['salt'], $sec_key);

        $ret = $this->call($fy_url, $args);

        $ret = json_decode($ret, true);

        return $ret;

    }

//加密

    function buildSign($query, $appID, $salt, $secKey)

    {

        $str = $appID . $query . $salt . $secKey;

        $ret = md5($str);

        return $ret;

    }

//发起网络请求

    function call($url, $args=null, $method="post", $testflag = 0, $timeout = 10, $headers=array())

    {

        $ret = false;

        $i = 0;

        while($ret === false)

        {

            if($i > 1)

                break;

            if($i > 0)

            {

                sleep(1);

            }

            $ret = $this->callOnce($url, $args, $method, false, $timeout, $headers);

            $i++;

        }

        return $ret;

    }

    function callOnce($url, $args=null, $method="post", $withCookie = false, $timeout = 10, $headers=array())

    {

        $ch = curl_init();

        if($method == "post")

        {

            $data = $this->convert($args);

            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

            curl_setopt($ch, CURLOPT_POST, 1);

        }

        else

        {

            $data = $this->convert($args);

            if($data)

            {

                if(stripos($url, "?") > 0)

                {

                    $url .= "&$data";

                }

                else

                {

                    $url .= "?$data";

                }

            }

        }

        curl_setopt($ch, CURLOPT_URL, $url);

        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        if(!empty($headers))

        {

            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        }

        if($withCookie)

        {

            curl_setopt($ch, CURLOPT_COOKIEJAR, $_COOKIE);

        }

        $r = curl_exec($ch);

        curl_close($ch);

        return $r;

    }

    function convert(&$args)

    {

        $data = '';

        if (is_array($args))

        {

            foreach ($args as $key=>$val)

            {

                if (is_array($val))

                {

                    foreach ($val as $k=>$v)

                    {

                        $data .= $key.'['.$k.']='.rawurlencode($v).'&';

                    }

                }

                else

                {

                    $data .="$key=".rawurlencode($val)."&";

                }

            }

            return trim($data, "&");

        }

        return $args;

    }

    function keyED($txt,$encrypt_key)
    {
        $encrypt_key = md5($encrypt_key);
        $ctr=0;
        $tmp = "";
        for ($i=0;$i<strlen($txt);$i++){
            if ($ctr==strlen($encrypt_key)) $ctr=0;
            $tmp.= substr($txt,$i,1) ^ substr($encrypt_key,$ctr,1);
            $ctr++;
        }

        return $tmp;
    }

    function encrypt($txt,$key){
        $encrypt_key = md5(rand(0,32000));//生成随机数，确保每次生成的密文都不一样

        $ctr=0;
        $tmp = "";
        for ($i=0;$i<strlen($txt);$i++){
            if ($ctr==strlen($encrypt_key)) $ctr=0;

            $tmp.= substr($encrypt_key,$ctr,1) . (substr($txt,$i,1) ^ substr($encrypt_key,$ctr,1));//随机数与异或后的密文轮流排列
            $ctr++;
        }

        return $this->keyED($tmp,$key);
    }

    function byte_wise_ord($string){  //密文转为ascii
	$tmp="";
	if(!empty($string)){
        for($i=0;$i<strlen($string);$i++){
            $tmp.=str_pad(ord(substr($string,$i,1)),3,"0",STR_PAD_LEFT);
        }
    }
	return $tmp;
}

    function byte_wise_chr($string){
        $tmp="";
        if(!empty($string)){
            for($i=0;$i<strlen($string)/3;$i++){ ///注意这里的循环次数是strlen($string)/3，因为我们的密文是3位为单位的
                $tmp.=chr(substr($string,$i*3,3)+0);
            }
        }
        return $tmp;
    }

    function decrypt($txt,$key){
        $txt = $this->keyED($txt,$key);

        $tmp = "";
        for ($i=0;$i<strlen($txt);$i++){
            $md5 = substr($txt,$i,1);
            $i++;
            $tmp.= (substr($txt,$i,1) ^ $md5);
        }
        return $tmp;
    }



    


}
