<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 用户模块Controller]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\api\v1;


use app\BaseController;
use app\lib\constant\WithdrawConstant;
use app\lib\exceptions\OrderException;
use app\lib\exceptions\ServiceException;
use app\lib\exceptions\UserException;
use app\lib\exceptions\WithdrawException;
use app\lib\models\BalanceDetail;
use app\lib\models\CrowdfundingBalanceDetail;
use app\lib\models\CrowdfundingTicketDetail;
use app\lib\models\Divide;
use app\lib\models\GrowthValueDetail;
use app\lib\models\Handsel;
use app\lib\models\IntegralDetail;
use app\lib\models\Order;
use app\lib\models\RechargeLink;
use app\lib\models\TeamPerformance;
use app\lib\models\UserAgreement as UserAgreementModel;
use app\lib\models\UserBankCard;
use app\lib\models\UserCoupon;
use app\lib\models\User as UserModel;
use app\lib\models\Collection;
use app\lib\models\Withdraw;
use app\lib\models\WxUserSync;
use app\lib\models\ZhongShuKePay;
use app\lib\services\Finance;
use app\lib\services\KuaiShangPay;
use app\lib\services\QrCode;
use app\lib\services\Summary;
use think\Db;

class User extends BaseController
{
    protected $middleware = [
        'checkUser' => ['except' => ['QrCode', 'teamMemberOrder','syncOtherAppUser']],
        'checkApiToken'
    ];

    /**
     * @title  用户详情
     * @param UserModel $model
     * @return string
     */
    public function info(UserModel $model)
    {
        $info = $model->getUserInfo($this->request->param('uid'));
        return returnData($info);
    }

    /**
     * @title  修改用户资料
     * @param UserModel $model
     * @throws \Exception
     * @return string
     */
    public function update(UserModel $model)
    {
        $res = $model->edit($this->requestData);
        return returnData($res);
    }

