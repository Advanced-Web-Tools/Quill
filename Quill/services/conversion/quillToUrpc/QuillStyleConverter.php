<?php

namespace Quill\services\conversion\quillToUrpc;

use Quill\services\conversion\shared\ConversionException;
use Quill\services\conversion\shared\ConversionInput;

final class QuillStyleConverter
{
    private const STATES = ['hover', 'focus', 'focus-visible', 'active', 'disabled', 'checked'];
    /** @param array{tablet?: int, mobile?: int} $breakpoints Maximum viewport widths in CSS pixels. */
    public function __construct(private array $breakpoints = [])
    {
        foreach ($breakpoints as $name => $width) {
            if (!in_array($name, ['tablet', 'mobile'], true) || !is_int($width) || $width < 1) {
                throw new \InvalidArgumentException('Breakpoints must be positive integer tablet/mobile widths.');
            }
        }
        if (isset($breakpoints['tablet'], $breakpoints['mobile']) && $breakpoints['mobile'] > $breakpoints['tablet']) {
            throw new \InvalidArgumentException('The mobile breakpoint must not exceed the tablet breakpoint.');
        }
    }

    /** @param array<string, scalar|null> $attributes @return array<string, scalar|null> */
    public function apply(\stdClass $block, array $attributes, ConversionContext $context, string $path): array
    {
        $styles = ConversionInput::object(ConversionInput::field($block, 'styles', new \stdClass()), $path . '/styles');
        $groups = ['base', 'desktop', 'tablet', 'mobile', ...self::STATES];
        foreach (['desktop', 'tablet', 'mobile'] as $viewport) {
            foreach (self::STATES as $state) $groups[] = $viewport . ':' . $state;
        }
        if (array_diff(array_keys(get_object_vars($styles)), $groups)) {
            throw ConversionException::at($path . '/styles', 'Unsupported style group.');
        }
        $scopeClass = 'quill-scope-' . hash('sha256', $context->sourceNamespace . '/' . $path);
        $hasTransitions = is_string($attributes['style'] ?? null) && preg_match('/(?:^|;)\s*transition(?:-[a-z-]+)?\s*:/i', $attributes['style']);
        $hasStyles = array_key_exists('hidden', $attributes) && $attributes['hidden'] !== false && $attributes['hidden'] !== null;
        if ($hasStyles) $context->addCss('.' . $scopeClass . '[hidden]{display:none!important;}');
        foreach ($groups as $group) {
            $declarations = $this->declarations(ConversionInput::field($styles, $group, new \stdClass()), $path . '/styles/' . $group);
            if ($declarations === '') continue;
            $hasTransitions = $hasTransitions || preg_match('/(?:^|;)transition(?:-[a-z-]+)?:/i', $declarations);
            $hasStyles = true;
            [$viewport, $state] = str_contains($group, ':') ? explode(':', $group)
                : (in_array($group, self::STATES, true) ? ['base', $group] : [$group, null]);
            $rule = '.' . $scopeClass . ($state !== null ? ':' . $state : '') . '{' . $declarations . '}';
            if ($viewport !== 'base') {
                $width = $this->breakpoints[$viewport === 'desktop' ? 'tablet' : $viewport] ?? throw ConversionException::at(
                    $path . '/styles/' . $group, "Configure the {$group} breakpoint; Quill defines no default."
                );
                $query = $viewport === 'desktop' ? '(min-width: ' . ($width + 1) . 'px)' : '(max-width: ' . $width . 'px)';
                $rule = '@media ' . $query . '{' . $rule . '}';
            }
            $context->addCss($rule);
        }
        if ($hasTransitions) {
            $hasStyles = true;
            $context->addCss('@media(prefers-reduced-motion:reduce){.' . $scopeClass . '{transition:none!important;}}');
        }
        if ($hasStyles) {
            if (array_key_exists('class', $attributes) && !is_string($attributes['class'])) {
                throw ConversionException::at($path . '/attributes/class', 'Scoped styles require a string class attribute.');
            }
            $attributes['class'] = trim(($attributes['class'] ?? '') . ' ' . $scopeClass);
        }
        return $attributes;
    }

    public function declarations(mixed $styles, string $path): string
    {
        $styles = ConversionInput::object($styles, $path);
        $declarations = [];
        foreach (get_object_vars($styles) as $property => $value) {
            if (!preg_match('/^(?:--[A-Za-z_][A-Za-z0-9_-]*|-?[A-Za-z][A-Za-z0-9-]*)$/D', $property)
                || (!is_string($value) && !is_int($value) && !is_float($value))
                || preg_match('/[{};<>\x00-\x1f\x7f]|\/\*|\*\//', (string) $value)) {
                throw ConversionException::at($path . '/' . $property, 'Expected a CSS property and a single declaration value.');
            }
            $declarations[] = $property . ':' . $value . ';';
        }
        return implode('', $declarations);
    }
}
