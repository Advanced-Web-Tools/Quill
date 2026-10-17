<?php
$framework=getenv('AWT_FRAMEWORK_CLASSES')?:dirname(__DIR__,3).'/Advanced-Web-Tools-Framework/awt_src/classes';
spl_autoload_register(static function(string $class)use($framework):void{$relative=str_replace('\\','/',$class).'.php';$path=str_starts_with($class,'Quill\\')?dirname(__DIR__).'/'.$relative:$framework.'/'.$relative;if(is_file($path))require_once $path;});
$tmp=sys_get_temp_dir().'/quill-dashboard-fixture-'.bin2hex(random_bytes(5));
mkdir($tmp.'/Dashboard/views/templates',0700,true);mkdir($tmp.'/Quill/views/templates',0700,true);mkdir($tmp.'/compiled');
register_shutdown_function(static function()use($tmp):void{if(session_status()===PHP_SESSION_ACTIVE)session_write_close();foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST)as$file)$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());rmdir($tmp);});
session_save_path($tmp);session_start();$_SESSION['_token']='dashboard-test-token';
define('HOSTNAME',getenv('EDITOR_TEST_ORIGIN')?:'http://localhost');define('PACKAGES',$tmp.'/');
$css=file_get_contents(dirname(__DIR__,2).'/Dashboard/Dashboard/views/assets/css/main.css');
file_put_contents($tmp.'/Dashboard/views/templates/main.awt.php','<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Quill dashboard</title><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css"><style>'.$css.'</style></head><body>@yield("content")</body></html>');
foreach(['index','components','routes','templates','history','templates/quill_dashboard']as$file)copy(dirname(__DIR__).'/Quill/views/'.$file.'.awt.php',$tmp.'/Quill/views/'.$file.'.awt.php');
$make=static function(int$id,string$name,string$status='draft',?int$published=null){return new class($id,$name,$status,$published){
    public string $description='A thoughtfully designed page.';public string $created_at='2026-10-15 09:30:00';public string $updated_at='2026-10-16 17:20:00';public int $admin_id=1;public int $lock_version=3;
    public function __construct(private int$id,public string$name,public string$status,public ?int$published_snapshot_id){} public function getKey():int{return $this->id;}
};};
$pages=[$make(1,'Landing page','published',7),$make(2,'Contact')];$templates=[$make(3,'My starter template')];
$mode=$argv[1]??'pages';$view=match($mode){'templates'=>'templates','history'=>'history','routes'=>'routes','components','external-components'=>'components',default=>'index'};
$params=['tab'=>match($mode){'templates'=>'Templates','routes'=>'Routes','components','external-components'=>'Components',default=>'Pages'},'pages'=>$pages,'components'=>[$make(4,'Site header'),$make(5,'Product card')],'templates'=>$templates,'pageTemplates'=>$templates,'authors'=>[1=>'stefan'],'pageUrls'=>[1=>'/welcome'],'page'=>$pages[0],
    'routes'=>[new class {public string $path='/welcome';public int $page_id=1;public string $created_at='2026-10-15 09:30:00';public string $updated_at='2026-10-16 17:20:00';public function getKey():int{return 1;}}],'pageNames'=>[1=>'Landing page'],
    'snapshots'=>[new class { public int $revision=2;public string $name='Landing page';public string $created_at='2026-10-16 17:00:00';public int $admin_id=1;public function getKey():int{return 7;}},new class {public int $revision=1;public string $name='First version';public string $created_at='2026-10-15 09:30:00';public int $admin_id=1;public function getKey():int{return 6;}}]];
if(str_starts_with($mode,'external-')){
    $params['externalPages']=[['id'=>'Shop:catalogue','name'=>'Product catalogue','description'=>'Products supplied by the shop package.','provider'=>'Shop','url'=>'/products']];
    $params['externalComponents']=[['id'=>'Shop:card','name'=>'Product card','description'=>'A provider-owned component.','provider'=>'Shop','url'=>null]];
}
$blade=new render\TemplateEngine\BladeOne($tmp,$tmp.'/compiled',5);$blade->setFileExtension('.awt.php');$blade->addAssetDict(0,'/Quill/views/assets');echo $blade->run('Quill.views.'.$view,$params);
