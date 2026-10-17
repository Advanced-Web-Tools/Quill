<?php

namespace Quill\services\actions;

use Quill\services\conversion\shared\ConversionInput;
use Quill\services\conversion\quillToUrpc\QuillStyleConverter;

final class ActionDefinitions
{
    public const BINDINGS = ['click' => 'onClick', 'dblclick' => 'onDoubleClick', 'mouseenter' => 'onMouseEnter', 'mouseleave' => 'onMouseLeave', 'focus' => 'onFocus', 'blur' => 'onBlur', 'change' => 'onChange', 'submit' => 'onSubmit', 'keydown' => 'onKeyDown'];
    public static function attribute(string $trigger): string { return $trigger === 'click' ? 'data-quill-action' : 'data-quill-on-' . $trigger; }
    private static function fields(\stdClass $value, array $allowed): void
    {
        if (array_diff(array_keys(get_object_vars($value)), $allowed)) throw new \InvalidArgumentException('Unsupported action field.');
    }

    public static function id(mixed $id): void
    {
        if (!is_string($id) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,99}$/D', $id)) throw new \InvalidArgumentException('Invalid action identity.');
    }

    private static function text(mixed $value, string $field, int $limit = 1024): void
    {
        if (!is_string($value) || trim($value) === '' || strlen($value) > $limit) throw new \InvalidArgumentException('Invalid action ' . $field . '.');
    }

    public static function catalog(mixed $actions): array
    {
        $actions = ConversionInput::orderedList($actions, 'settings/actions');
        if (count($actions) > 200) throw new \InvalidArgumentException('Maximum 200 actions per document.');
        $ids = [];
        foreach ($actions as $action) {
            $action = ConversionInput::object($action, 'action');
            self::fields($action, ['id', 'name', 'trigger', 'key', 'steps']); self::id($action->id ?? null);
            if (isset($ids[$action->id])) throw new \InvalidArgumentException('Duplicate action identity.');
            $ids[$action->id] = true;
            self::text($action->name ?? null, 'name', 255);
            if (!is_string($action->trigger ?? null) || !isset(self::BINDINGS[$action->trigger])) throw new \InvalidArgumentException('Unsupported action trigger.');
            if (property_exists($action, 'key') && ($action->trigger !== 'keydown' || !in_array($action->key, ['', 'Enter', ' ', 'Escape', 'Tab', 'ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'], true))) throw new \InvalidArgumentException('Invalid action key.');
            $steps = ConversionInput::orderedList($action->steps ?? null, 'action/steps');
            if (count($steps) < 1 || count($steps) > 50) throw new \InvalidArgumentException('An action requires 1–50 steps.');
            $stepIds = [];
            foreach ($steps as $step) {
                $step = ConversionInput::object($step, 'action/step');
                self::fields($step, ['id', 'target', 'condition', 'operation']); self::id($step->id ?? null);
                if (isset($stepIds[$step->id])) throw new \InvalidArgumentException('Duplicate action step identity.');
                $stepIds[$step->id] = true;
                $target = ConversionInput::object($step->target ?? null, 'action/target');
                self::fields($target, ['type', 'value']);
                if (!in_array($target->type ?? null, ['self', 'parent', 'element', 'selector'], true)) throw new \InvalidArgumentException('Invalid action target.');
                if (($target->type ?? '') === 'element') self::id($target->value ?? null);
                if (($target->type ?? '') === 'selector') self::text($target->value ?? null, 'selector');
                if (isset($step->condition)) {
                    $condition = ConversionInput::object($step->condition, 'action/condition'); self::fields($condition, ['type', 'value']);
                    if (!in_array($condition->type ?? null, ['has-class', 'missing-class', 'visible', 'hidden', 'viewport'], true)) throw new \InvalidArgumentException('Invalid action condition.');
                    if (in_array($condition->type, ['has-class', 'missing-class'], true)) self::className($condition->value ?? null);
                    if ($condition->type === 'viewport' && !in_array($condition->value ?? null, ['desktop', 'tablet', 'mobile'], true)) throw new \InvalidArgumentException('Invalid action viewport.');
                }
                $operation = ConversionInput::object($step->operation ?? null, 'action/operation'); self::fields($operation, ['type', 'property', 'value', 'animation']);
                if (!in_array($operation->type ?? null, ['set-style', 'reset-style', 'add-class', 'remove-class', 'toggle-class', 'show', 'hide', 'toggle-visibility', 'remove-element'], true)) throw new \InvalidArgumentException('Invalid action operation.');
                if (property_exists($operation, 'animation')) {
                    if (!in_array($operation->type, ['show', 'hide', 'toggle-visibility'], true)) throw new \InvalidArgumentException('Animations require a visibility operation.');
                    self::animation($operation->animation);
                }
                if (in_array($operation->type, ['set-style', 'reset-style'], true)) {
                    self::text($operation->property ?? null, 'style property', 100);
                    $value = $operation->type === 'set-style' ? ($operation->value ?? null) : 'initial';
                    if ($operation->type === 'set-style') self::text($value, 'style value', 4096);
                    (new QuillStyleConverter())->declarations((object) [$operation->property => $value], 'action/style');
                }
                if (in_array($operation->type, ['add-class', 'remove-class', 'toggle-class'], true)) self::className($operation->value ?? null);
            }
        }
        return $actions;
    }

    private static function animation(mixed $value, bool $nested = false): void
    {
        $animation = ConversionInput::object($value, 'action/animation');
        self::fields($animation, $nested ? ['effect','duration','delay','easing','direction','distance'] : ['effect','duration','delay','easing','direction','distance','exit']);
        if (!in_array($animation->effect ?? null, ['none','fade','slide','scale'], true)) throw new \InvalidArgumentException('Invalid animation effect.');
        foreach (['duration' => 10000, 'delay' => 10000, 'distance' => 500] as $field => $max) {
            if (property_exists($animation, $field) && ((!is_int($animation->{$field}) && !is_float($animation->{$field})) || !is_finite((float) $animation->{$field}) || $animation->{$field} < 0 || $animation->{$field} > $max)) throw new \InvalidArgumentException('Invalid animation ' . $field . '.');
        }
        if (property_exists($animation, 'easing') && !in_array($animation->easing, ['smooth','gentle','spring','linear'], true)) throw new \InvalidArgumentException('Invalid animation motion style.');
        if (property_exists($animation, 'direction') && !in_array($animation->direction, ['up','down','left','right'], true)) throw new \InvalidArgumentException('Invalid animation direction.');
        if (property_exists($animation, 'exit')) self::animation($animation->exit, true);
    }

    private static function className(mixed $value): void
    {
        self::text($value, 'class', 255);
        if (preg_match('/[\s\x00-\x1f\x7f]/', $value)) throw new \InvalidArgumentException('Enter a single CSS class.');
    }

    public static function attributes(\stdClass $block, array $attributes): array
    {
        $editor = ConversionInput::object($block->editor ?? new \stdClass(), 'block/editor');
        foreach (self::BINDINGS as $trigger => $field) {
            $attribute = self::attribute($trigger); unset($attributes[$attribute]);
            if (property_exists($editor, $field)) { self::id($editor->{$field}); $attributes[$attribute] = $editor->{$field}; }
        }
        unset($attributes['data-quill-target']);
        if (property_exists($editor, 'actionTargetId')) { self::id($editor->actionTargetId); $attributes['data-quill-target'] = $editor->actionTargetId; }
        return $attributes;
    }
}
