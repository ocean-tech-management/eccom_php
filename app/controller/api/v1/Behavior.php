<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------



namespace app\controller\api\v1;


use app\BaseController;
use app\lib\models\Behavior as BehaviorModel;
use app\lib\services\Behavior as BehaviorService;

class Behavior extends BaseController
{
    protected $middleware = [
        'checkApiToken' => ['except' => ['record']],
    ];

    /**
     * @title  用户行为轨迹
     * @param BehaviorModel $model
     * @return string
     * @throws \Exception
     */
    public function list(BehaviorModel $model)
    {
        $data = $this->requestData;
        $list = $model->list($data);
        return returnData($list);
    }

    /**
     * @title  用户关联的下级的行为轨迹
     * @param BehaviorModel $model
     * @return string
     * @throws \Exception
     */
    public function linkList(BehaviorModel $model)
    {
        $list = $model->linkList($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  记录用户行为轨迹
     * @param BehaviorService $service
     * @return string
     * @throws \Exception
     */
    public function record(BehaviorService $service)
    {
        set_time_limit(0);
        ini_set('memory_limit', '3072M');
        ini_set('max_execution_time', '0');
        //不记录行为轨迹
        return returnMsg(true);
        $res = $service->record($this->requestData);
        return returnMsg($res);
    }
}