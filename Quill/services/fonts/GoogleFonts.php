<?php

namespace Quill\services\fonts;

/** Local metadata only. Public rendering never contacts a metadata API. */
final class GoogleFonts
{
    private static ?array $catalog = null;
    private static ?array $byName = null;

    public static function catalog(): array
    {
        return self::$catalog ??= json_decode(file_get_contents(__DIR__ . '/../../resources/google-fonts.json'), true, 512, JSON_THROW_ON_ERROR)['families'];
    }

    public static function find(string $name): ?array
    {
        if (self::$byName === null) self::$byName = array_column(self::catalog(), null, 'family');
        return self::$byName[$name] ?? null;
    }

    public static function families(string $css): array
    {
        preg_match_all('/"([^"]+)"|\'([^\']+)\'|([^,]+)/u', $css, $matches, PREG_SET_ORDER);
        $families = [];
        foreach ($matches as $match) {
            $name = trim($match[1] ?: ($match[2] ?? '') ?: ($match[3] ?? ''));
            if (self::find($name)) $families[] = $name;
        }
        return array_values(array_unique($families));
    }

    public static function groups(\stdClass $block): array
    {
        $groups = [];
        foreach (get_object_vars($block->styles ?? new \stdClass()) as $key => $value) $groups[$key] = (array) $value;
        $groups['base'] ??= [];
        if (preg_match_all('/(?:^|;)\s*(font-family|font-weight|font-style)\s*:\s*([^;]+)/i', $block->attributes->style ?? '', $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $property = strtolower($match[1]);
                foreach ($groups as &$group) unset($group[$property]);
                unset($group);
                $groups['base'][$property] = trim(preg_replace('/\s*!important\s*$/i', '', $match[2]));
            }
        }
        return $groups;
    }

    /** Each authored viewport/state is a possible use; descendants inherit their union. */
    public static function contexts(array $groups, array $parent, string $tag = ''): array
    {
        $base = $parent;
        if (in_array(strtolower($tag), ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'strong', 'b', 'th'], true)) $base['weights'] = [700];
        if (in_array(strtolower($tag), ['em', 'i'], true)) $base['italics'] = [1];
        $apply = static function (array $values, array $inherited): array {
            foreach (['font-family', 'font-weight', 'font-style'] as $property) {
                $value = trim((string) ($values[$property] ?? ''));
                if ($value === '' || in_array($value, ['inherit', 'unset'], true)) continue;
                if ($property === 'font-family') $inherited['families'] = self::families($value);
                if ($property === 'font-weight') {
                    $inherited['weights'] = is_numeric($value) ? [(float) $value]
                        : ($value === 'bolder' ? array_map(static fn ($weight) => $weight < 350 ? 400 : ($weight < 550 ? 700 : 900), $inherited['weights'])
                        : ($value === 'lighter' ? array_map(static fn ($weight) => $weight < 550 ? 100 : ($weight < 750 ? 400 : 700), $inherited['weights']) : [$value === 'bold' ? 700 : 400]));
                }
                if ($property === 'font-style') $inherited['italics'] = [preg_match('/^(italic|oblique)/', $value) ? 1 : 0];
            }
            return $inherited;
        };
        $base = $apply($groups['base'] ?? [], $base);
        $contexts = [];
        foreach (['base', 'desktop', 'tablet', 'mobile'] as $viewport) {
            $scopes = $viewport === 'mobile' ? ['tablet', 'mobile'] : ($viewport === 'base' ? [] : [$viewport]);
            $view = $base;
            foreach ($scopes as $scope) $view = $apply($groups[$scope] ?? [], $view);
            $contexts[] = $view;
            foreach (['hover', 'focus', 'focus-visible', 'active', 'disabled', 'checked'] as $state) {
                $active = $apply($groups[$state] ?? [], $view);
                foreach ($scopes as $scope) $active = $apply($groups[$scope . ':' . $state] ?? [], $active);
                $contexts[] = $active;
            }
        }
        return $contexts;
    }

    public static function inherited(array $contexts): array
    {
        $result = ['families' => [], 'weights' => [], 'italics' => []];
        foreach ($result as $key => $_) foreach ($contexts as $context) $result[$key] = array_values(array_unique([...$result[$key], ...$context[$key]]));
        return $result;
    }

    public static function variant(array $font, float $weight, int $italic): string
    {
        $available = array_values(array_filter($font['variants'], static fn ($variant) => str_ends_with($variant, 'i') === (bool) $italic));
        if ($available === []) { $italic = 0; $available = array_values(array_filter($font['variants'], static fn ($variant) => !str_ends_with($variant, 'i'))); }
        // Some families contain only italic designs.
        if ($available === []) { $italic = 1; $available = $font['variants']; }
        if (isset($font['weightRange'])) $weight = max($font['weightRange'][0], min($font['weightRange'][1], $weight));
        else {
            usort($available, static fn ($a, $b) => [abs((float) $a - $weight), (float) $a] <=> [abs((float) $b - $weight), (float) $b]);
            $weight = (float) $available[0];
        }
        return $italic . ',' . $weight;
    }

    public static function url(string $family, array $variants): string
    {
        $variants = array_values(array_unique($variants));
        usort($variants, static fn ($a, $b) => array_map('floatval', explode(',', $a)) <=> array_map('floatval', explode(',', $b)));
        return 'https://fonts.googleapis.com/css2?family=' . str_replace('%20', '+', rawurlencode($family)) . ':ital,wght@' . implode(';', $variants) . '&display=swap';
    }
}
