<?php
namespace Quill\services\editor;

use event\EventDispatcher;
use Quill\api\resources\ExternalDocument;
use Quill\models\{QuillPage, QuillPageSnapshot, QuillBlock, QuillBlockSnapshot, QuillPageSnapshotBlock};
use Quill\services\conversion\quillToUrpc\{ConversionResult, QuillToUrpcConverter};
use Quill\services\conversion\urpcToQuill\UrpcToQuillConverter;
use Quill\services\conversion\shared\ConversionInput;

/** Source-bound pages persist UID patches and structural operations, never a materialized provider tree. */
final class ExternalPageOverrides
{
    private const FIELDS = ['descriptor','attributes','content','styles','bindings','custom_settings','editor','animations','definition_key'];
    public function __construct(private ?EventDispatcher $dispatcher = null) {}

    private function dispatcher(): EventDispatcher
    {
        global $eventDispatcher;
        return $this->dispatcher ?? ($eventDispatcher instanceof EventDispatcher ? $eventDispatcher : throw new \LogicException('External pages require the framework event dispatcher.'));
    }
    public static function resourceKey(string $key, string $kind = 'page'): string
    {
        if (!in_array($kind, ['page','component'], true)) throw new \InvalidArgumentException('Unknown external resource kind.');
        return $kind === 'component' ? 'Quill/external/component/' . $key : $key;
    }
    public static function isComponent(QuillPage $page): bool { return str_starts_with((string)$page->source_key, 'Quill/external/component/'); }
    public function definition(QuillPage $page): ExternalDocument
    {
        $resources = new ExternalResources(); $key = (string)$page->source_key;
        $registry = self::isComponent($page) ? $resources->components($this->dispatcher()) : $resources->pages($this->dispatcher());
        if (self::isComponent($page)) $key = substr($key, strlen('Quill/external/component/'));
        return $registry->get($key) ?? throw new \OutOfBoundsException('The external provider is unavailable. Your saved changes are retained.');
    }
    public function find(string $key, string $kind = 'page'): ?QuillPage
    {
        return (new QuillPage())->newQuery()->where(['source_type'=>'urpc','source_key'=>self::resourceKey($key,$kind),'deleted_at'=>null])->firstModel();
    }
    public function open(ExternalDocument $definition, int $adminId, string $kind = 'page'): QuillPage
    {
        $source = $this->source($definition); // Validate before creating even the owner record.
        if ($kind === 'component' && (count($source['document']->sections)!==1 || count($source['document']->sections[0]->blocks)!==1)) throw new \InvalidArgumentException('External components require one root in one section.');
        $key = self::resourceKey($definition->key, $kind);
        if ($page = $this->find($definition->key, $kind)) return $page;
        if (strlen($key) > 255) throw new \InvalidArgumentException('External page keys must fit in 255 bytes.');
        $page = new QuillPage();
        $page->name=$definition->name; $page->description=$definition->description; $page->admin_id=$adminId;
        $page->source_type='urpc'; $page->source_key=$key; $page->status='draft'; $page->lock_version=1;
        $page->setDocument((object)['schema_version'=>1,'sections'=>[],'settings'=>new \stdClass(),'assets'=>(object)['styles'=>[],'scripts'=>[]]]);
        try {
            if ($page->saveModel() === null) throw new \RuntimeException('Unable to open the external page.');
        } catch (\PDOException|\model\exceptions\ModelCRUDException $error) {
            // The unique source_key also protects concurrent editor opens.
            return $this->find($definition->key, $kind) ?? throw $error;
        }
        return $page;
    }

