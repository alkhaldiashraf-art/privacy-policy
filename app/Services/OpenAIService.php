<?php
namespace App\Services;

use App\Core\Env;

/**
 * Minimal server-side OpenAI Responses API client.
 * All scan calls use Structured Outputs and store=false so downstream code
 * never has to trust free-form model text as a scanner result.
 */
final class OpenAIService {
    public static function configured(): bool {
        return trim((string)Env::get('OPENAI_API_KEY','')) !== '' && function_exists('curl_init');
    }

    public static function model(): string {
        $scan=trim((string)Env::get('OPENAI_SCAN_MODEL',''));
        return $scan !== '' ? $scan : (string)Env::get('OPENAI_MODEL','gpt-4.1-mini');
    }

    public static function structured(string $name, array $schema, string $instructions, array $input, int $maxOutputTokens=6000): array {
        if(!self::configured()) throw new \RuntimeException('AI scanning is not configured. Add OPENAI_API_KEY in config/config.php.');

        $payload=[
            'model'=>self::model(),
            'store'=>false,
            'max_output_tokens'=>max(1200,$maxOutputTokens),
            'temperature'=>0.1,
            'input'=>[
                ['role'=>'system','content'=>[['type'=>'input_text','text'=>$instructions]]],
                ['role'=>'user','content'=>[['type'=>'input_text','text'=>json_encode($input,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)]]],
            ],
            'text'=>[
                'format'=>[
                    'type'=>'json_schema',
                    'name'=>$name,
                    'strict'=>true,
                    'schema'=>$schema,
                ],
            ],
        ];

        $raw=false;$code=0;$err='';
        for($attempt=0;$attempt<2;$attempt++){
            $ch=curl_init('https://api.openai.com/v1/responses');
            curl_setopt_array($ch,[
                CURLOPT_RETURNTRANSFER=>true,
                CURLOPT_POST=>true,
                CURLOPT_TIMEOUT=>90,
                CURLOPT_CONNECTTIMEOUT=>10,
                CURLOPT_SSL_VERIFYPEER=>true,
                CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_HTTPHEADER=>[
                    'Authorization: Bearer '.(string)Env::get('OPENAI_API_KEY'),
                    'Content-Type: application/json',
                ],
                CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE),
            ]);
            $raw=curl_exec($ch);
            $code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
            $err=(string)curl_error($ch);
            curl_close($ch);
            $retry=($raw===false || $err!=='' || in_array($code,[429,500,502,503,504],true));
            if(!$retry || $attempt===1)break;
            usleep(350000);
        }

        if($raw===false || $err!=='' || $code<200 || $code>=300){
            error_log('SIUGOALS AI scan provider failure HTTP '.$code.($err!==''?' '.$err:''));
            throw new \RuntimeException('AI evidence analysis could not be completed. The deterministic scan results were preserved.');
        }

        $j=json_decode((string)$raw,true);
        if(!is_array($j)) throw new \RuntimeException('AI provider returned an unreadable response.');
        if(($j['status']??'')==='incomplete') throw new \RuntimeException('AI evidence analysis was incomplete. Try the scan again.');
        if(!empty($j['error'])) throw new \RuntimeException('AI evidence analysis failed safely.');

        $text=trim((string)($j['output_text']??''));
        if($text===''){
            foreach($j['output']??[] as $item){
                foreach($item['content']??[] as $content){
                    if(($content['type']??'')==='refusal') throw new \RuntimeException('AI evidence analysis was refused safely.');
                    if(($content['type']??'')==='output_text') $text.=(string)($content['text']??'');
                }
            }
        }
        $parsed=json_decode($text,true);
        if(!is_array($parsed)) throw new \RuntimeException('AI provider returned invalid structured scan data.');
        return [
            'data'=>$parsed,
            'model'=>(string)($j['model']??self::model()),
            'response_id'=>(string)($j['id']??''),
            'usage'=>is_array($j['usage']??null)?$j['usage']:[],
        ];
    }
}
