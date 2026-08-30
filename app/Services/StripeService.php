<?php
namespace App\Services;
use App\Core\Env;
final class StripeService {
    public static function configured(): bool { return (bool)Env::get('STRIPE_SECRET_KEY'); }
    private static function req(string $method,string $path,array $data=[]): array { if(!function_exists('curl_init')) throw new \RuntimeException('PHP cURL extension is required for Stripe.'); $ch=curl_init('https://api.stripe.com/v1'.$path);$headers=['Authorization: Bearer '.Env::get('STRIPE_SECRET_KEY')];curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);curl_setopt($ch,CURLOPT_HTTPHEADER,$headers);curl_setopt($ch,CURLOPT_TIMEOUT,30);curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,true);curl_setopt($ch,CURLOPT_SSL_VERIFYHOST,2);if($method==='POST'){curl_setopt($ch,CURLOPT_POST,true);curl_setopt($ch,CURLOPT_POSTFIELDS,http_build_query($data));}elseif($method==='DELETE'){curl_setopt($ch,CURLOPT_CUSTOMREQUEST,'DELETE');if($data)curl_setopt($ch,CURLOPT_POSTFIELDS,http_build_query($data));}$raw=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$err=curl_error($ch);curl_close($ch);if($raw===false||$err)throw new \RuntimeException('Stripe connection failed.');$j=json_decode((string)$raw,true)?:[];if($code>=400)throw new \RuntimeException($j['error']['message']??'Stripe request failed.');return $j; }
    public static function createConnectAccount(): array { return self::req('POST','/accounts',['type'=>'express']); }
    public static function accountLink(string $account,string $return,string $refresh): array { return self::req('POST','/account_links',['account'=>$account,'return_url'=>$return,'refresh_url'=>$refresh,'type'=>'account_onboarding']); }
    public static function retrieveAccount(string $account): array { return self::req('GET','/accounts/'.rawurlencode($account)); }
    public static function loginLink(string $account): array { return self::req('POST','/accounts/'.rawurlencode($account).'/login_links'); }
    public static function checkoutSession(array $lineItem,string $success,string $cancel,string $mode='payment',array $metadata=[]): array {
        $data=['mode'=>$mode,'success_url'=>$success,'cancel_url'=>$cancel,'line_items[0][quantity]'=>1,'line_items[0][price_data][currency]'=>'usd','line_items[0][price_data][unit_amount]'=>$lineItem['amount'],'line_items[0][price_data][product_data][name]'=>$lineItem['name']];
        if($mode==='subscription')$data['line_items[0][price_data][recurring][interval]']=$lineItem['interval']??'month';
        foreach($metadata as $k=>$v)$data['metadata['.$k.']']=$v; return self::req('POST','/checkout/sessions',$data);
    }
    public static function connectedCheckout(string $accountId,int $amount,string $name,string $success,string $cancel,array $metadata=[]): array { $data=['mode'=>'payment','success_url'=>$success,'cancel_url'=>$cancel,'line_items[0][quantity]'=>1,'line_items[0][price_data][currency]'=>'usd','line_items[0][price_data][unit_amount]'=>$amount,'line_items[0][price_data][product_data][name]'=>$name,'payment_intent_data[transfer_data][destination]'=>$accountId]; foreach($metadata as $k=>$v)$data['metadata['.$k.']']=$v; return self::req('POST','/checkout/sessions',$data); }
    public static function cancelSubscriptionAtPeriodEnd(string $subscriptionId): array { return self::req('POST','/subscriptions/'.rawurlencode($subscriptionId),['cancel_at_period_end'=>'true']); }
    public static function verifyWebhook(string $payload,string $sig): bool {
        $secret=(string)Env::get('STRIPE_WEBHOOK_SECRET'); if(!$secret||!$sig)return false; $parts=[];foreach(explode(',',$sig) as $p){[$k,$v]=array_pad(explode('=',$p,2),2,'');$parts[$k][]=$v;} $t=(int)($parts['t'][0]??0); if(abs(time()-$t)>300)return false; $expected=hash_hmac('sha256',$t.'.'.$payload,$secret); foreach($parts['v1']??[] as $v)if(hash_equals($expected,$v))return true; return false;
    }
}

