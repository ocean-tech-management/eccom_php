<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 后端模块路由配置]
// +----------------------------------------------------------------------


use think\facade\Route;

Route::get('admin/index/hello', 'admin.index/hello');
Route::get('admin/index/test2', 'admin.index/test2');
Route::get('admin/index/test3', 'admin.index/test3');
Route::get('admin/index/test4', 'admin.index/test4');
Route::get('admin/index/test5', 'admin.index/test5');
Route::get('admin/index/test6', 'admin.index/test6');
Route::get('admin/index/test7', 'admin.index/test7');
Route::any('admin/index/test', 'admin.index/test');
Route::get('admin/index/connect', 'admin.index/connect');
Route::get('admin/index/test8', 'admin.index/test8');
Route::get('admin/index/testCheck', 'admin.Test/testCheck');
Route::get('admin/index/testC', 'admin.index/testC');
Route::get('admin/index/testB', 'admin.index/testB');
Route::get('admin/index/testM', 'admin.index/testM');
Route::get('admin/index/sandDev', 'admin.index/sandDev');
Route::get('admin/index/periodDeal', 'admin.index/periodDeal');
Route::get('admin/index/reRunTeamDivide', 'admin.index/reRunTeamDivide');

//登录模块
Route::post('admin/login/login', 'admin.Login/login');
Route::post('admin/login/lecturer', 'admin.Login/lecturer');

//刷新token
Route::post('admin/token/refresh', 'admin.Login/refreshToken');

//分类模块
Route::get('admin/category/list', 'admin.Category/list');
Route::get('admin/category/info', 'admin.Category/info');
Route::post('admin/category/create', 'admin.Category/create');
Route::post('admin/category/update', 'admin.Category/update');
Route::get('admin/category/delete', 'admin.Category/delete');
Route::post('admin/category/status', 'admin.Category/upOrDown');
Route::post('admin/category/sort', 'admin.Category/updateSort');


//优惠券模块
Route::get('admin/coupon/list', 'admin.Coupon/list');
Route::get('admin/coupon/deleteList', 'admin.Coupon/deleteList');
Route::get('admin/coupon/info', 'admin.Coupon/info');
Route::post('admin/coupon/create', 'admin.Coupon/create');
Route::post('admin/coupon/update', 'admin.Coupon/update');
Route::post('admin/coupon/operation', 'admin.Coupon/operating');
Route::get('admin/coupon/used', 'admin.Coupon/usedList');
Route::get('admin/coupon/type', 'admin.Coupon/typeList');
Route::get('admin/coupon/userType', 'admin.Coupon/userTypeList');
Route::get('admin/coupon/cList', 'admin.Coupon/cList');
Route::post('admin/coupon/deliver', 'admin.Coupon/deliverCoupon');
Route::get('admin/coupon/deliverHistory', 'admin.Coupon/deliverHistory');
Route::post('admin/coupon/number', 'admin.Coupon/updateCouponNumber');
Route::get('admin/coupon/userCoupon', 'admin.Coupon/userCouponList');
Route::post('admin/coupon/compensate', 'admin.Coupon/compensateCoupon');
Route::post('admin/coupon/destroyUserCoupon', 'admin.Coupon/destroyUserCoupon');

//广告位模块
Route::get('admin/banner/list', 'admin.Banner/list');
Route::get('admin/banner/info', 'admin.Banner/info');
Route::post('admin/banner/create', 'admin.Banner/create');
Route::post('admin/banner/update', 'admin.Banner/update');
Route::get('admin/banner/delete', 'admin.Banner/delete');
Route::post('admin/banner/status', 'admin.Banner/upOrDown');

//屏幕广告位模块
Route::get('admin/screenBanner/list', 'admin.ScreenBanner/list');
Route::get('admin/screenBanner/info', 'admin.ScreenBanner/info');
Route::post('admin/screenBanner/create', 'admin.ScreenBanner/create');
Route::post('admin/screenBanner/update', 'admin.ScreenBanner/update');
Route::get('admin/screenBanner/delete', 'admin.ScreenBanner/delete');
Route::post('admin/screenBanner/status', 'admin.ScreenBanner/upOrDown');

//授权书模版模块
Route::get('admin/warrant/list', 'admin.Warrant/list');
Route::get('admin/warrant/info', 'admin.Warrant/info');
Route::post('admin/warrant/create', 'admin.Warrant/create');
Route::post('admin/warrant/update', 'admin.Warrant/update');
Route::get('admin/warrant/delete', 'admin.Warrant/delete');
Route::post('admin/warrant/status', 'admin.Warrant/upOrDown');

//商品模块
Route::get('admin/goods/list', 'admin.Goods/spuList');
Route::get('admin/goods/info', 'admin.Goods/info');
Route::post('admin/goods/create', 'admin.Goods/create');
Route::post('admin/goods/update', 'admin.Goods/update');
Route::get('admin/goods/delete', 'admin.Goods/delete');
Route::get('admin/goods/status', 'admin.Goods/status');
Route::post('admin/goods/sort', 'admin.Goods/updateSort');
Route::get('admin/goods/attrKey', 'admin.Goods/attrKey');
Route::get('admin/goods/attrValue', 'admin.Goods/attrValue');
Route::get('admin/goods/deleteSku', 'admin.Goods/deleteSku');
Route::get('admin/goods/deleteImages', 'admin.Goods/deleteImages');
Route::get('admin/goods/postageTemplates', 'admin.Goods/postageTemplate');
Route::get('admin/goods/saleInfo', 'admin.Goods/goodsSkuSaleInfo');
Route::post('admin/goods/updateStock', 'admin.Goods/updateStock');
Route::post('admin/goods/copy', 'admin.Goods/copyGoods');
Route::post('admin/goods/spuSaleList', 'admin.Goods/spuSaleList');
Route::post('admin/goods/spuSaleSummary', 'admin.Goods/spuSaleSummary');
Route::post('admin/goods/spuSaleSummary1', 'admin.Goods/spuSaleSummary');
Route::get('admin/goods/spuSaleList1', 'admin.Goods/spuSaleList');
Route::post('admin/goods/priceMaintain', 'admin.Goods/updateGoodsPriceMaintain');

//商品参数模版模块
Route::get('admin/goodsParams/list', 'admin.Goods/paramList');
Route::get('admin/goodsParams/info', 'admin.Goods/paramInfo');
Route::post('admin/goodsParams/create', 'admin.Goods/paramCreate');
Route::post('admin/goodsParams/update', 'admin.Goods/paramUpdate');
Route::get('admin/goodsParams/delete', 'admin.Goods/paramDelete');
Route::post('admin/goodsParams/status', 'admin.Goods/paramUpOrDown');

