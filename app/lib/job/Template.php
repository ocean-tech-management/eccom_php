<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\job;


use app\lib\models\User;
use app\lib\services\Log;
use app\lib\services\Wx;
use think\queue\Job;

class Template
{
    //消息模版类型 1为小程序 2为公众号
    private $templateType = 1;

    public function fire(Job $job, $data)
    {
        $log['msg'] = '处理模版消息推送成功';
        $log['data'] = $data;
        $log['level'] = 'info';
        if (!empty($data)) {
            $uid = $data['uid'];
            $wxUser = (new User())->getUserProtectionInfo($uid);
            $template['openid'] = $wxUser['openid'];
            $template['type'] = $data['type'];
            $template['template'] = $data['template'];
            $template['page'] = $data['page'] ?? null;
            $template['access_key'] = $data['access_key'] ?? null;
            if (!empty($template['access_key'] ?? null)) {
                switch (substr($template['access_key'], 0, 1)) {
                    case 'm':
                        $this->templateType = 1;
                        break;
                    default:
                        $this->templateType = 2;
                        break;
                }
            }
            $res = (new Wx())->sendTemplate($template, $this->templateType);
            $log['send_res'] = $res;
            if (!$res) {
                $log['level'] = 'error';
            }
        } else {
            $log['level'] = 'error';
            $log['msg'] = '无法接受到模板内容详情';
        }
        $this->log($log, $log['level']);

        $job->delete();
    }

    /**
     * @title  记录日志
     * @param array $data 数据信息
     * @param string $level 日志等级
     * @return mixed
     */
    public function log(array $data, string $level = 'error')
    {
        return (new Log())->setChannel('wx')->record($data, $level);
    }
}