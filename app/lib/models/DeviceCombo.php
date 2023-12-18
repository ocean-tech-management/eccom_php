<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 设备套餐模块Model]
// +----------------------------------------------------------------------



namespace app\lib\models;


use app\BaseModel;
use app\lib\services\CodeBuilder;
use think\facade\Db;

class DeviceCombo extends BaseModel
{

    /**
     * @title  设备套餐列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear = []): array
    {
        $cacheKey = false;
        $cacheExpire = 0;

        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('device_name', $sear['keyword']))];
        }
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        $map[] = ['device_sn','=',$sear['device_sn']];

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }


        $list = $this->with(['device'])->where($map)->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->when($cacheKey, function ($query) use ($cacheKey, $cacheExpire) {
            $query->cache($cacheKey, $cacheExpire);
        })->order('create_time desc')->select()->each(function ($item) {
            if ($this->module == 'api') {
                if (!empty($item['oper_image'])) {
//                    $item['image'] .= '?x-oss-process=image/resize,h_1170,m_lfit';
                    $item['oper_image'] .= '?x-oss-process=image/format,webp';
                }
            }
            return $item;
        })->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }
    /**
     * @title  设备套餐详情
     * @param string $comboSn 套餐编码
     * @return mixed
     */
    public function info(string $comboSn)
    {
        $info = $this->with(['device'])->where(['combo_sn' => $comboSn, 'status' => $this->getStatusByRequestModule()])->findOrEmpty()->toArray();
        return $info;
    }

    /**
     * @title  新增或编辑
     * @param array $data
     * @return mixed
     */
    public function DBNewOrEdit(array $data)
    {
        $DBRes = Db::transaction(function () use ($data) {
            $deviceSn = $data['device_sn'];
            $codeBuilder = (new CodeBuilder());
            $res = false;
            foreach ($data['combo'] as $key => $value) {
                $save['device_sn'] = $deviceSn;
                if (empty($value['combo_sn'])) {
                    $save['combo_sn'] = $codeBuilder->buildDeviceComboCode();
                }
                $save['combo_title'] = $value['combo_title'];
//                $save['power_imei'] = $value['power_imei'];
//                $save['power_number'] = $value['power_number'] ?? 1;
                $save['oper_image'] = $value['oper_image'] ?? null;
                $save['desc'] = $value['desc'] ?? null;
                $save['continue_time'] = $value['continue_time'] ?? 120;
                $save['user_divide_scale'] = $value['user_divide_scale'] ?? 0;
                $save['price'] = $value['price'];
                $save['healthy_price'] = $value['healthy_price'];
                if (empty($value['combo_sn'])) {
                    $res[] = self::create($save);
                } else {
                    $res[] = self::update($save, ['combo_sn' => $value['combo_sn'], 'status' => [1, 2]]);
                }
            }
            return $res;
        });
        return $DBRes;
    }

    /**
     * @title  删除设备套餐
     * @param string $comboSn 设备套餐编码
     * @return self
     */
    public function del(string $comboSn)
    {
        return $this->baseDelete(['combo_sn' => $comboSn]);
    }

    public function device()
    {
        return $this->hasOne('Device','device_sn','device_sn');
    }
}