//文件模块
Route::post('admin/file/upload', 'admin.Upload/upload');
Route::post('admin/file/video', 'admin.Upload/uploadVideo');
Route::post('admin/file/app','admin.Upload/uploadApp');
Route::post('admin/file/create', 'admin.Upload/create');
Route::get('admin/file/read', 'admin.Upload/read');
Route::get('admin/file/video', 'admin.Upload/getVideoSignature');
Route::get('admin/file/update', 'admin.Upload/refreshVideoSignature');
Route::get('admin/file/config', 'admin.Upload/videoConfig');
Route::get('admin/file/source', 'admin.Upload/source');
Route::post('admin/file/transcode', 'admin.Upload/submitTranscode');
Route::get('admin/file/wxTemporaryMaterial', 'admin.Upload/WxTemporaryMaterial');
Route::post('admin/file/importExcel', 'admin.Upload/importExcel');


//课程属性
Route::get('admin/property/list', 'admin.Subject/subjectProperty');

//会员模块
Route::get('admin/vdc/list', 'admin.Member/vdcList');
Route::get('admin/teamVdc/list', 'admin.TeamDivide/vdcList');
Route::get('admin/shareholderVdc/list', 'admin.ShareholderDivide/vdcList');
Route::get('admin/areaVdc/list', 'admin.AreaDivide/vdcList');
Route::get('admin/expVdc/list', 'admin.ExpDivide/vdcList');
Route::get('admin/teamShareholderVdc/list', 'admin.ExpDivide/TeamShareholderVdcList');


//商品SPU模块
Route::get('admin/spu/list', 'admin.Goods/spuList');

//商品SKU模块
Route::get('admin/sku/list', 'admin.Goods/skuList');


//商品属性模块 --start--

//key
Route::get('admin/attr/key', 'admin.Attribute/keyList');
Route::post('admin/attr/keyCreate', 'admin.Attribute/keyCreate');
Route::post('admin/attr/keyUpdate', 'admin.Attribute/keyUpdate');
Route::get('admin/attr/keyDelete', 'admin.Attribute/keyDelete');
Route::get('admin/attr/keyStatus', 'admin.Attribute/keyStatus');
//value
Route::get('admin/attr/value', 'admin.Attribute/valueList');
Route::post('admin/attr/valueCreate', 'admin.Attribute/valueCreate');
Route::post('admin/attr/valueUpdate', 'admin.Attribute/valueUpdate');
Route::get('admin/attr/valueDelete', 'admin.Attribute/valueDelete');
Route::get('admin/attr/valueStatus', 'admin.Attribute/valueStatus');
//二合一
Route::post('admin/attr/create', 'admin.Attribute/create');

//商品属性模块 --end--

//订单模块
Route::rule('admin/order/list', 'admin.Order/list');
Route::get('admin/order/info', 'admin.Order/info');
Route::get('admin/order/memberList', 'admin.Order/memberList');
Route::post('admin/order/remark', 'admin.Order/orderRemark');
Route::get('admin/order/spuList', 'admin.Order/goodsSpu');
Route::get('admin/order/skuList', 'admin.Order/goodsSku');
Route::get('admin/order/correctionList', 'admin.Order/correctionList');
Route::post('admin/order/correctionCreate', 'admin.Order/correctionCreate');


//会员模块
Route::get('admin/member/list', 'admin.Member/list');
Route::post('admin/member/assign', 'admin.Member/assign');
Route::get('admin/member/info', 'admin.Member/info');
Route::get('admin/member/level', 'admin.Member/level');
Route::post('admin/member/revoke', 'admin.Member/revoke');
Route::post('admin/member/import', 'admin.Member/importMember');
Route::post('admin/member/importConfusion', 'admin.Member/importConfusionTeam');

//分销模块
Route::get('admin/divide/list', 'admin.Divide/ruleList');
Route::post('admin/divide/console', 'admin.Divide/console');
Route::get('admin/divide/info', 'admin.Divide/ruleInfo');
Route::post('admin/divide/create', 'admin.Divide/ruleCreate');
Route::post('admin/divide/update', 'admin.Divide/ruleUpdate');
Route::post('admin/divide/divideList', 'admin.Divide/list');
Route::get('admin/divide/divideDetail', 'admin.Divide/divideDetail');
Route::get('admin/divide/divideRecordList', 'admin.Divide/recordList');
Route::get('admin/divide/divideRecordDetail', 'admin.Divide/recordDetail');
Route::get('admin/divide/lecturerList', 'admin.Divide/lecturerList');
Route::get('admin/divide/stocksList', 'admin.Divide/stocksDivideList');


//用户模块
Route::get('admin/user/list', 'admin.User/list');
Route::get('admin/user/info', 'admin.User/info');
Route::get('admin/user/balance', 'admin.User/balance');
Route::post('admin/user/assign', 'admin.User/assign');
Route::get('admin/user/summary', 'admin.User/summary');
Route::get('admin/user/behavior', 'admin.User/behaviorList');
Route::post('admin/user/excelInfo', 'admin.Coupon/getUserInfoByExcel');
Route::get('admin/user/orderList', 'admin.Order/userSelfBuyOrder');
Route::get('admin/user/balanceSummary', 'admin.User/balanceSummary');
Route::get('admin/user/otherBalanceSummary', 'admin.User/userBalanceSummaryDetail');
Route::post('admin/user/banCrowdTransfer', 'admin.User/banUserCrowdTransfer');
Route::post('admin/user/banBuy', 'admin.User/banUserBuy');
Route::post('admin/user/removeThirdId', 'admin.User/removeKuaiShangThirdId');
Route::post('admin/user/updateUserPhone', 'admin.User/updateUserPhone');
Route::post('admin/user/clearUserSync', 'admin.User/clearUserSync');
Route::get('admin/user/banTransferUserList', 'admin.User/banTransferUserList');
Route::get('admin/user/banBuyUserList', 'admin.User/banBuyUserList');
Route::get('admin/user/withdrawDataPanel', 'admin.User/userWithdrawDataPanel');
Route::get('admin/user/linkRechargeList', 'admin.User/userLinkRechargeList');
Route::get('admin/user/clearUser','admin.User/clearUser');


//财务模块
Route::post('admin/finance/checkWithdraw', 'admin.Finance/CheckWithdraw');
Route::post('admin/finance/batchCheckWithdraw', 'admin.Finance/batchCheckWithdraw');
Route::get('admin/finance/withdrawList', 'admin.Finance/withdrawList');
Route::get('admin/finance/withdrawDetail', 'admin.Finance/withdrawDetail');
Route::get('admin/finance/withdrawSummary', 'admin.Finance/withdrawSummary');
Route::get('admin/finance/fundsWithdrawList', 'admin.Finance/fundsWithdrawList');
Route::post('admin/finance/newFundsWithdraw', 'admin.Finance/newFundsWithdraw');
Route::get('admin/finance/balanceSummary', 'admin.Summary/balanceSummary');
Route::post('admin/finance/rechargeCrowdBalance', 'admin.Finance/rechargeCrowdBalance');
Route::get('admin/finance/crowdBalanceList', 'admin.Finance/crowdBalanceList');
Route::post('admin/finance/rechargeHealthyBalance', 'admin.Finance/rechargeHealthyBalance');
Route::get('admin/finance/healthyBalanceList', 'admin.Finance/healthyBalanceList');
Route::get('admin/finance/rechargeRecordDetail', 'admin.Finance/rechargeRecordDetail');


