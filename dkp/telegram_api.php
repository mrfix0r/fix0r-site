<?php
declare(strict_types=1);
final class TelegramApi {
    public function __construct(private string $token) {}
    public function call(string $method,array $payload=[]):array {
        if(!in_array($method,['getMe','getWebhookInfo','getUpdates','sendMessage'],true) || !preg_match('/^[0-9]+:[A-Za-z0-9_-]{20,}$/D',$this->token))throw new RuntimeException('Invalid Telegram configuration');
        if(!function_exists('curl_init'))throw new RuntimeException('PHP curl extension required');
        $ch=curl_init('https://api.telegram.org/bot'.$this->token.'/'.$method);
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload,JSON_THROW_ON_ERROR),CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>8,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_FOLLOWLOCATION=>false]);
        $body=curl_exec($ch);$err=curl_errno($ch);curl_close($ch);
        // A timeout after sendMessage may mean delivery succeeded: report uncertainty.
        if($err || !is_string($body))return ['error_code'=>'network_unknown'];
        $data=json_decode($body,true);
        return is_array($data) && isset($data['ok'])?$data:['error_code'=>'response_unknown'];
    }
}
