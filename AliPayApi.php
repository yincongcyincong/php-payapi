<?php
namespace app\components;

use Yii;

class AliPayApi
{
    const QRCODEURL = 'https://openapi.alipay.com/gateway.do';

    public static function aliQrcode($price, $iid)
    {
        list($postParam, $content) = self::qrcodeParams($price, $iid);
        $res = self::curlPost(self::QRCODEURL . '?' . $postParam, $content);
        $res = json_decode($res, true);
        if (!self::checkSign($res['sign'], json_encode($res['alipay_trade_precreate_response']))) {
            return false;
        }
        if ($res['alipay_trade_precreate_response']['code'] == '10000') {
            return $res['alipay_trade_precreate_response'];
        }
        return false;
    }

    private static function qrcodeParams($price, $iid)
    {
        $data['app_id'] = Yii::$app->params['aliPay']['appid'];
        // $data['app_id'] = '2016091300499218';
        $data['method'] = 'alipay.trade.precreate';
        $data['format'] = 'JSON';
        $data['charset'] = 'UTF-8';
        $data['sign_type'] = 'RSA2';
        $data['timestamp'] = date('Y-m-d H:i:m', time());
        $data['version'] = '1.0';
        $data['notify_url'] = 'http://www.kbyun.com/home/order/ali-call-back';
        $content['biz_content'] = self::getContent($price, $iid);
        $data['sign'] = self::getSign(array_merge($data, $content));
        return [self::getUrlParam($data), $content];
    }

    public static function getSign($data)
    {
        $data = self::getSignContent($data);
        $res = "-----BEGIN RSA PRIVATE KEY-----\n" .
                wordwrap(Yii::$app->params['aliPay']['pri_key'], 64, "\n", true) .
                "\n-----END RSA PRIVATE KEY-----";
        openssl_sign($data, $sign, $res, OPENSSL_ALGO_SHA256);
        $sign = base64_encode($sign);
        return $sign;

    }

    private static function getContent($price, $iid)
    {
        $data['out_trade_no'] = $iid;
        $data['total_amount'] = $price;
        $data['subject'] = '快帮云支付';
        $data['body'] = '快帮云支付';
        $data['timeout_express'] = '120m';
        return json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    protected function curlPost($url, $postFields = null) 
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_FAILONERROR, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $postBodyString = self::getUrlParam($postFields);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postBodyString);
        $headers = array('content-type: application/x-www-form-urlencoded;charset=utf-8');
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $reponse = curl_exec($ch);
        if (curl_errno($ch)) {
            throw new \Exception(curl_error($ch), 0);
        } else {
            $httpStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if (200 !== $httpStatusCode) {
                throw new \Exception($reponse, $httpStatusCode);
            }
        }
        curl_close($ch);
        return $reponse;
    }

    protected static function getUrlParam($data)
    {
        $urlParam = '';
        foreach ($data as $k => $v) {
            $urlParam .= $k . "=" . urlencode($v) . "&";
        }
        $urlParam = substr($urlParam, 0, -1);
        return $urlParam;
    }

    protected static function getSignContent($params) 
    {
        ksort($params);
        $stringToBeSigned = "";
        $i = 0;
        foreach ($params as $k => $v) {
            if ($i == 0) {
                $stringToBeSigned .= "$k" . "=" . "$v";
            } else {
                $stringToBeSigned .= "&" . "$k" . "=" . "$v";
            }
            $i++;
        }
        return $stringToBeSigned;
    }

    public static function checkSign($sign, $data)
    {
        $res = "-----BEGIN PUBLIC KEY-----\n" .
                wordwrap(Yii::$app->params['aliPay']['pub_key'], 64, "\n", true) .
                "\n-----END PUBLIC KEY-----";
        $result = (bool)openssl_verify($data, base64_decode($sign), $res, OPENSSL_ALGO_SHA256);
        return $result;
    }

    public static function checkCallBackSign($data)
    {
        $sign = $data['sign'];
        unset($data['sign']);
        unset($data['sign_type']);
        $string = self::getSignContent($data);
        return self::checkSign($sign, $string);
    }                        
}