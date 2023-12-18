<?php
// 应用公共文件
use app\lib\exceptions\ServiceException;
use app\lib\models\SystemConfig;
use think\facade\Cache;
use think\facade\Request;
use app\lib\models\WxUser;
use app\lib\models\AppUser;

/**
 * @Name   返回数据
 * @param array $data 需要返回的数据
 * @param int $pageTotal 页面总数
 * @param int $code 状态码
 * @param string $message 返回的提示信息
 * @param int $total 返回的数据总条数
 * @return string
 */
function returnData($data = [], int $pageTotal = null, int $code = 0, string $message = "成功", int $total = 0)
{
    $dataEmpty = false;
    if (empty($data)) {
        $message = '暂无数据';
        $dataEmpty = true;
        if (isset($pageTotal) && !empty($pageTotal)) {
            if (isset($total)) {
                $data['total'] = 0;
            }

            $data['pageTotal'] = $pageTotal;
            $data['list'] = [];
        }
    }
    $aReturn = ['error_code' => $code, 'msg' => $message, 'data' => $data];
    if (empty($dataEmpty) && !empty($pageTotal)) {
        unset($aReturn['data']);
        if (isset($total)) {
            $aReturn['data']['total'] = $total ? $total : 0;
        }
        $aReturn['data']['pageTotal'] = $pageTotal;
        $aReturn['data']['list'] = $data ? $data : [];
    }
    return json($aReturn);
}

/**
 * @Name   返回信息
 * @param mixed $status
 * @return string
 */
function returnMsg($status = true)
{
    $status = judge($status);
    if ($status) {
        $msg = ['error_code' => 0, 'msg' => '成功'];
    } else {
        $msg = ['error_code' => 30010, 'msg' => '失败'];
    }
    return json($msg);
}

/**
 * @title  判断是否为空
 */
function judge($msg)
{
    if ($msg !== false && !empty($msg)) {
        return true;
    } else {
        return false;
    }
}

/**
 * @Name   获取本周所有日期
 * @param string $time 搜索日期
 * @param string $format 返回的日期格式
 * @return array 日期数组
 */
function getWeek(string $time = '', string $format = 'Y-m-d'): array
{
    $time = !empty($time) ? strtotime($time) : time();
    //获取当前周几
    $week = date('w', $time);
    $date = [];
    for ($i = 1; $i <= 7; $i++) {
        $date[$i] = date($format, strtotime('+' . $i - $week . ' days', $time));
    }
    return $date;
}

/**
 * @Name   获取本月所有日期
 * @param string $searTime 搜索日期
 * @param string $format 返回的日期格式
 * @return array 日期数组
 */
function getMonth(string $searTime = '', string $format = 'Y-m-d'): array
{
    $time = !empty($searTime) ? strtotime($searTime) : time();
    //获取当前周几
    $week = date('d', $time);
    $date = [];
    //获取该月所有日期
    for ($i = 1; $i <= date('t', $time); $i++) {
        $date[$i] = date($format, strtotime('+' . $i - $week . ' days', $time));
    }
    return $date;
}

/**
 * @title  根据开始和结束日期内所有的日期
 * @param string $startDate
 * @param string $endDate
 * @param string $format
 * @return array
 */
function getDateFromRange(string $startDate, string $endDate, string $format = 'Y-m-d')
{

    $stimeStamp = strtotime($startDate);
    $etimeStamp = strtotime($endDate);

    // 计算日期段内有多少天
    $days = ($etimeStamp - $stimeStamp) / 86400 + 1;

    // 保存每天日期
    $date = array();

    for ($i = 0; $i < $days; $i++) {
        $date[] = date($format, $stimeStamp + (86400 * $i));
    }

    return $date;
}

/**
 * @Name   生成唯一识别码
 * @param int $length 长度
 * @return string      用户唯一识别码
 */
function getUid($length = 10)
{
    $chars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
    $str = "";
    for ($i = 0; $i < $length; $i++) {
        $str .= substr($chars, mt_rand(0, strlen($chars) - 1), 1);
    }
    return $str;
}

/**
 * @Name   生成随机字符串
 * @param int $length 长度
 * @return string      随机字符串
 */
function getRandomString($length = 8)
{
    $chars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
    $str = "";
    for ($i = 0; $i < $length; $i++) {
        $str .= substr($chars, mt_rand(0, strlen($chars) - 1), 1);
    }
    return $str;
}

/**
 * @title  生成验证码
 * @param int $length 验证码长度
 * @return string
 * @author  Coder
 * @date   2019年11月13日 11:35
 */
function getCode(int $length = 4)
{
    $str = '';
    for ($i = 0; $i < $length; $i++) {
        $str .= rand(0, 9);
    }
    return $str;
}

/**
 * 生成不含0的验证码
 * @param int $length
 * @return string
 */
