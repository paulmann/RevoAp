<?php
/**
 * NormalizationService: Coerces raw LLM responses into a structured shape
 * suitable for consensus comparison.
 *
 * Strategy:
 *  - If the role defines a JSON output_schema, attempt to extract a JSON
 *    object from the raw content and project it onto the schema (best effort).
 *  - Otherwise, normalize plain text by trimming, lowercasing for hashing,
 *    and splitting into discrete claim sentences.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-05-05
 */
declare(strict_types=1);

namespace App\Consensus;

use App\Helpers\JsonHelper;
use App\Helpers\Utf8Helper;

final class NormalizationService
{
    /**
     * Normalize a single response.
     *
     * @param  string $rawContent      Raw LLM text.
     * @param  array<string,mixed>|null $outputSchema Optional JSON schema for the role.
     * @return array<string,mixed> Structured representation:
     *                              ['shape'=>'json'|'text', 'data'=>mixed, 'claims'=>string[]]
     */
    public function normalize(string $rawContent, ?array $outputSchema = null): array
    {
        $text = Utf8Helper::clean($rawContent);
        if ($outputSchema !== null) {
            $obj = JsonHelper::extractFirstObject($text);
            if ($obj !== null) {
                return [
                    'shape'  => 'json',
                    'data'   => $this->projectOntoSchema($obj, $outputSchema),
                    'claims' => $this->collectClaims($obj),
                ];
            }
        }
        return [
            'shape'  => 'text',
            'data'   => $text,
            'claims' => $this->splitSentences($text),
        ];
    }

    /**
     * @param  array<string,mixed> $data
     * @param  array<string,mixed> $schema
     * @return array<string,mixed>
     */
    private function projectOntoSchema(array $data, array $schema): array
    {
        $properties = $schema['properties'] ?? [];
        if (!is_array($properties)) {
            return $data;
        }
        $out = [];
        foreach (array_keys($properties) as $field) {
            if (array_key_exists($field, $data)) {
                $out[$field] = $data[$field];
            }
        }
        return $out !== [] ? $out : $data;
    }

    /**
     * @param  array<string,mixed> $obj
     * @return string[]
     */
    private function collectClaims(array $obj): array
    {
        $claims = [];
        array_walk_recursive($obj, static function ($v) use (&$claims): void {
            if (is_string($v)) {
                $v = trim($v);
                if ($v !== '' && mb_strlen($v) >= 4) {
                    $claims[] = $v;
                }
            }
        });
        return array_values(array_unique($claims));
    }

    /** @return string[] */
    private function splitSentences(string $text): array
    {
        $parts = preg_split('/(?<=[\.\!\?\:])\s+|\n+/u', $text) ?: [];
        $out   = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '' && mb_strlen($p) >= 4) {
                $out[] = $p;
            }
        }
        return $out;
    }
}
