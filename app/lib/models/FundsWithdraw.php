<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 财务备注资金提现记录表]
// +----------------------------------------------------------------------



namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\ServiceException;
use think\facade\Db;

class FundsWithdraw extends BaseModel
{
    /**
     * @title  财务备注资金提现记录列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('remark', trim($sear['keyword'])))];
        }

        if (!empty($sear['type'])) {
            $map[] = ['type', '=', $sear['type']];
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

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  新增记录
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    public function DBNew(array $data)
    {
        if (empty($data)) {
            return false;
        }
        foreach ($data as $key => $value) {
            if (empty(trim($value['price']))) {
                throw new ServiceException(['msg' => '请填写合规的金额']);
            }
            if (empty(trim($value['remark']))) {
                throw new ServiceException(['msg' => '请填写备注信息']);
            }
            if (empty(trim($value['time']))) {
                throw new ServiceException(['msg' => '请填写日期']);
            }
            $data[$key]['time'] = strtotime($value['time']);
        }

        $res = $this->saveAll($data);
        return judge($res);
    }

    public function getTimeAttr($value)
    {
        return !empty($value) ? timeToDateFormat($value) : $value;
    }
}