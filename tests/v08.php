<?php
declare(strict_types=1);
use Brightery\Core\Modules\{ModulePackage,ModuleInstaller,TrustedKeys};

check(version_compare(\Brightery\Core\Kernel::VERSION,'0.8.0','>='),'v0.8 framework release compatibility');
check(extension_loaded('sodium'),'Ed25519 cryptography available');
$root=sys_get_temp_dir().'/bf-modules-'.bin2hex(random_bytes(7));
mkdir($root.'/modules/System',0700,true);
mkdir($root.'/private',0700,true);
mkdir($root.'/source/Kit',0700,true);
file_put_contents($root.'/modules/System/module.json',json_encode(['name'=>'System','version'=>'0.3.4','required'=>true,'framework'=>'^0.2.0']));
$manifest=['name'=>'Kit','version'=>'1.0.0','required'=>false,'framework'=>'^0.2.0','dependencies'=>['System'=>'>=0.3.0']];
file_put_contents($root.'/source/Kit/module.json',json_encode($manifest));
file_put_contents($root.'/source/Kit/routes.php','<?php return static fn()=>null;');
$pair=sodium_crypto_sign_keypair();$secret=sodium_crypto_sign_secretkey($pair);$public=base64_encode(sodium_crypto_sign_publickey($pair));
$trust=new TrustedKeys($root.'/private/module-trust.json');$trust->trust('dev-signing-key',$public);
check($trust->all()['dev-signing-key']===$public,'trusted Ed25519 key persisted outside module tree');
try{$trust->trust('dev-signing-key',base64_encode(random_bytes(32)));check(false,'trusted key substitution allowed');}catch(RuntimeException){check(true,'trusted key substitution rejected');}
$envelope=ModulePackage::build($root.'/source/Kit','dev-signing-key',$secret);
$encoded=ModulePackage::encode($envelope);
check(ModulePackage::verify(ModulePackage::decode($encoded),$trust->all())['name']==='Kit','signed package verifies with trusted key');
try{ModulePackage::verify($envelope,[]);check(false,'untrusted package accepted');}catch(RuntimeException){check(true,'untrusted signing key rejected');}
$modified=$envelope;
$modified['payload']['files']['routes.php']=base64_encode('<?php echo "tampered";');
try{ModulePackage::verify($modified,$trust->all());check(false,'tampered module accepted');}catch(RuntimeException){check(true,'tampered signed payload rejected');}
$bad=$envelope;
$bad['payload']['files']['../escape.php']=base64_encode('bad');
try{ModulePackage::decode(ModulePackage::encode($bad));check(false,'path traversal allowed');}catch(RuntimeException){check(true,'package traversal rejected');}
$bad=$envelope;
$bad['payload']['files']['bad\\file.php']=base64_encode('bad');
try{ModulePackage::decode(ModulePackage::encode($bad));check(false,'backslash traversal allowed');}catch(RuntimeException){check(true,'Windows-style package paths rejected');}
$package=$root.'/Kit-1.bfp';file_put_contents($package,$encoded);
$installer=new ModuleInstaller($root.'/modules',$root.'/private/states.json',$root.'/private',$trust);
$plan=$installer->inspect($package);
check($plan['action']==='install'&&$plan['version']==='1.0.0'&&!$plan['applied'],'module compatibility preflight detects new installation');
check(!is_dir($root.'/modules/Kit'),'module dry-run does not change files');
$applied=$installer->install($package,true);
check($applied['applied']&&is_file($root.'/modules/Kit/routes.php'),'signed module install publishes files');
$manifest['version']='1.1.0';file_put_contents($root.'/source/Kit/module.json',json_encode($manifest));
file_put_contents($root.'/source/Kit/routes.php','<?php return static fn()=>"v1.1";');
$package2=$root.'/Kit-2.bfp';file_put_contents($package2,ModulePackage::encode(ModulePackage::build($root.'/source/Kit','dev-signing-key',$secret)));
check($installer->inspect($package2)['action']==='update','module upgrade plan detects installed version');
$updated=$installer->install($package2,true);
check($updated['applied']&&$updated['backup_id']!==null,'module upgrade creates rollback snapshot');
check(str_contains((string)file_get_contents($root.'/modules/Kit/routes.php'),'v1.1'),'upgraded PHP module published');
check(in_array($updated['backup_id'],$installer->rollbacks('Kit'),true),'rollback snapshots discoverable');
$rollback=$installer->rollback('Kit',$updated['backup_id']);
check($rollback['action']==='rollback'&&!$rollback['applied'],'rollback defaults to non-mutating dry run');
$installer->rollback('Kit',$updated['backup_id'],true);
check(!str_contains((string)file_get_contents($root.'/modules/Kit/routes.php'),'v1.1'),'module backup restored on explicit apply');
try{$installer->install($package,true);check(false,'same-version package accepted');}catch(RuntimeException){check(true,'module installs reject identical or downgraded versions');}
try{$installer->rollbacks('../Kit');check(false,'backup traversal accepted');}catch(RuntimeException){check(true,'backup selectors reject directory traversal');}
$manifest['version']='1.2.0';$manifest['dependencies']=['Missing'=>'>=1.0.0'];
file_put_contents($root.'/source/Kit/module.json',json_encode($manifest));
$badPackage=$root.'/Kit-bad.bfp';file_put_contents($badPackage,ModulePackage::encode(ModulePackage::build($root.'/source/Kit','dev-signing-key',$secret)));
try{$installer->inspect($badPackage);check(false,'missing module dependency accepted');}catch(RuntimeException){check(true,'dependency compatibility validated before installation');}
$trust->revoke('dev-signing-key');
check($trust->all()===[],'revoking signing key removes package trust');
try{$installer->inspect($package2);check(false,'revoked signer retained trust');}catch(RuntimeException){check(true,'revoked signing keys cannot install packages');}
try{ModulePackage::build($root.'/source/System' ,'dev-signing-key',$secret);check(false,'nonexistent source accepted');}catch(RuntimeException){check(true,'package source existence validated');}
// Test fixture cleanup without following symlinks.
$walker=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
foreach($walker as $node){if($node->isDir()&&!$node->isLink())rmdir($node->getPathname());else unlink($node->getPathname());}
rmdir($root);
sodium_memzero($secret);
