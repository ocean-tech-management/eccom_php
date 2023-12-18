<?php

namespace app;

use app\lib\BaseException;
use app\lib\Exceptions\ServiceException;
use app\lib\services\Log;
use think\facade\Env;
use think\Response;
use think\facade\Request;
use think\exception\Handle;
use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\exception\HttpException;
use think\exception\HttpResponseException;
use think\exception\ValidateException;

use Throwable;

/**
 * 应用异常处理类
 */
class ExceptionHandle extends Handle
{
    /**
     * http状态码
     * @var int
     */
    protected $code;

    /**
     * 错误信息
     * @var string
     */
    protected $msg;

    /**
     * 错误码
     * @var mixed|int
     */
    protected $errorCode;

    /**
     * 错误文件
     * @var mixed
     */
    protected $errorFile;

    /**
     * 日志记录提示语
     * @var mixed
     */
    protected $logMsg;

    /**
     * 日志记录错误文件
     * @var
     */
    protected $logErrorFile = null;

    /**
     * 日志通道
     * @var string
     */
    protected $logChannel = 'file';

    /**
     * 日志记录等级
     * @var string
     */
    protected $logStatus = 'error';

    /**
     * 错误路径
     * @var string|mixed
     */
    protected $errorPath;

    /**
     * 不需要记录信息（日志）的异常类列表
     * @var array
     */
    protected $ignoreReport = [
        HttpException::class,
        HttpResponseException::class,
        ModelNotFoundException::class,
        DataNotFoundException::class,
        ValidateException::class,
        BaseException::class,
    ];

    /**
     * 记录异常信息（包括日志或者其它方式记录）
     *
     * @access public
     * @param Throwable $exception
     * @return void
     */
    public function report(Throwable $exception): void
    {
        // 使用内置的方式记录异常日志
        parent::report($exception);
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @access public
     * @param \think\Request $request
     * @param Throwable $e
     * @return Response
     * @throws ServiceException
     */
    public function render($request, Throwable $e): Response
    {
        // 添加自定义异常处理机制
        if ($e instanceof BaseException) {
            //如果是自定义的异常,状态统一是200，请求成功，业务失败
            $this->code = 200;
            $this->msg = $e->msg;
            $this->errorCode = $e->errorCode;
            $this->logStatus = 'debug';
            $this->logMsg = $e->msg;
        } else {
            //如果是系统异常,修改抛出机制为json
            //获取http状态码
            if ($e instanceof HttpException) {
                $this->code = $e->getStatusCode();
            } else {
                $this->code = 500;
            }
            if (Env::get('app_debug')) {
                $this->msg = $e->getMessage();
                $this->errorCode = 404010;
                $this->errorFile = basename($e->getFile()) . ' line ' . $e->getLine();
                //return parent::render($request, $e);
            } else {
                $this->msg = '网络出错啦,请稍后重试~';
                $this->errorCode = 500010;
            }

            $this->logMsg = $e->getMessage();
            $this->logErrorFile = basename($e->getFile()) . ' line ' . $e->getLine();
        }

        $this->errorPath = $request->url();
        $this->logChannel = $e->logChannel ?? $this->logChannel;

        $result = [
            'msg' => $this->logMsg,
            'errorFile' => $this->logErrorFile,
            'error_code' => $this->errorCode,
            'error_path' => $this->errorPath,
            'header_ak' => $request->header('access-key'),
        ];
        $this->logRecord($result);
        // 其他错误交给系统处理
        $result['msg'] = $this->msg;
        $result['errorFile'] = $this->errorFile;
        //报错信息不返回客户端应用标识
        unset($result['header_ak']);
        //return parent::render($request, $e);
        return json($result, $this->code);
    }

    /**
     * @title  日志记录
     * @param array $result 日志信息
     * @throws ServiceException
     * @author  Coder
     * @date   2019年11月25日 14:49
     */
    private function logRecord(array $result): void
    {
        if (empty($this->errorFile)) {
            unset($result['errorFile']);
        }
        if ($this->errorCode == 500010) {
            $result['error_file'] = $this->logErrorFile;
        }
        $result['request_url'] = Request::url(true);
        $result['request_param'] = Request::param();
        (new Log())->setChannel($this->logChannel)->record($result, $this->logStatus);
    }
}
