<?php

namespace Quill\services\document;

use Quill\services\conversion\quillToUrpc\QuillStyleConverter;

final class ColorPalette
{
    public static function validate(mixed $palette): void
    {
        if (!is_array($palette) || !array_is_list($palette) || count($palette) > 100) throw new \InvalidArgumentException('Use at most 100 saved colors.');
        $ids = $names = [];
        foreach ($palette as $entry) {
            if (!$entry instanceof \stdClass || array_diff(array_keys(get_object_vars($entry)), ['id', 'name', 'value'])
                || !is_string($entry->id ?? null) || !preg_match('/^qp-[a-zA-Z0-9_-]{1,80}$/D', $entry->id) || in_array($entry->id, $ids, true)
                || !is_string($entry->name ?? null) || trim($entry->name) === '' || strlen($entry->name) > 128
                || in_array(strtolower(trim($entry->name)), $names, true)
                || !is_string($entry->value ?? null) || trim($entry->value) === '' || strlen($entry->value) > 2048) {
                throw new \InvalidArgumentException('Enter a valid, uniquely named saved color.');
            }
            $value = trim($entry->value);
            if (preg_match('/url\s*\(|var\s*\(|[{};<>\x00-\x1f\x7f]|\/\*|\*\//i', $value)
                || !self::isPaint($value)) throw new \InvalidArgumentException('Enter a color or CSS gradient for the palette.');
            $ids[] = $entry->id; $names[] = strtolower(trim($entry->name));
        }
    }

    public static function validateDefinition(\stdClass $palette): void
    {
        if (array_diff(array_keys(get_object_vars($palette)), ['name','colors']) || !is_string($palette->name ?? null) || trim($palette->name) === '' || strlen($palette->name) > 255) throw new \InvalidArgumentException('Provide a palette name up to 255 bytes.');
        self::validate($palette->colors ?? null);
    }

    public static function validateCollections(\stdClass $settings): void
    {
        $palettes = $settings->color_palettes ?? [];
        if (!is_array($palettes) || !array_is_list($palettes) || count($palettes) > 20) throw new \InvalidArgumentException('Use at most 20 local palettes.');
        $ids = []; $names = [];
        foreach ($palettes as $palette) {
            if (!$palette instanceof \stdClass || !is_string($palette->id ?? null) || !preg_match('/^qpal-[A-Za-z0-9_-]{1,80}$/D', $palette->id) || $palette->id === 'qpal-legacy'
                || isset($ids[$palette->id]) || array_diff(array_keys(get_object_vars($palette)), ['id','name','colors'])) throw new \InvalidArgumentException('Invalid local palette identity.');
            self::validateDefinition((object) ['name' => $palette->name ?? null, 'colors' => $palette->colors ?? null]);
            $name = strtolower(trim($palette->name));
            if (isset($names[$name])) throw new \InvalidArgumentException('Use distinct names for local palettes.');
            $ids[$palette->id] = true; $names[$name] = true;
        }
        $default = $settings->default_color_palette ?? null;
        if ($default === null) return;
        if (!$default instanceof \stdClass || array_diff(array_keys(get_object_vars($default)), ['scope','id']) || !in_array($default->scope ?? null, ['local','global','external'], true)) throw new \InvalidArgumentException('Invalid default palette.');
        if ($default->scope === 'global') {
            if (!is_int($default->id ?? null) || $default->id < 1 || $default->id > 9007199254740991) throw new \InvalidArgumentException('Invalid global default palette.');
        } elseif ($default->scope === 'external') {
            if (!is_string($default->id ?? null)) throw new \InvalidArgumentException('Invalid external default palette.');
            \Quill\api\editor\DefinitionValidation::key($default->id);
        } elseif (!is_string($default->id ?? null) || (!isset($ids[$default->id]) && !($default->id === 'qpal-legacy' && property_exists($settings, 'color_palette')))) throw new \InvalidArgumentException('Default local palette does not exist.');
    }

    private static function isPaint(string $value): bool
    {
        if (preg_match('/^#(?:[a-f0-9]{3}|[a-f0-9]{4}|[a-f0-9]{6}|[a-f0-9]{8})$/iD', $value)) return true;
        $names = 'aliceblue antiquewhite aqua aquamarine azure beige bisque black blanchedalmond blue blueviolet brown burlywood cadetblue chartreuse chocolate coral cornflowerblue cornsilk crimson cyan darkblue darkcyan darkgoldenrod darkgray darkgreen darkgrey darkkhaki darkmagenta darkolivegreen darkorange darkorchid darkred darksalmon darkseagreen darkslateblue darkslategray darkslategrey darkturquoise darkviolet deeppink deepskyblue dimgray dimgrey dodgerblue firebrick floralwhite forestgreen fuchsia gainsboro ghostwhite gold goldenrod gray green greenyellow grey honeydew hotpink indianred indigo ivory khaki lavender lavenderblush lawngreen lemonchiffon lightblue lightcoral lightcyan lightgoldenrodyellow lightgray lightgreen lightgrey lightpink lightsalmon lightseagreen lightskyblue lightslategray lightslategrey lightsteelblue lightyellow lime limegreen linen magenta maroon mediumaquamarine mediumblue mediumorchid mediumpurple mediumseagreen mediumslateblue mediumspringgreen mediumturquoise mediumvioletred midnightblue mintcream mistyrose moccasin navajowhite navy oldlace olive olivedrab orange orangered orchid palegoldenrod palegreen paleturquoise palevioletred papayawhip peachpuff peru pink plum powderblue purple rebeccapurple red rosybrown royalblue saddlebrown salmon sandybrown seagreen seashell sienna silver skyblue slateblue slategray slategrey snow springgreen steelblue tan teal thistle tomato turquoise violet wheat white whitesmoke yellow yellowgreen transparent currentcolor accentcolor accentcolortext activetext buttonborder buttonface buttontext canvas canvastext field fieldtext graytext highlight highlighttext linktext mark marktext selecteditem selecteditemtext visitedtext';
        if (in_array(strtolower($value), explode(' ', $names), true)) return true;
        $gradient = (bool) preg_match('/^(?:linear|radial|conic)-gradient\(.+\)$/isD', $value);
        $functionalColor = (bool) preg_match('/^(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch|color|color-mix|light-dark)\(.+\)$/isD', $value);
        if (!$gradient && !$functionalColor) return false;
        // Metadata stays inert; CSS.supports in the editor validates the color grammar.
        (new QuillStyleConverter())->declarations((object) [$gradient ? 'background-image' : 'color' => $value], 'color_palette');
        $depth = 0;
        foreach (str_split($value) as $character) {
            if ($character === '(') $depth++;
            if ($character === ')' && --$depth < 0) return false;
        }
        return $depth === 0;
    }
}
