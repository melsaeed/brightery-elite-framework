<?php
declare(strict_types=1);
namespace Brightery\Core\Mail;
use RuntimeException;
/** Minimal plaintext email SMTP adapter: TLS mandatory; no insecure fallback. */
final class SmtpTransport {
    public static function address(string $email):string {
        $email=trim($email);
        if(strlen($email)>190 || !filter_var($email,FILTER_VALIDATE_EMAIL)||preg_match('/[\r\n\x00-\x1F\x7F]/',$email))throw new RuntimeException('Invalid email address');
        return $email;
    }
    public static function message(string $from,string $recipient,string $subject,string $body):string {
        self::address($from);self::address($recipient);
        if($subject===''||strlen($subject)>160||preg_match('/[\r\n\x00-\x1F\x7F]/',$subject)||strlen($body)>50000)throw new RuntimeException('Invalid message');
        $subject='=?UTF-8?B?'.base64_encode($subject).'?=';
        $body=str_replace(["\r\n","\r"],"\n",$body);
        $body=str_replace("\n","\r\n",$body);
        // SMTP transparency: dot-stuff lines, including first line.
        $body=preg_replace('/(^|\r\n)\./','${1}..',$body);
        return 'From: <'.$from.">\r\nTo: <".$recipient.">\r\nSubject: ".$subject."\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\nDate: ".gmdate(DATE_RFC2822)."\r\n\r\n".$body;
    }
    public function send(string $to,string $subject,string $body):void {
        $from=self::address((string)env('MAIL_FROM',''));
        $message=self::message($from,$to,$subject,$body);
        $driver=(string)env('MAIL_DRIVER','disabled');
        if($driver==='log'){
            if((string)env('APP_ENV','production')!=='local')throw new RuntimeException('Mail log transport is only allowed in local mode');
            $dir=base_path('storage/private/mail');
            if(!is_dir($dir))mkdir($dir,0700,true);
            $file=$dir.'/'.bin2hex(random_bytes(12)).'.eml';
            if(file_put_contents($file,$message,LOCK_EX)===false)throw new RuntimeException('Mail write failed');
            chmod($file,0600);return;
        }
        if($driver!=='smtp')throw new RuntimeException('Mail transport not configured');
        $host=(string)env('SMTP_HOST','');$port=(int)env('SMTP_PORT',587);
        if(!preg_match('/^[a-z0-9.-]{1,253}$/iD',$host)||$port<1||$port>65535)throw new RuntimeException('SMTP host or port invalid');
        $user=(string)env('SMTP_USERNAME','');$pass=(string)env('SMTP_PASSWORD','');
        if($user===''||$pass==='')throw new RuntimeException('SMTP authentication required');
        $ctx=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>$host,'allow_self_signed'=>false,'SNI_enabled'=>true]]);
        $address=($port===465?'tls://':'tcp://').$host.':'.$port;
        $stream=@stream_socket_client($address,$errorNo,$error,10,STREAM_CLIENT_CONNECT,$ctx);
        if(!is_resource($stream))throw new RuntimeException('SMTP connection unavailable');
        stream_set_timeout($stream,10);
        try {
            $this->expect($stream,[220]);$this->command($stream,"EHLO localhost\r\n",[250]);
            if($port!==465){
                $this->command($stream,"STARTTLS\r\n",[220]);
                if(stream_socket_enable_crypto($stream,true,STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT|STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)!==true)throw new RuntimeException('SMTP TLS upgrade failed');
                $this->command($stream,"EHLO localhost\r\n",[250]);
            }
            $this->command($stream,"AUTH PLAIN ".base64_encode("\0".$user."\0".$pass)."\r\n",[235]);
            $this->command($stream,"MAIL FROM:<$from>\r\n",[250]);
            $this->command($stream,"RCPT TO:<$to>\r\n",[250,251]);
            $this->command($stream,"DATA\r\n",[354]);
            $this->command($stream,$message."\r\n.\r\n",[250]);
            $this->command($stream,"QUIT\r\n",[221]);
        }finally{fclose($stream);}
    }
    /** Discard all SMTP text to avoid exposing tokens or account details in exceptions/logs. */
    private function expect($stream,array $codes):void {
        $lines=0;$code=0;
        do {
            $line=fgets($stream,2048);
            if($line===false || ++$lines>40)throw new RuntimeException('SMTP protocol error');
            if(!preg_match('/^([0-9]{3})([ -])/D',rtrim($line,"\r\n"),$m))throw new RuntimeException('SMTP invalid reply');
            $code=(int)$m[1];$continuation=$m[2]==='-';
        }while($continuation);
        if(!in_array($code,$codes,true))throw new RuntimeException('SMTP command rejected');
    }
    private function command($stream,string $command,array $expected):void {
        $total=strlen($command);$written=0;
        while($written<$total){$n=fwrite($stream,substr($command,$written));if($n===false||$n===0)throw new RuntimeException('SMTP write failed');$written+=$n;}
        $this->expect($stream,$expected);
    }
}