//应用用户统计模块
Route::get('admin/analysis/coreData', 'admin.Analysis/coreData');


//后台数据·统计模块
Route::get('admin/summary/all', 'admin.Summary/adminSummary');
Route::get('admin/summary/goods', 'admin.Summary/goodsSummary');
Route::get('admin/summary/performance', 'admin.Summary/memberPerformance');
Route::get('admin/summary/sale', 'admin.Summary/saleSummary');
Route::get('admin/summary/summaryHard', 'admin.Summary/summaryHard');
Route::post('admin/summary/goodsSale', 'admin.Summary/goodsSale');


//品牌模块
Route::get('admin/brand/list', 'admin.Brand/list');
Route::get('admin/brand/info', 'admin.Brand/info');
Route::post('admin/brand/create', 'admin.Brand/create');
Route::post('admin/brand/update', 'admin.Brand/update');
Route::get('admin/brand/delete', 'admin.Brand/delete');
Route::post('admin/brand/status', 'admin.Brand/upOrDown');


//团队奖励模块
Route::get('admin/reward/list', 'admin.Reward/list');
Route::get('admin/reward/info', 'admin.Reward/info');
Route::post('admin/reward/create', 'admin.Reward/create');
Route::post('admin/reward/update', 'admin.Reward/update');
Route::get('admin/reward/delete', 'admin.Reward/delete');
Route::get('admin/reward/level', 'admin.Reward/level');


//快递物流模块
Route::get('admin/ship/company', 'admin.Ship/companyList');
Route::post('admin/ship/ship', 'admin.Ship/ship');
Route::post('admin/ship/noShippingCode', 'admin.Ship/noShippingCode');
Route::get('admin/ship/info', 'admin.Ship/info');
Route::get('admin/ship/summary', 'admin.Ship/summary');
Route::post('admin/ship/sync', 'admin.Ship/syncOrder');
Route::get('admin/ship/syncTime', 'admin.Ship/syncTime');
Route::post('admin/ship/orderList', 'admin.Ship/orderList');
Route::post('admin/ship/split', 'admin.Ship/splitOrder');
Route::post('admin/ship/merge', 'admin.Ship/mergeOrder');
Route::post('admin/ship/autoSplit', 'admin.Ship/autoSplitOrder');
Route::post('admin/ship/cancelSplit', 'admin.Ship/cancelSplit');
Route::post('admin/ship/cancelMerge', 'admin.Ship/cancelMerge');
Route::post('admin/ship/updateShip', 'admin.Ship/updateShip');
Route::post('admin/ship/updateAttach', 'admin.Ship/updateAttach');
Route::post('admin/ship/updateShipInfo', 'admin.Ship/updateShipInfo');
Route::post('admin/ship/city', 'admin.Ship/city');
Route::get('admin/ship/template', 'admin.Ship/postageTemplateList');
Route::get('admin/ship/templateInfo', 'admin.Ship/postageTemplateInfo');
Route::post('admin/ship/templateCreate', 'admin.Ship/postageTemplateCreate');
Route::post('admin/ship/templateUpdate', 'admin.Ship/postageTemplateUpdate');
Route::post('admin/ship/templateDelete', 'admin.Ship/postageTemplateDelete');
Route::post('admin/ship/templateDetailDelete', 'admin.Ship/postageDetailDelete');
Route::post('admin/ship/templateDefault', 'admin.Ship/setDefaultTemplate');
Route::post('admin/ship/orderSummary', 'admin.Ship/orderSummary');
Route::post('admin/ship/storing', 'admin.Ship/storing');

//售后模块
Route::get('admin/afterSale/list', 'admin.AfterSale/list');
Route::get('admin/afterSale/info', 'admin.AfterSale/info');
Route::post('admin/afterSale/verify', 'admin.AfterSale/verify');
Route::post('admin/afterSale/refund', 'admin.AfterSale/refund');
Route::post('admin/afterSale/fillInShip', 'admin.AfterSale/sellerFillInShip');
Route::post('admin/afterSale/confirm', 'admin.AfterSale/confirmReceiveGoods');
Route::post('admin/afterSale/refuse', 'admin.AfterSale/refuseReceiveGoods');
Route::post('admin/afterSale/userFillInShip', 'admin.AfterSale/helpUserFillInShip');
Route::post('admin/afterSale/confirmReceiveChangeGoods', 'admin.AfterSale/HelpUserConfirmReceiveChangeGoods');
Route::post('admin/afterSale/cancel', 'admin.AfterSale/systemCancelAfterSale');
Route::post('admin/afterSale/msg', 'admin.AfterSale/afterSaleMessage');
Route::post('admin/afterSale/open', 'admin.AfterSale/openAfterSaleAgain');
Route::get('admin/afterSale/correctionList', 'admin.Order/correctionList');
Route::post('admin/afterSale/correctionCreate', 'admin.Order/correctionCreate');
Route::post('admin/afterSale/initiateAfterSale','admin.AfterSale/HelpUserInitiateAfterSale');

//拼团模块
Route::get('admin/pt/list', 'admin.Activity/ptList');
Route::post('admin/pt/create', 'admin.Activity/ptCreate');
Route::get('admin/pt/info', 'admin.Activity/ptInfo');
Route::post('admin/pt/update', 'admin.Activity/ptUpdate');
Route::post('admin/pt/delete', 'admin.Activity/ptDelete');
Route::post('admin/pt/status', 'admin.Activity/ptUpOrDown');
Route::post('admin/pt/goodsUpdate', 'admin.Activity/createOrUpdatePtGoods');
Route::post('admin/pt/goodsDelete', 'admin.Activity/deletePtGoods');
Route::post('admin/pt/goodsSkuDelete', 'admin.Activity/deletePtGoodsSku');
Route::get('admin/pt/goodsInfo', 'admin.Activity/ptGoodsInfo');
Route::get('admin/pt/goodsSkuInfo', 'admin.Activity/ptGoodsSkuInfo');
Route::post('admin/pt/goodsSort', 'admin.Activity/updatePtGoodsSort');
Route::get('admin/pt/saleInfo', 'admin.Activity/ptGoodsSkuSaleInfo');
Route::post('admin/pt/updateStock', 'admin.Activity/updatePtStock');

//权限模块
Route::get('admin/auth/ruleList', 'admin.Auth/ruleList');
Route::post('admin/auth/ruleCreate', 'admin.Auth/ruleCreate');
Route::post('admin/auth/ruleUpdate', 'admin.Auth/ruleUpdate');
Route::post('admin/auth/ruleDelete', 'admin.Auth/ruleDelete');
Route::get('admin/auth/groupList', 'admin.Auth/groupList');
Route::get('admin/auth/groupInfo', 'admin.Auth/groupInfo');
Route::post('admin/auth/groupCreate', 'admin.Auth/groupCreate');
Route::post('admin/auth/groupUpdate', 'admin.Auth/groupUpdate');
Route::post('admin/auth/groupDelete', 'admin.Auth/groupDelete');

