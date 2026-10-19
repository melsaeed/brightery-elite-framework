<?php
declare(strict_types=1);
namespace Modules\System\Controllers;
use Brightery\Core\{App,View,Auth,Database,Security,RateLimiter};
use Brightery\Core\Modules\ModuleManager;
use Brightery\Core\I18n\Translator;
use Brightery\Core\Http\{Request,Response};
use Brightery\Core\Form\Fields;
use Throwable;
final class SystemController {
    private static function svc(string $class):object {return App::get()->service($class);}
    private static function view(string $view,array $data=[]):Response {return Response::html(self::svc(View::class)->render($view,$data));}
    private static function admin(string $title,string $view,array $data=[]):Response {
        $content=self::svc(View::class)->render($view,$data);
        return self::view('admin/layout',['title'=>$title,'content'=>$content,'csrf'=>self::svc(Security::class)->csrf(),'user'=>self::svc(Auth::class)->user(),'translator'=>self::svc(Translator::class)]);
    }
    private static function error(string $message,int $code=422):Response {return self::admin('Attention','admin/message',['message'=>$message,'code'=>$code]);}
    public static function home():Response {return self::view('frontend/home',['title'=>'Brightery Framework']);}
    public static function health():Response {return Response::json(['status'=>'ok','framework'=>\Brightery\Core\Kernel::VERSION]);}
    public static function loginPage():Response {
        if(self::svc(Auth::class)->id())return Response::redirect('/admin');
        if(self::svc(Security::class)->pendingMfa()!==null)return Response::redirect('/mfa');
        return self::view('admin/login',['csrf'=>self::svc(Security::class)->csrf()]);
    }
    public static function login(Request $request):Response {
        $email=trim((string)$request->input('email',''));$pw=(string)$request->input('password','');
        if(strlen($email)>190 || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($pw)>1024)return Response::html('Invalid credentials',401);
        $key=hash('sha256',$request->ip().'|'.strtolower($email));
        if(!self::svc(RateLimiter::class)->hit('login:'.$key,5,300))return Response::html('Too many attempts; retry later',429);
        if(!self::svc(Auth::class)->attempt($email,$pw))return self::view('admin/login',['csrf'=>self::svc(Security::class)->csrf(),'error'=>'Email or password is incorrect']);
        return Response::redirect(self::svc(Security::class)->pendingMfa()!==null?'/mfa':'/admin');
    }
    public static function logout():Response {self::svc(Security::class)->logout();return Response::redirect('/admin/login');}
    public static function dashboard():Response {
        $db=self::svc(Database::class);
        return self::admin('Dashboard','admin/dashboard',['users'=>(int)($db->row('SELECT COUNT(*) total FROM users')['total']??0),'groups'=>(int)($db->row('SELECT COUNT(*) total FROM groups')['total']??0),'modules'=>count(self::svc(ModuleManager::class)->available())]);
    }
    public static function modules():Response {return self::admin('Modules','admin/modules',['modules'=>self::svc(ModuleManager::class)->available(),'manager'=>self::svc(ModuleManager::class)]);}
    public static function modulePlan(Request $r):Response {
        $name=(string)$r->input('name','');$enabled=(string)$r->input('enabled','')==='1';
        try{$plan=self::svc(ModuleManager::class)->planChange($name,$enabled);}
        catch(\RuntimeException $e){return self::error($e->getMessage());}
        return self::admin('Review module change','admin/module-plan',['plan'=>$plan]);
    }
    public static function toggleModule(Request $r):Response {
        $name=(string)$r->input('name','');$enabled=(string)$r->input('enabled','')==='1';
        $token=(string)$r->input('plan_token','');
        $confirmation=(string)$r->input('confirmation','');
        if(!hash_equals(($enabled?'ACTIVATE ':'DEACTIVATE ').$name,$confirmation))return self::error('Confirmation text does not match; no changes made');
        try{self::svc(ModuleManager::class)->setEnabled($name,$enabled,$token);self::svc(\Brightery\Core\Audit\AuditTrail::class)->record('platform.module.changed',self::svc(Security::class)->userId(),'module',$name,['enabled'=>$enabled]);}catch(\RuntimeException $e){return self::error($e->getMessage());}
        return Response::redirect('/admin/modules');
    }
    private static function locale(Request $r):string {$l=(string)$r->input('locale','en');return preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/',$l)?$l:'en';}
    public static function translations(Request $r):Response {$locale=self::locale($r);return self::admin('Translations','admin/translations',['locale'=>$locale,'entries'=>self::svc(Translator::class)->load($locale)]);}
    public static function saveTranslations(Request $r):Response {
        $locale=self::locale($r);$json=(string)$r->input('dictionary','{}');
        if(strlen($json)>500000)return self::error('Dictionary too large');
        try {$data=json_decode($json,true,512,JSON_THROW_ON_ERROR);if(!is_array($data)||array_is_list($data))throw new \InvalidArgumentException('Dictionary must be a JSON object');self::svc(Translator::class)->save($locale,$data);}
        catch(Throwable $e){return self::error('Invalid dictionary: '.$e->getMessage());}
        return Response::redirect('/admin/translations?locale='.$locale);
    }
    public static function importTranslations(Request $r):Response {
        $file=$r->files['dictionary']??null;
        if(!is_array($file)||($file['error']??1)!==UPLOAD_ERR_OK||($file['size']??0)>500000)return self::error('Invalid translation file (max 500KB)');
        $json=file_get_contents($file['tmp_name']);
        $post=new Request('POST','/admin/translations/save',[],['locale'=>self::locale($r),'dictionary'=>$json]);
        return self::saveTranslations($post);
    }
    public static function exportTranslations(Request $r):Response {
        $locale=self::locale($r);
        return new Response(json_encode(self::svc(Translator::class)->load($locale),JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),200,['Content-Type'=>'application/json; charset=utf-8','Content-Disposition'=>'attachment; filename="'.$locale.'.json"','Cache-Control'=>'private, no-store']);
    }
    public static function users():Response {
        $db=self::svc(Database::class);
        return self::admin('Users','admin/users',['users'=>$db->rows('SELECT u.id,u.name,u.email,u.is_active,GROUP_CONCAT(g.name SEPARATOR ", ") AS groups FROM users u LEFT JOIN user_groups ug ON ug.user_id=u.id LEFT JOIN groups g ON g.id=ug.group_id GROUP BY u.id ORDER BY u.id DESC LIMIT 100'), 'groups'=>$db->rows('SELECT id,name FROM groups ORDER BY name')]);
    }
    public static function createUser(Request $r):Response {
        $name=trim((string)$r->input('name'));$email=strtolower(trim((string)$r->input('email')));$pw=(string)$r->input('password');$gid=(int)$r->input('group_id');
        if($name===''||strlen($name)>160||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>190||strlen($pw)<12||strlen($pw)>256) return self::error('Enter a valid name, email and a password with at least 12 characters');
        $db=self::svc(Database::class);
        if(!$db->row('SELECT id FROM groups WHERE id=?',[$gid]))return self::error('Invalid group');
        try {$db->transactional(function(Database $db)use($name,$email,$pw,$gid){$db->execute('INSERT INTO users(name,email,password_hash) VALUES(?,?,?)',[$name,$email,password_hash($pw,PASSWORD_ARGON2ID)]);$id=(int)$db->pdo()->lastInsertId();$db->execute('INSERT INTO user_groups(user_id,group_id) VALUES(?,?)',[$id,$gid]);});}
        catch(Throwable){return self::error('Could not create user (email may already be in use)');}
        return Response::redirect('/admin/users');
    }
    public static function groups():Response {
        $db=self::svc(Database::class);
        return self::admin('Groups & permissions','admin/groups',['groups'=>$db->rows('SELECT id,name,description FROM groups ORDER BY id'),'permissions'=>$db->rows('SELECT id,code,label FROM permissions ORDER BY id'),'assigned'=>$db->rows('SELECT group_id,permission_id FROM group_permissions')]);
    }
    public static function createGroup(Request $r):Response {
        $name=trim((string)$r->input('name'));if($name===''||strlen($name)>120)return self::error('Invalid name');
        try{self::svc(Database::class)->execute('INSERT INTO groups(name,description) VALUES(?,?)',[$name,substr((string)$r->input('description'),0,1000)]);}catch(Throwable){return self::error('Group already exists');}
        return Response::redirect('/admin/groups');
    }
    public static function changePermission(Request $r):Response {
        $gid=(int)$r->input('group_id');$pid=(int)$r->input('permission_id');$enable=(string)$r->input('enabled')==='1';
        $db=self::svc(Database::class);
        if($gid===1)return self::error('The Super Admin permission group is protected');
        if(!$db->row('SELECT id FROM groups WHERE id=?',[$gid])||!$db->row('SELECT id FROM permissions WHERE id=? AND code<>\'*\'',[$pid]))return self::error('Invalid group or permission');
        if($enable)$db->execute('INSERT IGNORE INTO group_permissions(group_id,permission_id) VALUES(?,?)',[$gid,$pid]);
        else $db->execute('DELETE FROM group_permissions WHERE group_id=? AND permission_id=?',[$gid,$pid]);
        return Response::redirect('/admin/groups');
    }
    public static function settings():Response {
        $settings=[];foreach(self::svc(Database::class)->rows('SELECT setting_key,setting_value FROM settings') as $row)$settings[$row['setting_key']]=$row['setting_value'];
        return self::admin('Settings','admin/settings',['settings'=>$settings]);
    }
    public static function saveSettings(Request $r):Response {
        foreach(['site_name'=>150,'support_email'=>190,'site_description'=>500] as $key=>$limit){
            $value=trim((string)$r->input($key));if(strlen($value)>$limit)return self::error("$key is too long");
            if($key==='support_email'&&$value!==''&&!filter_var($value,FILTER_VALIDATE_EMAIL))return self::error('Invalid email');
            self::svc(Database::class)->execute('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)',[$key,$value]);
        }
        return Response::redirect('/admin/settings');
    }
    public static function components():Response {
        return self::admin('Components','admin/components',['text'=>Fields::text('company','Company name','',['placeholder'=>'Your business']),'select'=>Fields::select('sector','Industry',['agency'=>'Agency','retail'=>'Retail','software'=>'Software']),'files'=>Fields::files('photos','Product images'),'auto'=>Fields::autocomplete('search','Search users','/admin/autocomplete'), 'tags'=>self::svc(\Brightery\Core\Ui\IslandRenderer::class)->render('tags',['name'=>'topics','label'=>'Tags / Topics','values'=>['CRM','Marketing']])]);
    }
    public static function autocomplete(Request $r):Response {
        $q=trim((string)$r->input('q'));if(strlen($q)<2||strlen($q)>70)return Response::json([]);
        $rows=self::svc(Database::class)->rows('SELECT id,name AS label FROM users WHERE name LIKE ? ORDER BY name LIMIT 8',['%'.str_replace(['%','_'],['\\%','\\_'],$q).'%']);
        return Response::json($rows);
    }
    public static function media():Response {return self::admin('Media library','admin/media',['files'=>self::mediaRows()]);}
    private static function mediaRows():array {return self::svc(Database::class)->rows('SELECT id,original_name,mime,file_size,created_at FROM media ORDER BY id DESC LIMIT 100');}
    public static function mediaJson():Response {return Response::json(self::mediaRows());}
    public static function mediaFile(Request $request,array $params):Response {
        $id=(int)($params['id']??0);$row=self::svc(Database::class)->row('SELECT filename,mime FROM media WHERE id=?',[$id]);
        if(!$row)return Response::html('Not found',404);
        $file=base_path('storage/private/uploads/'.$row['filename']);
        if(!is_file($file))return Response::html('Not found',404);
        return new Response(file_get_contents($file),200,['Content-Type'=>$row['mime'],'Content-Disposition'=>'inline','Cache-Control'=>'private, max-age=60']);
    }
    public static function upload(Request $r):Response {
        $files=$r->files['photos']??null;if(!is_array($files))return self::error('No file provided');
        $names=(array)($files['name']??[]);$tmp=(array)($files['tmp_name']??[]);$sizes=(array)($files['size']??[]);$errors=(array)($files['error']??[]);
        if(count($names)>12)return self::error('Up to 12 images per upload');
        $allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
        $dir=base_path('storage/private/uploads');if(!is_dir($dir))mkdir($dir,0700,true);
        foreach($names as $i=>$name) {
            if(($errors[$i]??1)!==UPLOAD_ERR_OK || ($sizes[$i]??0)>8*1024*1024 || ($sizes[$i]??0)<=0)return self::error('Invalid upload (JPEG/PNG/WebP; max 8 MB each)');
            $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($tmp[$i]);
            if(!isset($allowed[$mime]) || !getimagesize($tmp[$i]))return self::error('Only valid JPEG, PNG and WebP images are accepted');
            $filename=bin2hex(random_bytes(32));$path=$dir.'/'.$filename;
            if(!is_uploaded_file($tmp[$i])||!move_uploaded_file($tmp[$i],$path))return self::error('Could not store upload');
            chmod($path,0600);
            try {self::svc(Database::class)->execute('INSERT INTO media(user_id,original_name,filename,mime,file_size) VALUES(?,?,?,?,?)',[self::svc(Auth::class)->id(),substr(basename((string)$name),0,255),$filename,$mime,(int)$sizes[$i]]);}
            catch(Throwable $e){@unlink($path);throw $e;}
        }
        return Response::redirect('/admin/media');
    }
    public static function language(Request $r):Response {
        $l=self::locale($r);self::svc(Security::class)->startSession();$_SESSION['locale']=$l;
        return Response::redirect('/admin');
    }
}
