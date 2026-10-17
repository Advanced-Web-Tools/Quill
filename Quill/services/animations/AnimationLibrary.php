<?php
namespace Quill\services\animations;

use Quill\models\QuillAnimation;
use Quill\models\QuillModel;

/** Shared definitions; structural blocks store only the database identity and trigger. */
final class AnimationLibrary
{
    public function __construct(private ?QuillModel $connection = null) {}
    private function model(): QuillAnimation
    {
        $model = new QuillAnimation();
        if ($this->connection) $model->useConnectionFrom($this->connection);
        return $model;
    }
    public function all(): array
    {
        return array_map($this->summary(...), $this->model()->newQuery()->where(['deleted_at' => null])->orderBy('name', 'ASC')->getModels());
    }
    public function find(int $id): ?QuillAnimation
    {
        return $this->model()->newQuery()->where(['id' => $id, 'deleted_at' => null])->firstModel();
    }
    public function definition(int $id): ?\stdClass { return $this->find($id)?->getDefinition(); }
    public function summary(QuillAnimation $animation): array
    {
        return ['id' => $animation->getKey(), 'name' => $animation->name, 'version' => $animation->lock_version,
            'admin_id' => $animation->admin_id, 'created_at' => $animation->created_at, 'updated_at' => $animation->updated_at];
    }
    public function save(\stdClass $definition, int $adminId, ?int $id = null, ?int $version = null): array
    {
        AnimationDefinitions::validate((object) [($definition->id ?? '') => $definition]);
        $model = $id === null ? $this->model() : ($this->find($id) ?? throw new \OutOfBoundsException('Animation not found.'));
        if ($id === null) {
            $model->name = trim($definition->name); $model->admin_id = $adminId;
            $model->lock_version = 1; $model->setDefinition($definition);
            if ($model->saveModel() === null) throw new \RuntimeException('Animation insert failed.');
            $model = $this->find((int) $model->getKey()) ?? throw new \RuntimeException('Animation could not be reloaded.');
        } else {
            if ($version !== $model->lock_version) throw new \DomainException('This animation changed. Reopen it before replacing it.');
            $changed = $model->table('quill_animation')->where(['id' => $id, 'lock_version' => $version, 'deleted_at' => null])->update([
                'name' => trim($definition->name), 'animation_json' => json_encode($definition, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'updated_at' => date('Y-m-d H:i:s'), 'lock_version' => $version + 1,
            ]);
            if (!$changed) throw new \DomainException('This animation changed. Reopen it before replacing it.');
            $model = $this->find($id) ?? throw new \OutOfBoundsException('Animation not found.');
        }
        return $this->summary($model) + ['definition' => $model->getDefinition()];
    }
    public function delete(int $id, int $version): void
    {
        $model = $this->find($id) ?? throw new \OutOfBoundsException('Animation not found.');
        if ($model->lock_version !== $version || !$model->table('quill_animation')->where(['id' => $id, 'lock_version' => $version, 'deleted_at' => null])->update([
            'deleted_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'), 'lock_version' => $version + 1,
        ])) throw new \DomainException('This animation changed. Refresh the library before deleting it.');
    }
    public static function validateBindings(mixed $bindings): array
    {
        $bindings = \Quill\services\conversion\shared\ConversionInput::orderedList($bindings, 'block/animations');
        if (count($bindings) > 20) throw new \InvalidArgumentException('A block supports up to 20 animations.');
        $ids = [];
        foreach ($bindings as $binding) {
            if (!$binding instanceof \stdClass || array_diff(array_keys(get_object_vars($binding)), ['animation_id', 'trigger'])
                || !is_int($binding->animation_id ?? null) || $binding->animation_id < 1 || $binding->animation_id > 9007199254740991
                || isset($ids[$binding->animation_id]) || !in_array($binding->trigger ?? null, ['load', 'click', 'hover', 'focus'], true)) throw new \InvalidArgumentException('Invalid block animation reference.');
            $ids[$binding->animation_id] = true;
        }
        return $bindings;
    }
}
