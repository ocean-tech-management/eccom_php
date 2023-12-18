<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 导出文件记录Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use think\facade\Db;

class Export extends BaseModel
{
    /**
     * 导出文件列表
     * @param array $params
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function list(array $params = []){
        $page = $params['page'] ?? 1;
        if (!empty($params['pageNumber'] ?? null)) {
            $this->pageNumber = intval($params['pageNumber']);
        }
        if (!empty($params['start_time']) && !empty($params['end_time'])) {
            $map[] = ['a.create_time'
                , 'between'
                , [substr(
                    $params['start_time']
                        , 0
                        , 10
                    )
                , substr(
                    $params['end_time']
                    , 0
                    , 10
                    )
                ]
            ];
        }else{
            $map[] = ['a.create_time'
                , 'between'
                , [strtotime("-3 day"),time()]
            ];
        }
        if(isset($params['type']) && !empty($params['type'])){
            switch ($params['type']){
                case "withdraw":
                default:
                    $map[] = ['a.model_name','=',$params['type']];
                    break;
            }
        }

        $map[] = ['a.status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];

        $aTotal = Db::name('export')->alias('a')
            ->join('sp_admin_user b', 'a.admin_uid = b.id', 'left')
            ->where($map)->count();
        $pageTotal = ceil($aTotal / $this->pageNumber);

        if($aTotal > 0){
            $list = Db::name('export')->alias('a')
                ->join('sp_admin_user b', 'a.admin_uid = b.id', 'left')
                ->where($map)
                ->field('a.*,b.name as admin_name')
                ->when($page, function ($query) use ($page) {
                    $query->page($page, $this->pageNumber);
                })
                ->order('a.create_time desc')->select()
                ->each(function ($item, $key) {
                    if (!empty($item['create_time'])) {
                        $item['create_time'] = timeToDateFormat($item['create_time']);
                    }
                    if (!empty($item['update_time'])) {
                        $item['update_time'] = timeToDateFormat($item['update_time']);
                    }


                    if (empty($item['admin_name'])) {
                        $item['admin_name'] = '未知管理员';
                    }
                    if(!empty($item['search_data'])){
                        $item['search_data'] = isset($item['search_data']) && !empty($item['search_data']) ? json_decode($item['search_data'],true) : [];
                    }
                    unset ($item['file_pwd']);
                    //将已发送的次数转换为剩余次数
                    $item['last_send_num'] = $item['max_send_num'] - $item['send_num'];
                    if (doubleval($item['last_send_num']) <= 0) {
                        $item['last_send_num'] = 0;
                    }
                    $item['send_num'] = $item['last_send_num'];
                    return $item;
                })->toArray();
        }else{
            $list = [];
        }
        return ['pageTotal'=>$pageTotal,'list'=>$list];

    }
}