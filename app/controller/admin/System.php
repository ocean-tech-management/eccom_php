<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\controller\admin;


use app\BaseController;
use app\lib\exceptions\ServiceException;
use app\lib\models\SystemConfig;
use think\facade\Cache;

class System extends BaseController
{
    protected $middleware = [
        'checkAdminToken',
        'checkRule',
        'OperationLog',
    ];

    /**
     * @title  系统配置信息
     * @param SystemConfig $model
     * @return string
     */
    public function info(SystemConfig $model)
    {
        $info = $model->info($this->requestData);
        return returnData($info);
    }

    /**
     * @title  修改系统配置信息
     * @param SystemConfig $model
     * @return string
     */
    public function update(SystemConfig $model)
    {
        $res = $model->DBEdit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  手动强制清除缓存
     * @return bool
     */
    public function clearCache()
    {
        $data = $this->requestData;
        $type = $data['type'] ?? 1;
        //type 1为清除首页活动列表以及商品列表缓存 2为首页顶部的图片等素材
        switch ($type) {
            case 1:
                cache('ApiHomeAllList', null);
                cache('HomeApiOtherActivityList', null);
                cache('ApiHomePtList', null);
                cache('ApiHomePpylList', null);
                cache('ApiHomeCategoryList', null);
                Cache::tag(['apiHomeGoodsList', 'HomeApiActivityList','HomeApiCrowdFundingActivityList','HomeApiCrowdFundingPeriodList','ApiCrowdFundingPeriodGoodsList'])->clear();
                break;
            case 2:
                cache('mallHomeApiCategory', null);
                cache('ApiHomeBanner', null);
                cache('ApiHomeScreenBanner', null);
                cache('apiHomeMemberBg', null);
                cache('apiHomeBrandSpaceBg', null);
                cache('ApiHomeIconList', null);
                Cache::tag(['ApiHomeScreenBanner'])->clear();
                cache('apiCrowdFundSystemConfig',null);
                break;
            default:
                throw new ServiceException(['msg' => '未知的类型']);
        }
        return returnMsg(true);
    }

    /**
     * @title  获取客户端accessKey
     * @return string
     */
    public function accessKeyList()
    {
        $ackList = config('system.clientConfig');
        $list = [];
        if (!empty($ackList ?? [])) {
            foreach ($ackList as $key => $value) {
                $list[$key]['access_key'] = $key;
                $list[$key]['name'] = $value['name'] ?? "未知应用";
                $list[$key]['app_id'] = $value['app_id'] ?? null;
            }
            $list = array_values($list);
        }
        return returnData($list);
    }

    /**
     * @title  获取不同应用的海报背景图等
     * @throws \Exception
     * @return string
     */
    public function clientBackground()
    {
        $accessKeyList = $this->accessKeyList()->getData()['data'] ?? [];
        if (empty($accessKeyList)) {
            return returnData([]);
        }
        $bgSystemInfos = SystemConfig::where(['status' => 1])->value('client_background');

        foreach ($accessKeyList as $key => $value) {
            $accessKeyList[$key]['system_info'] = [];
        }
        if (empty($bgSystemInfos)) {
            return returnData($accessKeyList);
        }
        $bgSystemList = json_decode($bgSystemInfos, true);
        foreach ($bgSystemList as $key => $value) {
            $bgSystemInfo[$value['access_key']] = $value;
        }

        foreach ($accessKeyList as $key => $value) {
            if (!empty($bgSystemInfo[$value['access_key']] ?? [])) {
                $accessKeyList[$key]['system_info'] = $bgSystemInfo[$value['access_key']];
            }
        }

        return returnData($accessKeyList);
    }
}