<?php

declare(strict_types=1);

namespace App\Service\Catalogue;

final class SpellDescriptionExtractor
{
    /**
     * @return array{description: string, higherLevels: ?string}
     */
    public function extract(string $html): array
    {
        if (trim($html) === '') {
            throw new \InvalidArgumentException('Description HTML vide.');
        }

        $separator = '~<strong>\s*<em>\s*Aux niveaux supérieurs\s*</em>\s*</strong>\s*\.?~iu';

        $parts = preg_split($separator, $html);

        if ($parts === false || count($parts) > 2) {
            throw new \InvalidArgumentException(
                'Structure des niveaux supérieurs ambiguë.'
            );
        }

        $description = $this->toText($parts[0]);
        $higherLevels = isset($parts[1])
            ? $this->toText($parts[1])
            : null;

        if ($description === '') {
            throw new \InvalidArgumentException('Description principale vide.');
        }

        if (isset($parts[1]) && $higherLevels === '') {
            throw new \InvalidArgumentException(
                'Section niveaux supérieurs vide.'
            );
        }

        return [
            'description' => $description,
            'higherLevels' => $higherLevels,
        ];
    }

    private function toText(string $html): string
    {
        // Conserver les séparations avant de retirer les balises.
        $html = preg_replace(
            '~<br\s*/?>|</(?:p|div|li|ul|ol|h[1-6])>~iu',
            "\n",
            $html
        );

        $html = preg_replace(
            '~<li(?:\s[^>]*)?>~iu',
            "\n- ",
            $html
        );

        $html = strip_tags($html);

        $text = html_entity_decode(
            $html,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        $text = str_replace("\xC2\xA0", ' ', $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text);
        $text = preg_replace('/ *\n */u', "\n", $text);
        $text = preg_replace('/\n{3,}/u', "\n\n", $text);

        return trim($text);
    }
}
