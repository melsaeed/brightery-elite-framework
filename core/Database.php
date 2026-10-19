<?php
declare(strict_types=1);
namespace Brightery\Core;
use PDO;
final class Database {
    private ?PDO $pdo=null;
    public function table(string $name): \Brightery\Core\Database\QueryBuilder {return new \Brightery\Core\Database\QueryBuilder($this,$name);}
    public function pdo(): PDO {
        if($this->pdo)return $this->pdo;
        $host=(string)env('DB_HOST','127.0.0.1');$port=(int)env('DB_PORT',3306);$db=(string)env('DB_DATABASE','brightery');
        if (!preg_match('/^[a-zA-Z0-9_]+$/',$db))throw new \RuntimeException('Invalid database name');
        $pdo=new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4",(string)env('DB_USERNAME'),(string)env('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
        $pdo->exec("SET time_zone = '+00:00'");
        return $this->pdo=$pdo;
    }
    public function rows(string $sql,array $params=[]): array {$s=$this->pdo()->prepare($sql);$s->execute($params);return $s->fetchAll();}
    public function row(string $sql,array $params=[]): ?array {$s=$this->pdo()->prepare($sql);$s->execute($params);$r=$s->fetch();return $r===false?null:$r;}
    public function execute(string $sql,array $params=[]): int {$s=$this->pdo()->prepare($sql);$s->execute($params);return $s->rowCount();}
    /** Nested operations use SAVEPOINT instead of committing an outer module transaction. */
    public function transactional(callable $fn):mixed {
        $pdo=$this->pdo();$nested=$pdo->inTransaction();
        $point=$nested?'bf_'.bin2hex(random_bytes(8)):null;
        if($nested)$pdo->exec('SAVEPOINT '.$point);else $pdo->beginTransaction();
        try{
            $result=$fn($this);
            if($nested)$pdo->exec('RELEASE SAVEPOINT '.$point);else $pdo->commit();
            return $result;
        }catch(\Throwable $e){
            if($pdo->inTransaction()){
                if($nested){$pdo->exec('ROLLBACK TO SAVEPOINT '.$point);$pdo->exec('RELEASE SAVEPOINT '.$point);}
                else $pdo->rollBack();
            }
            throw $e;
        }
    }
}
