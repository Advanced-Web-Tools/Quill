<?php
namespace Quill\services\animations;

use Quill\services\conversion\shared\ConversionInput;
use Quill\services\conversion\quillToUrpc\QuillStyleConverter;

/** Committed page resources, independent of structural block metadata. */
final class AnimationDefinitions
{
    private const PROPERTY = '/^(?:opacity|color|background(?:-(?:color|image|size|position|position-x|position-y|clip))?|border(?:-(?:top|right|bottom|left))?(?:-(?:width|color|style))?|border-radius|border-(?:top-left|top-right|bottom-left|bottom-right)-radius|border-image-(?:source|slice)|(?:min-|max-)?(?:width|height)|aspect-ratio|margin(?:-(?:top|right|bottom|left))?|padding(?:-(?:top|right|bottom|left))?|gap|row-gap|column-gap|top|right|bottom|left|inset|translate|rotate|scale|transform|transform-origin|filter|backdrop-filter|box-shadow|text-shadow|font-size|font-weight|line-height|letter-spacing|word-spacing|text-indent|text-decoration-(?:color|thickness)|text-underline-offset|flex-(?:grow|shrink|basis)|grid-template-(?:columns|rows)|-webkit-text-fill-color|-webkit-background-clip|--[A-Za-z_][A-Za-z0-9_-]*)$/D';
    private static function fields(\stdClass $object, array $allowed): void
    {
        if (array_diff(array_keys(get_object_vars($object)), $allowed)) throw new \InvalidArgumentException('Unsupported animation field.');
    }
    private static function id(mixed $id): void
    {
        if (!is_string($id) || !preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,99}$/D', $id)) throw new \InvalidArgumentException('Invalid animation identity.');
    }
    private static function time(mixed $value): bool { return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0 && $value <= 600000; }
    private static function easing(mixed $value): void
    {
        if (!is_string($value) || !preg_match('/^(?:linear|ease|ease-in|ease-out|ease-in-out|step-start|step-end|cubic-bezier\(\s*-?[\d.]+\s*,\s*-?[\d.]+\s*,\s*-?[\d.]+\s*,\s*-?[\d.]+\s*\)|steps\(\s*[1-9]\d*\s*(?:,\s*(?:start|end|jump-start|jump-end|jump-none|jump-both)\s*)?\))$/D', $value)) throw new \InvalidArgumentException('Invalid animation easing.');
    }
    public static function validate(mixed $registry, mixed $bindings = []): \stdClass
    {
        $registry = ConversionInput::object($registry, 'settings/animations');
        $bindings = ConversionInput::orderedList($bindings, 'settings/animation_bindings');
        if (count(get_object_vars($registry)) > 100 || count($bindings) > 500 || strlen(json_encode([$registry, $bindings], JSON_THROW_ON_ERROR)) > 2000000) throw new \InvalidArgumentException('Page animation registry is too large.');
        foreach (get_object_vars($registry) as $id => $animation) {
            self::id($id); $animation = ConversionInput::object($animation, 'animation');
            self::fields($animation, ['id','name','duration','delay','iterations','direction','fillMode','easing','tracks']);
            if (($animation->id ?? null) !== $id || !is_string($animation->name ?? null) || trim($animation->name) === '' || strlen($animation->name) > 255
                || !self::time($animation->duration ?? null) || $animation->duration < 1 || !self::time($animation->delay ?? null)
                || !(($animation->iterations ?? null) === 'infinite' || (is_int($animation->iterations ?? null) && $animation->iterations >= 1 && $animation->iterations <= 1000))
                || !in_array($animation->direction ?? null, ['normal','reverse','alternate','alternate-reverse'], true)
                || !in_array($animation->fillMode ?? null, ['none','forwards','backwards','both'], true)) throw new \InvalidArgumentException('Invalid animation settings.');
            self::easing($animation->easing ?? null);
            $tracks = ConversionInput::object($animation->tracks ?? null, 'animation/tracks');
            if (count(get_object_vars($tracks)) < 1 || count(get_object_vars($tracks)) > 100) throw new \InvalidArgumentException('An animation requires 1–100 tracks.');
            foreach (get_object_vars($tracks) as $property => $frames) {
                if (!preg_match(self::PROPERTY, $property)) throw new \InvalidArgumentException('Unsupported animation property: ' . $property);
                $frames = ConversionInput::orderedList($frames, 'animation/keyframes');
                if (count($frames) < 1 || count($frames) > 200) throw new \InvalidArgumentException('A track requires 1–200 keyframes.');
                $previous = -1;
                foreach ($frames as $frame) {
                    $frame = ConversionInput::object($frame, 'animation/keyframe'); self::fields($frame, ['time','value','easing']);
                    if (!self::time($frame->time ?? null) || $frame->time > $animation->duration || $frame->time <= $previous
                        || !is_string($frame->value ?? null) || trim($frame->value) === '' || strlen($frame->value) > 4096) throw new \InvalidArgumentException('Invalid animation keyframe.');
                    (new QuillStyleConverter())->declarations((object) [$property => $frame->value], 'animation/keyframe');
                    if (isset($frame->easing)) self::easing($frame->easing);
                    $previous = $frame->time;
                }
            }
        }
        $ids = [];
        foreach ($bindings as $binding) {
            $binding = ConversionInput::object($binding, 'animation/binding'); self::fields($binding, ['id','animationId','target','trigger']); self::id($binding->id ?? null);
            if (isset($ids[$binding->id]) || !is_string($binding->animationId ?? null) || !property_exists($registry, $binding->animationId) || !in_array($binding->trigger ?? null, ['load','click','hover','focus'], true)) throw new \InvalidArgumentException('Invalid animation binding.');
            $target = ConversionInput::object($binding->target ?? null, 'animation/target'); self::fields($target, ['section','path']);
            $path = ConversionInput::orderedList($target->path ?? null, 'animation/target/path');
            if (!is_string($target->section ?? null) || trim($target->section) === '' || count($path) < 1 || count($path) > 128) throw new \InvalidArgumentException('Invalid animation target.');
            foreach ($path as $index) if (!is_int($index) || $index < 0) throw new \InvalidArgumentException('Invalid animation target path.');
            $ids[$binding->id] = true;
        }
        return $registry;
    }

