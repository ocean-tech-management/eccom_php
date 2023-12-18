<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 开放平台用户帐号配置Model]
// +----------------------------------------------------------------------



namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\ParamException;
use app\lib\services\CodeBuilder;
use think\facade\Db;

class OpenAccount extends BaseModel
{

    /**
     * @title  开放平台开发者列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear = []): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('name|appId', $sear['keyword']))];
        }

        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->where($map)->field('name,appId,secretKey,remark,status,create_time')->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('create_time desc')->select()->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  新增开发者帐号
     * @param array $data
     * @return mixed
     */
    public function new(array $data)
    {
        $add['name'] = trim($data['name']);
        if (empty($add['name'])) {
            throw new ParamException(['msg' => '请填写名称']);
        }
        $add['appId'] = (new CodeBuilder())->buildOpenDeveloperAppId();
        $add['secretKey'] = (new CodeBuilder())->buildOpenDeveloperSecretKey($add['appId']);
        $add['remark'] = trim($data['remark'] ?? null);
        return $this->baseCreate($add, true);
    }

    /**
     * @title  开发者详情
     * @param string $appId
     * @return mixed
     */
    public function info(string $appId)
    {
        return $this->where(['appId' => $appId, 'status' => [1, 2]])->findOrEmpty()->toArray();
    }

    /**
     * @title  更新开发者信息
     * @param array $data
     * @return mixed
     */
    public function edit(array $data)
    {
        $save['name'] = trim($data['name']);
        $save['remark'] = trim($data['remark'] ?? null);
        if (empty($save['name'])) {
            throw new ParamException(['msg' => '请填写名称']);
        }
        return $this->baseUpdate(['appId' => $data['appId']], $save);
    }

    /**
     * @title  删除开发者
     * @param string $appID
     * @return mixed
     */
    public function del(string $appID)
    {
        return $this->baseDelete(['appId' => $appID]);
    }

    /**
     * @title  禁/启用开发者
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
        return $this->baseUpdate(['appId' => $data['appId']], $save);
    }
}