//管理员模块
Route::get('admin/manager/list', 'admin.Manager/adminUserList');
Route::post('admin/manager/create', 'admin.Manager/adminUserCreate');
Route::post('admin/manager/update', 'admin.Manager/adminUserUpdate');
Route::post('admin/manager/delete', 'admin.Manager/adminUserDelete');
Route::post('admin/manager/status', 'admin.Manager/adminUserStatus');
Route::post('admin/manager/updateUserGroup', 'admin.Manager/updateUserGroup');
Route::get('admin/manager/userRule', 'admin.Manager/userAuthList');
Route::post('admin/manager/updatePwd', 'admin.Manager/updateAdminUserPwd');
Route::post('admin/manager/updateMyPwd', 'admin.Manager/updateMyselfPwd');

//活动模块
Route::get('admin/activity/list', 'admin.Activity/list');
Route::get('admin/activity/specialPayList', 'admin.Activity/specialPayActivityList');
Route::get('admin/activity/info', 'admin.Activity/info');
Route::post('admin/activity/update', 'admin.Activity/update');
Route::post('admin/activity/status', 'admin.Activity/upOrDown');
Route::post('admin/activity/delete', 'admin.Activity/delete');
Route::post('admin/activity/goodsUpdate', 'admin.Activity/createOrUpdateGoods');
Route::post('admin/activity/goodsDelete', 'admin.Activity/deleteGoods');
Route::post('admin/activity/goodsSkuDelete', 'admin.Activity/deleteGoodsSku');
Route::get('admin/activity/goodsInfo', 'admin.Activity/goodsInfo');
Route::get('admin/activity/goodsSkuInfo', 'admin.Activity/goodsSkuInfo');
Route::post('admin/activity/goodsSort', 'admin.Activity/updateGoodsSort');
Route::post('admin/activity/goodsProgress', 'admin.Activity/updateActivityGoodsProgressBar');
Route::post('admin/activity/spuSaleNumber', 'admin.Activity/updateActivityGoodsSpuSaleNumber');
Route::get('admin/activity/all', 'admin.Activity/allActivity');

//直播模块
Route::get('admin/live/syncRoomList', 'admin.Live/syncLiveRoom');
Route::get('admin/live/roomList', 'admin.Live/liveRoomList');
Route::get('admin/live/goodsList', 'admin.Live/goodsList');
Route::get('admin/live/syncGoodsList', 'admin.Live/syncGoods');
Route::post('admin/live/createGoods', 'admin.Live/createGoods');
Route::post('admin/live/updateGoods', 'admin.Live/updateGoods');
Route::post('admin/live/deleteGoods', 'admin.Live/deleteGoods');
Route::post('admin/live/resetAuditGoods', 'admin.Live/resetAuditGoods');
Route::post('admin/live/importLiveRoomGoods', 'admin.Live/importLiveRoomGoods');
Route::post('admin/live/shareCode', 'admin.Live/liveRoomShareCode');
Route::post('admin/live/roomShowStatus', 'admin.Live/updateRoomShowStatus');

//excel导出模块
Route::rule('admin/excel/order', 'admin.Excel/order');
Route::get('admin/excel/divide', 'admin.Excel/divide');
Route::post('admin/excel/incomeList', 'admin.Excel/incomeList');
Route::rule('admin/excel/withdraw', 'admin.Excel/withdraw');
Route::rule('admin/excel/withdrawExport', 'admin.Excel/withdrawExport');
Route::rule('admin/excel/withdrawExportPwd', 'admin.Excel/withdrawExportPwd');
Route::rule('admin/excel/WithdrawExportList', 'admin.Excel/WithdrawExportList');
Route::rule('admin/excel/ExportField', 'admin.Excel/getExportField');
Route::rule('admin/excel/shipOrder', 'admin.Excel/orderList');
Route::get('admin/excel/performance', 'admin.Excel/memberPerformance');
Route::get('admin/excel/afterSale', 'admin.Excel/afterSaleList');
Route::get('admin/excel/userSelfBuyOrder', 'admin.Excel/userSelfBuyOrder');
Route::rule('admin/excel/ppylOrder', 'admin.Excel/ppylOrder');
Route::rule('admin/excel/ppylReward', 'admin.Excel/ppylReward');
Route::rule('admin/excel/spuList', 'admin.Excel/spuList');
Route::rule('admin/excel/propagandaRewardList', 'admin.Excel/propagandaRewardList');
Route::rule('admin/excel/userBalance', 'admin.Excel/userBalance');
Route::rule('admin/excel/userCrowdFundBalance', 'admin.Excel/userCrowdFundingBalance');
Route::rule('admin/excel/userStocks', 'admin.Excel/userStocksDivideList');

//商品标签模块
Route::get('admin/tag/list', 'admin.Tag/list');
Route::get('admin/tag/info', 'admin.Tag/info');
Route::post('admin/tag/create', 'admin.Tag/create');
Route::post('admin/tag/update', 'admin.Tag/update');
Route::get('admin/tag/delete', 'admin.Tag/delete');
Route::post('admin/tag/status', 'admin.Tag/upOrDown');

//系统配置
Route::get('admin/system/info', 'admin.System/info');
Route::post('admin/system/update', 'admin.System/update');
Route::get('admin/system/clearCache', 'admin.System/clearCache');
Route::get('admin/system/accessKeyList', 'admin.System/accessKeyList');
Route::get('admin/system/clientBackground', 'admin.System/clientBackground');

//供应商模块
Route::get('admin/supplier/list', 'admin.supplier/list');
Route::get('admin/supplier/info', 'admin.supplier/info');
Route::post('admin/supplier/create', 'admin.supplier/create');
Route::post('admin/supplier/update', 'admin.supplier/update');
Route::get('admin/supplier/delete', 'admin.supplier/delete');
Route::post('admin/supplier/status', 'admin.supplier/upOrDown');
Route::post('admin/supplier/payList', 'admin.supplier/payList');
Route::post('admin/supplier/payGoods', 'admin.supplier/payGoods');
Route::post('admin/supplier/afterPayRefund', 'admin.supplier/afterPayRefund');
Route::post('admin/supplier/payList1', 'admin.supplier/payList');

//成长值模块
Route::get('admin/growthValue/list', 'admin.GrowthValue/list');
Route::post('admin/growthValue/grant', 'admin.GrowthValue/grant');
Route::post('admin/growthValue/reduce', 'admin.GrowthValue/reduce');
Route::get('admin/growthValue/user', 'admin.GrowthValue/allUserList');

//N宫格模块
Route::get('admin/squareGrid/list', 'admin.SpecialActivity/squareGridList');
Route::post('admin/squareGrid/info', 'admin.SpecialActivity/squareGridInfo');
Route::get('admin/squareGrid/update', 'admin.SpecialActivity/squareGridUpdate');
Route::post('admin/squareGrid/status', 'admin.SpecialActivity/squareGridUpOrDown');

