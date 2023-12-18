<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 商户移动端模块路由配置]
// +----------------------------------------------------------------------


use think\facade\Route;

//登录模块
Route::post('manager/login/login','manager.Login/login');

//刷新token
Route::post('manager/token/refresh','manager.Login/refreshToken');

//商品模块
Route::get('manager/goods/list','manager.Goods/spuList');
Route::get('manager/goods/info','manager.Goods/info');
Route::post('manager/goods/create','manager.Goods/create');
Route::post('manager/goods/update','manager.Goods/update');
Route::get('manager/goods/delete','manager.Goods/delete');
Route::get('manager/goods/status','manager.Goods/status');
Route::post('manager/goods/sort','manager.Goods/updateSort');
Route::get('manager/goods/attrKey','manager.Goods/attrKey');
Route::get('manager/goods/attrValue','manager.Goods/attrValue');
Route::get('manager/goods/deleteSku','manager.Goods/deleteSku');
Route::get('manager/goods/deleteImages','manager.Goods/deleteImages');
Route::get('manager/goods/postageTemplates','manager.Goods/postageTemplate');


//会员模块
Route::get('manager/vdc/list','manager.Member/vdcList');

//后台数据·统计模块
Route::get('manager/summary/all','manager.Summary/adminSummary');
Route::get('manager/summary/goods','manager.Summary/goodsSummary');

//商品SPU模块
Route::get('manager/spu/list','manager.Goods/spuList');

//商品SKU模块
Route::get('manager/sku/list','manager.Goods/skuList');


//商品属性模块 --start--

//key
Route::get('manager/attr/key','manager.Attribute/keyList');
Route::post('manager/attr/keyCreate','manager.Attribute/keyCreate');
Route::post('manager/attr/keyUpdate','manager.Attribute/keyUpdate');
Route::get('manager/attr/keyDelete','manager.Attribute/keyDelete');
Route::get('manager/attr/keyStatus','manager.Attribute/keyStatus');
//value
Route::get('manager/attr/value','manager.Attribute/valueList');
Route::post('manager/attr/valueCreate','manager.Attribute/valueCreate');
Route::post('manager/attr/valueUpdate','manager.Attribute/valueUpdate');
Route::get('manager/attr/valueDelete','manager.Attribute/valueDelete');
Route::get('manager/attr/valueStatus','manager.Attribute/valueStatus');
//二合一
Route::post('manager/attr/create','manager.Attribute/create');

//商品属性模块 --end--

//订单模块
Route::get('manager/order/list','manager.Order/list');
Route::get('manager/order/info','manager.Order/info');
Route::get('manager/order/memberList','manager.Order/memberList');
Route::post('manager/order/remark','manager.Order/orderRemark');
Route::get('manager/order/spuList','manager.Order/goodsSpu');
Route::get('manager/order/skuList','manager.Order/goodsSku');


//会员模块
Route::get('manager/member/list','manager.Member/list');
Route::post('manager/member/assign','manager.Member/assign');
Route::get('manager/member/info','manager.Member/info');
Route::get('manager/member/level','manager.Member/level');
Route::post('manager/member/revoke','manager.Member/revoke');

//分销模块
Route::get('manager/divide/list','manager.Divide/ruleList');
Route::get('manager/divide/console','manager.Divide/console');
Route::get('manager/divide/info','manager.Divide/ruleInfo');
Route::post('manager/divide/create','manager.Divide/ruleCreate');
Route::post('manager/divide/update','manager.Divide/ruleUpdate');
Route::get('manager/divide/divideList','manager.Divide/list');
Route::get('manager/divide/divideDetail','manager.Divide/divideDetail');
Route::get('manager/divide/divideRecordList','manager.Divide/recordList');
Route::get('manager/divide/divideRecordDetail','manager.Divide/recordDetail');
Route::get('manager/divide/lecturerList','manager.Divide/lecturerList');


//用户模块
Route::get('manager/user/list','manager.User/list');
Route::get('manager/user/balance','manager.User/balance');
Route::post('manager/user/assign','manager.User/assign');
Route::get('manager/user/summary','manager.User/summary');


//财务模块
Route::post('manager/finance/checkWithdraw','manager.Finance/CheckWithdraw');
Route::get('manager/finance/withdrawList','manager.Finance/withdrawList');
Route::get('manager/finance/withdrawDetail','manager.Finance/withdrawDetail');


//应用用户统计模块
Route::get('manager/analysis/coreData','manager.Analysis/coreData');



//快递物流模块
Route::get('manager/ship/company','manager.Ship/companyList');
Route::post('manager/ship/ship','manager.Ship/ship');
Route::get('manager/ship/info','manager.Ship/info');
Route::get('manager/ship/summary','manager.Ship/summary');
Route::post('manager/ship/sync','manager.Ship/syncOrder');
Route::get('manager/ship/syncTime','manager.Ship/syncTime');
Route::get('manager/ship/orderList','manager.Ship/orderList');
Route::post('manager/ship/split','manager.Ship/splitOrder');
Route::post('manager/ship/merge','manager.Ship/mergeOrder');
Route::post('manager/ship/cancelSplit','manager.Ship/cancelSplit');
Route::post('manager/ship/cancelMerge','manager.Ship/cancelMerge');
Route::post('manager/ship/updateShip','manager.Ship/updateShip');
Route::post('manager/ship/updateShipInfo','manager.Ship/updateShipInfo');
Route::post('manager/ship/city','manager.Ship/city');
Route::get('manager/ship/template','manager.Ship/postageTemplateList');
Route::get('manager/ship/templateInfo','manager.Ship/postageTemplateInfo');
Route::post('manager/ship/templateCreate','manager.Ship/postageTemplateCreate');
Route::post('manager/ship/templateUpdate','manager.Ship/postageTemplateUpdate');
Route::post('manager/ship/templateDelete','manager.Ship/postageTemplateDelete');
Route::post('manager/ship/templateDetailDelete','manager.Ship/postageDetailDelete');
Route::post('manager/ship/templateDefault','manager.Ship/setDefaultTemplate');

