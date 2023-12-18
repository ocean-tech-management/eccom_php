<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 乐小活免签用户模块Model]
// +----------------------------------------------------------------------



namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\ServiceException;
use app\lib\exceptions\UserException;
use think\facade\Db;

class LetfreeExemptUser extends BaseModel
{

    /**
     * @title  乐小活免签用户列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear = []): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('b.name|b.phone', trim($sear['keyword'])))];
        }
        if (!empty($sear['real_name'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('a.real_name', trim($sear['real_name'])))];
        }

        $orderField = 'a.create_time desc,a.id desc';

        $map[] = ['a.status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->alias('a')->join('sp_user b', 'a.uid = b.uid', 'left')->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }
        $list = $this->alias('a')->join('sp_user b', 'a.uid = b.uid', 'left')->where($map)->field('a.*,b.name as user_name,b.phone as user_phone')->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order($orderField)->select()->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  新增
     * @param array $data
     * @return bool|\think\Collection
     * @throws \Exception
     */
    public function DBNew(array $data)
    {
        $userList = $data['user_list'];
        if (empty($userList)) {
            throw new UserException(['msg' => '参数异常']);
        }

        $allUser = User::where(['phone' => array_unique(array_column($userList, 'userPhone')), 'status' => 1])->column('uid', 'phone');

        foreach ($userList as $key => $value) {
            if (!empty($allUser[$value['userPhone']] ?? null)) {
                $newData[$key]['uid'] = $allUser[$value['userPhone']];
                $newData[$key]['user_phone'] = $value['userPhone'];
                $newData[$key]['remark'] = $value['remark'] ?? null;
                $newData[$key]['real_name'] = $value['realName'] ?? null;
            }
        }

        if (!empty($newData)) {
            $existUser = self::where(['uid' => array_unique(array_column($newData, 'uid')), 'status' => 1])->column('uid');
            if (!empty($existUser)) {
                foreach ($newData as $key => $value) {
                    if (in_array($value['uid'], $existUser)) {
                        unset($newData[$key]);
                    }
                }
                if (empty($newData)) {
                    throw new ServiceException(['msg' => '用户数据已存在,无需重复导入']);
                }
            }
        }

        $res = false;
        if (!empty($newData)) {
            $res = $this->saveAll($newData);
        }
        return $res;
    }

    /**
     * @title  删除用户
     * @param string $uid 用户uid
     * @return mixed
     */
    public function del(string $uid)
    {
        $res = self::update(['status' => -1], ['uid' => $uid, 'status' => 1]);
        return $res;
    }
}