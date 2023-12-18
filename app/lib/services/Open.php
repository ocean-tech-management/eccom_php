<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 开放平台Service]
// +----------------------------------------------------------------------



namespace app\lib\services;


use app\lib\exceptions\OpenException;
use app\lib\exceptions\ServiceException;
use app\lib\models\OpenAccount;
use app\lib\models\OpenRequestLog;
use think\facade\Cache;

class Open
{
    protected $throwError = true;                   //是否抛出异常
    protected $notThrowErrorReturnData = false;
    private $limitFrequency = 100;                       //限制时间内的请求次数
    private $limitTime = 60;                             //限制时间(s)
    private $redisLimitKey = 'openApiRequestLimit';
    private $redisLimitTimeKey = 'openApiRequestNumber';

    /**
     * @title  是否抛出异常,不抛出则返回指定的错误信息
     * @param bool $bool
     * @param bool|string $errorMsg
     * @return void
     */
    public function throwError(bool $bool = true, $errorMsg = false)
    {
        if (empty($bool)) {
            $this->throwError = false;
            $this->notThrowErrorReturnData = $errorMsg;
        }
    }

    /**
     * @title  获取参数
     * @param array $data
     * @return mixed
     */
    public function getParam(array $data)
    {
        if (empty($data['schema']) || empty($data['param'])) {
            (new Log())->record($data, 'error');
            throw new OpenException(['errorCode' => 2600103]);
        }

        switch ($data['schema'] ?? null) {
            case 'json':
                if (!is_string($data['param']) || is_null(json_decode($data['param']))) {
                    throw new OpenException(['errorCode' => 2600103]);
                }
                $jsonData = json_decode($data['param'], true);

                if (($jsonData && is_object($jsonData)) || (is_array($jsonData) && !empty($jsonData))) {
                    $param = $jsonData;
                }

                break;
            default:
                throw new OpenException(['errorCode' => 2600103]);
        }

        if (empty($param)) {
            throw new OpenException(['errorCode' => 2600103]);
        }

        return $param;
    }

    /**
     * @title  检查参数
     * @param array $data
     * @return bool
     */
    public function checkParam(array $data)
    {
        if (empty($data) || empty($data['appId']) || empty($data['signature'])) {
            (new Log())->record($data, 'error');
            throw new OpenException(['errorCode' => 2600103]);
        }

        $checkSign = $data;
        unset($checkSign['signature']);
        unset($checkSign['version']);

        $secret = $this->checkUser(['appId' => $data['appId'] ?? null]);

        ksort($checkSign);
        $str = '';
        foreach ($checkSign as $key => $value) {
            $str .= $value;
        }
        $str .= $secret;
        $signature = md5($str);
        if (strtoupper($signature) != strtoupper($data['signature'])) {
            if (!empty($this->throwError)) {
                throw new OpenException(['errorCode' => 2600106]);
            } else {
                return $this->notThrowErrorReturnData;
            }
        }
        return true;
    }

    /**
     * @title  查看开放平台用户是否合法
     * @param array $data
     * @return bool
     */
    public function checkUser(array $data)
    {
        if (empty($data['appId'])) {
            if (!empty($this->throwError)) {
                throw new OpenException(['errorCode' => 2600102]);
            } else {
                return $this->notThrowErrorReturnData;
            }
        }

        //判断开发者帐号是否有效
        $map[] = ['appId', '=', trim($data['appId'])];
        $map[] = ['status', '=', 1];
        $developerInfo = OpenAccount::where($map)->findOrEmpty()->toArray();
        if (empty($developerInfo)) {
            if (!empty($this->throwError)) {
                throw new OpenException(['errorCode' => 2600101]);
            } else {
                return $this->notThrowErrorReturnData;
            }

        }
        return $developerInfo['secretKey'];
    }

    /**
     * @title  限流
     * @param array $data
     * @return mixed
     */
    public function limitFrequency(array $data)
    {
        $appId = trim($data['appId']);
        //数据库限流
//        $map[] = ['create_time', '>=', (time() - $this->limitTime)];
//        $map[] = ['create_time', '<', time()];
//        $map[] = ['appId', '=', $appId];
//        $requestNumber = OpenRequestLog::where($map)->count();
//        if ($requestNumber >= $this->limitFrequency) {
//            throw new OpenException(['errorCode' => 2600104]);
//        }

        //redis 计数器算法限流,时间问题暂未完成令牌桶算法
        $redisLimitKey = $this->redisLimitKey . $appId;
        $redisLimitTimeKey = $this->redisLimitTimeKey . $appId . $this->redisLimitKey;

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
        // dd($cacheNumber);
        if ($cacheNumber >= $this->limitFrequency) {
            if (empty($data['notThrowError'])) {
                throw new OpenException(['errorCode' => 2600104]);
            } else {
                return false;
            }
        } else {
            Cache::inc($redisLimitTimeKey, 1);
        }

        return true;
    }

    /**
     * @title  检验参数安全, 防止注入
     * @param array $data
     * @return mixed
     */
    public function checkParamSave(array $data)
    {
        foreach ($data as $key => $value) {
            if (!empty($key) && ($key == 'user_password' || $key == 'user_buy_password' || $key == 'user_for_uid' || $key == 'user_for_name')) {
                continue;
            }
            $keyword = null;
            $banKeyword = null;
            $regex = "/\/|\～|\，|\。|\！|\？|\“|\”|\【|\】|\『|\』|\：|\；|\《|\》|\’|\‘|\ |\!|\@|\#|\\$|\%|\^|\&|\*|\(|\)|\+|\{|\}|\:|\<|\>|\?|\[|\]|\,|\/|\;|\'|\`|\=|\\\|\|\"|update|insert|delete|drop|union|into|load_file|outfile|SELECT|UNION|DROP|DELETE|SLEEP|FROM|from|dump|DUMP|UPDATE|INSERT|ext|shell|script|style|html|body|title|link|meta|object|OR|or|LIKE|like|\n|\m|\e|\i|\r|\t|/";
            $keyword = preg_replace($regex, "", $value);
            if (empty($keyword)) {
                if (!empty($this->throwError)) {
                    throw new OpenException(['errorCode' => 2600107]);
                } else {
                    return $this->notThrowErrorReturnData;
                }
            }

            $banKeyword = str_replace(" ", '', trim($keyword));
            if (empty($banKeyword)) {
                if (!empty($this->throwError)) {
                    throw new OpenException(['errorCode' => 2600108]);
                } else {
                    return $this->notThrowErrorReturnData;
                }
            }
            $banCondition = ['delete', 'select', 'limit', 'drop', 'insert', 'like', 'union', 'sleep', 'dump', 'update', 'drop'];
            foreach ($banCondition as $bKey => $bValue) {
                if (!empty(stristr(strtolower($banKeyword), $bValue))) {
                    if (!empty($this->throwError)) {
                        throw new OpenException(['errorCode' => 2600109]);
                    } else {
                        return $this->notThrowErrorReturnData;
                    }
                }
            }
        }
        return $data;
    }


    /**
     * @title  记录日志
     * @param array $data 数据信息
     * @param string $level 日志信息
     * @param string $channel 日志通道
     * @return mixed
     */
    public function log(array $data, string $level = 'error', string $channel = 'callback')
    {
        return (new Log())->setChannel($channel)->record($data, $level);
    }


}