//售后模块
Route::get('manager/afterSale/list','manager.AfterSale/list');
Route::get('manager/afterSale/info','manager.AfterSale/info');
Route::post('manager/afterSale/verify','manager.AfterSale/verify');
Route::post('manager/afterSale/refund','manager.AfterSale/refund');
Route::post('manager/afterSale/fillInShip','manager.AfterSale/sellerFillInShip');
Route::post('manager/afterSale/confirm','manager.AfterSale/confirmReceiveGoods');
Route::post('manager/afterSale/refuse','manager.AfterSale/refuseReceiveGoods');

//拼团模块
Route::get('manager/pt/list','manager.Activity/ptList');
Route::post('manager/pt/create','manager.Activity/ptCreate');
Route::get('manager/pt/info','manager.Activity/ptInfo');
Route::post('manager/pt/update','manager.Activity/ptUpdate');
Route::post('manager/pt/delete','manager.Activity/ptDelete');
Route::post('manager/pt/status','manager.Activity/ptUpOrDown');
Route::post('manager/pt/goodsUpdate','manager.Activity/createOrUpdatePtGoods');
Route::post('manager/pt/goodsDelete','manager.Activity/deletePtGoods');
Route::post('manager/pt/goodsSkuDelete','manager.Activity/deletePtGoodsSku');
Route::get('manager/pt/goodsInfo','manager.Activity/ptGoodsInfo');
Route::get('manager/pt/goodsSkuInfo','manager.Activity/ptGoodsSkuInfo');

//权限模块
Route::get('manager/auth/ruleList','manager.Auth/ruleList');
Route::post('manager/auth/ruleCreate','manager.Auth/ruleCreate');
Route::post('manager/auth/ruleUpdate','manager.Auth/ruleUpdate');
Route::post('manager/auth/ruleDelete','manager.Auth/ruleDelete');
Route::get('manager/auth/groupList','manager.Auth/groupList');
Route::get('manager/auth/groupInfo','manager.Auth/groupInfo');
Route::post('manager/auth/groupCreate','manager.Auth/groupCreate');
Route::post('manager/auth/groupUpdate','manager.Auth/groupUpdate');
Route::post('manager/auth/groupDelete','manager.Auth/groupDelete');

//管理员模块
Route::get('manager/manager/list','manager.Manager/managerUserList');
Route::post('manager/manager/create','manager.Manager/managerUserCreate');
Route::post('manager/manager/update','manager.Manager/managerUserUpdate');
Route::post('manager/manager/delete','manager.Manager/managerUserDelete');
Route::post('manager/manager/status','manager.Manager/managerUserStatus');
Route::post('manager/manager/updateUserGroup','manager.Manager/updateUserGroup');
Route::get('manager/manager/userRule','manager.Manager/userAuthList');
Route::post('manager/manager/updatePwd','manager.Manager/updatemanagerUserPwd');

//活动模块
Route::get('manager/activity/list','manager.Activity/list');
Route::get('manager/activity/info','manager.Activity/info');
Route::post('manager/activity/update','manager.Activity/update');
Route::post('manager/activity/status','manager.Activity/upOrDown');
Route::post('manager/activity/delete','manager.Activity/delete');
Route::post('manager/activity/goodsUpdate','manager.Activity/createOrUpdateGoods');
Route::post('manager/activity/goodsDelete','manager.Activity/deleteGoods');
Route::post('manager/activity/goodsSkuDelete','manager.Activity/deleteGoodsSku');
Route::get('manager/activity/goodsInfo','manager.Activity/goodsInfo');
Route::get('manager/activity/goodsSkuInfo','manager.Activity/goodsSkuInfo');
Route::post('manager/activity/goodsSort','manager.Activity/updateGoodsSort');

//直播模块
Route::get('manager/live/syncRoomList','manager.Live/syncLiveRoom');
Route::get('manager/live/roomList','manager.Live/liveRoomList');
Route::get('manager/live/goodsList','manager.Live/goodsList');
Route::get('manager/live/syncGoodsList','manager.Live/syncGoods');
Route::post('manager/live/createGoods','manager.Live/createGoods');
Route::post('manager/live/updateGoods','manager.Live/updateGoods');
Route::post('manager/live/deleteGoods','manager.Live/deleteGoods');
Route::post('manager/live/resetAuditGoods','manager.Live/resetAuditGoods');
Route::post('manager/live/importLiveRoomGoods','manager.Live/importLiveRoomGoods');

//excel导出模块
Route::get('manager/excel/order','manager.Excel/order');
Route::get('manager/excel/divide','manager.Excel/divide');
Route::get('manager/excel/incomeList','manager.Excel/incomeList');
Route::get('manager/excel/withdraw','manager.Excel/withdraw');
Route::get('manager/excel/shipOrder','manager.Excel/orderList');

//商品标签模块
Route::get('manager/tag/list','manager.Tag/list');
Route::get('manager/tag/info','manager.Tag/info');
Route::post('manager/tag/create','manager.Tag/create');
Route::post('manager/tag/update','manager.Tag/update');
Route::get('manager/tag/delete','manager.Tag/delete');
Route::post('manager/tag/status','manager.Tag/upOrDown');

//系统配置
Route::get('manager/system/info','manager.System/info');
Route::post('manager/system/update','manager.System/update');

Route::miss(function() {
    return json(['error_code'=>-1,'msg'=>'不要猜啦~']);
});