//品牌馆模块
Route::get('admin/brandSpace/list', 'admin.SpecialActivity/brandSpaceList');
Route::post('admin/brandSpace/create', 'admin.SpecialActivity/brandSpaceCreate');
Route::get('admin/brandSpace/info', 'admin.SpecialActivity/brandSpaceInfo');
Route::post('admin/brandSpace/update', 'admin.SpecialActivity/brandSpaceUpdate');
Route::post('admin/brandSpace/status', 'admin.SpecialActivity/brandSpaceUpOrDown');
Route::post('admin/brandSpace/delete', 'admin.SpecialActivity/brandSpaceDelete');
Route::post('admin/brandSpace/goodsUpdate', 'admin.SpecialActivity/brandSpaceCreateOrUpdateGoods');
Route::post('admin/brandSpace/goodsDelete', 'admin.SpecialActivity/brandSpaceDeleteGoods');
Route::post('admin/brandSpace/goodsSkuDelete', 'admin.SpecialActivity/brandSpaceDeleteGoodsSku');
Route::get('admin/brandSpace/goodsInfo', 'admin.SpecialActivity/brandSpaceGoodsInfo');
Route::get('admin/brandSpace/goodsSkuInfo', 'admin.SpecialActivity/brandSpaceGoodsSkuInfo');
Route::post('admin/brandSpace/goodsSort', 'admin.SpecialActivity/brandSpaceUpdateGoodsSort');

//限时专场模块
Route::get('admin/specialArea/list', 'admin.SpecialActivity/specialAreaList');
Route::post('admin/specialArea/create', 'admin.SpecialActivity/specialAreaCreate');
Route::get('admin/specialArea/info', 'admin.SpecialActivity/specialAreaInfo');
Route::post('admin/specialArea/update', 'admin.SpecialActivity/specialAreaUpdate');
Route::post('admin/specialArea/status', 'admin.SpecialActivity/specialAreaUpOrDown');
Route::post('admin/specialArea/delete', 'admin.SpecialActivity/specialAreaDelete');
Route::post('admin/specialArea/goodsUpdate', 'admin.SpecialActivity/specialAreaCreateOrUpdateGoods');
Route::post('admin/specialArea/goodsDelete', 'admin.SpecialActivity/specialAreaDeleteGoods');
Route::post('admin/specialArea/goodsSkuDelete', 'admin.SpecialActivity/specialAreaDeleteGoodsSku');
Route::get('admin/specialArea/goodsInfo', 'admin.SpecialActivity/specialAreaGoodsInfo');
Route::get('admin/specialArea/goodsSkuInfo', 'admin.SpecialActivity/specialAreaGoodsSkuInfo');
Route::post('admin/specialArea/goodsSort', 'admin.SpecialActivity/specialAreaUpdateGoodsSort');

//icon入口模块
Route::get('admin/entrance/list', 'admin.Entrance/list');
Route::get('admin/entrance/info', 'admin.Entrance/info');
Route::post('admin/entrance/create', 'admin.Entrance/create');
Route::post('admin/entrance/update', 'admin.Entrance/update');
Route::get('admin/entrance/delete', 'admin.Entrance/delete');
Route::post('admin/entrance/status', 'admin.Entrance/upOrDown');

//礼品模块
Route::get('admin/gift/batchList', 'admin.GiftCard/batchList');
Route::get('admin/gift/batchInfo', 'admin.GiftCard/batchInfo');
Route::post('admin/gift/batchCreate', 'admin.GiftCard/batchCreate');
Route::post('admin/gift/batchUpdate', 'admin.GiftCard/batchUpdate');
Route::post('admin/gift/batchDelete', 'admin.GiftCard/batchDelete');
Route::get('admin/gift/attrInfo', 'admin.GiftCard/attrInfo');
Route::post('admin/gift/attrCreate', 'admin.GiftCard/attrCreate');
Route::post('admin/gift/destroyCard', 'admin.GiftCard/destroyCard');
Route::get('admin/gift/cardList', 'admin.GiftCard/cardList');

//开放平台开发者管理模块
Route::get('admin/openDeveloper/list', 'admin.Banner/developerList');
Route::get('admin/openDeveloper/info', 'admin.Banner/developerInfo');
Route::post('admin/openDeveloper/create', 'admin.Banner/developerCreate');
Route::post('admin/openDeveloper/update', 'admin.Banner/developerUpdate');
Route::get('admin/openDeveloper/delete', 'admin.Banner/developerDelete');
Route::post('admin/openDeveloper/status', 'admin.Banner/developerUpOrDown');

//商品系统评价(口碑评价)模块
Route::get('admin/reputation/list', 'admin.Reputation/list');
Route::get('admin/reputation/info', 'admin.Reputation/info');
Route::post('admin/reputation/create', 'admin.Reputation/create');
Route::post('admin/reputation/update', 'admin.Reputation/update');
Route::get('admin/reputation/delete', 'admin.Reputation/delete');
Route::post('admin/reputation/status', 'admin.Reputation/status');
Route::post('admin/reputation/top', 'admin.Reputation/top');
Route::post('admin/reputation/featured', 'admin.Reputation/featured');
Route::post('admin/reputation/sort', 'admin.Reputation/sort');
Route::post('admin/reputation/imagesDelete', 'admin.Reputation/imagesDelete');
Route::post('admin/reputation/check', 'admin.Reputation/check');

//商品系统评价官(口碑评价官)模块
Route::get('admin/reputation/userList', 'admin.Reputation/userList');
Route::get('admin/reputation/userInfo', 'admin.Reputation/userInfo');
Route::post('admin/reputation/userCreate', 'admin.Reputation/userCreate');
Route::post('admin/reputation/userUpdate', 'admin.Reputation/userUpdate');
Route::get('admin/reputation/userDelete', 'admin.Reputation/userDelete');
Route::post('admin/reputation/userStatus', 'admin.Reputation/userStatus');

