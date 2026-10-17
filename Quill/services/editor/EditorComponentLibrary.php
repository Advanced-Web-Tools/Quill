<?php

namespace Quill\services\editor;

use Quill\models\QuillComponent;
use Quill\services\conversion\shared\ConversionInput;

final class EditorComponentLibrary
{
    public function all(): array
    {
        return array_map($this->summary(...), (new QuillComponent())->newQuery()->where(['deleted_at' => null])->orderBy('name', 'ASC')->getModels());
    }

    public function summary(QuillComponent $component): array
    {
        return ['id' => $component->getKey(), 'name' => $component->name, 'description' => $component->description ?? '', 'version' => $component->lock_version];
    }

    public function document(QuillComponent $component): \stdClass
    {
        if (get_object_vars($component->getDocument()) === []) throw new \InvalidArgumentException('This component is empty. Add blocks in its editor first.');
        return (new EditorDocumentLoader())->load($component)['document'];
    }

    public function create(string $name, ?string $description, \stdClass $document, int $adminId): array
    {
        $sections = ConversionInput::orderedList($document->sections ?? null, 'document/sections');
        if (count($sections) !== 1 || count(ConversionInput::orderedList($sections[0]->blocks ?? null, 'section/blocks')) !== 1) {
            throw new \InvalidArgumentException('Select one block with its children to create a component.');
        }
        $document->settings = ConversionInput::object($document->settings ?? new \stdClass(), 'document/settings');
        $document->settings->title = $name;
        $document->settings->description = $description ?? '';
        $owner = new QuillComponent();
        return $owner->transaction(function () use ($owner, $name, $description, $document, $adminId): array {
            $owner->lock_version = 1;
            $owner->name = $name; $owner->description = $description; $owner->admin_id = $adminId;
            $owner->setDocument(new \stdClass());
            if ($owner->saveModel() === null) throw new \RuntimeException('Component insert failed.');
            $saved = (new EditorDocumentSaver())->save($owner, $document, 1, 'draft', $adminId);
            $owner->lock_version = $saved['lock_version'];
            return $this->summary($owner);
        });
    }
}
