<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\AuthException;
use app\lib\services\Auth;
use app\lib\validates\AuthGroup as AuthGroupValidate;
use Exception;
use think\facade\Db;

class AuthGroup extends BaseModel
{
    protected $validateFields = ['title', 'rules'];

    /**
     * @title  用户组列表
     * @param array $sear
     * @return array
     * @throws Exception
     */
    public function list(array $sear = []): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('title', $sear['keyword']))];
        }
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->where($map)->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('create_time desc')->select()->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  用户组详情
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function info(array $data)
    {
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        $map[] = ['id', '=', $data['id']];

        $info = $this->where($map)->findOrEmpty()->toArray();

        if (!empty($info)) {
            $rules = explode(',', $info['rules']);
            $authRule = AuthRule::where(['id' => $rules, 'status' => 1])->withoutField('update_time')->order('sort asc,create_time desc')->select()->toArray();
            if (empty($authRule)) {
                throw new AuthException();
            }
            $info['rules'] = (new Auth())->getGenreTree($authRule);
        }

        return $info ?? [];

    }

    /**
     * @title  新增用户组
     * @param array $data
     * @return AuthGroup
     */
    public function DBNew(array $data)
    {
        (new AuthGroupValidate())->goCheck($data, 'create');
        $data['rules'] = implode(',', array_unique($data['rules']));
        return $this->baseCreate($data, true);
    }

    /**
     * @title  编辑用户组
     * @param array $data
     * @return AuthGroup
     */
    public function DBEdit(array $data)
    {
        (new AuthGroupValidate())->goCheck($data, 'edit');
        $data['rules'] = implode(',', array_unique($data['rules']));
        return $this->baseUpdate([$this->getPk() => $data[$this->getPk()]], $data);
    }

    /**
     * @title  删除用户组
     * @param string $id
     * @return AuthGroup
     */
    public function DBDelete(string $id)
    {
        return $this->baseDelete([$this->getPk() => $id]);
    }
}