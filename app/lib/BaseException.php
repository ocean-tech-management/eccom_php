<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 异常类基类]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib;

use RuntimeException;

class BaseException extends RuntimeException
{

    /**
     * HTTP 状态码 404,200
     * @var int|mixed
     */
    public $code = 400;

    /**
     * 错误具体信息
     * @var string
     */
    public $msg;

    /**
     * 占位符的动态变量值数组
     * @var
     */
    public $vars = [];

    /**
     * 语言
     * @var
     * @remake 不填系统默认zh-cn
     */
    public $lang = '';

    /**
     * 自定义的错误码
     * @var string|int
     */
    public $errorCode;

    /**
     * 默认错误码
     * @var int
     */
    private $defaultErrorCode = 100000;

    /**
     * 默认错误信息
     * @var string
     */
    private $defaultMsg = '参数错误';

    /**
     * 默认记录日志通道
     * @var mixed|string
     */
    public $logChannel = 'file';

    /**
     * 数据
     * @var array|mixed
     */
    public $data = [];

    /**
     * 全局异常码配置文件名称
     * @var string
     */
    private $exceptionCodeConfig = 'exceptionCode';
    private $configExt = '.php';

    /**
     * BaseException constructor.
     * @param array $params
     */
    public function __construct($params = [])
    {
        if (array_key_exists('code', $params)) {
            $this->code = $params['code'];
        }

        if (array_key_exists('data', $params)) {
            $this->data = $params['data'];
        }

        if (array_key_exists('logChannel', $params)) {
            $this->logChannel = $params['logChannel'];
        }

        if (array_key_exists('vars', $params)) {
            $this->vars = $params['vars'];
        }

        if (array_key_exists('lang', $params)) {
            $this->lang = $params['lang'];
        }

        //读取全局异常码配置
        $this->getExceptionCode($params);
    }

    /**
     * @title  获取异常码
     * @param array $params
     * @return void
     */
    public function getExceptionCode(array $params): void
    {
        $user = $this->getExceptionUserCode($params);
        if ($user['status']) {
            $errorCode = $user['errorCode'];
            $msg = $user['msg'];
        } else {
            $config = $this->getExceptionConfigCode($params);
            $errorCode = $config['errorCode'];
            $msg = $config['msg'];
        }
        $this->errorCode = $errorCode;
        $this->msg = $this->msgLanguageSwitch($msg, $this->vars, $this->lang);
    }

    /**
     * @title  获取用户自定义异常码
     * @param array $params
     * @return array
     */
    public function getExceptionUserCode(array $params): array
    {
        $userExist = false;
        $errorCode = $params['errorCode'] ?? ($this->errorCode ?? null);
        $msg = $params['msg'] ?? ($errorCode == $this->errorCode ? $this->msg : null);
        if (!empty($msg)) $userExist = true;
        return ['status' => $userExist, 'errorCode' => $errorCode, 'msg' => $msg];
    }

    /**
     * @title  获取配置文件异常码
     * @param array $params
     * @return array
     */
    public function getExceptionConfigCode(array $params): array
    {
        $Code = $params['errorCode'] ?? ($this->errorCode ?? null);
        if (file_exists(app()->getConfigPath() . $this->exceptionCodeConfig . $this->configExt)) {
            $thisClass = get_class($this);//获取子类名称
            $thisException = trim(strrchr($thisClass, '\\'), '\\');
            $configCode = config($this->exceptionCodeConfig . '.' . $thisException);
            $errorCode = empty($Code) ? (key($configCode) ?? null) : $Code;
            $msg = empty($Code) ? (current($configCode) ?? null) : ($configCode[$Code] ?? null);
            if (empty($msg)) {
                $miss = explode(":", config($this->exceptionCodeConfig . '.' . 'miss'));
                if (!empty($miss)) {
                    $errorCode = $miss[0];
                    $msg = $miss[1];
                }
            }
        }
        return ['errorCode' => $errorCode ?? $this->defaultErrorCode, 'msg' => $msg ?? $this->defaultMsg];
    }

    /**
     * @title 自动切换报错文案语言包
     * @param mixed $msg 报错文案
     * @param array $vars 占位符的动态变量值数组
     * @param string $lang 语言
     * @return mixed|string
     * @remark 返回文案自动多语言包处理, 支持占位符替换, 占位符替换值为数组; 语言不填系统默认zh-cn
     */
    public function msgLanguageSwitch($msg, array $vars = [], string $lang = '')
    {
        //判断语言配置文件中关于异常报错语言包是否开启, 如果为true则标识需要语言转化
        $langExtendList = Config('lang.exception_lang');
        if (!empty($vars) && is_array($vars)) {
            $vars = array_values($vars);
        }
        if (empty($langExtendList)) {
            //原样返回也要注意变量填充
            if (!empty($vars) && is_array($vars) && key($vars) === 0) {
                /**
                 * Notes:
                 * 为了检测的方便，数字索引的判断仅仅是参数数组的第一个元素的key为数字0
                 * 数字索引采用的是系统的 sprintf 函数替换，用法请参考 sprintf 函数
                 */
                array_unshift($vars, $msg);
                $msg = call_user_func_array('sprintf', $vars);
            }
            return $msg;
        }
        //占位符必须为键名从0开始的一维数组, 为了避免无所谓的报错这里直接返回原文
        if (!empty($vars) && !is_array($vars)) {
            return $msg;
        }
        $rawMsg = $msg;
        //防止语言包方法出错导致异常无法正常抛出, 如果语言包处理有误则默认原文案原样显示
        try {
            $returnMsg = lang($msg, $vars, $lang);
        } catch (\app\lib\BaseException $baseE) {
            $msg = $baseE->msg;
            $code = $baseE->errorCode;
            $returnMsg = $rawMsg;
        } catch (\Exception $e) {
            $msg = $e->getMessage();
            $code = $e->getCode();
            $returnMsg = $rawMsg;
        }
        return $returnMsg ?? $msg;
    }


}