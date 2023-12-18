<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 后台操作日志Service]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\services;


use app\lib\exceptions\ServiceException;
use app\lib\exceptions\TokenException;
use app\lib\models\MemberOperLog;
use app\lib\models\TeamMemberOperLog;
use think\facade\Request;

class AdminLog
{
    private $adminInfo = [];

    /**
     * @title  获取管理员信息
     * @return array|mixed
     */
    public function getAdminInfo()
    {
        $baseUrl = explode('/', trim(Request::pathinfo()));
        $root = $baseUrl[0];
        if ($root == 'admin' || $root == 'manager') {
            $controller = $baseUrl[1];
            $action = $baseUrl[2];
            $route = $controller . '/' . $action;
            $aAdmin = (new Token())->verifyToken();
            if (empty($aAdmin)) {
                throw new TokenException(['errorCode' => 1000101]);
            }
            $this->adminInfo = $aAdmin;
        } else {
            throw new ServiceException(['msg' => '不允许操作的应用']);
        }
        return $aAdmin ?? [];
    }

    /**
     * @title  代理等级操作日志
     * @param array $data
     * @return mixed
     */
    public function memberOPER(array $data)
    {
        $adminInfo = $this->getAdminInfo();
        $mLog['admin_account'] = $adminInfo['account'];
        $mLog['oper_uid'] = $data['uid'];
        $mLog['before_level'] = $data['before_level'];
        $mLog['after_level'] = $data['after_level'];
        $mLog['before_link_user'] = $data['before_link_user'] ?? null;
        $mLog['after_link_user'] = $data['after_link_user'] ?? null;
        $mLog['type'] = $data['type'];
        $mLog['remark'] = $data['remark'] ?? null;
        $res = MemberOperLog::create($mLog);
        return $res->getData();
    }

    /**
     * @title  团队代理等级操作日志
     * @param array $data
     * @return mixed
     */
    public function teamMemberOPER(array $data)
    {
        $adminInfo = $this->getAdminInfo();
        $mLog['admin_account'] = $adminInfo['account'];
        $mLog['oper_uid'] = $data['uid'];
        $mLog['before_level'] = $data['before_level'];
        $mLog['after_level'] = $data['after_level'];
        $mLog['before_link_user'] = $data['before_link_user'] ?? null;
        $mLog['after_link_user'] = $data['after_link_user'] ?? null;
        $mLog['type'] = $data['type'];
        $mLog['remark'] = $data['remark'] ?? null;
        $res = TeamMemberOperLog::create($mLog);
        return $res->getData();
    }

    /**
     * @title  区代等级操作日志
     * @param array $data
     * @return mixed
     */
    public function areaMemberOPER(array $data)
    {
        $adminInfo = $this->getAdminInfo();
        $mLog['admin_account'] = $adminInfo['account'];
        $mLog['oper_uid'] = $data['uid'];
        $mLog['before_level'] = $data['before_level'];
        $mLog['after_level'] = $data['after_level'];
        $mLog['before_link_user'] = $data['before_link_user'] ?? null;
        $mLog['after_link_user'] = $data['after_link_user'] ?? null;
        $mLog['type'] = $data['type'];
        $mLog['remark'] = $data['remark'] ?? null;
        $res = TeamMemberOperLog::create($mLog);
        return $res->getData();
    }
}