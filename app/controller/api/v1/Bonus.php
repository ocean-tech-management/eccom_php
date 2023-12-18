<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 广宣奖模块Controller]
// +----------------------------------------------------------------------



namespace app\controller\api\v1;


use app\BaseController;
use app\lib\exceptions\OrderException;
use app\lib\exceptions\ServiceException;
use app\lib\models\BalanceDetail;
use app\lib\models\CrowdfundingBalanceDetail;
use app\lib\models\Divide;
use app\lib\services\UserSummary;

class Bonus extends BaseController
{

    protected $middleware = [
        'checkUser',
        'checkApiToken'
    ];
    /**
     * @title  个人余额报表(H5 包含广宣奖等)
     * @param UserSummary $service
     * @return mixed
     * @throws \Exception
     */
    public function balanceAll(UserSummary $service)
    {
        $info = $service->h5UserAllInCome();
        return returnData($info);
    }

    /**
     * @title  用户奖励列表
     * @param Divide $model
     * @return string
     * @throws \Exception
     */
    public function divideList(Divide $model)
    {
        $data = $this->requestData;
        $data['type'] = $data['divideType'] ?? 2;
        $list = $model->userDivideList($data);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  美丽金转赠
     * @param CrowdfundingBalanceDetail $model
     * @return string
     * @throws \Exception
     */
    public function crowdBalanceTransfer(CrowdfundingBalanceDetail $model)
    {
        throw new ServiceException(['msg'=>'系统升级中, 请耐心等待, 感谢您的理解与支持']);
        $data = $this->requestData;
        $msg = $model->transfer($data);
        return returnMsg($msg);
    }

    /**
     * @title  余额转换
     * @param BalanceDetail $model
     * @return string
     * @throws \Exception
     */
    public function balanceTransfer(BalanceDetail $model)
    {
        throw new ServiceException(['msg'=>'系统升级中, 请耐心等待, 感谢您的理解与支持']);
        $data = $this->requestData;
        $msg = $model->transfer($data);
        return returnMsg($msg);
    }

}