function getCodeOutZero(int $length = 4)
{
    $str = '';
    for ($i = 0; $i < $length; $i++) {
        $str .= rand(1, 9);
    }
    return $str;
}
/**
 * @title  价格格式化,保留两位小数点
 * @param string $price 价格
 * @return bool|string
 */
function priceFormat(string $price)
{
    return substr(sprintf("%.3f", $price), 0, -1);
}

/**
 * @title  时间戳格式化
 * @param string $date
 * @return false|string|null
 */
function timeToDateFormat(string $date)
{
    return !empty($date) ? date('Y-m-d H:i:s', $date) : null;
}

/**
 * @title  筛选相近值
 * @param  $Number
 * @param  $NumberRangeArray
 * @return mixed
 */
function NextNumberArray($Number, $NumberRangeArray)
{
    $w = 0;
    $c = -1;
    $abstand = 0;
    $l = count($NumberRangeArray);
    for ($pos = 0; $pos < $l; $pos++) {
        $n = $NumberRangeArray[$pos];
        $abstand = ($n < $Number) ? $Number - $n : $n - $Number;
        if ($c == -1) {
            $c = $abstand;
            continue;
        } else if ($abstand < $c) {
            $c = $abstand;
            $w = $pos;
        }
    }
    return $NumberRangeArray[$w];
}

/**
 * @title  手机中间四位加密
 * @param string $phone 手机号码
 * @param string $symbol 隐藏符号
 * @param int $startNum 开始位置
 * @param int $length 持续长度
 * @return string|string[]|null
 */
function encryptPhone(string $phone, string $symbol = '*', int $startNum = 3, int $length = 4)
{
    $aSymbol = '';
    for ($i = 1; $i <= $length; $i++) {
        $aSymbol .= $symbol;
    }
    return !empty($phone) ? substr_replace($phone, $aSymbol, $startNum, $length) : null;
}

/**
 * @title  银行卡或身份证加密
 * @param string $card 卡号
 * @param string $symbol 隐藏符号
 * @param int $startNum 开始位置
 * @param int $lastLength 剩余明文长度
 * @return string|string[]|null
 */
function encryptBank(string $card, string $symbol = '**** ', int $startNum = 0, int $lastLength = 4)
{
    return !empty($card) ? substr_replace($card, $symbol, $startNum, strlen($card) - $lastLength) : null;
}

/**
 * @title  银行卡或身份证加密
 * @param string $card 卡号
 * @param string $symbol 隐藏符号
 * @param int $startNum 开始位置
 * @param int $lastLength 剩余明文长度
 * @return string|string[]|null
 */
function encryptBankNew(string $card, int $startNum = 4, int $lastLength = 4, string $symbol = '****')
{
    return !empty($card) ? substr_replace($card, $symbol, $startNum, (strlen($card) - $startNum - $lastLength)) : null;
}

/**
 * @title  md5加密
 * @param string $string 加密字符串
 * @param int $length 长度,默认32,支持16和32
 * @return bool|string
 */
function md5Encrypt(string $string, int $length = 32)
{
    $encrypt = md5($string);
    if ($length == 16) {
        $encrypt = substr($encrypt, 8, 16);
    }
    return $encrypt;
}

/**
 * @title  base64-Url编码
 * @param $string
 * @return string|string[]
 */
function urlSafeBase64encode($string)
{
    $data = base64_encode($string);
    $data = str_replace(array('+', '/', '='), array('-', '_', ''), $data);
    return $data;
}

/**
 * @title  base64-Url解码
 * @param $string
 * @return false|string
 */
function urlSafeBase64decode($string)
{
    $data = str_replace(array('-', '_'), array('+', '/'), $string);
    $mod4 = strlen($data) % 4;
    if ($mod4) {
        $data .= substr('====', $mod4);
    }
    return base64_decode($data);
}


