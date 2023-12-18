<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 权限模块-权限规则Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\models;


use app\BaseModel;
use app\lib\services\Auth;
use Exception;
use think\facade\Db;
use app\lib\validates\Auth as AuthValidate;

class AuthRule extends BaseModel
{
    protected $validateFields = ['condition'];

    /**
     * @title  权限列表
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
        })->withoutField('update_time')->order('sort asc,create_time desc')->select()->toArray();
        if (!empty($list)) {
            $list = (new Auth())->getGenreTree($list);
        }
        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  新增权限
     * @param array $data
     * @return mixed
     */
    public function DBNew(array $data)
    {
        (new AuthValidate())->goCheck($data, 'create');
        return $this->baseCreate($data, true);
    }

    /**
     * @title  编辑权限
     * @param array $data
     * @return AuthRule
     */
    public function DBEdit(array $data)
    {
        (new AuthValidate())->goCheck($data, 'edit');
        return $this->validate(false)->baseUpdate([$this->getPk() => $data[$this->getPk()]], $data);
    }

    /**
     * @title  删除权限
     * @param string $id
     * @return AuthRule
     */
    public function DBDelete(string $id)
    {
        return $this->baseDelete([$this->getPk() => $id]);
    }

    public function parent()
    {
        return $this->hasOne(get_class($this), 'id', 'pid');
    }

}