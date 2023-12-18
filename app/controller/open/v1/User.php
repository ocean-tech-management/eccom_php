<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 开饭平台用户模块Controller]
// +----------------------------------------------------------------------



namespace app\controller\open\v1;


use app\BaseController;
use app\lib\exceptions\OpenException;
use app\lib\models\WxUser;
use app\lib\services\CodeBuilder;
use app\lib\services\Open;

class User extends BaseController
{
    protected $middleware = [
        'checkOpenUser',
        'openRequestLog',
    ];

    /**
     * @title  用户等级
     * @return string
     */
    public function userLevel()
    {
        $appId = (new CodeBuilder())->buildOpenDeveloperAppId();
        $appIdKey = (new CodeBuilder())->buildOpenDeveloperSecretKey($appId);

        $requestData = $this->request->openParam;
        if (empty($requestData['unionId'])) {
            throw new OpenException(['errorCode' => 2600102, 'msg' => '非法参数: unionId']);
        }

        $unionId = trim($requestData['unionId']);
        $userLevel = WxUser::alias('a')
            ->join('sp_user_auth_type c', 'a.tid = c.tid', 'left')
            ->join('sp_user b', 'b.uid = c.user_id', 'left')
            ->where(['a.unionId' => $unionId, 'b.status' => 1])
            ->value('b.vip_level');
        if (empty($userLevel)) {
            $userLevel = 0;
        }

        $returnData['unionId'] = $unionId;
        $returnData['userLevel'] = $userLevel;
        return returnData($returnData);
    }
}