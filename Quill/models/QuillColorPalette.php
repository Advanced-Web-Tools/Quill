<?php
namespace Quill\models;

final class QuillColorPalette extends QuillModel
{
    public ?string $model_source = 'quill_color_palette';
    protected function writableAttributes(): ?array
    {
        return ['name', 'palette_json', 'admin_id', 'created_at', 'updated_at', 'lock_version', 'deleted_at'];
    }
    protected function attributeCasts(): array
    {
        return ['id' => 'int', 'admin_id' => 'int', 'lock_version' => 'int'];
    }
    public function getDefinition(): \stdClass { return $this->decodeDocument('palette_json'); }
    public function setDefinition(\stdClass $definition): void { $this->encodeDocument('palette_json', $definition); }
}
