<?php
namespace Quill\models;

final class QuillPageTemplate extends QuillModel
{
    public ?string $model_source = 'quill_page_template';
    protected function writableAttributes(): ?array { return ['name','description','template_json','admin_id','created_at','updated_at','lock_version','deleted_at']; }
    protected function attributeCasts(): array { return ['id'=>'int','admin_id'=>'int','lock_version'=>'int']; }
    public function getDocument(): \stdClass { return $this->decodeDocument('template_json'); }
    public function setDocument(\stdClass $document): void { $this->encodeDocument('template_json',$document); }
}
