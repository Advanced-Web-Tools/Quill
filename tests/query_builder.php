<?php
// Real AWT QueryBuilder/Model/ModelCollection, SQLite, URPC and persistence.
ob_start();
require __DIR__ . '/connect_data.php';

use Quill\services\data\ConnectionQuery;
use Quill\services\data\ConnectionQueryBuilder;
use Quill\services\data\DataConnection;
use Quill\services\data\ModelConnectionRecords;

$queryStart=$count;
$schema=database\creator\TableWizard::create();
$schema->addColumn(database\creator\ColumnCreator::INT('id')->primary()->autoIncrement());
foreach(['title','status','category','note'] as $column)$schema->addColumn(database\creator\ColumnCreator::TEXT($column)->nullable());
foreach(['price','featured'] as $column)$schema->addColumn(database\creator\ColumnCreator::INT($column));
check($schema->createTable('query_products'),'Create a registered source for typed query tests');
foreach([
 ['Alpha 100%_!',10,'live','books',1,null],['Beta',20,'draft','books',0,''],['Gamma',30,'live','games',0,'present'],
 ['Delta',40,'live','games',1,''],['Echo',50,'draft','games',1,null],['Zeta',60,'live','books',0,'present'],
] as [$title,$price,$status,$category,$featured,$note])$database->table('query_products')->insert(compact('title','price','status','category','featured','note'))->executeInsert();
$base=['source'=>'query_products','select'=>'id','mode'=>'collection','show'=>'all','sort_by'=>'id','sort'=>'asc','set'=>['content'=>['content'=>'title']]];
function rule(string $column,string $operator,mixed $value=null,string $type='text'):array {
 $rule=['column'=>$column,'operator'=>$operator];if(!in_array($operator,ConnectionQuery::UNARY,true))$rule['value']=['source'=>'literal','type'=>$type,'value'=>$value];return $rule;
}
function groupRules(array $conditions,string $match='all'):array{return ['match'=>$match,'conditions'=>$conditions];}
function querySpec(array $where):array{return ['version'=>1,'where'=>$where];}
function queryRows(array $where,array $parameters=[],array $override=[]):array {
 global $base;
 $connection=DataConnection::parse(array_replace($base,['query'=>querySpec($where)],$override));
 return [...(new ModelConnectionRecords())->find($connection,$parameters)];
}
function queryIds(array $where,array $parameters=[],array $override=[]):array{return array_map(static fn($model)=>$model->getParam('id'),queryRows($where,$parameters,$override));}

$filter=groupRules([rule('price','gte',30,'number'),rule('status','eq','live')]);
check(queryIds($filter)===[3,4,6],'All combines numeric and equality conditions');
check(queryIds($filter,[],['show'=>2])===[3,4],'Filters run in the SELECT before the collection limit');
check(queryIds($filter,[],['show'=>2,'sort'=>'desc'])===[6,4],'Framework sorting and limit apply after filtering');
check(queryRows($filter)[0] instanceof model\Model,'Filters hydrate actual framework Models');
$nested=groupRules([rule('category','eq','books'),groupRules([rule('featured','eq',true,'boolean'),rule('price','gte',60,'number')],'any')]);
check(queryIds($nested)===[1,6],'Nested Any is grouped within the surrounding All conditions');
check(queryIds(groupRules([rule('status','eq','draft'),groupRules([])],'any'))===[2,5],'Empty editing groups cannot broaden an Any query');
check(queryIds(groupRules([]))===[1,2,3,4,5,6],'Empty root preserves legacy collection behavior');
check(queryIds(groupRules([rule('status','eq','draft')]),[],['mode'=>'single','default_value'=>2])===[2],'Single matching value can be narrowed with filters');
check(queryIds(groupRules([rule('status','eq','live'),rule('featured','eq',false,'boolean')],'any'),[],['mode'=>'single','default_value'=>2])===[2],'An OR group remains ANDed with single-record identity');
check(queryIds(groupRules([rule('status','eq','live')]),[],['mode'=>'single','default_value'=>2])===[],'Filters cannot bypass the single-record match');

foreach([
 ['ne','status','live','text',[2,5]],['gt','price',40,'number',[5,6]],['gte','price',40,'number',[4,5,6]],
 ['lt','price',30,'number',[1,2]],['lte','price',30,'number',[1,2,3]],['contains','title','ta','text',[2,4,6]],
 ['not_contains','title','ta','text',[1,3,5]],['starts_with','title','Gam','text',[3]],['ends_with','title','ta','text',[2,4,6]],
 ['in','id',[1,3,6],'number',[1,3,6]],['not_in','id',[1,3,6],'number',[2,4,5]],['between','price',[20,40],'number',[2,3,4]],
 ['is_null','note',null,'text',[1,5]],['not_null','note',null,'text',[2,3,4,6]],['is_empty','note',null,'text',[1,2,4,5]],['not_empty','note',null,'text',[3,6]],
 ['contains','title','%_!','text',[1]],['eq','featured',false,'boolean',[2,3,6]],
] as [$operator,$column,$value,$type,$expected])check(queryIds(groupRules([rule($column,$operator,$value,$type)]))===$expected,'Execute '.$operator.' through the framework with typed values');
check(queryIds(groupRules([rule('title','contains',"' OR 1=1 --")]))===[],'SQL-looking entered values remain bound data');
check(queryIds(groupRules([rule('missing_column','eq','anything')]))===[],'Unavailable filter columns fall back instead of dropping restrictions');