    /**
     * @title  修改用户昵称或头像
     * @param UserModel $model
     * @throws \Exception
     * @return mixed
     */
    public function updateNicknameOrAvatarUrl(UserModel $model)
    {
        $res = $model->editNicknameOrAvatarUrl($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  同步老用户资料
     * @param UserModel $model
     * @return string
     */
    public function syncOldUser(UserModel $model)
    {
        throw new UserException(['msg' => '无需同步~']);
        $res = $model->syncOldUser($this->requestData);
        return returnData($res);
    }

    /**
     * @title  用户订单列表
     * @param Order $model
     * @return string
     * @throws \Exception
     */
    public function order(Order $model)
    {
        $list = $model->userList($this->requestData);
        return returnData($list['list'], $list['pageTotal'], 0, '成功', $list['total'] ?? 0);
    }

    /**
     * @title  用户订单金额汇总
     * @param Order $model
     * @return string
     * @throws \Exception
     */
    public function orderSummary(Order $model)
    {
        $info = $model->userListSummary($this->requestData);
        return returnData($info);
    }

    /**
     * @title  用户相关分润团队订单列表(老方法,不记录过往人员订单)
     * @param Order $model
     * @return string
     * @throws \Exception
     */
    public function teamDivideOrder(Order $model)
    {
        $list = $model->userDivideOrderList($this->requestData);
        return returnData($list['list'], $list['pageTotal'], 0, '成功', $list['total'] ?? 0);
    }

    /**
     * @title  用户相关分润团队订单各级别总数(老方法,直推间推不记录过往人员订单)
     * @param Order $model
     * @return string
     * @throws \Exception
     */
    public function teamDivideOrderCount(Order $model)
    {
        $list = $model->userDivideOrderCount($this->requestData);
        return returnData($list);
    }

    /**
     * @title  用户相关分润团队订单列表(新方法,记录过往人员订单)
     * @param TeamPerformance $model
     * @return string
     * @throws \Exception
     */
    public function teamDivideOrderNew(TeamPerformance $model)
    {
        $data = $this->requestData;
        $data['levelType'] = $data['orderUserType'] == 1 ? 1 : 3;
        $list = $model->userTeamAllOrderList($data);
        return returnData($list['list'], $list['pageTotal'], 0, '成功', $list['total'] ?? 0);
    }


    /**
     * @title  用户团队全部订单列表
     * @param TeamPerformance $model
     * @return string
     * @throws \Exception
     */
    public function teamAllOrder(TeamPerformance $model)
    {
        $list = $model->userTeamAllOrderList($this->requestData);
        return returnData($list['list'], $list['pageTotal'], 0, '成功', $list['total'] ?? 0);
    }

    /**
     * @title  用户团队全部订单各级别总数
     * @param TeamPerformance $model
     * @return string
     * @throws \Exception
     */
    public function teamAllOrderCount(TeamPerformance $model)
    {
        $list = $model->userTeamAllOrderCount($this->requestData);
        return returnData($list);
    }

    /**
     * @title  查看团队成员订单
     * @param TeamPerformance $model
     * @return string
     * @throws \Exception
     */
    public function teamMemberOrder(TeamPerformance $model)
    {
        $list = $model->teamMemberOrder($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  团队中直推的普通用户列表
     * @param UserModel $model
     * @return string
     * @throws \Exception
     */
    public function teamDirectNormalUser(UserModel $model)
    {
        $list = $model->teamDirectNormalUser($this->requestData);
        return returnData($list['list'], $list['pageTotal'], 0, '成功', $list['total'] ?? 0);
    }

    /**
     * @title  团队中直推的普通用户列表数据汇总面板
     * @param UserModel $model
     * @return string
     * @throws \Exception
     */
    public function teamDirectNormalUserSummary(UserModel $model)
    {
        $info = $model->teamDirectNormalUserSummary($this->requestData);
        return returnData($info);
    }

    /**
     * @title  用户优惠券列表
     * @param UserCoupon $model
     * @return string
     * @throws \Exception
     */
    public function coupon(UserCoupon $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  用户领取优惠券
     * @param UserCoupon $model
     * @return string
     */
    public function receiveCoupon(UserCoupon $model)
    {
        $res = $model->new($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  用户收藏列表
     * @param Collection $model
     * @return string
     * @throws \Exception
     */
    public function collection(Collection $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  绑定上下级关系
     * @param UserModel $model
     * @return string
     * @throws \Exception
     */
    public function bindSuperiorUser(UserModel $model)
    {
        $data = $this->requestData;
        $res = $model->bindSuperiorUser($data['uid'], $data['link_uid']);
        return returnMsg($res);
    }

    /**
     * @title  成长值明细列表
     * @param GrowthValueDetail $model
     * @return string
     * @throws \Exception
     */
    public function growthValueList(GrowthValueDetail $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  积分(美丽豆)明细列表
     * @param IntegralDetail $model
     * @return string
     * @throws \Exception
     */
    public function integralList(IntegralDetail $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  美丽券明细列表
     * @param CrowdfundingTicketDetail $model
     * @return string
     * @throws \Exception
     */
    public function ticketList(CrowdfundingTicketDetail $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title 用户积分(美丽豆)数据面板
     * @param IntegralDetail $model
     * @return string
     */
    public function integralIncome(IntegralDetail $model)
    {
        $info['balance'] = $model->getAllBalanceByUid($this->request->uid);
        return returnData($info);
    }

    /**
     * @title 用户美丽券数据面板
     * @param CrowdfundingTicketDetail $model
     * @return string
     */
    public function ticketIncome(CrowdfundingTicketDetail $model)
    {
        $info['balance'] = $model->getAllBalanceByUid($this->request->uid);
        return returnData($info);
    }

    /**
     * @title  余额明细列表
     * @param BalanceDetail $model
     * @return string
     * @throws \Exception
     */
    public function balanceLit(BalanceDetail $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  用户分润列表
     * @param Divide $model
     * @return string
     * @throws \Exception
     */
    public function divideList(Divide $model)
    {
        $data = $this->requestData;
        $data['type'] = [1, 3, 4];
        $list = $model->userDivideList($data);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  我的团队列表
     * @param UserModel $model
     * @return string
     * @throws \Exception
     */
    public function team(UserModel $model)
    {
        $list = $model->teamList($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  提交提现申请
     * @param Finance $service
     * @return string
     */
    public function withdraw(Finance $service)
    {
//        throw new ServiceException(['msg'=>'系统升级中, 请耐心等待, 感谢您的理解与支持']);
        $data = $this->requestData;
        $res = $service->userWithdraw($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  提现申请列表
     * @param Withdraw $model
     * @return string
     * @throws \Exception
     */
    public function withdrawList(Withdraw $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  生成海报二维码
     * @param QrCode $service
     * @return string
     * @throws \Endroid\QrCode\Exception\InvalidPathException
     */
    public function QrCode(QrCode $service)
    {
        $codeImage = $service->create($this->request->param('content'));
        return returnData($codeImage);
    }

    /**
     * @title  用户快商签约详情
     * @param KuaiShangPay $service
     * @return string
     */
    public function kuaiShangContractInfo(KuaiShangPay $service, ZhongShuKePay $zhongShuKePayModel)
    {
        $data = $this->requestData;
        $withdrawPayType = config('system.withdrawPayType');
        //1为微信支付 2为汇聚支付 3为杉德支付 4为快商 5为中数科 88为线下打款 默认4
        switch ($withdrawPayType) {
            case WithdrawConstant::WITHDRAW_PAY_TYPE_WX:
            case WithdrawConstant::WITHDRAW_PAY_TYPE_JOIN:
            case WithdrawConstant::WITHDRAW_PAY_TYPE_SHANDE:
            case WithdrawConstant::WITHDRAW_PAY_TYPE_OFFLINE:
            default:
                throw new WithdrawException(['errorCode' => 11007]);
            case WithdrawConstant::WITHDRAW_PAY_TYPE_KUAISHANG:
                throw new WithdrawException(['msg' => '快商签约功能已停用']);
                $data['needVerify'] = true;
                $info = $service->contractInfo($data);
                break;
            case WithdrawConstant::WITHDRAW_PAY_TYPE_ZSK:
                $info = $zhongShuKePayModel->info($data);

        }
        $info['withdrawPayType'] = $withdrawPayType;
        return returnData($info);
    }

    /**
     * @title  用户赠送的套餐列表
     * @param Handsel $model
     * @throws \Exception
     * @return mixed
     */
    public function handselList(Handsel $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  用户众筹钱包余额明细
     * @param CrowdfundingBalanceDetail $model
     * @return string
     * @throws \Exception
     */
    public function crowdBalanceDetail(CrowdfundingBalanceDetail $model)
    {
        $data = $this->requestData;
        $list = $model->list($data);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  用户银行卡列表
     * @param UserBankCard $model
     * @return string
     * @throws \Exception
     */
    public function userBankCardList(UserBankCard $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  同步其他小程序或公众号的用户信息到主体帐号
     * @return void
     */
    public function syncOtherAppUser(WxUserSync $model)
    {
        throw new ServiceException(['msg' => '功能已停用~']);
        $res = $model->syncOtherAppUser($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  用户下级充值额度进度查询
     * @throws \Exception
     * @param RechargeLink $model
     * @return string
     */
    public function userSubordinateRechargeRate(RechargeLink $model)
    {
        $info = $model->userSubordinateRechargeRate($this->requestData);
        return returnData($info);
    }

    /**
     * @title  用户新增业绩汇总数据面板
     * @param Summary $service
     * @return string
     */
    public function userWithdrawDataPanel(Summary $service)
    {
        $info = $service->userWithdrawDataPanel($this->requestData);
        return returnData($info);
    }

    /**
     * @title  用户历史充值和提现
     * @param Summary $service
     * @return string
     */
    public function userHisWithdrawAndRecharge(Summary $service)
    {
        $info = $service->userHisWithdrawAndRecharge($this->requestData);
        return returnData($info);
    }

    /**
     * @title 用户充值列表
     * @param CrowdfundingBalanceDetail $model
     * @return string
     * @throws \Exception
     */
    public function userRechargeList(CrowdfundingBalanceDetail  $model)
    {
        $list = $model->rechargeList($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title 用户美丽金转出列表
     * @param CrowdfundingBalanceDetail $model
     * @return string
     * @throws \Exception
     */
    public function userTransferList(CrowdfundingBalanceDetail  $model)
    {
        $list = $model->transferList($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title 中数科签约
     * @param ZhongShuKePay $model
     * @return string
     */
    public function userContractAdd(ZhongShuKePay $model){
        $res = $model->add($this->requestData);
        return returnData($res);
    }

    /**
     * @title 中数科变更银行卡号
     * @param ZhongShuKePay $model
     * @return string
     */
    public function userContractEdit(ZhongShuKePay $model){
        $res = $model->edit($this->requestData);
        return returnData($res);
    }

    /**
     * @title  用户签约列表
     * @param UserAgreementModel $model
     * @return mixed
     * @throws \Exception
     */
    public function userAgreementList(UserAgreementModel $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  用户签约详情
     * @param UserAgreementModel $model
     * @return mixed
     */
    public function userAgreementInfo(UserAgreementModel $model)
    {
        $info = $model->info($this->requestData);
        return returnData($info);
    }

    /**
     * @title  新增 用户签约
     * @param UserAgreementModel $model
     * @return string
     */
    public function createUserAgreement(UserAgreementModel $model)
    {
        $res = $model->DBNew($this->requestData);
        return returnMsg($res);
    }

}