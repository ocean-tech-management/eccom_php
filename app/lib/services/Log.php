<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 日志服务]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\services;

use app\lib\exceptions\ServiceException;
use think\facade\Request;

class Log
{
    public $channel = 'file';
    public $level = 'info';
    public $formatTime = 'Y-m-d H:i:s';

    /**
     * @title  设置日志通道
     * @param string $channel 通道名称
     * @return $this
     * @throws ServiceException
     * @author  Coder
     * @date   2019年11月11日 15:39
     */
    public function setChannel(string $channel = 'file')
    {
        if ($this->checkLicitChannel($channel)) throw new ServiceException(['msg' => '日志通道有误']);
        $this->channel = $channel;
        return $this;
    }

    /**
     * @title  写入日志
     * @param mixed $data 日志信息
     * @param string $level 日志等级
     * @return mixed
     * @author  Coder
     * @date   2019年11月11日 15:41
     */
    public function record($data, string $level = '')
    {
        if (empty($level)) $level = $this->level;
        $logData = $this->refreshLogData($data);
        $res = \think\facade\Log::channel($this->channel)->write($logData, $level);
        return $res;
    }

    /**
     * @title  获取日志通道配置
     * @author  Coder
     * @date   2019年11月11日 15:43
     * return  array
     */
    public function getConfigChannel(): array
    {
        $dbConfig = config('log.channels');
        return array_keys($dbConfig);
    }

    /**
     * @title  检查通道合法性
     * @param string $channel 通道名称
     * @return bool
     * @author  Coder
     * @date   2019年11月11日 15:44
     */
    public function checkLicitChannel(string $channel): bool
    {
        $aChannels = $this->getConfigChannel();
        if (!in_array($channel, $aChannels)) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * @title  格式化日志信息
     * @param mixed $data 日志信息
     * @return array
     * @author  Coder
     * @date   2019年11月11日 15:44
     */
    public function refreshLogData($data): array
    {
        $log['logId'] = mt_rand(100000, 999999);
        $log['data'] = $data;
        $log['request_method'] = Request::method() ?? NULL;
        $log['request_time'] = microtime(true);
        $log['format_time'] = date($this->formatTime);
        return $log;
    }


}