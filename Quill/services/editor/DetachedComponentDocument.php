<?php
namespace Quill\services\editor;

use Quill\models\QuillComponent;
use Quill\services\conversion\quillToUrpc\QuillContentSource;
use Quill\services\conversion\quillToUrpc\QuillToUrpcConverter;
use Quill\services\conversion\shared\ConversionInput;

/** Read-only materialization through the existing converter: no component or block rows are written. */
final class DetachedComponentDocument
{
    private array $bindings = [];
    private array $animations = [];
    private array $targets = [];
    private string $rootScope;

    public function create(QuillComponent $component, string $instance): array
    {
        if (!preg_match('/^qc-[a-zA-Z0-9_-]{1,80}$/D', $instance)) throw new \InvalidArgumentException('Invalid component instance.');
        $reference = (object) ['schema_version'=>1,'kind'=>'component','component_id'=>$component->getKey(),
            'editor'=>(object)['componentInstanceId'=>$instance]];
        $source = new EditorContentSource((object)['schema_version'=>1,'sections'=>[(object)['name'=>'main','blocks'=>[1]]]], [1=>$reference]);
        $result = (new QuillToUrpcConverter())->convert($source);
        $transport = (new EditorDocumentLoader())->expanded($result);
        $nested = $source->getComponent($component->getKey(), 'detach');
        $this->rootScope = $nested->getScopeKey();
        $this->bindings=[];$this->animations=[];$this->targets=[];
        $offset=0;
        $roots=$this->document($nested, $transport->sections[0]->blocks, $offset);
        $paths = new \SplObjectStorage();
        $visit = function (array $nodes, array $prefix=[]) use (&$visit,$paths): void {
            foreach ($nodes as $index=>$node) {$path=[...$prefix,$index];$paths[$node]=$path;$visit($node->children,$path);}
        };
        $visit($roots);
        $bindings=[];
        foreach ($this->bindings as [$binding,$node]) {
            $binding->target=(object)['section'=>'main','path'=>$paths[$node]];$bindings[]=$binding;
        }
        return ['document'=>(object)['schema_version'=>1,'sections'=>[(object)['name'=>'main','blocks'=>$roots]],
            'settings'=>(object)['actions'=>$result->actions,'animations'=>(object)$this->animations,'animation_bindings'=>$bindings],
            'assets'=>(object)$result->assets], 'targetMap'=>(object)$this->targets];
    }

    private function document(QuillContentSource $source, array $nodes, int &$offset, ?\stdClass $inheritedBreakpoints = null): array
    {
        $document=ConversionInput::decode($source->getDocument('detach'),'detach');
        $breakpoints=$document->settings->breakpoints??$inheritedBreakpoints;
        $targets=[];$roots=[];
        $group=function(array $references,array $nodes,int &$offset,string $section,array $prefix=[]) use (&$group,&$targets,$source,$breakpoints):array {
            $result=[];
            foreach($references as $index=>$id){
                $input=ConversionInput::decode($source->getBlock($id,'detach/block'),'detach/block');
                $path=[...$prefix,$index];
                if($input->kind==='component'){
                    $children=$this->document($source->getComponent($input->component_id,'detach/component'),$nodes,$offset,$breakpoints);
                    foreach($children as $child) if(!empty($input->animations)) $child->_quill->animations=$this->mergeAnimations($child->_quill->animations??[],$input->animations);
                    $targets[json_encode([$section,$path])]=$children;
                    array_push($result,...$children);
                }else{
                    $node=$nodes[$offset++]??throw new \LogicException('Component expansion did not match its source.');
                    $childOffset=0;$node->children=$group($input->children??[],$node->children,$childOffset,$section,$path);
                    $node->_quill??=new \stdClass();$node->_quill->editor??=new \stdClass();
                    if($breakpoints!==null&&!isset($node->_quill->editor->styleBreakpoints))$node->_quill->editor->styleBreakpoints=ConversionInput::copy($breakpoints);
                    if($source->getScopeKey()===$this->rootScope&&isset($input->editor->actionTargetId,$node->_quill->editor->actionTargetId))$this->targets[$input->editor->actionTargetId]=$node->_quill->editor->actionTargetId;
                    // Old preview scope/animation classes are generated again from editable metadata.
                    if(isset($node->attributes->class)){
                        $classes=trim(preg_replace('/(?:^|\s)(?:quill-scope-[a-f0-9]{64}|quill-animation-[a-f0-9]{24})(?=\s|$)/','',$node->attributes->class));
                        if($classes==='')unset($node->attributes->class);else$node->attributes->class=$classes;
                    }
                    $targets[json_encode([$section,$path])]=[$node];$result[]=$node;
                }
            }
            return $result;
        };
        foreach($document->sections as $section)array_push($roots,...$group($section->blocks,$nodes,$offset,$section->name));
        $ids=[];
        foreach(get_object_vars($document->settings->animations??new \stdClass())as$id=>$animation){
            $copy=ConversionInput::copy($animation);$copy->id='qanim-'.bin2hex(random_bytes(16));$ids[$id]=$copy->id;$this->animations[$copy->id]=$copy;
        }
        foreach($document->settings->animation_bindings??[]as$binding)foreach($targets[json_encode([$binding->target->section,$binding->target->path])]??[]as$node){
            $copy=ConversionInput::copy($binding);$copy->id='qbinding-'.bin2hex(random_bytes(16));$copy->animationId=$ids[$binding->animationId];$this->bindings[]=[$copy,$node];
        }
        return $roots;
    }

    private function mergeAnimations(array $own,array $parent):array
    {
        foreach($parent as $binding){$own=array_values(array_filter($own,static fn($item)=>$item->animation_id!==$binding->animation_id));$own[]=ConversionInput::copy($binding);}return $own;
    }
}
