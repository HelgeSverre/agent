<?php

namespace App;

use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;

class TextUtils
{
    public static function normalizeWhitespace(string $text): string
    {
        return Str::of($text)->squish()->trim()->toString();
    }

    public static function splitText(string $text, int $chunkSize, int $overlap = 0): array
    {
        $chunks = [];
        $length = strlen($text);
        $i = 0;

        while ($i < $length) {
            $end = min($i + $chunkSize, $length);

            // Ensure we don't split in the middle of a word
            while ($end < $length && !ctype_space($text[$end]) && !ctype_punct($text[$end])) {
                $end++;
            }

            $chunks[] = substr($text, $i, $end - $i);
            $i = $end - $overlap; // Move back for overlap

            if ($i + $chunkSize > $length) {
                // Adjust for last chunk
                $i = max($i, $length - $chunkSize);
            }
        }

        return $chunks;
    }


    public static function cleanHtml(
        string $html,
        array  $elementsToRemove = ['script', 'style', 'link', 'head', 'noscript', 'template', 'svg', 'br', 'hr', 'footer', 'nav'],
        bool   $normalizeWhitespace = true
    ): string
    {
        $inputHtml = $normalizeWhitespace
            ? Str::of($html)
                ->replace('<', ' <')
                ->replace('>', '> ')
                ->toString()
            : $html;

        $crawler = new Crawler($inputHtml);

        foreach ($elementsToRemove as $element) {
            $crawler->filter($element)->each(function (Crawler $node) {
                return $node->getNode(0)->parentNode->removeChild($node->getNode(0));
            });
        }

        return $normalizeWhitespace ? self::normalizeWhitespace($crawler->text('')) : $crawler->text('');
    }
}
