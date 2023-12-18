<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 众筹模式系统配置模块Model]
// +----------------------------------------------------------------------



namespace app\lib\models;


use app\BaseModel;

class CrowdfundingSystemConfig extends BaseModel
{
    /**
     * @title  系统配置详情
     * @param array $sear
     * @return array
     */
    public function info(array $sear): array
    {
        switch ($sear['searField'] ?? null) {
            case 1:
                $field = 'rule,banner';
                break;
            case 2:
                $field = 'offline_receipt_image,offline_remark';
                break;
            default:
                $field = '*';
        }

        $cacheKey = false;
        $cacheExpire = 0;

        $info = $this->where([$this->getPk() => 1])->field($field)->when($cacheKey, function ($query) use ($cacheKey, $cacheExpire) {
            $query->cache($cacheKey, $cacheExpire);
        })->findOrEmpty()->toArray();

        return $info ?? [];
    }

    /**
     * @title  修改系统配置
     * @param array $data
     * @return CrowdfundingSystemConfig
     */
    public function DBEdit(array $data)
    {
        cache('apiCrowdFundSystemConfig', null);
        return $this->baseUpdate([$this->getPk() => 1], $data);
    }

    /**
     * @title  C端系统配置详情
     * @param array $sear
     * @return array
     */
    public function cInfo(array $sear): array
    {
        switch ($sear['type'] ?? null) {
            case 1:
                $field = 'rule,banner';
                break;
            default:
                $field = 'rule,banner';
        }

        $cacheKey = 'apiCrowdFundSystemConfig';
        $cacheExpire = 1800;
        if (!empty(cache($cacheKey))) {
            return cache($cacheKey);
        }

        $info = $this->where([$this->getPk() => 1])->field($field)->findOrEmpty()->toArray();
        if (!empty($info)) {
            cache($cacheKey, $info, $cacheExpire);
        }
        return $info ?? [];
    }
}