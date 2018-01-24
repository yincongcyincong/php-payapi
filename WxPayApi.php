<?php
namespace app\components;

use Yii;

class PayApi
{
    const QRCODEURL = 'https://api.mch.weixin.qq.com/pay/unifiedorder';
    const WXKEY = '123';
    const OPENURL = 'https://api.weixin.qq.com/sns/oauth2/access_token';

    public static function wxQrcode($price, $iid)
    {
        $xml = self::params($price, $iid);
        $res = self::postXmlCurl($xml,self::QRCODEURL);
        $res = json_decode(json_encode(simplexml_load_string($res, 'SimpleXMLElement', LIBXML_NOCDATA)), true);
        if (!self::checkSign($res)) {
            return false;
        }
        if ($res['return_code'] == "SUCCESS") {
            return $res;
        }
        return false;
    }

    public static function officialPay($price, $iid, $code)
    {
        $xml = self::officialParam($price, $iid, $code);
        $res = self::postXmlCurl($xml,self::QRCODEURL);
        $res = json_decode(json_encode(simplexml_load_string($res, 'SimpleXMLElement', LIBXML_NOCDATA)), true);
        if ($res['return_code'] == "SUCCESS") {
            $data['package'] = 'prepay_id=' . $res['prepay_id'];
        } else {
            return false;
        }
        $data['appId'] = Yii::$app->params['officialAccounts']['appid'];
        $data['timeStamp'] = time();
        $data['nonceStr'] = (string)mt_rand(10000000, 99999999);
        $data['signType'] = 'MD5';
        $data['paySign'] = self::wxGetSign($data);
        return $data;
    }

    public static function officialParam($price, $iid, $code)
    {
        $data['appid'] = Yii::$app->params['officialAccounts']['appid'];
        $data['mch_id'] = Yii::$app->params['officialAccounts']['mch_id'];
        $data['device_info'] = 'WEB';
        $data['nonce_str'] = (string)mt_rand(10000000, 99999999);
        $data['sign_type'] = 'MD5';
        $data['body'] = '支付';
        $data['out_trade_no'] = $iid;
        $data['fee_type'] = 'CNY';
        $data['total_fee'] = $price * 100;
        $data['spbill_create_ip'] = $_SERVER['REMOTE_ADDR']; 
        $data['time_start'] = date('YmdHim', time());
        $data['time_expire'] = date('YmdHim', time() + 7200);
        $data['notify_url'] = 'http://www.kbyun.com/home/order/wx-call-back';
        $data['trade_type'] = 'JSAPI';
        $data['product_id'] = '1';
        $data['openid'] = self::wxGetOpenId($code);
        $data['sign'] = self::wxGetSign($data);
        return self::toXml($data);
    }

    private static function params($price, $iid)
    {
        $data['appid'] = Yii::$app->params['officialAccounts']['appid'];
        $data['mch_id'] = Yii::$app->params['officialAccounts']['mch_id'];
        $data['device_info'] = 'WEB';
        $data['nonce_str'] = (string)mt_rand(10000000, 99999999);
        $data['sign_type'] = 'MD5';
        $data['body'] = '支付';
        $data['out_trade_no'] = $iid;
        $data['fee_type'] = 'CNY';
        $data['total_fee'] = $price * 100;
        $data['spbill_create_ip'] = $_SERVER['REMOTE_ADDR']; 
        $data['time_start'] = date('YmdHim', time());
        $data['time_expire'] = date('YmdHim', time() + 7200);
        $data['notify_url'] = 'http://www.kbyun.com/home/order/wx-call-back';
        $data['trade_type'] = 'NATIVE';
        $data['product_id'] = '1';
        $data['sign'] = self::wxGetSign($data);
        return self::toXml($data);
    }

    public static function wxGetSign($data)
    {
        ksort($data);
        $string = http_build_query($data, '', '&');
        $string = urldecode($string);
        $string = $string . "&key=" . self::WXKEY;
        $string = md5($string);
        $result = strtoupper($string);
        return $result;
    }

    //获取微信公从号access_token
    public static function wxGetToken() 
    {
        $content = file_get_contents('./access_token/token.txt');
        $expire = "";
        if (!empty($content)) {
            list($token, $expire) = explode(':', $content);
        }
        if ($expire > time() && !empty($content)) {
            return $token;
        } else {
            $appid = Yii::$app->params['officialAccounts']['appid'];
            $secret = Yii::$app->params['officialAccounts']['secret'];
            $url = "https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid=".$appid."&secret=".$secret;
            $html=file_get_contents($url);
            $result=json_decode($html, true);
            $content = $result['access_token'].":".(time()+7000);
            file_put_contents('./access_token/token.txt', $content);
            return $result['access_token'];
        }
    }

    //获取微信公从号ticket
    public static function wxGetJsapiTicket() 
    {
        $content = file_get_contents('./access_token/ticket.txt');
        $expire = "";
        if (!empty($content)) {
            list($ticket, $expire) = explode(':', $content);
        }
        if ($expire > time() && !empty($content)) {
            return $ticket;
        } else {
            $url = sprintf("https://api.weixin.qq.com/cgi-bin/ticket/getticket?access_token=%s&type=jsapi", self::wxGetToken());
            $res = file_get_contents($url);
            $res = json_decode($res, true);
            file_put_contents('./access_token/ticket.txt', $res['ticket'].":".(time()+7000));
            return $res['ticket'];
        }
    }

    public static function wxGetOpenId($code)
    {
        $url = self::OPENURL . '?appid=' . Yii::$app->params['officialAccounts']['appid'] . '&secret=' . Yii::$app->params['officialAccounts']['secret'] . '&code=' . $code . '&grant_type=authorization_code';
        $html=file_get_contents($url);
        $result=json_decode($html, true);
        return $result['openid'];
    }

    private static function postXmlCurl($xml, $url, $useCert = false, $second = 30)
    {       
        $ch = curl_init();
        //设置超时
        curl_setopt($ch, CURLOPT_TIMEOUT, $second);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);//严格校验
        //设置header
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        //要求结果为字符串且输出到屏幕上
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    
        if($useCert == true){
            //设置证书
            //使用证书：cert 与 key 分别属于两个.pem文件
            curl_setopt($ch,CURLOPT_SSLCERTTYPE,'PEM');
            curl_setopt($ch,CURLOPT_SSLCERT, WxPayConfig::SSLCERT_PATH);
            curl_setopt($ch,CURLOPT_SSLKEYTYPE,'PEM');
            curl_setopt($ch,CURLOPT_SSLKEY, WxPayConfig::SSLKEY_PATH);
        }
        //post提交方式
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
        //运行curl
        $data = curl_exec($ch);
        //返回结果
        if($data){
            curl_close($ch);
            return $data;
        } else { 
            $error = curl_errno($ch);
            curl_close($ch);
            throw new \Exception("curl出错，错误码:$error");
        }
    }
    

    public static function toXml($data)
    {
        $xml = "<xml>";
        foreach ($data as $key => $val)
        {
            if (is_numeric($val)){
                $xml.="<".$key.">".$val."</".$key.">";
            }else{
                $xml.="<".$key."><![CDATA[".$val."]]></".$key.">";
            }
        }
        $xml.="</xml>";
        return $xml; 
    }

    public static function checkSign($data)
    {
        $sign = $data['sign'];
        unset($data['sign']);
        if ($sign == self::wxGetSign($data)) {
            return true;
        }
        return false;
    } 
}
