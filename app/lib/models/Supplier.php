<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 供应商模块Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\ServiceException;
use app\lib\services\CodeBuilder;
use Exception;
use think\facade\Db;

class Supplier extends BaseModel
{
    protected $validateFields = ['supplier_code', 'name'];

    /**
     * @title  供应商列表
     * @param array $sear
     * @return array
     * @throws Exception
     */
    public function list(array $sear = []): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('name', $sear['keyword']))];
        }
        if (!empty($sear['code'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('supplier_code', $sear['code']))];
        }
        if (!empty($sear['level'])) {
            $map[] = ['level', '=', $sear['level']];
        }
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }
        $field = $this->getListFieldByModule();

        $list = $this->where($map)->field($field)->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('sort asc,create_time desc')->select()->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }


    /**
     * @title  供应商详情
     * @param string $code 供应商编码
     * @return mixed
     */
    public function info(string $code)
    {
        return $this->where(['supplier_code' => $code, 'status' => $this->getStatusByRequestModule()])->findOrEmpty()->toArray();
    }

    /**
     * @title  新增供应商
     * @param array $data
     * @return mixed
     */
    public function new(array $data)
    {
        $data['supplier_code'] = (new CodeBuilder())->buildSupplierCode($this);
        $exist = $this->where(['name' => trim($data['name']), 'status' => [1, 2]])->count();
        if (!empty($exist)) {
            throw new ServiceException(['msg' => '该供应商已存在']);
        }
        return $this->validate()->baseCreate($data, true);
    }

    /**
     * @title  编辑供应商
     * @param array $data
     * @return Supplier
     */
    public function edit(array $data)
    {
        $eMap[] = ['name', '=', trim($data['name'])];
        $eMap[] = ['status', 'in', [1, 2]];
        $eMap[] = ['supplier_code', '<>', $data['supplier_code']];
        $exist = $this->where($eMap)->count();
        if (!empty($exist)) {
            throw new ServiceException(['msg' => '该供应商已存在']);
        }
        return $this->validate()->baseUpdate(['supplier_code' => $data['supplier_code']], $data);
    }

    /**
     * @title  删除供应商
     * @param string $code 供应商编码
     * @return Supplier
     */
    public function del(string $code)
    {
        return $this->baseDelete(['supplier_code' => $code]);
    }

    /**
     * @title  上下架
     * @param array $data
     * @return Supplier|bool
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
        return $this->baseUpdate(['supplier_code' => $data['supplier_code']], $save);
    }

    private function getListFieldByModule()
    {
        switch ($this->module) {
            case 'admin':
            case 'manager':
                $field = 'id,supplier_code,name,concat_user,concat_phone,level,address,sort,create_time,status';
                break;
            case 'api':
                $field = 'id,supplier_code,name,concat_user,concat_phone,sort,address,level';
                break;
            default:
                $field = '*';
        }
        return $field;
    }

}