//拼拼有礼模块
Route::rule('admin/ppyl/list', 'admin.Ppyl/ptList');
Route::post('admin/ppyl/create', 'admin.Ppyl/ptCreate');
Route::get('admin/ppyl/info', 'admin.Ppyl/ptInfo');
Route::post('admin/ppyl/update', 'admin.Ppyl/ptUpdate');
Route::post('admin/ppyl/delete', 'admin.Ppyl/ptDelete');
Route::post('admin/ppyl/status', 'admin.Ppyl/ptUpOrDown');
Route::get('admin/ppylArea/list', 'admin.Ppyl/ptAreaList');
Route::post('admin/ppylArea/create', 'admin.Ppyl/ptAreaCreate');
Route::get('admin/ppylArea/info', 'admin.Ppyl/ptAreaInfo');
Route::post('admin/ppylArea/update', 'admin.Ppyl/ptAreaUpdate');
Route::post('admin/ppylArea/delete', 'admin.Ppyl/ptAreaDelete');
Route::post('admin/ppylArea/status', 'admin.Ppyl/updatePtAreaGoodsSort');
Route::post('admin/ppyl/goodsUpdate', 'admin.Ppyl/createOrUpdatePtGoods');
Route::post('admin/ppyl/goodsDelete', 'admin.Ppyl/deletePtGoods');
Route::post('admin/ppyl/goodsSkuDelete', 'admin.Ppyl/deletePtGoodsSku');
Route::get('admin/ppyl/goodsInfo', 'admin.Ppyl/ptGoodsInfo');
Route::get('admin/ppyl/goodsSkuInfo', 'admin.Ppyl/ptGoodsSkuInfo');
Route::post('admin/ppyl/goodsSort', 'admin.Ppyl/updatePtGoodsSort');
Route::get('admin/ppyl/saleInfo', 'admin.Ppyl/ptGoodsSkuSaleInfo');
Route::post('admin/ppyl/updateStock', 'admin.Ppyl/updatePtStock');
Route::rule('admin/ppyl/order', 'admin.Ppyl/ppylOrder');
Route::rule('admin/ppyl/waitOrder', 'admin.Ppyl/ppylWaitOrder');
Route::rule('admin/ppyl/reward', 'admin.Ppyl/ppylReward');
Route::get('admin/ppyl/rewardDetail', 'admin.Ppyl/rewardDetail');
Route::get('admin/ppyl/userList', 'admin.Ppyl/userList');
Route::post('admin/ppyl/repurchase', 'admin.Ppyl/updateUserRepurchase');
Route::get('admin/ppyl/userRepurchase', 'admin.Ppyl/userRepurchaseList');

//拼拼有礼营销规则模块
Route::get('admin/ppylMember/list', 'admin.Ppyl/ruleList');
Route::get('admin/ppylMember/info', 'admin.Ppyl/ruleInfo');
Route::post('admin/ppylMember/create', 'admin.Ppyl/ruleCreate');
Route::post('admin/ppylMember/update', 'admin.Ppyl/ruleUpdate');

Route::get('admin/ppylMember/vdcList', 'admin.Ppyl/vdcList');

//拼拼有礼CVIP规则模块
Route::get('admin/ppylCVIP/list', 'admin.Ppyl/CVIPList');
Route::get('admin/ppylCVIP/info', 'admin.Ppyl/CVIPInfo');
Route::post('admin/ppylCVIP/create', 'admin.Ppyl/CVIPCreate');
Route::post('admin/ppylCVIP/update', 'admin.Ppyl/CVIPUpdate');
Route::post('admin/ppylCVIP/status', 'admin.Ppyl/CVIPUpOrDown');
Route::post('admin/ppylCVIP/delete', 'admin.Ppyl/CVIPDelete');
Route::post('admin/ppylCVIP/assign', 'admin.Ppyl/assignCVIP');

//拼拼有礼活动广告位模块
Route::get('admin/ppylBanner/list', 'admin.PpylBanner/list');
Route::get('admin/ppylBanner/info', 'admin.PpylBanner/info');
Route::post('admin/ppylBanner/create', 'admin.PpylBanner/create');
Route::post('admin/ppylBanner/update', 'admin.PpylBanner/update');
Route::get('admin/ppylBanner/delete', 'admin.PpylBanner/delete');
Route::post('admin/ppylBanner/status', 'admin.PpylBanner/upOrDown');

//拼拼有礼配置
Route::post('admin/ppylConfig/update', 'admin.Ppyl/updateConfig');
Route::get('admin/ppylConfig/info', 'admin.Ppyl/configInfo');

//广宣奖规则模块
Route::get('admin/propagandaRewardRule/list', 'admin.Bonus/propagandaRewardRuleList');
Route::get('admin/propagandaRewardRule/info', 'admin.Bonus/propagandaRewardRuleInfo');
Route::post('admin/propagandaRewardRule/create', 'admin.Bonus/propagandaRewardRuleCreate');
Route::post('admin/propagandaRewardRule/update', 'admin.Bonus/propagandaRewardRuleUpdate');

//广宣奖计划模块
Route::get('admin/propagandaRewardPlan/list', 'admin.Bonus/propagandaRewardPlanList');
Route::get('admin/propagandaRewardPlan/info', 'admin.Bonus/propagandaRewardPlanInfo');
Route::post('admin/propagandaRewardPlan/create', 'admin.Bonus/propagandaRewardPlanCreate');
Route::post('admin/propagandaRewardPlan/update', 'admin.Bonus/propagandaRewardPlanUpdate');
Route::post('admin/propagandaRewardPlan/delete', 'admin.Bonus/propagandaRewardPlanDelete');
Route::get('admin/propagandaReward/rewardList', 'admin.Bonus/planRewardList');
Route::get('admin/propagandaReward/userList', 'admin.Bonus/showRewardUserForPlan');
Route::post('admin/propagandaReward/grant', 'admin.Bonus/propagandaReward');

//赠送套餐模块
Route::get('admin/handselStand/abnormalList', 'admin.handselStand/abnormalList');
Route::post('admin/handselStand/abnormalOperate', 'admin.handselStand/abnormalOperate');

//团队业绩奖励规则模块
Route::get('admin/teamDivide/list', 'admin.TeamDivide/ruleList');
Route::post('admin/teamDivide/console', 'admin.TeamDivide/console');
Route::get('admin/teamDivide/info', 'admin.TeamDivide/ruleInfo');
Route::post('admin/teamDivide/create', 'admin.TeamDivide/ruleCreate');
Route::post('admin/teamDivide/update', 'admin.TeamDivide/ruleUpdate');

//团队会员模块
Route::get('admin/teamMember/list', 'admin.TeamDivide/memberList');
Route::get('admin/teamMember/level', 'admin.TeamDivide/memberLevel');
Route::post('admin/teamMember/assign', 'admin.TeamDivide/assign');
//Route::post('admin/teamMember/toggleExp','admin.TeamDivide/assignToggleExp');


//股东奖规则模块
Route::get('admin/shareholderRewardRule/list', 'admin.Bonus/shareholderRewardRuleList');
Route::get('admin/shareholderRewardRule/info', 'admin.Bonus/shareholderRewardRuleInfo');
Route::post('admin/shareholderRewardRule/create', 'admin.Bonus/shareholderRewardRuleCreate');
Route::post('admin/shareholderRewardRule/update', 'admin.Bonus/shareholderRewardRuleUpdate');

