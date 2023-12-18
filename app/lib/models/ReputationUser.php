<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 商品系统评价官(口碑评价官)模块Model]
// +----------------------------------------------------------------------



namespace app\lib\models;


use app\BaseModel;
use app\lib\services\CodeBuilder;
use think\facade\Db;
use app\lib\validates\ReputationUser as ReputationUserValidate;

class ReputationUser extends BaseModel
{
    /**
     * @title  口碑评价官列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear = []): array
    {
        $list = [];

        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('user_name', $sear['keyword']))];
        }
        if (!empty($sear['user_level'])) {
            $map[] = ['user_level', '=', $sear['user_level']];
        }

        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }


        $list = $this->where($map)->withoutField('id,update_time')->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('create_time desc')->select()->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0, 'total' => $aTotal ?? 0];
    }


    /**
     * @title  评价官详情
     * @param array $data
     * @return mixed
     */
    public function info(array $data)
    {
        $info = $this->where(['user_code' => $data['user_code']])->findOrEmpty()->toArray();
        return $info ?? [];
    }

    /**
     * @title  新增口碑评价官
     * @param array $data
     * @return mixed
     */
    public function new(array $data)
    {
        $data['user_name'] = trim($data['user_name']);
        (new ReputationUserValidate())->goCheck($data, 'create');

        $DBRes = false;
        $DBRes = Db::transaction(function () use ($data) {
            $data['user_code'] = (new CodeBuilder())->buildReputationUserCode();
            $newReputationId = $this->baseCreate($data, true);
            return $newReputationId;
        });

        return $DBRes;
    }

    /**
     * @title  编辑口碑评价官
     * @param array $data
     * @return mixed
     */
    public function edit(array $data)
    {
        $data['user_name'] = trim($data['user_name']);
        (new ReputationUserValidate())->goCheck($data, 'edit');

        $DBRes = false;
        $DBRes = Db::transaction(function () use ($data) {
            $res = $this->baseUpdate(['user_code' => $data['user_code']], $data);
            return $res;
        });

        return $DBRes;
    }

    /**
     * @title  删除口碑评价官
     * @param string $userCode 口碑评价官编码
     * @return mixed
     */
    public function del(string $userCode)
    {
        $res = $this->baseDelete(['user_code' => $userCode]);
        return $res;
    }

    /**
     * @title  上下架口碑评价官
     * @param array $data
     * @return mixed
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
        return $this->baseUpdate(['user_code' => $data['user_code']], $save);
    }
}