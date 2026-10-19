<?php
declare(strict_types=1);
namespace Brightery\Core\Database;
use Brightery\Core\Database;
use Brightery\Core\Modules\ModuleManager;
use RuntimeException;

/** MySQL DDL is not transactional; apply idempotent additive migration scripts. */
final class Migrator {
    public function __construct(private readonly Database $database,private readonly ModuleManager $modules){}
    public function paths():array {
        $paths=[];
        foreach(glob(base_path('database/migrations/*.sql'))?:[] as $file)$paths[basename($file)]=$file;
        ksort($paths);
        return [...$paths,...$this->modules->migrationPaths()];
    }
    public function status():array {
        $this->ensureTable();$applied=array_fill_keys(array_column($this->database->rows('SELECT version FROM schema_migrations'),'version'),true);
        $rows=[];foreach($this->paths() as $name=>$file)$rows[$name]=isset($applied[$name])?'applied':'pending';return $rows;
    }
    /** Inspect only this module without turning it on. */
    public function statusForModule(string $name):array {
        $this->ensureTable();$paths=$this->modules->migrationPathsFor($name);
        $applied=array_fill_keys(array_column($this->database->rows('SELECT version FROM schema_migrations'),'version'),true);
        $rows=[];foreach($paths as $version=>$file)$rows[$version]=isset($applied[$version])?'applied':'pending';
        return $rows;
    }
    public function run(?callable $logger=null):array{return $this->applyPaths($this->paths(),$logger);}
    /** Run ONLY a disabled module's migrations, under the same global advisory migration lock. */
    public function runDisabledModule(string $name,?callable $logger=null):array {
        if($this->modules->enabled($name))throw new RuntimeException('Target module must be disabled for offline migration');
        return $this->applyPaths($this->modules->migrationPathsFor($name),$logger);
    }
    private function applyPaths(array $paths,?callable $logger=null):array {
        $pdo=$this->database->pdo();
        $locked=(int)$pdo->query("SELECT GET_LOCK('brightery_framework_migrations', 15)")->fetchColumn();
        if($locked!==1)throw new RuntimeException('Could not obtain database migration lock');
        $done=[];
        try {
            $this->ensureTable();
            foreach($paths as $name=>$file){
                if(strlen($name)>150)throw new RuntimeException('Migration name exceeds 150 characters: '.$name);
                if($this->database->row('SELECT version FROM schema_migrations WHERE version=?',[$name]))continue;
                foreach(self::statements((string)file_get_contents($file)) as $statement)$pdo->exec($statement);
                $this->database->execute('INSERT INTO schema_migrations(version) VALUES(?)',[$name]);
                $done[]=$name;if($logger)$logger($name);
            }
        } finally {$pdo->query("SELECT RELEASE_LOCK('brightery_framework_migrations')");}
        return $done;
    }
    private function ensureTable():void {
        $this->database->pdo()->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(150) PRIMARY KEY, executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)');
    }
    /** Supports SQL strings, comments and semicolons in quoted values; not MySQL DELIMITER routines. */
    public static function statements(string $sql):array {
        $out=[];$buffer='';$quote=null;$lineComment=false;$blockComment=false;$length=strlen($sql);
        for($i=0;$i<$length;$i++){
            $ch=$sql[$i];$next=$i+1<$length?$sql[$i+1]:'';
            if($lineComment){if($ch==="\n"){$lineComment=false;$buffer.=' ';}continue;}
            if($blockComment){if($ch==='*'&&$next==='/'){$blockComment=false;$i++;}continue;}
            if($quote!==null){
                $buffer.=$ch;
                if($ch==='\\'&&$i+1<$length){$buffer.=$sql[++$i];continue;}
                if($ch===$quote){if($next===$quote){$buffer.=$next;$i++;}else $quote=null;}
                continue;
            }
            if($ch==='\''||$ch==='"'||$ch==='`'){$quote=$ch;$buffer.=$ch;continue;}
            if($ch==='-'&&$next==='-'&&($i+2===$length||ctype_space($sql[$i+2]))){$lineComment=true;$i++;continue;}
            if($ch==='#'){$lineComment=true;continue;}
            if($ch==='/'&&$next==='*'){$blockComment=true;$i++;continue;}
            if($ch===';'){$trimmed=trim($buffer);if($trimmed!=='')$out[]=$trimmed;$buffer='';continue;}
            $buffer.=$ch;
        }
        if($quote!==null||$blockComment)throw new RuntimeException('Unterminated quote/comment in SQL migration');
        if(trim($buffer)!=='')$out[]=trim($buffer);
        return $out;
    }
}
