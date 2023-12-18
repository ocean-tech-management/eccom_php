<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\AdminException;
use app\lib\exceptions\ServiceException;
use app\lib\services\Token;
use Exception;
use think\facade\Db;
use think\initializer\Error;

class Lecturer extends BaseModel
{
    protected $field = ['name', 'background', 'desc', 'avatar_path', 'status', 'link_uid', 'link_user_phone', 'pwd', 'divide', 'bind_time'];
    protected $validateFields = ['name', 'avatar_path'];

    /**
     * @title  讲师列表
     * @param array $sear
     * @return array
     * @throws Exception
     */
    public function list(array $sear = []): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('name', $sear['keyword']))];
        }
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }
        $field = $this->getListFieldByModule();
        $list = $this->with(['user'])->where($map)->field($field)->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('create_time desc')->select()->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  讲师详情
     * @param int $id
     * @return mixed
     * @throws Exception
     */
    public function info(int $id)
    {
        $field = $this->getInfoFieldByModule();
        $info = $this->with(['user'])->where(['id' => $id, 'status' => [1, 2]])->field($field)->findOrEmpty()->toArray();
        if (!empty($info)) {
            $subjectList = (new Subject())->list(['lecturer_id' => $info['id']]);
            $info['subject_list'] = $subjectList['list'];
        }
        return $info;
    }

    /**
     * @title  讲师课程列表
     * @param string $id 讲师id
     * @return array
     * @throws \Exception
     */
    public function subject(string $id)
    {
        return (new Subject())->withCount(['chapter'])->where(['lecturer_id' => $id, 'status' => 1])->order('create_time desc')->select()->toArray();
    }

    /**
     * @title  新增讲师
     * @param array $data
     * @return mixed
     */
    public function new(array $data)
    {
        if (!empty($data['link_uid'])) {
            $userInfo = (new User())->getUserProtectionInfo($data['link_uid']);
            $data['pwd'] = md5($data['pwd']);
            if (!empty($userInfo)) {
                $linkUserExist = $this->where(['link_uid' => $data['uid_id'], 'status' => [1, 2]])
                    ->count();
                if (!empty($linkUserExist)) {
                    throw new ServiceException(['msg' => '该用户已绑定其他讲师，不可绑定多个讲师']);
                }
                (new Member())->specifyTopLevel($data['link_uid']);
                $data['link_user_phone'] = $userInfo['phone'];
                $data['bind_time'] = time();
            } else {
                unset($data['link_uid']);
                unset($data['divide']);
            }
        }
        return $this->validate()->baseCreate($data, true);
    }

    /**
     * @title  编辑讲师
     * @param array $data
     * @return Lecturer
     */
    public function edit(array $data)
    {
        if (!empty($data['link_uid'])) {
            $userInfo = (new User())->getUserProtectionInfo($data['link_uid']);
            if (!empty($userInfo)) {
                $map[] = ['link_uid', '=', $data['link_uid']];
                $map[] = ['status', 'in', [1, 2]];
                $map[] = ['id', '<>', $data[$this->getPk()]];
                $linkUserExist = $this->where($map)->count();
                if (!empty($linkUserExist)) {
                    throw new ServiceException(['msg' => '该用户已绑定其他讲师，不可绑定多个讲师']);
                }
                (new Member())->specifyTopLevel($data['link_uid']);
                $data['link_user_phone'] = $userInfo['phone'];
            } else {
                unset($data['link_uid']);
                unset($data['divide']);
            }
        }
        return $this->validate()->baseUpdate([$this->getPk() => $data[$this->getPk()]], $data);
    }

    /**
     * @title  删除讲师
     * @param int $id
     * @return mixed
     */
    public function del(int $id)
    {
        $res = $this->baseDelete([$this->getPk() => $id]);
        //自动解绑关联用户
        $this->untieUser($id);
        return $res;
    }

    /**
     * @title  根据用户uid查看是否有关联的讲师
     * @param string $uid 关联用户uid
     * @return mixed
     */
    public function getLecturerInfoByLinkUid(string $uid)
    {
        $info = $this->where(['link_uid' => $uid, 'status' => [1, 2]])->withoutField('pwd,update_time')->findOrEmpty()->toArray();
        return $info;
    }

    /**
     * @title  解除讲师关联用户绑定
     * @param int $id 讲师id
     * @return Lecturer
     */
    public function untieUser(int $id)
    {
        $save['link_uid'] = null;
        $save['link_user_phone'] = null;
        $save['pwd'] = null;
        $save['divide'] = 0;
        $save['bind_time'] = null;
        return $this->baseUpdate([$this->getPk() => $id], $save);
    }

    /**
     * @title  上下架
     * @param array $data
     * @return Lecturer|bool
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

    /**
     * @title  讲师登录后台
     * @param array $data
     * @return mixed
     */
    public function login(array $data)
    {
        $map[] = ['link_user_phone', '=', $data['account']];
        $map[] = ['pwd', '=', md5($data['pwd'])];
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        $aInfo = $this->where($map)->field('id,name,link_uid,link_user_phone,background,avatar_path,divide,status,create_time')->findOrEmpty()->toArray();
        if (empty($aInfo)) {
            throw new AdminException(['errorCode' => 1300102]);
        }
        if ($aInfo['status'] == 2) {
            throw new AdminException(['errorCode' => 1300101]);
        }
        $token = (new Token())->buildToken($aInfo);
        $aInfo['token'] = $token;
        return $aInfo;
    }

    /**
     * @title  修改讲师密码
     * @param array $data
     * @return Lecturer|null
     * @throws Exception
     */
    public function changePwd(array $data)
    {
        $res = null;
        $lecturerInfo = $this->info($data['lecturer_id']);
        if (empty($lecturerInfo)) {
            throw new AdminException(['errorCode' => 1300102]);
        }
        if (!empty($data['pwd'])) {
            $save['pwd'] = md5($data['pwd']);
        }
        if (!empty($save)) {
            $res = $this->baseUpdate([$this->getPk() => $data['lecturer_id']], $save);
        }
        return $res;
    }

    private function getListFieldByModule()
    {
        switch ($this->module) {
            case 'admin':
            case 'manager':
                $field = 'id,name,link_uid,link_user_phone,divide,background,desc,avatar_path,status,create_time';
                break;
            case 'api':
                $field = 'id,name,background,desc,avatar_path,create_time';
                break;
            default:
                $field = 'id,name,background,desc,avatar_path,create_time';
        }
        return $field;
    }

    private function getInfoFieldByModule()
    {
        switch ($this->module) {
            case 'admin':
                $field = 'id,name,link_uid,link_user_phone,divide,background,desc,avatar_path,status,create_time';
                break;
            case 'api':
                $field = 'id,name,background,desc,avatar_path,create_time';
                break;
            default:
                $field = 'id,name,background,desc,avatar_path,create_time';
        }
        return $field;
    }

    public function user()
    {
        return $this->hasOne('User', 'uid', 'link_uid')->bind(['link_user_name' => 'name']);
    }

}