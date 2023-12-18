<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------



namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\ServiceException;
use app\lib\exceptions\UserException;
use think\facade\Db;

class UserPwdOperationLog extends BaseModel
{
    /**
     * @title  用户列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear = []): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('name|phone', trim($sear['keyword'])))];
        }

        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }
        $list = $this->with(['user'])->where($map)->withoutField('id,update_time,change_pwd')->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('create_time desc,id desc')->select()->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  修改用户密码或支付密码
     * @param array $data
     * @return bool
     */
    public function managerUpdateUserPwd(array $data)
    {
        $uid = $data['oper_uid'];
        if (empty($uid)) {
            throw new ServiceException(['msg' => '非法用户']);
        }
        $userInfo = User::where(['uid' => $uid, 'status' => 1])->findOrEmpty()->toArray();
        if (empty($userInfo)) {
            throw new ServiceException(['msg' => '非法用户']);
        }
        switch ($data['oper_type'] ?? 1) {
            case 1:
                if (empty($userInfo['pwd'] ?? null) || empty($userInfo['phone'])) {
                    throw new UserException(['msg' => '用户暂无密码或暂未授权手机号码,无需重置']);
                }
                break;
            case 2:
                if (empty($userInfo['pay_pwd'] ?? null)) {
                    throw new UserException(['msg' => '用户暂未设置支付密码,无需初始化']);
                }
                break;
            default:
                throw new UserException(['msg' => '不支持的操作类型']);
        }
        $adminInfo = $data['adminInfo'];
        if (empty($adminInfo)) {
            throw new ServiceException(['msg' => '非法管理员']);
        }
        $adminPwd = AdminUser::where(['id' => $adminInfo['id'], 'status' => 1])->value('oper_pwd');
        if (empty($adminPwd)) {
            throw new ServiceException(['msg' => '管理员无操作权限']);
        }
        if (empty(trim($data['oper_pwd'] ?? null)) || (!empty($data['oper_pwd']) && md5(trim($data['oper_pwd'])) != $adminPwd)) {
            throw new ServiceException(['msg' => '操作密码有误!']);
        }

        $new['oper_uid'] = $data['oper_uid'];
        $new['admin_id'] = $adminInfo['id'];
        $new['admin_name'] = $adminInfo['name'];
        $new['proof'] = $data['proof'];
        $new['remark'] = $data['remark'];
        $new['oper_type'] = $data['oper_type'];
        switch ($new['oper_type'] ?? 1) {
            case 1:
                $new['change_pwd'] = md5(substr($userInfo['phone'], -4));
                break;
            case 2:
                $new['change_pwd'] = null;
                break;
            default:
                throw new UserException(['msg' => '不支持的操作类型']);
        }
        $res = self::create($new);
        switch ($new['oper_type'] ?? 1) {
            case 1:
                User::update(['pwd' => $new['change_pwd']], ['uid' => $data['oper_uid'], 'status' => 1]);
                break;
            case 2:
                User::update(['pay_pwd' => $new['change_pwd']], ['uid' => $data['oper_uid'], 'status' => 1]);
                break;
            default:
                throw new UserException(['msg' => '不支持的操作类型']);
        }
        //修改成功会重置输错密码的次数
        $cacheKey = 'payPwdError-' . $data['oper_uid'];
        cache($cacheKey, null);
        return judge($res);
    }

    public function user()
    {
        return $this->hasOne('User', 'uid', 'oper_uid')->bind(['user_name' => 'name', 'user_phone' => 'phone']);
    }
}