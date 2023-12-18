<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\AdminException;
use app\lib\exceptions\AuthException;
use app\lib\exceptions\ServiceException;
use app\lib\models\OperationLog as OperationLogModel;
use app\lib\services\Auth;
use app\lib\services\Token;
use app\lib\validates\AdminUser as AdminUserValidate;
use Exception;
use think\facade\Db;
use think\facade\Request;

class AdminUser extends BaseModel
{
    protected $field = ['name', 'account', 'pwd', 'type', 'status', 'login_ip', 'is_show', 'type', 'login_time', 'supplier_code'];

    /**
     * @title  登录
     * @param array $data
     * @return mixed
     */
    public function login(array $data)
    {
        $map[] = ['account', '=', $data['account']];
        $map[] = ['pwd', '=', md5($data['pwd'])];
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        $aInfo = $this->where($map)->field('id,name,account,status,login_ip,status,type,create_time,supplier_code')->findOrEmpty()->toArray();
        if (empty($aInfo)) {
            throw new AdminException(['errorCode' => 1300102]);
        }
        if ($aInfo['status'] == 2) {
            throw new AdminException(['errorCode' => 1300101]);
        }
        $token = (new Token())->buildToken($aInfo);
        $aInfo['token'] = $token;
        self::update(['login_ip' => Request::ip(), 'login_time' => time()], ['id' => $aInfo['id']]);

        //记录登录日志
        $log['admin_id'] = $aInfo['id'];
        $log['admin_name'] = $aInfo['name'];
        $log['path'] = 'login/login';
        $log['path_name'] = '登录';
        $log['content'] = ($data['login_way'] ?? 1) == 2 ? '通过商户移动端登录' : null; //此处留给以后需要重点关注操作的操作数据说明
        OperationLogModel::create($log);

        //记录当前登录用户的所有token, 以便后续用户登录异常清除所有token
        $cacheKey = 'admin-' . $aInfo['id'];
        $LoginToken = cache($cacheKey);
        if (empty($LoginToken)) {
            $LoginToken = [$token];
        } else {
            $LoginToken[] = $token;
        }
        if (!empty($LoginToken)) {
            cache($cacheKey, $LoginToken, 10800);
        }

        return $aInfo;
    }