function curl_get($url)
{
    $info = curl_init();
    curl_setopt($info, CURLOPT_URL, $url);
    curl_setopt($info, CURLOPT_HEADER, 0);
    curl_setopt($info, CURLOPT_NOBODY, 0);
    curl_setopt($info, CURLOPT_SSL_VERIFYPEER, FALSE);
    curl_setopt($info, CURLOPT_SSL_VERIFYHOST, FALSE);
    curl_setopt($info, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($info, CURLOPT_TIMEOUT, 30); //超时时间30S
    $output = curl_exec($info);
    $err = curl_error($info);
    curl_close($info);
    return $output;
}

/**
 * @title  检查参数是否包含非法字符
 * @param string $keyword
 */
function checkParam($keyword)
{
    $regex = "/\/|\～|\，|\。|\！|\？|\“|\”|\【|\】|\『|\』|\：|\；|\《|\》|\’|\‘|\ |\!|\@|\#|\\$|\%|\^|\&|\*|\(|\)|\+|\{|\}|\:|\<|\>|\?|\[|\]|\,|\/|\;|\'|\`|\=|\\\|\|\"|update|insert|delete|drop|union|into|load_file|outfile|select|SELECT|UNION|DROP|DELETE|SLEEP|FROM|from|dump|DUMP|UPDATE|INSERT|script|style|html|body|title|link|meta|object|OR|or|LIKE|like|md5|rm|rf|cd|chmod|RM|RF|CD|CHMOD|\n|\m|\e|\i|\r|\t|/";
    $keyword = preg_replace($regex, "", $keyword);
    if (empty($keyword)) {
        throw new ServiceException(['msg' => '关键词包含非法参数,请重新输入~']);
    }

    $banKeyword = str_replace(" ", '', trim($keyword));
    if (empty($banKeyword)) {
        throw new ServiceException(['msg' => '请填写不为空的合规关键词']);
    }
    $banCondition = ['delete', 'select', 'limit', 'drop', 'insert', 'like', 'union', 'sleep', 'dump', 'update', 'md5', 'rm -rf', 'chmod', 'exit', 'die', 'print', 'printf'];
    foreach ($banCondition as $key => $value) {
        if (!empty(stristr(strtolower($banKeyword), $value))) {
            throw new ServiceException(['msg' => '非法参数注入, 您的IP已被记录, 请立即停止您的行为!']);
        }
    }
}

function curl_post($url, $data)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url); //定义请求地址
    curl_setopt($ch, CURLOPT_POST, true); //定义提交类型 1：POST ；0：GET
    curl_setopt($ch, CURLOPT_HEADER, 0); //定义是否显示状态头 1：显示 ； 0：不显示
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
    //curl_setopt($ch, CURLOPT_HTTPHEADER, 1);//定义header
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);//定义是否直接输出返回流
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data); //定义提交的数据
    $res = curl_exec($ch);
    curl_close($ch);
    return $res;
}


/**
 * 私钥加密
 * @param $data
 * @param $private_key
 * @return string
 */
function privateEncrypt($data)
{
    $private_key = config("system.private_key");
    if (is_array($data)) {
        ksort($data);
        $data = json_encode($data);
    }
    $pem = chunk_split(trim($private_key), 64, PHP_EOL);
    $pem = "-----BEGIN RSA PRIVATE KEY-----" . PHP_EOL . $pem . "-----END RSA PRIVATE KEY-----";
    $privateKey = openssl_pkey_get_private($pem);
    $ciphertext = '';
    $data = str_split($data, 117); // 加密的数据长度限制为比密钥长度少11位，如128位的密钥最多加密的数据长度为117
    foreach ($data as $d) {
        openssl_private_encrypt($d, $crypted, $privateKey); // OPENSSL_PKCS1_PADDING
        $ciphertext .= $crypted;
    }
    return base64_encode($ciphertext);
}

/**
 * 私钥解密
 * @param $data
 * @param $private_key
 * @param bool $unserialize
 * @return mixed
 * @throws \Exception
 */
function privateDecrypt($data)
{
    $private_key = config("system.private_key");
    $pem = chunk_split(trim($private_key), 64, "\n");
    $pem = "-----BEGIN RSA PRIVATE KEY-----\n" . $pem . "-----END RSA PRIVATE KEY-----";
    $privateKey = openssl_pkey_get_private($pem);
    $crypto = '';
    foreach (str_split(base64_decode($data), 128) as $chunk) {
        openssl_private_decrypt($chunk, $decryptData, $privateKey);
        $crypto .= $decryptData;
    }
    if ($crypto === false) {
        throw new \Exception('Could not decrypt the data.');
    }
    return json_decode($crypto, true,512, JSON_BIGINT_AS_STRING)?? (string)$crypto;
}


/**
 * 公钥加密
 * @param $data
 * @param $public_key
 * @param bool $serialize 是为了不管你传的是字符串还是数组，都能转成字符串
 * @return string
 * @throws \Exception
 */
function publicEncrypt($data)
{
    $public_key = config("system.public_key");
    $pem = chunk_split(trim($public_key), 64, "\n");
    $pem = "-----BEGIN PUBLIC KEY-----\n" . $pem . "-----END PUBLIC KEY-----";
    $publicKey = openssl_pkey_get_public($pem);
    $ciphertext = '';
    if (is_array($data)) {
        ksort($data);
        $data = json_encode($data);
    }
    // 加密的数据长度限制为比密钥长度少11位，如128位的密钥最多加密的数据长度为117
    $data = str_split($data, 117);
    foreach ($data as $d) {
        openssl_public_encrypt($d, $crypted, $publicKey); // OPENSSL_PKCS1_PADDING
        $ciphertext .= $crypted;
    }
    if ($ciphertext === false) {
        throw new \Exception('Could not encrypt the data.');
    }
    openssl_free_key($publicKey);
    return base64_encode($ciphertext);
}