//股东奖计划模块
Route::get('admin/shareholderRewardPlan/list', 'admin.Bonus/shareholderRewardPlanList');
Route::get('admin/shareholderRewardPlan/info', 'admin.Bonus/shareholderRewardPlanInfo');
Route::post('admin/shareholderRewardPlan/create', 'admin.Bonus/shareholderRewardPlanCreate');
Route::post('admin/shareholderRewardPlan/update', 'admin.Bonus/shareholderRewardPlanUpdate');
Route::post('admin/shareholderRewardPlan/delete', 'admin.Bonus/shareholderRewardPlanDelete');
Route::get('admin/shareholderReward/rewardList', 'admin.Bonus/shareholderPlanRewardList');
Route::get('admin/shareholderReward/userList', 'admin.Bonus/showShareholderRewardUserForPlan');
Route::post('admin/shareholderReward/grant', 'admin.Bonus/shareholderReward');
Route::get('admin/shareholderMember/list', 'admin.Bonus/shareholderMemberList');
Route::post('admin/shareholderMember/assign', 'admin.Bonus/assignShareholderMember');

//股东会员规则模块
Route::get('admin/shareholderDivide/list', 'admin.ShareholderDivide/ruleList');
Route::post('admin/shareholderDivide/console', 'admin.ShareholderDivide/console');
Route::get('admin/shareholderDivide/info', 'admin.ShareholderDivide/ruleInfo');
Route::post('admin/shareholderDivide/create', 'admin.ShareholderDivide/ruleCreate');
Route::post('admin/shareholderDivide/update', 'admin.ShareholderDivide/ruleUpdate');

//区域代理奖励规则模块
Route::get('admin/areaDivide/list', 'admin.AreaDivide/ruleList');
Route::get('admin/areaDivide/info', 'admin.AreaDivide/ruleInfo');
Route::post('admin/areaDivide/create', 'admin.AreaDivide/ruleCreate');
Route::post('admin/areaDivide/update', 'admin.AreaDivide/ruleUpdate');

//区域代理会员模块
Route::get('admin/areaMember/list', 'admin.AreaDivide/memberList');
Route::get('admin/areaMember/level', 'admin.AreaDivide/memberLevel');
Route::post('admin/areaMember/assign', 'admin.AreaDivide/assign');

//众筹活动模块
Route::get('admin/crowdFunding/activityList', 'admin.CrowdFund/activityList');
Route::get('admin/crowdFunding/activityInfo', 'admin.CrowdFund/activityInfo');
Route::post('admin/crowdFunding/activityCreate', 'admin.CrowdFund/activityCreate');
Route::post('admin/crowdFunding/activityUpdate', 'admin.CrowdFund/activityUpdate');
Route::post('admin/crowdFunding/activityStatus', 'admin.CrowdFund/activityUpOrDown');
Route::post('admin/crowdFunding/activityDelete', 'admin.CrowdFund/activityDelete');
Route::get('admin/crowdFunding/periodList', 'admin.CrowdFund/periodList');
Route::get('admin/crowdFunding/periodInfo', 'admin.CrowdFund/periodInfo');
Route::post('admin/crowdFunding/periodCreate', 'admin.CrowdFund/periodCreate');
Route::post('admin/crowdFunding/periodUpdate', 'admin.CrowdFund/periodUpdate');
Route::post('admin/crowdFunding/periodStatus', 'admin.CrowdFund/periodUpOrDown');
Route::post('admin/crowdFunding/periodDelete', 'admin.CrowdFund/periodDelete');
Route::post('admin/crowdFunding/updateSalesPrice', 'admin.CrowdFund/updateSalesPrice');
Route::post('admin/crowdFunding/updateCondition', 'admin.CrowdFund/updateCondition');
Route::post('admin/crowdFunding/complete', 'admin.CrowdFund/completePeriod');
Route::post('admin/crowdFunding/completeByOrder', 'admin.CrowdFund/completePeriodByOrder');
Route::post('admin/crowdFunding/periodGoodsUpdate', 'admin.CrowdFund/periodCreateOrUpdateGoods');
Route::post('admin/crowdFunding/periodGoodsDelete', 'admin.CrowdFund/periodDeleteGoods');
Route::post('admin/crowdFunding/periodGoodsSkuDelete', 'admin.CrowdFund/periodDeleteGoodsSku');
Route::get('admin/crowdFunding/periodGoodsInfo', 'admin.CrowdFund/periodGoodsInfo');
Route::get('admin/crowdFunding/periodGoodsSkuInfo', 'admin.CrowdFund/periodGoodsSkuInfo');
Route::post('admin/crowdFunding/periodGoodsSort', 'admin.CrowdFund/periodUpdateGoodsSort');
Route::get('admin/crowdFunding/balance', 'admin.CrowdFund/crowdFundingBalance');
Route::get('admin/crowdFunding/withdrawCustomList', 'admin.CrowdFund/crowdfundingWithdrawCustom');
Route::post('admin/crowdFunding/createWithdrawCustom', 'admin.CrowdFund/createWithdrawCustom');
Route::get('admin/crowdFunding/freezeCustomList', 'admin.CrowdFund/crowdfundingFreezeCustom');
Route::post('admin/crowdFunding/createFreezeCustom', 'admin.CrowdFund/createFreezeCustom');
Route::post('admin/crowdFunding/orderList', 'admin.CrowdFund/orderList');
Route::get('admin/crowdFunding/crowdBalanceSummary', 'admin.CrowdFund/userCrowdBalanceSummaryList');
Route::get('admin/crowdFunding/sear', 'admin.CrowdFund/crowdfundingSear');

//众筹时间段
Route::get('admin/crowdFunding/durationList', 'admin.CrowdFund/periodDurationList');
Route::post('admin/crowdFunding/durationUpdate', 'admin.CrowdFund/periodDurationNewOrUpdate');
Route::post('admin/crowdFunding/durationDelete', 'admin.CrowdFund/periodDurationDelete');

//众筹配置
Route::get('admin/crowdFunding/configInfo', 'admin.CrowdFund/configInfo');
Route::post('admin/crowdFunding/configUpdate', 'admin.CrowdFund/configUpdate');

//众筹抽奖
Route::get('admin/crowdFundingLottery/list', 'admin.CrowdFund/lotteryList');
Route::get('admin/crowdFundingLottery/info', 'admin.CrowdFund/lotteryInfo');
Route::post('admin/crowdFundingLottery/create', 'admin.CrowdFund/lotteryCreate');
Route::post('admin/crowdFundingLottery/update', 'admin.CrowdFund/lotteryUpdate');
Route::post('admin/crowdFundingLottery/delete', 'admin.CrowdFund/lotteryDelete');
Route::get('admin/crowdFundingLottery/winInfo', 'admin.CrowdFund/lotteryWinInfo');

//线下充值
Route::get('admin/offlineRecharge/list', 'admin.CrowdFund/offlineRechargeList');
Route::post('admin/offlineRecharge/check', 'admin.CrowdFund/offlineRechargeCheck');
Route::post('admin/offlineRecharge/grant', 'admin.CrowdFund/offlineRechargeGrantPrice');
Route::post('admin/offlineRecharge/configUpdate', 'admin.CrowdFund/offlineConfigUpdate');
Route::get('admin/offlineRecharge/configInfo', 'admin.CrowdFund/offlineConfigInfo');

