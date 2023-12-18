<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 前端模块路由配置]
// +----------------------------------------------------------------------


use think\facade\Route;
Route::group(function () {
    Route::get('api/<version>/qiu/index','api.<version>.Index/index');
    Route::get('api/<version>/qiu/test','api.<version>.Index/test');

    //登录模块
    Route::get('api/<version>/login/login','api.<version>.Login/login');
    Route::get('api/<version>/login/code','api.<version>.Login/code');
    Route::post('api/<version>/token/refresh', 'api.<version>.Login/refreshToken');
    Route::post('api/<version>/token/new', 'api.<version>.Login/buildToken');

    //用户模块
    Route::get('api/<version>/user/info','api.<version>.User/info');
    Route::post('api/<version>/user/update','api.<version>.User/update');
    Route::get('api/<version>/user/coupon','api.<version>.User/coupon');
    Route::get('api/<version>/user/user','api.<version>.User/user');
    Route::get('api/<version>/user/order','api.<version>.User/order');
    Route::get('api/<version>/user/orderSummary','api.<version>.User/orderSummary');
    Route::get('api/<version>/user/teamOrder','api.<version>.User/teamDivideOrder');
    Route::get('api/<version>/user/teamOrderCount','api.<version>.User/teamDivideOrderCount');
    //Route::get('api/<version>/user/teamOrderCount','api.<version>.User/teamAllOrderCount');
    Route::get('api/<version>/user/teamAllOrder','api.<version>.User/teamAllOrder');
    Route::get('api/<version>/user/teamAllOrderCount','api.<version>.User/teamAllOrderCount');
    Route::get('api/<version>/user/collection','api.<version>.Collection/list');
    Route::post('api/<version>/user/bind','api.<version>.User/bindSuperiorUser');
    Route::get('api/<version>/user/integral','api.<version>.User/integralList');
    Route::get('api/<version>/user/integralIncome','api.<version>.User/integralIncome');
    Route::get('api/<version>/user/ticket','api.<version>.User/ticketList');
    Route::get('api/<version>/user/ticketIncome','api.<version>.User/ticketIncome');
    Route::get('api/<version>/user/balance','api.<version>.User/balanceLit');
    Route::get('api/<version>/user/team','api.<version>.User/team');
    Route::post('api/<version>/user/withdraw','api.<version>.User/withdraw');
    Route::get('api/<version>/user/withdrawList','api.<version>.User/withdrawList');
    Route::get('api/<version>/user/QrCode','api.<version>.User/QrCode');
    Route::get('api/<version>/user/divide','api.<version>.User/divideList');
    Route::post('api/<version>/user/sync','api.<version>.User/syncOldUser');
    Route::get('api/<version>/user/growthValue','api.<version>.User/growthValueList');
    Route::get('api/<version>/user/teamMemberOrder','api.<version>.User/teamMemberOrder');
    Route::get('api/<version>/user/teamDirectNormalUser','api.<version>.User/teamDirectNormalUser');
    Route::get('api/<version>/user/teamDirectNormalUserCount','api.<version>.User/teamDirectNormalUserSummary');
    Route::get('api/<version>/user/handselList','api.<version>.User/handselList');
    Route::get('api/<version>/user/bankCardList','api.<version>.User/userBankCardList');
    Route::post('api/<version>/user/updateNicknameOrAvatarUrl','api.<version>.User/updateNicknameOrAvatarUrl');
    Route::post('api/<version>/user/syncOtherAppUser','api.<version>.User/syncOtherAppUser');
    Route::get('api/<version>/user/subordinateRechargeRate','api.<version>.User/userSubordinateRechargeRate');
    Route::get('api/<version>/user/withdrawDataPanel','api.<version>.User/userWithdrawDataPanel');
    Route::get('api/<version>/user/historyWithdrawAndRecharge','api.<version>.User/userHisWithdrawAndRecharge');
    Route::get('api/<version>/user/rechargeList','api.<version>.User/userRechargeList');
    Route::get('api/<version>/user/transferList','api.<version>.User/userTransferList');

    //用户登录注册模块
    Route::post('api/<version>/wx/webLogin','api.<version>.Wx/webLogin');
    Route::post('api/<version>/wx/webRegister','api.<version>.Wx/webRegister');

    //banner模块
    Route::get('api/<version>/banner/list','api.<version>.Banner/list');

    //授权书模版模块
    Route::get('api/<version>/warrant/list','api.<version>.Warrant/list');
    Route::get('api/<version>/warrant/user','api.<version>.Warrant/userInfo');

    //微信模块
    Route::post('api/<version>/wx/config','api.<version>.Wx/config');
    Route::post('api/<version>/wx/auth','api.<version>.Wx/auth');
    Route::post('api/<version>/wx/crypt','api.<version>.Wx/crypt');
    Route::post('api/<version>/wx/authNew','api.<version>.Wx/authNew');
    Route::any('api/<version>/wx/checkUser','api.<version>.Wx/getUserByOpenid');
    Route::post('api/<version>/wx/authPublic','api.<version>.Wx/authForWxPublic');
    Route::post('api/<version>/wx/newAuthPublic','api.<version>.Wx/authForWxPublicV2');

    //首页模块
    Route::get('api/<version>/home/top','api.<version>.Home/topList');
    Route::get('api/<version>/home/middle','api.<version>.Home/subjectList');
    Route::get('api/<version>/home/hot','api.<version>.Home/hotSaleList');
    Route::get('api/<version>/home/list','api.<version>.Home/list');
    Route::get('api/<version>/home/activityInfo','api.<version>.Home/activityInfo');
    Route::get('api/<version>/home/memberActivity','api.<version>.Home/memberActivityList');
    Route::get('api/<version>/home/userTypeList','api.<version>.Home/userTypeList');
    Route::post('api/<version>/home/wxCode','api.<version>.Home/getWxaCode');
    Route::post('api/<version>/home/QrCode','api.<version>.Home/buildQrCode');
    Route::get('api/<version>/home/entrance','api.<version>.Home/entrance');
    Route::get('api/<version>/home/merge','api.<version>.Home/merge');
    Route::post('api/<version>/home/newDynamicParams','api.<version>.Home/newDynamicParams');
    Route::post('api/<version>/home/getDynamicParams','api.<version>.Home/getDynamicParams');
    Route::get('api/<version>/home/exchangeList','api.<version>.Home/exchangeList');
    Route::get('api/<version>/home/specialPayList','api.<version>.Home/specialPayActivityList');

    //授权模块
    Route::post('api/<version>/authPlayForm/loginType','api.<version>.AuthPlatForm/userLoginType');

    //测试模块
    Route::get('api/<version>/test/test5','api.<version>.test/test5');


    //优惠券模块
    Route::get('api/<version>/coupon/list','api.<version>.Coupon/list');
    Route::get('api/<version>/coupon/info','api.<version>.Coupon/info');
    Route::post('api/<version>/coupon/receive','api.<version>.Coupon/receive');
    Route::get('api/<version>/coupon/goods','api.<version>.Coupon/goodsInfoCoupon');

    //分类模块
    Route::get('api/<version>/category/list','api.<version>.Category/list');

    //订单模块
    Route::post('api/<version>/order/create','api.<version>.Order/create');
    Route::post('api/<version>/order/coupon','api.<version>.Order/coupon');
    Route::post('api/<version>/order/ready','api.<version>.Order/readyOrder');
    Route::get('api/<version>/order/buyed','api.<version>.Order/buySubject');
    Route::get('api/<version>/order/info','api.<version>.Order/info');
    Route::post('api/<version>/order/task','api.<version>.Order/taskOrder');
    Route::post('api/<version>/order/cancel','api.<version>.Order/cancelPay');
    Route::post('api/<version>/order/again','api.<version>.Order/orderPayAgain');
    Route::post('api/<version>/order/confirm','api.<version>.Order/confirm');
    Route::get('api/<version>/order/receipt','api.<version>.Order/receipt');

    //会员模块
    Route::post('api/<version>/member/order','api.<version>.Member/becomeMember');
    Route::get('api/<version>/member/title','api.<version>.Member/memberName');
    Route::get('api/<version>/teamMember/title', 'api.<version>.TeamMember/memberName');

    //收藏模块
    Route::get('api/<version>/collection/list','api.<version>.Collection/list');
    Route::post('api/<version>/collection/update','api.<version>.Collection/update');

    //商品模块
    Route::get('api/<version>/goods/list','api.<version>.Goods/list');
    Route::get('api/<version>/goods/info','api.<version>.Goods/info');
    Route::get('api/<version>/goods/other','api.<version>.Goods/otherGoodsList');
    Route::get('api/<version>/goods/reputation','api.<version>.Goods/reputation');
    Route::get('api/<version>/goods/recommendGoods','api.<version>.Goods/otherGoodsListInUserCenter');

    //收货地址模块
    Route::get('api/<version>/address/list','api.<version>.ShippingAddress/list');
    Route::get('api/<version>/address/info','api.<version>.ShippingAddress/info');
    Route::post('api/<version>/address/create','api.<version>.ShippingAddress/create');
    Route::post('api/<version>/address/update','api.<version>.ShippingAddress/update');
    Route::get('api/<version>/address/delete','api.<version>.ShippingAddress/delete');

    //售后模块
    Route::get('api/<version>/afterSale/list','api.<version>.AfterSale/list');
    Route::get('api/<version>/afterSale/info','api.<version>.AfterSale/info');
    Route::get('api/<version>/afterSale/shipCompany','api.<version>.AfterSale/companyList');
    Route::post('api/<version>/afterSale/create','api.<version>.AfterSale/initiateAfterSale');
    Route::post('api/<version>/afterSale/fillInShip','api.<version>.AfterSale/userFillInShip');
    Route::post('api/<version>/afterSale/confirm','api.<version>.AfterSale/userConfirmReceiveChangeGoods');
    Route::post('api/<version>/afterSale/cancel','api.<version>.AfterSale/cancel');
    Route::get('api/<version>/afterSale/msgInfo','api.<version>.AfterSale/afterSaleRemarkInfo');
    Route::post('api/<version>/afterSale/msgSubmit','api.<version>.AfterSale/submitAfterSaleMsg');
    Route::get('api/<version>/afterSale/msgList','api.<version>.AfterSale/afterSaleMsgList');

    //文件上传
    Route::post('api/<version>/upload/upload','api.<version>.Upload/upload');
    Route::post('api/<version>/upload/certificate','api.<version>.Upload/uploadCertificate');

    //物流模块
    Route::get('api/<version>/ship/info','api.<version>.Ship/info');
    Route::post('api/<version>/ship/goods','api.<version>.Ship/infoAndGoods');

    //购物车模块
    Route::get('api/<version>/shopCart/list','api.<version>.ShopCart/list');
    Route::post('api/<version>/shopCart/create','api.<version>.ShopCart/create');
    Route::post('api/<version>/shopCart/update','api.<version>.ShopCart/update');
    Route::post('api/<version>/shopCart/delete','api.<version>.ShopCart/delete');

    //团队业绩模块
    Route::get('api/<version>/team/performance','api.<version>.Summary/myPerformance');
    Route::get('api/<version>/team/teamPerformance','api.<version>.Summary/myTeamPerformance');
    Route::get('api/<version>/team/directTeam','api.<version>.Summary/myDirectTeam');
    Route::get('api/<version>/team/allTeam','api.<version>.Summary/myAllTeam');
    Route::get('api/<version>/team/allTeamSummary','api.<version>.Summary/myAllTeamSummary');
    Route::get('api/<version>/team/searMyTeam','api.<version>.Summary/searMyTeam');
    Route::get('api/<version>/team/sameLevelDirectTeam','api.<version>.Summary/sameLevelDirectTeam');
    Route::get('api/<version>/team/nextDirectTeam','api.<version>.Summary/nextDirectTeam');
    Route::get('api/<version>/team/balanceAll','api.<version>.Summary/balanceAll');
    Route::get('api/<version>/team/willIncome','api.<version>.Summary/userTeamIncome');
    Route::get('api/<version>/team/allInCome','api.<version>.Summary/userAllInCome');
    Route::get('api/<version>/team/teamAllUserNumber','api.<version>.Summary/teamAllUserNumber');
    Route::get('api/<version>/team/specific','api.<version>.Summary/allTeamSpecific');
    Route::get('api/<version>/team/allTeamNew','api.<version>.Summary/newTeamUser');
    Route::get('api/<version>/team/allTeamSummaryNew','api.<version>.Summary/newTeamUserSummary');


    //直播模块
    Route::get('api/<version>/live/list','api.<version>.Live/list');

    //拼团模块
    Route::get('api/<version>/pt/goodsPtList','api.<version>.Pt/goodsInfoPtList');
    Route::post('api/<version>/pt/startCheck','api.<version>.Pt/startPtCheck');
    Route::post('api/<version>/pt/joinCheck','api.<version>.Pt/joinPtCheck');
    Route::get('api/<version>/pt/goodsList','api.<version>.Pt/ptGoodsList');
    Route::get('api/<version>/pt/list','api.<version>.Pt/list');
    Route::get('api/<version>/pt/info','api.<version>.Pt/ptInfo');
    Route::get('api/<version>/pt/myself','api.<version>.Pt/userPtList');

    //品牌馆列表
    Route::get('api/<version>/brandSpace/list','api.<version>.Home/brandSpaceList');

    //行为轨迹
    Route::get('api/<version>/behavior/list','api.<version>.Behavior/list');
    Route::get('api/<version>/behavior/linkList','api.<version>.Behavior/linkList');
    Route::post('api/<version>/behavior/record','api.<version>.Behavior/record');

    //口碑评价
    Route::post('api/<version>/reputation/create','api.<version>.Reputation/submitAppraise');

    //拼拼有礼
    Route::get('api/<version>/ppyl/area','api.<version>.Ppyl/areaList');
    Route::get('api/<version>/ppyl/goods','api.<version>.Ppyl/areaGoods');
    Route::get('api/<version>/ppyl/banner','api.<version>.Ppyl/bannerList');
    Route::get('api/<version>/ppyl/number','api.<version>.Ppyl/ppylNumber');
    Route::post('api/<version>/ppyl/order','api.<version>.PpylOrder/ppylOrder');
    Route::get('api/<version>/ppyl/info','api.<version>.PpylOrder/ppylInfo');
    Route::post('api/<version>/ppyl/startCheck','api.<version>.Ppyl/startPtCheck');
    Route::post('api/<version>/ppyl/joinCheck','api.<version>.Ppyl/joinPtCheck');
    Route::get('api/<version>/ppyl/goodsPpylList','api.<version>.Ppyl/goodsInfoPpylList');
    Route::post('api/<version>/ppyl/win','api.<version>.PpylOrder/userWinPpylOrderList');
    Route::get('api/<version>/ppyl/winInfo','api.<version>.PpylOrder/userWinPpylOrderInfo');
    Route::post('api/<version>/ppyl/winShipping','api.<version>.PpylOrder/winShipping');
    Route::get('api/<version>/ppyl/CVIPInfo','api.<version>.Ppyl/CVIPInfo');
    Route::post('api/<version>/ppyl/CVIPOrderCreate','api.<version>.Ppyl/CVIPOrderCreate');
    Route::get('api/<version>/ppyl/CVIPOrderList','api.<version>.Ppyl/CVIPOrderList');
    Route::get('api/<version>/ppyl/reward','api.<version>.Ppyl/rewardList');
    Route::get('api/<version>/ppyl/rewardCount','api.<version>.Ppyl/rewardCount');
    Route::post('api/<version>/ppyl/receiveReward','api.<version>.Ppyl/receiveReward');
    Route::post('api/<version>/ppyl/quicklyReward','api.<version>.Ppyl/quicklyReceiveReward');
    Route::post('api/<version>/ppyl/waitList','api.<version>.PpylOrder/waitOrderList');
    Route::post('api/<version>/ppyl/cancelWait','api.<version>.PpylOrder/cancelWait');
    Route::get('api/<version>/ppyl/autoList','api.<version>.PpylOrder/autoOrderList');
    Route::post('api/<version>/ppyl/cancelAuto','api.<version>.PpylOrder/cancelAuto');
    Route::post('api/<version>/ppyl/autoReceiveSwitch','api.<version>.Ppyl/autoReceiveSwitch');
    Route::post('api/<version>/ppyl/transform','api.<version>.Ppyl/transformMallBalance');
    Route::get('api/<version>/ppyl/orderSummary','api.<version>.PpylOrder/ppylOrderSummary');
    Route::get('api/<version>/ppyl/userRewardSummary','api.<version>.Ppyl/userRewardSummary');
    Route::get('api/<version>/ppyl/balance','api.<version>.Ppyl/ppylBalanceDetail');
    Route::get('api/<version>/ppyl/allInCome','api.<version>.Ppyl/userPpylAllInCome');
    Route::get('api/<version>/ppyl/rewardSummaryGroup','api.<version>.Ppyl/userPpylRewardGroup');
    Route::post('api/<version>/ppyl/refund','api.<version>.PpylOrder/submitRefund');
    Route::get('api/<version>/ppyl/activityList','api.<version>.Ppyl/activityList');

    //限制专场列表
    Route::get('api/<version>/specialArea/list','api.<version>.Home/specialAreaList');

    //快商模块列表
    Route::get('api/<version>/kuaishang/ContractInfo','api.<version>.User/kuaiShangContractInfo');
    //中数科模块列表
    Route::post('api/<version>/zsk/ContractAdd','api.<version>.User/userContractAdd');
    Route::post('api/<version>/zsk/ContractEdit','api.<version>.User/userContractEdit');


    //系统配置信息
    Route::get('api/<version>/system/info','api.<version>.System/info');
    Route::get('api/<version>/system/clientGg','api.<version>.System/clientGg');

    //H5模块
    Route::post('api/<version>/user/login','api.<version>.Login/login');
    Route::post('api/<version>/user/updatePwd','api.<version>.Login/updatePwd');
    Route::post('api/<version>/user/updatePayPwd','api.<version>.Login/updatePayPwd');
    //收益模块
    Route::get('api/<version>/bonus/balanceAll','api.<version>.Bonus/balanceAll');
    Route::get('api/<version>/bonus/propagandaRewardList','api.<version>.Bonus/divideList');
    Route::post('api/<version>/bonus/balanceTransfer','api.<version>.Bonus/balanceTransfer');
    Route::post('api/<version>/bonus/crowdBalanceTransfer','api.<version>.Bonus/crowdBalanceTransfer');


    //众筹模块模块
    Route::get('api/<version>/crowdFunding/activityList','api.<version>.CrowdFund/activityList');
    Route::get('api/<version>/crowdFunding/periodList','api.<version>.CrowdFund/periodList');
    Route::get('api/<version>/crowdFunding/goodsList','api.<version>.CrowdFund/goodsList');
    Route::post('api/<version>/crowdFunding/balance','api.<version>.User/crowdBalanceDetail');
    Route::get('api/<version>/crowdFunding/income','api.<version>.CrowdFund/incomeDetail');
    Route::get('api/<version>/crowdFunding/config','api.<version>.CrowdFund/config');
    Route::post('api/<version>/crowdFunding/recharge','api.<version>.Order/recharge');
    Route::get('api/<version>/crowdFunding/crowdOrderList','api.<version>.Order/crowdOrderList');
    Route::get('api/<version>/crowdFunding/advanceCardDetail','api.<version>.CrowdFund/advanceCardDetail');
    Route::get('api/<version>/crowdFunding/lotteryWinInfo','api.<version>.CrowdFund/lotteryWinInfo');
    Route::post('api/<version>/crowdFunding/applyLottery','api.<version>.CrowdFund/applyLottery');
    Route::post('api/<version>/crowdFunding/choosePeriodFusePlan','api.<version>.CrowdFund/userChoosePeriodFusePlan');


    Route::post('api/<version>/offlineRecharge/apply','api.<version>.OfflineRecharge/apply');
    Route::get('api/<version>/offlineRecharge/config','api.<version>.OfflineRecharge/config');

    //协议支付
    Route::post('api/<version>/agreement/signSms','api.<version>.Order/agreementSignSms');
    Route::post('api/<version>/agreement/contract','api.<version>.Order/agreementSignContract');
    Route::post('api/<version>/agreement/verifyPaySms','api.<version>.Order/agreementVerifyPaySmsCode');
    Route::post('api/<version>/agreement/paySmsAgain','api.<version>.Order/agreementPaySmsSendAgain');
    Route::get('api/<version>/agreement/orderStatus','api.<version>.Order/agreementCheckOrderStatus');
    Route::post('api/<version>/agreement/unSign','api.<version>.Order/agreementUnSign');

    //设备模块
    Route::get('api/<version>/device/info','api.<version>.Device/deviceInfo');
    Route::post('api/<version>/device/orderCreate','api.<version>.Device/buildOrder');
    Route::get('api/<version>/healthy/balance','api.<version>.Device/balanceDetail');
    Route::get('api/<version>/device/redirect','api.<version>.Device/qrCodeRedirectUrl');
    ###跨平台转入健康豆记录列表###
    Route::get('api/<version>/healthy/converList','api.<version>.Device/balanceConverList');

    //乐小活模块
    Route::post('api/<version>/letfree/buildContract','api.<version>.LetFree/buildContract');
    Route::get('api/<version>/letfree/ContractInfo','api.<version>.LetFree/contractInfo');

    //同步模块
    Route::post('api/<version>/superApp/auth','api.<version>.Wx/superAppAuth');

    //前端日志debug
    Route::any('api/<version>/debug/record','open.<version>.Debug/debugRecordLog');

    //用户签约模块
    Route::get('api/<version>/userAgreement/list','api.<version>.User/userAgreementList');
    Route::get('api/<version>/userAgreement/info','api.<version>.User/userAgreementInfo');
    Route::post('api/<version>/userAgreement/sign','api.<version>.User/createUserAgreement');

    //协议模块
    Route::get('api/<version>/agreement/list','api.<version>.Agreement/list');
    Route::get('api/<version>/agreement/info','api.<version>.Agreement/info');

})->middleware(\app\lib\middleware\CheckAccess::class);

Route::post('api/<version>/wx/webClearLoginBan','api.<version>.Wx/webClearLoginBan');

//APP版本模块
Route::get('api/<version>/appVersion/list','api.<version>.AppVersion/list');

Route::miss(function() {
    return json(['error_code'=>-1,'msg'=>'不要猜啦~']);
});