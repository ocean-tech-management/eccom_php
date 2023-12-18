<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------



namespace app\lib\services;

use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MQTTClient;

class Mqtt
{

    private $server = 'mqtt.andyoudao.cn';
    private $port = 1883;
    private $clientId = 'zetaSoft0001';
    private $username = 'zetaSoftUser01';
    private $password = 'Qaz111111';


    /**
     * @title  发布消息
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    public function publish(array $data = [])
    {
        $server = $this->server;
        $port = $this->port;
        $clientId = $this->clientId;
        $username = $this->username;
        $password = $this->password;
        $clean_session = true;


        //连接
        //<--php-mqtt/client 1.*用法-->
//        $mqtt = new MqttClient($server, $port, $clientId);
//        $connectionSettings = (new ConnectionSettings())
//            ->setUsername($username)
//            ->setPassword($password)
//            ->setKeepAliveInterval(400);
        // Last Will 设置
//            ->setLastWillTopic('emqx/test/last-will')
//            ->setLastWillMessage('client disconnect')
//            ->setLastWillQualityOfService(1);

//        $mqtt->connect($connectionSettings, $clean_session);

        //<--php-mqtt/client 0.3用法-->
        $mqtt = new MQTTClient($server, $port, $clientId);
        $connectionSettings = (new ConnectionSettings(0,false,false,5,400));
        $mqtt->connect($username,$password,$connectionSettings, $clean_session);

//        $mqtt->subscribe('/PUB/862167052825621', function ($topic, $message) {
//            printf("Received message on topic [%s]: %s\n", $topic, $message);
//        }, 0);
        $imei = $data['imei'] ?? '867435053377164';
        //操作类型 1为打开 0为关闭
        $operType = $data['type'] ?? 0;
        $topic = '/SUB/' . $imei;
        $msgId = sprintf("%08d", mt_rand(1, 99999999));
        $operPowerNumber = null;
        //多组开启或自定义某一个开关, power_number 一维数组 键名是(123456) 值为0或1
        if (!empty($data['power_number'] ?? null)) {
            foreach ($data['power_number'] as $key => $value) {
                $operPowerNumber['sw' . $key] = $value;
            }
            $operPowerNumber = json_encode($operPowerNumber, 256);
        }
        $payload = '{"method":"thing.service.property.set", "id":"' . $msgId . '", "params":{"sw1":' . $operType . '}, "version":"1.0.0"}';
        if (!empty($operPowerNumber)) {
            $payload = '{"method":"thing.service.property.set", "id":"' . $msgId . '", "params":' . $operPowerNumber . ', "version":"1.0.0"}';
        }

        //发布主题
        $mqtt->publish(
        // topic
            $topic,
            // payload
            $payload,
            // qos
            0,
            // retain
            true
        );

        return true;
    }
}