<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 团队奖励制度释放明细模块Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;

class RewardFreed extends BaseModel
{
    protected $field = ['uid', 'order_sn', 'freed_sn', 'divide_price', 'reward_scale', 'reward_other', 'freed_scale', 'freed_cycle', 'freed_each', 'freed_start', 'freed_end', 'is_first', 'next_freed_support', 'next_freed_cycle', 'next_freed_scale', 'next_freed_each', 'next_freed_start', 'next_freed_end', 'reward_integral', 'freed_integral', 'fronzen_integral', 'status'];
}