//修改用户登录密码/支付密码
Route::get('admin/manager/updateUserPwdList', 'admin.User/updateUserPwdList');
Route::post('admin/manager/updateUserPwd', 'admin.User/updateUserPwd');

//体验中心
Route::get('admin/exp/list', 'admin.User/expUserList');
Route::post('admin/exp/assign', 'admin.User/assignToggleExp');

//团队股东列表
Route::get('admin/teamShareholder/list', 'admin.User/teamShareholderUserList');
Route::post('admin/teamShareholder/assign', 'admin.User/assignToggleTeamShareholder');

//设备列表
Route::get('admin/device/list', 'admin.Device/list');
Route::get('admin/device/info', 'admin.Device/info');
Route::post('admin/device/create', 'admin.Device/create');
Route::post('admin/device/update', 'admin.Device/update');
Route::post('admin/device/delete', 'admin.Device/delete');
Route::post('admin/device/status', 'admin.Device/status');
Route::post('admin/device/deviceStatus', 'admin.Device/deviceStatus');
Route::get('admin/device/deviceUser', 'admin.Device/areaOrToggleUserList');
Route::get('admin/device/orderList', 'admin.Device/deviceOrder');
Route::post('admin/device/complete', 'admin.Device/completeDivideOrder');
Route::get('admin/device/divide', 'admin.Device/deviceDivideList');
Route::post('admin/device/QrCode', 'admin.Device/buildQrCode');

Route::get('admin/device/comboList', 'admin.Device/comboList');
Route::get('admin/device/comboInfo', 'admin.Device/comboInfo');
Route::post('admin/device/comboUpdate', 'admin.Device/comboUpdate');
Route::post('admin/device/comboDelete', 'admin.Device/comboDelete');
Route::post('admin/device/power', 'admin.Device/operDevicePower');

//兑换商品
Route::post('admin/exchange/goodsUpdate', 'admin.Exchange/createOrUpdateGoods');
Route::post('admin/exchange/goodsDelete', 'admin.Exchange/deleteGoods');
Route::post('admin/exchange/goodsSkuDelete', 'admin.Exchange/deleteGoodsSku');
Route::get('admin/exchange/goodsList', 'admin.Exchange/goodsList');
Route::get('admin/exchange/goodsSkuInfo', 'admin.Exchange/goodsSkuInfo');
Route::post('admin/exchange/goodsSort', 'admin.Exchange/updateGoodsSort');
Route::post('admin/exchange/updateStock', 'admin.Exchange/updateStock');


//乐小活模块
Route::get('admin/letfree/exemptUserList', 'admin.LetFree/exemptUserList');
Route::post('admin/letfree/exemptUserCreate', 'admin.LetFree/exemptUserCreate');
Route::post('admin/letfree/exemptUserDelete', 'admin.LetFree/exemptUserDelete');

//体验中心分润规则模块
Route::get('admin/expDivide/list', 'admin.ExpDivide/ruleList');
Route::get('admin/expDivide/info', 'admin.ExpDivide/ruleInfo');
Route::post('admin/expDivide/create', 'admin.ExpDivide/ruleCreate');
Route::post('admin/expDivide/update', 'admin.ExpDivide/ruleUpdate');


//用户额外提现额度模块
Route::get('admin/userExtra/list', 'admin.UserExtraWithdraw/list');
Route::get('admin/userExtra/info', 'admin.UserExtraWithdraw/info');
Route::post('admin/userExtra/create', 'admin.UserExtraWithdraw/create');
Route::post('admin/userExtra/update', 'admin.UserExtraWithdraw/update');
Route::post('admin/userExtra/delete', 'admin.UserExtraWithdraw/delete');

//团队充值记录列表
Route::get('admin/rechargeRecord/list', 'admin.Finance/rechargeRecordDetail');
Route::get('admin/rechargeRecord/rate', 'admin.Finance/userRechargeRate');

//用户授权管理模块
Route::get('admin/userAuthType/list', 'admin.UserAuthType/list');
Route::get('admin/userAuthType/allList', 'admin.UserAuthType/allList');
Route::get('admin/userAuthClear/list', 'admin.userAuthClear/list');
Route::post('admin/userAuthClear/create', 'admin.UserAuthClear/create');

//积分(美丽豆明细)
Route::get('admin/integral/balance', 'admin.User/integralList');
Route::get('admin/integral/rechargeList', 'admin.Integral/rechargeIntegralList');
Route::post('admin/integral/recharge', 'admin.Integral/rechargeIntegralBalance');

//美丽券明细
Route::get('admin/ticket/balance', 'admin.User/ticketList');

//健康豆明细
Route::get('admin/healthy/balance', 'admin.User/healthyBalanceList');

//健康豆转出明细
Route::get('admin/healthy/hbConverList', 'admin.Finance/healthyBalanceConverList');
Route::get('admin/healthy/hbConverAmount', 'admin.Finance/healthyBalanceAmount');

//美丽卡
Route::get('admin/advance/balance', 'admin.User/advanceCardList');
Route::post('admin/advance/recharge', 'admin.User/rechargeAdvanceCard');
Route::get('admin/advance/rechargeList', 'admin.User/advanceCardRechargeList');

//用户余额限制模块
Route::get('admin/userBalanceLimit/list', 'admin.userBalanceLimit/list');
Route::get('admin/userBalanceLimit/info', 'admin.userBalanceLimit/info');
Route::post('admin/userBalanceLimit/create', 'admin.userBalanceLimit/create');
Route::post('admin/userBalanceLimit/update', 'admin.userBalanceLimit/update');
Route::post('admin/userBalanceLimit/delete', 'admin.userBalanceLimit/delete');

//APP版本模块
Route::get('admin/appVersion/list','admin.AppVersion/list');
Route::get('admin/appVersion/info','admin.AppVersion/info');
Route::post('admin/appVersion/create','admin.AppVersion/create');
Route::post('admin/appVersion/update','admin.AppVersion/update');
Route::get('admin/appVersion/release','admin.AppVersion/release');
Route::get('admin/appVersion/delete','admin.AppVersion/delete');
Route::post('admin/appVersion/status','admin.AppVersion/upOrDown');

//用户签约模块
Route::get('admin/userAgreement/list','admin.UserAgreement/list');
Route::get('admin/userAgreement/info','admin.UserAgreement/info');

//协议模块
Route::get('admin/agreement/list', 'admin.Agreement/list');
Route::get('admin/agreement/info', 'admin.Agreement/info');
Route::post('admin/agreement/create', 'admin.Agreement/create');
Route::post('admin/agreement/update', 'admin.Agreement/update');
Route::post('admin/agreement/delete', 'admin.Agreement/delete');
Route::post('admin/agreement/status', 'admin.Agreement/upOrDown');

//冻结福利金汇总列表
Route::get('admin/crowdFuse/balance', 'admin.User/userFuseCrowdBalanceList');
Route::miss(function() {
    return json(['error_code'=>-1,'msg'=>'不要猜啦~']);
});
