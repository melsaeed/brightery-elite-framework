<?php
declare(strict_types=1);
namespace Brightery\Core\Mail;
use Brightery\Core\Database;
use Brightery\Core\Crypto\Encrypter;
use Brightery\Core\Jobs\Queue;
use RuntimeException;
/** Mail queued by ID only; body + verification link encrypted at rest in dedicated outbox. */
final class Outbox {
    public function __construct(private readonly Database $db,private readonly Encrypter $crypt,private readonly Queue $queue,private readonly SmtpTransport $smtp){}
    public function enqueue(string $recipient,string $subject,string $body,?int $userId=null):int {
        SmtpTransport::address($recipient);
        // Validate without requiring MAIL_FROM at queue time.
        if($subject===''||strlen($body)>50000||strlen($subject)>160||preg_match('/[\x00-\x1F\x7F]/',$subject))throw new RuntimeException('Invalid email');
        $payload=$this->crypt->encrypt(json_encode(['subject'=>$subject,'body'=>$body],JSON_THROW_ON_ERROR));
        $work=function(Database $db)use($recipient,$payload,$userId):int {
            $db->execute('INSERT INTO bf_email_outbox(user_id,recipient,payload_cipher) VALUES(?,?,?)',[$userId,$recipient,$payload]);
            $id=(int)$db->pdo()->lastInsertId();$this->queue->dispatch('system.mail.send',['id'=>$id],0,5);
            return $id;
        };
        return $this->db->pdo()->inTransaction()?$work($this->db):$this->db->transactional($work);
    }
    public function deliver(int $id):void {
        if($id<1)throw new RuntimeException('Invalid outbox message');
        $row=$this->db->row('SELECT recipient,payload_cipher,status FROM bf_email_outbox WHERE id=?',[$id]);
        if(!$row||$row['status']==='delivered')return;
        $data=json_decode($this->crypt->decrypt((string)$row['payload_cipher']),true,16,JSON_THROW_ON_ERROR);
        if(!is_array($data))throw new RuntimeException('Corrupted outbound message');
        $this->smtp->send((string)$row['recipient'],(string)$data['subject'],(string)$data['body']);
        $this->db->execute("UPDATE bf_email_outbox SET status='delivered',sent_at=UTC_TIMESTAMP(),payload_cipher='' WHERE id=? AND status='queued'",[$id]);
    }
    public function prune():int{return $this->db->execute("DELETE FROM bf_email_outbox WHERE status='delivered' AND sent_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY)");}
}
