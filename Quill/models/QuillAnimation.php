<?php
namespace Quill\models;

final class QuillAnimation extends QuillModel
{
    public ?string $model_source = 'quill_animation';
    protected function writableAttributes(): ?array
    {
        return ['name', 'animation_json', 'admin_id', 'created_at', 'updated_at', 'lock_version', 'deleted_at'];
    }
    protected function attributeCasts(): array
    {
        return ['id' => 'int', 'admin_id' => 'int', 'lock_version' => 'int'];
    }
    public function getDefinition(): \stdClass { return $this->decodeDocument('animation_json'); }
    public function setDefinition(\stdClass $definition): void { $this->encodeDocument('animation_json', $definition); }
}