/**
 * 公钥解密
 * @param $data
 * @param $public_key
 * @return mixed
 * @throws \Exception
 */
function publicDecrypt($data)
{
    $public_key = config("system.public_key");
    $pem = chunk_split(trim($public_key), 64, "\n");
    $pem = "-----BEGIN PUBLIC KEY-----\n" . $pem . "-----END PUBLIC KEY-----";
    $pem = openssl_pkey_get_public($pem);
    $dataArray = str_split(base64_decode($data), 128);
    $decrypted = '';
    foreach ($dataArray as $subData) {
        $subDecrypted = null;
        openssl_public_decrypt($subData, $subDecrypted, $pem);
        $decrypted .= $subDecrypted;
    }

    return json_decode($decrypted, true,512, JSON_BIGINT_AS_STRING)?? (string)$decrypted;
//    return $decrypted;
}

/**
 * @title 获取数据库系统配置
 * @param string $field
 * @return array|mixed|string
 */
function systemConfig(string $field = "")
{

    $cacheKey = false;
    $cacheExpire = 0;
    $field = trim(trim($field), ",");
    $info = (new SystemConfig())->where(['status' => 1])->field($field)
        ->when($cacheKey, function ($query) use (
            $cacheKey,
            $cacheExpire
        ) {
            $query->cache($cacheKey, $cacheExpire);
        })->findOrEmpty()->toArray();
    if (!empty($info)) {
        if (sizeof($info) == 1) {
            $res = $info[$field];
        } else {
            $res = $info;
        }
    } else {
        $res = "";
    }
    return $res;
}

//获取客户端身份access_key
function getAccessKey()
{
    return Request::header("access-key") ?? (env("access.access_key") ?? 'p10011');
}

//获取客户端身份Model
function getAccessModel($key)
{
    //判断access_key类型
    $type = substr($key, 0, 1);
    switch ($type) {
        case 'a':
            $model = (new AppUser());
            break;
        case 'p':
        case 'm':
        case 'd':
        case 'w':
            $model = (new WxUser());
            break;
        default:
            $model = (new WxUser());
            break;
    }
    return $model;
}


/**
 * 根据key转换成excel的列名
 * @param $columnIndex
 * @return string
 */
function stringFromColumnIndex($columnIndex)
{
    static $indexCache = [];
    static $lookupCache = ' ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    if (!isset($indexCache[$columnIndex])) {
        $indexValue = $columnIndex;
        $base26 = '';
        do {
            $characterValue = ($indexValue % 26) ?: 26;
            $indexValue = ($indexValue - $characterValue) / 26;
            $base26 = $lookupCache[$characterValue] . $base26;
        } while ($indexValue > 0);
        $indexCache[$columnIndex] = $base26;
    }

    return $indexCache[$columnIndex];
}

//生成web端openid
function getWebH5Openid($key)
{
    $openid = substr($key . md5(date('Ymd') . substr(implode('', array_map('ord', str_split(substr(uniqid(), 7, 13), 1))), 0, 8)), 0, 28);
    return $openid;
}


/**
 * 限制方法访问频率
 * @param $key
 * @param int $ttl
 * @throws \Psr\SimpleCache\InvalidArgumentException
 */
function functionLimit($key,$ttl=10){

    /**  限制接口调用频率 START */

    if (Cache::store('redis')->get($key)) {
        throw new ServiceException(['errorCode' => 400103]);
    } else {
        Cache::store('redis')->set($key, 1, $ttl);
    }
    /**  限制接口调用频率 END*/
}

/**
 * 限制接口调用频率解除
 * @param $key
 * @throws \Psr\SimpleCache\InvalidArgumentException
 */
function functionLimitClear($key){

    /**  限制接口调用频率解除 START */
    Cache::store('redis')->delete($key);
    /**  限制接口调用频率解除 END*/
}

//通过appid获取应用配置
function getConfigByAppid($app_id = 'wxe23efd6fbd330328')
{
    $config = config('system.clientConfig');
    $keys = array_keys($config);
    $app_ids = array_column($config, 'app_id');
    return $config[$keys[array_search($app_id, $app_ids)]];
}

/**
 * @title 获取UUID
 * @param string|mixed $prefix 前缀
 * @return string
 */
function getUUID($prefix = '')
{
    $chars = md5(uniqid(mt_rand(), true));
    $uuid = substr($chars, 0, 8) . '-';
    $uuid .= substr($chars, 8, 4) . '-';
    $uuid .= substr($chars, 12, 4) . '-';
    $uuid .= substr($chars, 16, 4) . '-';
    $uuid .= substr($chars, 20, 12);
    return $prefix . $uuid;
}
