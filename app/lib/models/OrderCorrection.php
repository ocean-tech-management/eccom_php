<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 订单资金校正表模块Model]
// +----------------------------------------------------------------------



namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\ServiceException;
use think\facade\Db;

class OrderCorrection extends BaseModel
{
    /**
     * @title  订单资金校正记录列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('order_sn', trim($sear['keyword'])))];
        }

        $map[] = ['order_sn', '=', $sear['order_sn']];
        $map[] = ['sku_sn', '=', $sear['sku_sn']];
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
            if (!empty($value['price']) && in_array($value['type'], [1, 2]) && intval($value['price'] ?? 0) < 0) {
                throw new ServiceException(['msg' => '请填写合规的金额']);
            }
        }

        $res = $this->saveAll($data);
        return judge($res);
    }
}