    /** Compile per-property animations so independent keyframe offsets/easings are retained. */
    public static function compile(\stdClass $settings, string $namespace): array
    {
        $registry = self::validate($settings->animations ?? new \stdClass(), $settings->animation_bindings ?? []);
        $css = []; $targets = []; $click = false; $groups = [];
        foreach (get_object_vars($registry) as $id => $animation) {
            foreach (array_keys(get_object_vars($animation->tracks)) as $property) {
                if (preg_match('/^--quill-animation-(translate|scale)-([xyz])$/D', $property, $axis)) {
                    $syntax = $axis[1] === 'scale' ? '<number>' : ($axis[2] === 'z' ? '<length>' : '<length-percentage>');
                    $initial = $axis[1] === 'scale' ? '1' : '0px';
                    $css[] = '@property ' . $property . '{syntax:"' . $syntax . '";inherits:false;initial-value:' . $initial . ';}';
                }
            }
            $names = []; $hash = substr(hash('sha256', $namespace . '/' . $id), 0, 24);
            foreach (get_object_vars($animation->tracks) as $property => $frames) {
                $name = 'quill-kf-' . $hash . '-' . count($names); $names[] = $name;
                $rule = '@keyframes ' . $name . '{';
                foreach ($frames as $frame) {
                    $offset = rtrim(rtrim(sprintf('%.8F', $frame->time / $animation->duration * 100), '0'), '.');
                    $rule .= $offset . '%{' . $property . ':' . $frame->value . ';' . (isset($frame->easing) ? 'animation-timing-function:' . $frame->easing . ';' : '') . '}';
                }
                $css[] = $rule . '}';
            }
            foreach ($settings->animation_bindings ?? [] as $binding) {
                if ($binding->animationId !== $id) continue;
                $class = 'quill-animation-' . substr(hash('sha256', $namespace . '/' . $binding->id), 0, 24);
                $targets[json_encode([$binding->target->section, $binding->target->path])] [] = ['class' => $class, 'trigger' => $binding->trigger];
                $selector = '.' . $class . match ($binding->trigger) { 'hover' => ':hover', 'focus' => ':focus', 'click' => '[data-quill-animation-playing]', default => '' };
                $composition = '';
                foreach (['translate','scale'] as $group) {
                    $hasAxes = array_filter(array_keys(get_object_vars($animation->tracks)), fn ($property) => str_starts_with($property, '--quill-animation-' . $group . '-'));
                    if ($hasAxes) {
                        $values = [];
                        foreach (['x','y','z'] as $axis) $values[] = 'var(--quill-animation-' . $group . '-' . $axis . ',' . ($group === 'scale' ? '1' : '0px') . ')';
                        $composition .= $group . ':' . implode(' ', $values) . ';';
                    }
                }
                $css[] = $selector . '{' . $composition . 'animation-name:' . implode(',', $names) . ';animation-duration:' . $animation->duration . 'ms;animation-delay:' . $animation->delay . 'ms;animation-iteration-count:' . $animation->iterations . ';animation-direction:' . $animation->direction . ';animation-fill-mode:' . $animation->fillMode . ';animation-timing-function:' . $animation->easing . ';}';
                $css[] = '@media(prefers-reduced-motion:reduce){.' . $class . '{animation:none!important;}}';
                $groups[json_encode([$binding->target, $binding->trigger])][] = ['class' => $class, 'names' => $names, 'animation' => $animation, 'composition' => $composition, 'trigger' => $binding->trigger];
                $click = $click || $binding->trigger === 'click';
            }
        }
        // Several active resources on one element share a CSS animation list rather
        // than overriding each other's animation-name declarations.
        foreach ($groups as $group) {
            if (count($group) < 2) continue;
            $names = []; $durations = []; $delays = []; $iterations = []; $directions = []; $fills = []; $easings = []; $composition = '';
            foreach ($group as $item) {
                $animation = $item['animation']; $composition .= $item['composition'];
                foreach ($item['names'] as $name) {
                    $names[] = $name; $durations[] = $animation->duration . 'ms'; $delays[] = $animation->delay . 'ms';
                    $iterations[] = $animation->iterations; $directions[] = $animation->direction; $fills[] = $animation->fillMode; $easings[] = $animation->easing;
                }
            }
            $selector = '.' . implode('.', array_column($group, 'class')) . match ($group[0]['trigger']) { 'hover' => ':hover', 'focus' => ':focus', 'click' => '[data-quill-animation-playing]', default => '' };
            $css[] = $selector . '{' . $composition . 'animation-name:' . implode(',', $names) . ';animation-duration:' . implode(',', $durations) . ';animation-delay:' . implode(',', $delays)
                . ';animation-iteration-count:' . implode(',', $iterations) . ';animation-direction:' . implode(',', $directions) . ';animation-fill-mode:' . implode(',', $fills) . ';animation-timing-function:' . implode(',', $easings) . ';}';
        }
        return ['css' => implode("\n", $css), 'targets' => $targets, 'click' => $click];
    }
}