    /**
     * @title  管理员列表
     * @param array $sear
     * @return array
     * @throws Exception
     */
    public function list(array $sear = []): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('name|account', $sear['keyword']))];
        }
        if (!empty($sear['type'])) {
            $map[] = ['type', '=', $sear['type']];
        }

        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        $map[] = ['is_show', '=', 1];

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->with(['group', 'supplier'])->where($map)->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->withoutField('pwd,update_time')->order('create_time desc')->select()->toArray();
        if (!empty($list)) {
            $groupId = array_unique(array_filter(array_column($list, 'group_id')));
            $groupList = AuthGroup::where(['id' => $groupId, 'status' => [1, 2]])->column('title', 'id');
            if (!empty($groupList)) {
                foreach ($list as $key => $value) {
                    $list[$key]['group_title'] = '暂无用户组';
                    if (!empty($value['group_id']) && !empty($groupList[$value['group_id']])) {
                        $list[$key]['group_title'] = $groupList[$value['group_id']];
                    }
                }
            }
        }
        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  管理员详情
     * @param int $adminId 管理员id
     * @return mixed
     */
    public function info(int $adminId)
    {
        return $this->where(['id' => $adminId, 'status' => $this->getStatusByRequestModule()])->findOrEmpty()->toArray();
    }

    /**
     * @title  新增管理员
     * @param array $data
     * @return AdminUser
     */
    public function DBNew(array $data)
    {
        (new AdminUserValidate())->goCheck($data, 'create');
        $data['pwd'] = md5($data['pwd']);
        $data['type'] = $data['type'] ?? 1;
        $res = $this->baseCreate($data, true);
        (new AuthGroupAccess())->updateUserGroup(['admin_id' => $res, 'group_id' => $data['group_id']]);
        return $res;
    }

    /**
     * @title  编辑管理员
     * @param array $data
     * @return AdminUser
     */
    public function DBEdit(array $data)
    {
        //操作的当前管理员信息
        $nowAdminUserInfo = $this->where([$this->getPk() => $data['nowAdminId'], 'status' => 1])->findOrEmpty()->toArray();
        //被修改的管理员信息
        $operAdminUserInfo = $this->where([$this->getPk() => $data[$this->getPk()], 'status' => 1])->findOrEmpty()->toArray();

        if (empty($nowAdminUserInfo) || empty($operAdminUserInfo)) {
            throw new ServiceException(['errorCode' => 400101]);
        }

        //是否为修改自己密码
        $myself = false;
        if ($data['nowAdminId'] == $data[$this->getPk()]) {
            $myself = true;
        }

        //不允许其他管理员修改超管的信息
        if ($operAdminUserInfo['type'] == 88 && $nowAdminUserInfo['type'] != 88) {
            throw new AuthException(['errorCode' => 2200103]);
        }

        //超管仅允许修改自己的密码,不允许修改其他超管的密码
        if ($operAdminUserInfo['type'] == 88 && $nowAdminUserInfo['type'] == 88) {
            if ($operAdminUserInfo['id'] != $nowAdminUserInfo['id']) {
                throw new AuthException(['errorCode' => 2200106]);
            }
        }

        //验证场景
        $scene = $data['scene'] ?? 'edit';
        (new AdminUserValidate())->goCheck($data, $scene);
        if (!empty($data['pwd']) && $scene == 'pwd') {
            //如果是修改自己的密码需要验证旧密码
            if (!empty($myself)) {
                if (empty($data['oldPwd'])) {
                    throw new AuthException(['errorCode' => 2200107]);
                }
                if (md5($data['oldPwd']) != $operAdminUserInfo['pwd']) {
                    throw new AuthException(['errorCode' => 2200108]);
                }
                //判断登录环境是否为同一ip
//                if(!empty($operAdminUserInfo['login_ip']) && Request::ip() != $operAdminUserInfo['login_ip']){
//                    throw new AuthException(['errorCode' => 2200109]);
//                }
            }

            $saveData = $data;
            $saveData['pwd'] = md5($data['pwd']);
            $saveData[$this->getPk()] = $data[$this->getPk()];
            unset($data);
            $data = $saveData;
        }
        $res = $this->baseUpdate([$this->getPk() => $data[$this->getPk()]], $data);
        if ($scene == 'edit') {
            (new AuthGroupAccess())->updateUserGroup(['admin_id' => $data[$this->getPk()], 'group_id' => $data['group_id']]);
        }
        return $res;
    }


    /**
     * @title  删除管理员
     * @param int $adminId 管理员id
     * @return AdminUser
     */
    public function DBDelete(int $adminId)
    {
        return $this->baseDelete([$this->getPk() => $adminId]);
    }

    /**
     * @title  禁/启用管理员
     * @param array $data
     * @return AdminUser|bool
     */
    public function upOrDown(array $data)
    {
        if ($data['status'] == 1) {
            $save['status'] = 2;
        } elseif ($data['status'] == 2) {
            $save['status'] = 1;
        } else {
            return false;
        }
        return $this->baseUpdate([$this->getPk() => $data[$this->getPk()]], $save);
    }

    public function getLoginTimeAttr($value)
    {
        return !empty($value) ? timeToDateFormat($value) : null;
    }

    public function group()
    {
        return $this->hasOne('AuthGroupAccess', 'admin_id', 'id')->bind(['admin_id', 'group_id'])->where(['status' => 1]);
    }

    public function supplier()
    {
        return $this->hasOne('Supplier', 'supplier_code', 'supplier_code')->where(['status' => 1]);
    }

}