$route=['column'=>'price','operator'=>'gte','value'=>['source'=>'route','type'=>'number','parameter'=>'minimum']];
check(queryIds(groupRules([$route]),['minimum'=>'40'])===[4,5,6],'Typed route value supplies a numeric condition');
check(queryIds(groupRules([$route]))===[],'Missing route detail without fallback returns no records');
check(queryIds(groupRules([$route,rule('status','eq','live')],'any'))===[],'Unresolved route condition rejects the complete query, including OR groups');
check(queryIds(groupRules([$route]),['minimum'=>['40']])===[],'Array route values cannot enter filter bindings');
check(queryIds(groupRules([$route]),['minimum'=>'invalid'])===[],'Invalid numeric route detail cannot silently broaden the query');
$route['value']['default_value']=50;
check(queryIds(groupRules([$route]))===[5,6],'Explicit route fallback is typed and applied');
check(queryIds(groupRules([$route]),['minimum'=>'0'])===[1,2,3,4,5,6],'Zero route value is not mistaken for a missing value');

$resolved=ConnectionQuery::resolve(ConnectionQuery::parse(querySpec($nested)),[]);
$builder=(new ConnectionQueryBuilder($resolved))->table('`query_products`')->select()->where(['`id`'=>1])->orderBy('`id`','DESC');
$payload=$builder->buildSelect(5);
check(isset($payload->bindings[':p1'],$payload->bindings[':quill_filter_0'],$payload->bindings[':limit']) && !str_contains($payload->sql,'books'),'Filter bindings coexist with AWT match and limit parameters without interpolating values');
$builder->reset();$builder->table('`query_products`')->select();
check(count($builder->buildSelect()->bindings)===3,'Framework reset and model clone retain immutable connection filters without stale bindings');

$invalid=[
 ['version'=>2,'where'=>groupRules([])],['version'=>1,'sql'=>'DELETE','where'=>groupRules([])],
 querySpec(groupRules([rule('id; DROP TABLE x','eq',1)])),querySpec(groupRules([rule('id','raw','x')])),
 querySpec(groupRules([rule('price','gt','NaN','number')])),querySpec(groupRules([rule('featured','eq','maybe','boolean')])),
 querySpec(groupRules([rule('id','in',[],'number')])),querySpec(groupRules([rule('id','between',[1,2,3],'number')])),
 querySpec(groupRules([rule('title','contains',2,'number')])),querySpec(groupRules(array_fill(0,65,rule('id','eq',1)))),
 querySpec(groupRules([['column'=>'id','operator'=>'eq','value'=>['source'=>'expression','type'=>'text','value'=>'1+1']]])),
];
$deep=groupRules([rule('id','eq',1)]);for($i=0;$i<6;$i++)$deep=groupRules([$deep]);$invalid[]=querySpec($deep);
foreach($invalid as $bad){try{ConnectionQuery::parse(json_decode(json_encode($bad)));throw new RuntimeException('Expected invalid filters to fail');}catch(InvalidArgumentException){check(true,'Invalid or unsupported query definition is rejected');}}

$owner=request('page',['_token'=>'test-csrf','name'=>'Filtered catalog'],201);
$props=array_replace($base,['show'=>2,'query'=>querySpec($filter)]);
$transport=['schema_version'=>1,'sections'=>[['name'=>'main','blocks'=>[['descriptor'=>['name'=>'p','props'=>$props,'renderer'=>DataConnection::RENDERER],'attributes'=>new stdClass(),'content'=>'Filtered fallback','children'=>[]]]]]];
$save=documentRequest(['type'=>'page','id'=>(string)$owner['id']],['_token'=>'test-csrf','lock_version'=>'1','mode'=>'publish','document'=>json_encode($transport)],200);
$ownerModel=new Quill\models\QuillPage();$ownerModel->selectByID($owner['id']);
$opened=(new Quill\services\editor\EditorDocumentLoader())->load($ownerModel)['document']->sections[0]->blocks[0];
check(json_encode($opened->descriptor->props->query)===json_encode($props['query']),'Filter AST survives draft/publish and editor reopening without data loss');
check($opened->content==='Filtered fallback','Saving query settings leaves authored fallback intact');
$html=publicController($ownerModel)->render();
check(str_contains($html,'Gamma')&&str_contains($html,'Delta')&&!str_contains($html,'Alpha')&&!str_contains($html,'Zeta'),'Published URPC output uses filters before Show 2');
$before=$ownerModel->page_json;publicController($ownerModel)->render();check($ownerModel->page_json===$before,'Query rendering does not mutate persisted page data');
$transport['sections'][0]['blocks'][0]['descriptor']['props']['query']=$invalid[0];
documentRequest(['type'=>'page','id'=>(string)$owner['id']],['_token'=>'test-csrf','lock_version'=>'2','mode'=>'draft','document'=>json_encode($transport)],422);

echo 'Passed '.($count-$queryStart)." visual-query definition / ModelCollection / URPC checks.\n";
ob_end_flush();