    /** @return array{document: \stdClass, blocks: array, identities: array, revision: string} */
    private function source(ExternalDocument $definition): array
    {
        $import=(new UrpcToQuillConverter())->convert($definition->document);
        $seen=[];
        foreach ($import->blocks as $id=>$block) {
            $uid=$import->identities[$id]['uid'];
            if ($uid==='' || strlen($uid)>255 || isset($seen[$uid])) throw new \InvalidArgumentException('The provider must supply unique original URPC UIDs for every editable block.');
            $seen[$uid]=true; $block->editor->urpcUid=$uid;
        }
        return ['document'=>$import->document,'blocks'=>$import->blocks,'identities'=>$import->identities,
            'namespace'=>$definition->key,'revision'=>hash('sha256', $this->json([$import->document,$import->blocks,$import->identities]))];
    }
    private function json(mixed $value): string
    {
        $sort=function(mixed $value)use(&$sort):mixed {
            if ($value instanceof \stdClass) { $map=get_object_vars($value); ksort($map); foreach($map as &$item)$item=$sort($item); return (object)$map; }
            if(is_array($value))return array_map($sort,$value);
            return $value;
        };
        return json_encode($sort($value),JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION|JSON_UNESCAPED_UNICODE);
    }
    private function own(\stdClass $block): \stdClass
    {
        $own=ConversionInput::copy($block); unset($own->children,$own->schema_version,$own->kind,$own->editor->urpcUid);
        return $own;
    }
    private function diff(mixed $base, mixed $next, array $path=[]): array
    {
        if ($this->json($base)===$this->json($next))return [];
        if ($base instanceof \stdClass && $next instanceof \stdClass) {
            $ops=[];
            foreach(array_unique([...array_keys(get_object_vars($base)),...array_keys(get_object_vars($next))]) as $key) {
                $at=[...$path,$key];
                if(!property_exists($next,$key))$ops[]=(object)['path'=>$at,'remove'=>true];
                elseif(!property_exists($base,$key))$ops[]=(object)['path'=>$at,'value'=>$next->{$key}];
                else $ops=[...$ops,...$this->diff($base->{$key},$next->{$key},$at)];
            }
            return $ops;
        }
        return [(object)['path'=>$path,'value'=>$next]];
    }
    private function patch(\stdClass $block, \stdClass $record): void
    {
        if (($record->kind??null)!=='override' || ($record->schema_version??null)!==1 || !is_array($record->changes??null)) throw new \UnexpectedValueException('Invalid external block override.');
        foreach($record->changes as $op) {
            $path=$op->path??null;
            if(!is_array($path)||!array_is_list($path)||!count($path)||count($path)>128||!in_array($path[0],self::FIELDS,true))throw new \UnexpectedValueException('Invalid external override path.');
            $target=$block; $last=array_pop($path);
            foreach($path as $part) {
                if(!is_string($part))throw new \UnexpectedValueException('Invalid external override path.');
                if(!isset($target->{$part})||!$target->{$part} instanceof \stdClass)$target->{$part}=new \stdClass();
                $target=$target->{$part};
            }
            if(!is_string($last)||($op->remove??false)!==true&&!property_exists($op,'value'))throw new \UnexpectedValueException('Invalid external override value.');
            if(($op->remove??false)===true)unset($target->{$last});else $target->{$last}=ConversionInput::copy((object)['value'=>$op->value])->value;
        }
    }
    private function records(QuillPage|QuillPageSnapshot $owner): array
    {
        $records=[];
        foreach($owner->getDocument()->sections??[] as $section)foreach($section->blocks??[] as $id) {
            if(!is_int($id)||$id<1)throw new \UnexpectedValueException('Invalid override manifest.');
            if($owner instanceof QuillPageSnapshot) {
                $link=(new QuillPageSnapshotBlock())->useConnectionFrom($owner)->newQuery()->where(['page_snapshot_id'=>$owner->getKey(),'block_id'=>$id])->firstModel()
                    ?? throw new \UnexpectedValueException('Missing override snapshot manifest.');
                $model=(new QuillBlockSnapshot())->useConnectionFrom($owner)->newQuery()->where(['id'=>$link->block_snapshot_id,'block_id'=>$id])->firstModel();
            } else $model=(new QuillBlock())->useConnectionFrom($owner)->newQuery()->where(['id'=>$id,'deleted_at'=>null])->firstModel();
            if(!$model)throw new \UnexpectedValueException('Missing saved override block.');
            $record=$model->getDocument(); $uid=$model->urpc_uid;
            if(!is_string($uid)||$uid===''||($record->urpc_uid??null)!==$uid||isset($records[$uid]))throw new \UnexpectedValueException('Invalid or ambiguous saved override UID.');
            $records[$uid]=$record;
        }
        return $records;
    }
    private function apply(array $source, array $records): array
    {
        $source['document']=ConversionInput::copy($source['document']);
        $source['blocks']=array_map(fn($block)=>ConversionInput::copy($block),$source['blocks']);
        foreach($source['blocks'] as $id=>$block) {
            $uid=$source['identities'][$id]['uid'];
            if(isset($records[$uid]))$this->patch($block,$records[$uid]);
            $block->editor??=new \stdClass(); $block->editor->urpcUid=$uid;
        }
        return (new ExternalStructureOverrides())->apply($source,$records);
    }
    private function conversion(array $source, \stdClass $settings, \stdClass $assets, ?QuillPage $owner=null): ConversionResult
    {
        $source['document']->settings=$settings; $source['document']->assets=$assets;
        $namespace='Quill/external/'.($owner?->source_key??$source['namespace']??'source');
        $contentSource=new EditorContentSource($source['document'],$source['blocks'],$owner);
        $result=(new QuillToUrpcConverter())->convert($contentSource,$namespace);
        $keys=[]; foreach($source['identities'] as $identity)$keys[$identity['uid']]=$identity['source_key'];
        $visit=function(array $blocks)use(&$visit,$result,$keys,$owner,$contentSource):void {
            foreach($blocks as $block) {
                $uid=$result->blockSources[spl_object_id($block)]->editor->urpcUid??null;
                if(is_string($uid) && ($result->blockSourceScopes[spl_object_id($block)]??null)===$contentSource->getScopeKey()) { $block->getBlockDescriptor()->setBlockUid($uid); $block->getBlockDescriptor()->setSourceKey(($keys[$uid]??null)?:'Quill/external/'.($owner?->source_key??'source')); }
                $visit($block->getBlockChildren());
            }
        }; foreach($result->document->getSections() as $blocks)$visit($blocks);
        return $result;
    }
    public function load(QuillPage $page): array
    {
        $definition=$this->definition($page); $source=$this->source($definition); $revision=$source['revision'];
        $source=$this->apply($source,$this->records($page)); $saved=$page->getDocument();
        $settings=$saved->settings??new \stdClass(); $settings->title=$page->name; $settings->description=$page->description??'';
        $assets=$saved->assets??(object)['styles'=>[],'scripts'=>[]];
        $result=$this->conversion($source,$settings,$assets,$page);
        $document=(new EditorDocumentLoader())->grouped($result,new EditorContentSource($source['document'],$source['blocks'],$page),$page); $document->settings=$settings; $document->assets=$assets;
        return ['document'=>$document,'css'=>$result->scopedCss,'assets'=>$result->assets,'sourceRevision'=>$revision,'sourceKey'=>$definition->key,'warnings'=>$source['warnings']??[]];
    }
    /** Public by default: drafts never leak when there is no published override set. */
    public function render(ExternalDocument $definition, ?QuillPage $page=null, bool $draft=false, string $kind='page'): ConversionResult
    {
        $page??=$this->find($definition->key,$kind); $owner=$page;
        $key=self::resourceKey($definition->key,$kind);
        if($page && $page->source_key!==$key)throw new \InvalidArgumentException('The override owner does not match this external resource.');
        if($page && !$draft) {
            $owner=$page->published_snapshot_id===null?null:(new QuillPageSnapshot())->useConnectionFrom($page)->newQuery()->where(['id'=>$page->published_snapshot_id,'page_id'=>$page->getKey(),'source_key'=>$key])->firstModel();
            if($page->published_snapshot_id!==null && !$owner)throw new \UnexpectedValueException('The published override snapshot is missing.');
        }
        if(!$owner) {
            // Convert into a separate tree even before the first publish: render listeners
            // must never receive the provider's mutable document instance.
            $import=(new UrpcToQuillConverter())->convert($definition->document);
            foreach($import->blocks as $id=>$block)$block->editor->urpcUid=$import->identities[$id]['uid'];
            return $this->conversion(['document'=>$import->document,'blocks'=>$import->blocks,'identities'=>$import->identities,'namespace'=>self::resourceKey($definition->key,$kind)],new \stdClass(),(object)['styles'=>[],'scripts'=>[]],$page);
        }
        $saved=$owner->getDocument();
        return $this->conversion($this->apply($this->source($definition),$this->records($owner)),$saved->settings??new \stdClass(),$saved->assets??(object)['styles'=>[],'scripts'=>[]],$page);
    }
    public function componentSource(ExternalDocument $definition): ExternalComponentContentSource
    {
        $page=$this->find($definition->key,'component');
        $import=(new UrpcToQuillConverter())->convert($definition->document);
        if(count($import->document->sections)!==1||count($import->document->sections[0]->blocks)!==1)throw new \InvalidArgumentException('External components require one root in one section.');
        if($page && $page->published_snapshot_id!==null) {
            $snapshot=(new QuillPageSnapshot())->useConnectionFrom($page)->newQuery()->where(['id'=>$page->published_snapshot_id,'page_id'=>$page->getKey(),'source_key'=>self::resourceKey($definition->key,'component')])->firstModel()
                ??throw new \UnexpectedValueException('Published external component version is missing.');
            $source=$this->apply($this->source($definition),$this->records($snapshot));
            $saved=$snapshot->getDocument(); $source['document']->settings=$saved->settings??new \stdClass(); $source['document']->assets=$saved->assets??(object)['styles'=>[],'scripts'=>[]];
            return new ExternalComponentContentSource($definition->key,$source['document'],$source['blocks'],$page);
        }
        return new ExternalComponentContentSource($definition->key,$import->document,$import->blocks,$page);
    }
    public function publishedComponentDocument(ExternalDocument $definition): \stdClass
    {
        $page=$this->find($definition->key,'component');
        if(!$page || $page->published_snapshot_id===null)return (new ExternalResources())->document($definition,true);
        $result=$this->render($definition,$page,false,'component');
        $document=(new EditorDocumentLoader())->grouped($result,$this->componentSource($definition),$page);
        $snapshot=(new QuillPageSnapshot())->useConnectionFrom($page)->newQuery()->where(['id'=>$page->published_snapshot_id,'page_id'=>$page->getKey()])->firstModel();
        $saved=$snapshot->getDocument(); $document->settings=$saved->settings??new \stdClass(); $document->assets=(object)$result->assets;
        return $document;
    }
    public function save(QuillPage $page, \stdClass $input, int $version, string $mode, int $adminId, string $sourceRevision): array
    {
        if(!in_array($mode,['draft','publish'],true))throw new \InvalidArgumentException('Choose draft or publish.');
        $source=$this->source($this->definition($page));
        if(!hash_equals($source['revision'],$sourceRevision))throw new \DomainException('The provider document changed. Reload before saving; your current edits have not been saved.');
        if(self::isComponent($page) && isset($input->settings->styles) && get_object_vars(ConversionInput::object($input->settings->styles,'component/styles'))!==[])throw new \InvalidArgumentException('Use the component block inspector for styling; page-wide body styles do not belong to a component.');
        $mapped=(new EditorDocumentMapper())->map($input,$page->getDocument(),$page);
        if(self::isComponent($page) && (count($mapped->document->sections)!==1 || count($mapped->document->sections[0]->blocks)!==1))throw new \InvalidArgumentException('An external component must keep exactly one root block. Add blocks inside its root container.');
        $structure=new ExternalStructureOverrides();
        $submitted=$structure->index($mapped->document,$mapped->blocks);
        $records=$structure->changes($source,$mapped->document,$mapped->blocks,$this->records($page));
        foreach($source['blocks'] as $id=>$original) {
            $uid=$source['identities'][$id]['uid'];
            if(!isset($submitted[$uid]))continue;
            $next=$mapped->blocks[$submitted[$uid]['id']];
            if($next->kind!=='element')throw new \InvalidArgumentException('Replace a provider block with a newly inserted component instead of changing its identity.');
            $changes=$this->diff($this->own($original),$this->own($next));
            if($changes) {
                $records[$uid]??=(object)['schema_version'=>1,'kind'=>'override','urpc_uid'=>$uid];
                $records[$uid]->changes=$changes;
            }
        }
        return $this->persist($page,$records,$mapped->document->settings,$mapped->document->assets,$version,$mode,$adminId);
    }
    public function restore(QuillPage $page, QuillPageSnapshot $snapshot, int $version, int $adminId): void
    {
        if($snapshot->page_id!==$page->getKey()||$snapshot->source_type!=='urpc'||$snapshot->source_key!==$page->source_key)throw new \OutOfBoundsException('Override version not found.');
        $doc=$snapshot->getDocument(); $settings=$doc->settings??new \stdClass(); $settings->title=$snapshot->name; $settings->description=$snapshot->description??'';
        $this->persist($page,$this->records($snapshot),$settings,$doc->assets??(object)['styles'=>[],'scripts'=>[]],$version,'draft',$adminId);
    }
    private function persist(QuillPage $page, array $records, \stdClass $settings, \stdClass $assets, int $version, string $mode, int $adminId): array
    {
        return $page->transaction(function()use($page,$records,$settings,$assets,$version,$mode,$adminId):array {
            if(!$page->table('quill_page')->where(['id'=>$page->getKey(),'lock_version'=>$version,'source_type'=>'urpc','source_key'=>$page->source_key,'deleted_at'=>null])->update(['lock_version'=>$version+1,'updated_at'=>date('Y-m-d H:i:s')]))throw new \DomainException('This page changed. Reload before saving.');
            $ids=[]; $rows=[]; $old=$this->records($page); $oldIds=[];
            foreach($page->getDocument()->sections??[] as $section)foreach($section->blocks??[] as $id) {
                $model=(new QuillBlock())->useConnectionFrom($page)->newQuery()->where(['id'=>$id,'deleted_at'=>null])->firstModel(); if($model)$oldIds[$model->urpc_uid]=$id;
            }
            foreach($records as $uid=>$record) {
                if(isset($old[$uid]) && $this->json($old[$uid])===$this->json($record))$id=$oldIds[$uid];
                else {
                    $block=(new QuillBlock())->useConnectionFrom($page); $block->urpc_uid=(string)$uid; $block->admin_id=$adminId; $block->setDocument($record);
                    $id=$block->saveModel()??throw new \RuntimeException('Unable to save the changed block.');
                }
                $ids[]=$id; $rows[$id]=$record;
            }
            $document=(object)['schema_version'=>1,'sections'=>[(object)['name'=>'overrides','blocks'=>$ids]],'settings'=>$settings,'assets'=>$assets];
            $json=json_encode($document,JSON_THROW_ON_ERROR); $name=$settings->title??$page->name; $description=$settings->description??$page->description; $snapshotId=null;
            if($mode==='publish') {
                $snapshot=(new QuillPageSnapshot())->useConnectionFrom($page);
                $latest=$snapshot->newQuery()->where(['page_id'=>$page->getKey()])->orderBy('revision','DESC')->firstModel();
                $snapshot->page_id=$page->getKey(); $snapshot->revision=($latest?->revision??0)+1; $snapshot->name=$name; $snapshot->description=$description;
                $snapshot->source_type='urpc'; $snapshot->source_key=$page->source_key; $snapshot->admin_id=$adminId; $snapshot->setDocument($document);
                $snapshotId=$snapshot->saveModel()??throw new \RuntimeException('Unable to publish overrides.');
                foreach($rows as $id=>$record) {
                    $blockSnapshot=(new QuillBlockSnapshot())->useConnectionFrom($page); $blockSnapshot->block_id=$id; $blockSnapshot->revision=1;
                    $blockSnapshot->urpc_uid=$record->urpc_uid; $blockSnapshot->admin_id=$adminId; $blockSnapshot->setDocument($record);
                    $blockSnapshotId=$blockSnapshot->saveModel()??throw new \RuntimeException('Unable to snapshot an override.');
                    $link=(new QuillPageSnapshotBlock())->useConnectionFrom($page); $link->page_snapshot_id=$snapshotId; $link->block_id=$id; $link->block_snapshot_id=$blockSnapshotId;
                    if($link->saveModel()===null)throw new \RuntimeException('Unable to save override manifest.');
                }
            }
            $changes=['page_json'=>$json,'name'=>$name,'description'=>$description,'status'=>$mode==='publish'?'published':'draft'];
            if($snapshotId!==null)$changes['published_snapshot_id']=$snapshotId;
            $page->table('quill_page')->where(['id'=>$page->getKey()])->update($changes);
            return ['lock_version'=>$version+1,'mode'=>$mode,'snapshot_id'=>$snapshotId];
        });
    }
}
