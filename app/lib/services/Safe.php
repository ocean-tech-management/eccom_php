<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 安全防护模块Service]
// +----------------------------------------------------------------------


namespace app\lib\services;

use app\lib\exceptions\ServiceException;
use think\facade\Cache;

class Safe
{

    protected $throwError = true;                   //是否抛出异常
    protected $notThrowErrorReturnData = false;
    private $limitFrequency = 15;                       //限制时间内的请求次数
    private $limitTime = 5;                             //限制时间(s)
    private $redisLimitKey = 'requestSafeLimit';
    private $redisLimitTimeKey = 'apiRequestSafeNumber';
    private $whiteIpList = [];

    public function __construct()
    {
        //白名单可以设置在env文件设置
        $this->whiteIpList = !empty(env('LIMIT.whiteIpList') ?? null) ? array_unique(array_filter(explode(',', env('LIMIT.whiteIpList')))) : [];
    }

    /**
     * @title  限流
     * @param array $data
     * @return mixed
     */
    public function limitFrequency(array $data)
    {
        $appId = trim($data['ip'] ?? null);
        $env = env('ENV');
        if (!empty($env) && $env == 'local') {
            return true;
        }
        if (empty($appId)) {
            return false;
        }
        //redis 计数器算法限流,时间问题暂未完成令牌桶算法
        $redisLimitKey = $this->redisLimitKey . $appId;
        $redisLimitTimeKey = $this->redisLimitTimeKey . $appId . $this->redisLimitKey;
        if (!in_array($appId, $this->whiteIpList)) {
            $lastCacheTime = Cache::get($redisLimitKey);
            //若在限定时间没有对应的请求缓存记录,直接重置一个新记录
            if (empty($lastCacheTime) || (!empty($lastCacheTime) && $lastCacheTime - time() >= $this->limitTime)) {
                Cache::set($redisLimitKey, time(), $this->limitTime);
                Cache::set($redisLimitTimeKey, 0, $this->limitTime);
                Cache::inc($redisLimitTimeKey, 1);
                return true;
            }
            //若在限定时间有对应请求缓存记录,则获取对应的请求次数,判断是否超过限制
            $cacheNumber = Cache::get($redisLimitTimeKey);
            if ($cacheNumber >= $this->limitFrequency) {
                if (empty($data['notThrowError'])) {
                    throw new ServiceException(['errorCode' => 400103]);
                } else {
                    return false;
                }
            } else {
                Cache::inc($redisLimitTimeKey, 1);
            }
        }
        return true;
    }
}