<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 开放平台模块路由配置]
// +----------------------------------------------------------------------


use think\facade\Route;
//订单模块
Route::post('open/<version>/deviceOrder/query','open.<version>.DeviceOrder/orderCheck');
Route::post('open/<version>/deviceOrder/callback','open.<version>.DeviceOrder/callback');
Route::any('open/<version>/devicePower/callback','open.<version>.DeviceMQTT/record');
//健康豆模块
Route::any('open/<version>/healthyBlance/conver','open.<version>.HealthyBlance/conver');
Route::any('open/<version>/healthyBlance/checkConverOrder','open.<version>.HealthyBlance/checkConverOrder');
Route::any('open/<version>/healthyBlance/checkUser','open.<version>.HealthyBlance/checkUser');
Route::any('open/<version>/debug/record','open.<version>.Debug/debugRecordLog');
Route::any('open/<version>/healthyBlance/amount','open.<version>.HealthyBlance/amount');

Route::any('open/<version>/test/ddsTest','open.<version>.Test/ddsTest');

Route::miss(function() {
    return json(['error_code' => -1, 'msg' => '非法请求 请检查请求路由或方式']);
});
