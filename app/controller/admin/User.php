<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 用户模块Controller]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\admin;


use app\BaseController;
use app\lib\constant\WithdrawConstant;
use app\lib\exceptions\WithdrawException;
use app\lib\models\AdvanceCardDetail;
use app\lib\models\BalanceDetail;
use app\lib\models\Behavior as BehaviorModel;
use app\lib\models\CrowdfundingFuseRecord;
use app\lib\models\CrowdfundingTicketDetail;
use app\lib\models\HealthyBalanceDetail;
use app\lib\models\IntegralDetail;
use app\lib\models\RechargeTopLinkRecord;
use app\lib\models\TeamMember;
use app\lib\models\User as UserModel;
use app\lib\models\UserPwdOperationLog;
use app\lib\models\UserPrivacy;
use app\lib\models\WxUserSync;
use app\lib\models\ZhongShuKePay;
use app\lib\services\KuaiShangPay;
use app\lib\services\Member as MemberService;
use app\lib\services\Summary;

class User extends BaseController
{
    protected $middleware = [
        'checkAdminToken',
        'checkRule',
        'OperationLog',
    ];

    /**
     * @title  用户列表
     * @param UserModel $model
     * @return string
     * @throws \Exception
     */
    public function list(UserModel $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  用户数量汇总
     * @param UserModel $model
     * @return string
     * @throws \Exception
     */
    public function summary(UserModel $model)
    {
        $info = $model->total($this->requestData);
        return returnData($info);
    }

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
     * @title  用户余额明细
     * @param BalanceDetail $model
     * @return string
     * @throws \Exception
     */
    public function balance(BalanceDetail $model)
    {
        $data = $this->requestData;
        $list = $model->list($data);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  指定会员上级/等级
     * @param MemberService $service
     * @return string
     */
    public function assign(MemberService $service)
    {
        $res = $service->assignUserLevel($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  用户行为轨迹
     * @param BehaviorModel $model
     * @return string
     * @throws \Exception
     */
    public function behaviorList(BehaviorModel $model)
    {
        $data = $this->requestData;
        $list = $model->list($data);
        return returnData($list);
    }

    /**
     * @title  余额明细
     * @param BalanceDetail $model
     * @return string
     * @throws \Exception
     */
    public function balanceSummary(BalanceDetail $model)
    {
        $data = $this->requestData;
        $returnData = $model->getSummaryBalanceByUid($data);
        return returnData($returnData);
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
     * @title  健康豆余额明细
     * @param HealthyBalanceDetail $model
     * @return string
     * @throws \Exception
     */
    public function healthyBalanceList(HealthyBalanceDetail $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  修改用户登录密码/支付密码记录
     * @param UserPwdOperationLog $model
     * @throws \Exception
     * @return string
     */
    public function updateUserPwdList(UserPwdOperationLog $model)
    {
        $data = $this->requestData;
        $list = $model->list($data);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  修改用户登录密码/支付密码
     * @param UserPwdOperationLog $model
     * @return string
     */
    public function updateUserPwd(UserPwdOperationLog $model)
    {
        $data = $this->requestData;
        $data['adminInfo'] = $this->request->adminInfo;
        $res = $model->managerUpdateUserPwd($data);
        return returnMsg($res);
    }

    /**
     * @title  体验中心用户列表
     * @param UserModel $model
     * @return string
     * @throws \Exception
     */
    public function expUserList(UserModel $model)
    {
        $data = $this->requestData;
        $data['needAllLevel'] = true;
        $data['exp_level'] = 1;
        $list = $model->list($data);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  指定用户为体验中心身份
     * @param UserModel $model
     * @return string
     */
    public function assignToggleExp(UserModel $model)
    {
        $list = $model->assignToggleExp($this->requestData);
        return returnData($list);
    }

    /**
     * @title  团队股东用户列表
     * @param UserModel $model
     * @return string
     * @throws \Exception
     */
    public function teamShareholderUserList(UserModel $model)
    {
        $data = $this->requestData;
        $data['needAllLevel'] = true;
        $data['team_shareholder_level'] = 1;
        $list = $model->list($data);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  指定用户为团队股东身份
     * @param UserModel $model
     * @return string
     */
    public function assignToggleTeamShareholder(UserModel $model)
    {
        $list = $model->assignToggleTeamShareholder($this->requestData);
        return returnData($list);
    }

    /**
     * @title  禁用用户美丽金转赠功能
     * @param UserModel $model
     * @return string
     */
    public function banUserCrowdTransfer(UserModel $model)
    {
        $list = $model->banUserCrowdTransfer($this->requestData);
        return returnData($list);
    }

    /**
     * @title  清除快商签约信息
     * @param KuaiShangPay $service
     * @return string
     */
    public function removeKuaiShangThirdId(KuaiShangPay $service,ZhongShuKePay $model)
    {

        $withdrawPayType = config('system.withdrawPayType');
        //1为微信支付 2为汇聚支付 3为杉德支付 4为快商 5为中数科 88为线下打款 默认4
        switch ($withdrawPayType){
            case WithdrawConstant::WITHDRAW_PAY_TYPE_WX:
            case WithdrawConstant::WITHDRAW_PAY_TYPE_JOIN:
            case WithdrawConstant::WITHDRAW_PAY_TYPE_SHANDE:
            case WithdrawConstant::WITHDRAW_PAY_TYPE_OFFLINE:
            default:
                throw new WithdrawException(['errorCode'=>11007]);
            case WithdrawConstant::WITHDRAW_PAY_TYPE_KUAISHANG:
                $res = $service->removeThirdId($this->requestData);
                break;
            case WithdrawConstant::WITHDRAW_PAY_TYPE_ZSK:
                $res = $model->remove($this->requestData);
                break;

        }
        return returnMsg($res);
    }


    /**
     * @title  修改用户手机号码
     * @param UserModel $model
     * @return string
     */
    public function updateUserPhone(UserModel $model)
    {
        $res = $model->updateUserPhone($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  清除用户同步信息
     * @param WxUserSync $model
     * @return string
     */
    public function clearUserSync(WxUserSync $model)
    {
        $res = $model->clearUserSyncInfo($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  禁用美丽金互转白名单用户列表
     * @param UserModel $model
     * @return string
     * @throws \Exception
     */
    public function banTransferUserList(UserModel $model)
    {
        $data = $this->requestData;
        $data['ban_crowd_transfer'] = 2;
        $data['needAllLevel'] = true;
        $list = $model->list($data);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  禁止购买黑名单用户列表
     * @param UserModel $model
     * @return string
     * @throws \Exception
     */
    public function banBuyUserList(UserModel $model)
    {
        $data = $this->requestData;
        $data['can_buy'] = 2;
        $data['needAllLevel'] = true;
        $list = $model->list($data);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  禁止用户购买功能
     * @param UserModel $model
     * @return string
     */
    public function banUserBuy(UserModel $model)
    {
        $list = $model->banUserBuy($this->requestData);
        return returnData($list);
    }

    /**
     * @title 用户充值业绩数据面板
     * @param Summary $service
     * @return string
     */
    public function userWithdrawDataPanel(Summary $service)
    {
        $info = $service->userWithdrawDataPanel($this->requestData);
        return returnData($info);
    }

    /**
     * @title 用户关联的下级充值明细 30%
     * @param RechargeTopLinkRecord $model
     * @return string
     * @throws \Exception
     */
    public function userLinkRechargeList(RechargeTopLinkRecord $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title 清退用户(删除用户部分相关数据,危险操作!!!)
     * @param UserModel $model
     * @return mixed
     */
    public function clearUser(UserModel  $model)
    {
        $res = $model->clearUser($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title 用户余额汇总详情
     * @param UserModel $model
     * @return string
     */
    public function userBalanceSummaryDetail(UserModel  $model)
    {
        $info = $model->userBalanceSummaryDetail($this->requestData);
        return returnData($info);
    }

    /**
     * @title 美丽卡(众筹提前购卡)明细
     * @param AdvanceCardDetail $model
     * @return mixed
     */
    public function advanceCardList(AdvanceCardDetail $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title 美丽卡(众筹提前购卡)充值明细
     * @param AdvanceCardDetail $model
     * @return mixed
     */
    public function advanceCardRechargeList(AdvanceCardDetail $model)
    {
        $data = $this->requestData;
        $data['change_type'] = 6;
        $list = $model->list($data);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title 充值美丽卡(众筹提前购卡)
     * @param AdvanceCardDetail $model
     * @return string
     */
    public function rechargeAdvanceCard(AdvanceCardDetail $model)
    {
        $res = $model->newBatch($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title 用户冻结美丽金明细
     * @param CrowdfundingFuseRecord $model
     * @throws \Exception
     * @return mixed
     */
    public function userFuseCrowdBalanceList(CrowdfundingFuseRecord $model)
    {
        $data = $this->requestData;
        $list = $model->list($data);
        return returnData($list['list'], $list['pageTotal']);
    }
}