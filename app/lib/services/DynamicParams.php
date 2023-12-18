<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 动态参数模块Service]
// +----------------------------------------------------------------------



namespace app\lib\services;

class DynamicParams
{
    private $isPermanent = false; //是否为永久动态参数

    /**
     * @title  获取缓存键名
     * @return string
     */
    public function getCacheKey()
    {
        return (new CodeBuilder())->buildDynamicParamsCacheKey(7);
    }

    /**
     * @title  设置动态参数类型
     * @param bool $isPermanent
     * @return $this
     */
    public function setType(bool $isPermanent = false)
    {
        $this->isPermanent = $isPermanent;
        return $this;
    }

    /**
     * @title  保存动态参数
     * @param array $data
     * @return string
     */
    public function create(array $data)
    {
        $cacheTag = $data['aId'] ?? null;
        $cacheKey = $this->getCacheKey();
        $cacheExpire = 24 * 3600;
        if ($this->isPermanent) {
            $newKey['key'] = $cacheKey;
            $dynamicParams = trim($data['dynamic_params']);
            $newKey['content'] = $dynamicParams;
            if (!empty($data['method']) && $data['method'] == 2) {
                $newKey['content'] = json_encode($dynamicParams, 256);
            }
        } else {
            //如有业务需要此处可以设置多个缓存标识,以便以后统一管理
//        cache($cacheKey, $data, $cacheExpire,[$cacheTag]);
            cache($cacheKey, $data, $cacheExpire, $cacheTag);
        }

        return $cacheKey;
    }

    /**
     * @title  获取动态参数
     * @param string $key
     * @return mixed
     */
    public function verify(string $key)
    {
        $info = [];
        $info = cache($key);
//        //如果临时缓存没有看看永久参数有没有
//        if(empty($info)){
//            $info = DynamicParamsModel::cache($key,3600)->where(['key' => $key, 'status' => 1])->value('content');
//        }

        return $info;
    }

}