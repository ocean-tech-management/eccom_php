<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 系统基础配置模块Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\models;


use app\BaseModel;

class SystemConfig extends BaseModel
{
    /**
     * @title  系统配置详情
     * @param array $sear
     * @return array
     */
    public function info(array $sear): array
    {
        $from = "db";
        switch ($sear['searField'] ?? null) {
            case 1:
                $field = 'system_name,system_logo,contact_user,consumer_hotline';
                break;
            case 2:
                $field = 'brand_space_background,servicer_background';
                break;
            case 3:
                $field = 'usage_notice,user_agreement,privacy_policy';
                break;
            case 4:
                $field = 'withdrawPayType';
                $from = 'conf';
                break;
            case 5:
                $field = 'agreement_zsk';
                break;
            default:
                $field = '*';
        }

        if($from == 'db') {
            $cacheKey = false;
            $cacheExpire = 0;

            $info = $this->where(['status' => 1])->field($field)->when($cacheKey, function ($query) use ($cacheKey, $cacheExpire) {
                $query->cache($cacheKey, $cacheExpire);
            })->findOrEmpty()->toArray();
        }elseif($from == 'conf'){
            $info[$field] = config("system.".$field);
        }
        return $info ?? [];
    }

    /**
     * @title  修改系统配置
     * @param array $data
     * @return SystemConfig
     */
    public function DBEdit(array $data)
    {
        cache('apiHomeBrandSpaceBg', null);
        cache('apiClientBackgroundCache', null);
        return $this->baseUpdate([$this->getPk() => 1], $data);
    }

    /**
     * @title  C端系统配置详情
     * @param array $sear
     * @return array
     */
    public function cInfo(array $sear): array
    {
        switch ($sear['from'] ?? 'db'){
            case 'conf':
                switch ($sear['type'] ?? null) {
                    case 9:
                    default:
                        $field = 'withdrawPayType';
                        break;
                }
                $field = explode(',',$field);
                foreach ($field as $item){
                    $info[$item] = config('system.'.$item);
                }
                break;
            case 'db':
            default:
                switch ($sear['type'] ?? null) {
                    case 1:
                        $field = 'system_name,system_logo,contact_user,consumer_hotline';
                        break;
                    case 2:
                        $field = 'brand_space_background';
                        break;
                    case 3:
                        $field = 'usage_notice';
                        break;
                    case 4:
                        $field = 'user_agreement';
                        break;
                    case 5:
                        $field = 'privacy_policy';
                        break;
                    case 6:
                        $field = 'agreement_protocol';
                        break;
                    case 7:
                        $field = 'servicer_background';
                        break;
                    case 8:
                        $field = 'agreement_zsk';
                        break;
                        break;
                    default:
                        $field = 'system_name,system_logo,contact_user,consumer_hotline,brand_space_background,usage_notice,user_agreement,privacy_policy';
                }

                $cacheKey = false;
                $cacheExpire = 0;

                $info = $this->where(['status' => 1])->field($field)->when($cacheKey, function ($query) use ($cacheKey, $cacheExpire) {
                    $query->cache($cacheKey, $cacheExpire);
                })->findOrEmpty()->toArray();
                break;
        }
        return $info ?? [];
    }

    /**
     * @title  获取背景图片列表
     * @param array $sear
     * @return array
     */
    public function getBackGround(array $sear): array
    {
        $cacheKey = 'apiClientBackgroundCache';
        $cacheExpire = 3600 * 12;
        if (!empty(cache($cacheKey))) {
            $info = cache($cacheKey);
        } else {
            $field = 'client_background';
            $info = $this->where(['status' => 1])->value($field);
            cache($cacheKey, $info, $cacheExpire);
        }

        $accessKey = getAccessKey();
        $finallyInfo = [];
        if (!empty($info)) {
            $info = json_decode($info, true);
            if (!empty($info)) {
                foreach ($info as $key => $value) {
                    if ($value['access_key'] == $accessKey) {
                        $finallyInfo[] = $value;
                    }
                }
            }

        }
        return $finallyInfo ?? [];
    }
}