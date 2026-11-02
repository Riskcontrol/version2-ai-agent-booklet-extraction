<?php

namespace App\Services;

use App\Models\VerificationBatch;
use App\Models\VerificationRecord;
use App\Models\VerificationResult;

class VerificationComparisonService
{
    public const FIELDS = ['name', 'institution', 'course', 'qualification', 'grade', 'session'];

    private function available(string $value): bool
    {
        $normalized = $this->words($value);
        return $normalized !== '' && !in_array($normalized,
            ['UNKNOWN', 'UNREADABLE', 'ILLEGIBLE', 'NOT STATED', 'NOT PROVIDED', 'N A', 'NOT APPLICABLE'], true);
    }

    public function words(string $value): string
    {
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        return trim(preg_replace('/[^A-Z0-9]+/', ' ', strtoupper(str_replace('&', ' AND ', $value))));
    }

    private function name(string $value): string
    {
        $tokens = explode(' ', $this->words($value));
        sort($tokens);
        return implode(' ', $tokens);
    }

    private function qualification(string $value): string
    {
        $value = $this->words($value);
        $aliases = ['HIGHER NATIONAL DIPLOMA' => 'HND', 'NATIONAL DIPLOMA' => 'ND',
            'BACHELOR OF SCIENCE' => 'BSC', 'BACHELOR OF ARTS' => 'BA',
            'BACHELOR OF EDUCATION' => 'BED', 'MASTER OF SCIENCE' => 'MSC',
            'POSTGRADUATE DIPLOMA' => 'PGD', 'POST GRADUATE DIPLOMA' => 'PGD'];
        $value = strtr($value, $aliases);
        $value = str_replace(['B SC', 'M SC', 'B ED'], ['BSC', 'MSC', 'BED'], $value);
        preg_match('/^(HND|ND|BSC|BA|BED|MSC|PGD|PHD|BENG|LLB|LLM)\b/', $value, $match);
        return $match[1] ?? $value;
    }

    private function institution(string $value): string
    {
        $value = preg_replace('/^THE /', '', $this->words($value));
        return match ($value) {
            'LASPOTECH', 'LAGOS STATE POLYTECHNIC IKORODU' => 'LAGOS STATE POLYTECHNIC',
            default => $value,
        };
    }

    private function course(array $fields): string
    {
        $value = $this->words($fields['course'] ?? '');
        // Explicit equivalence observed in the supplied certificate/report pair.
        $value = str_replace('AGRICULTURAL ENGINEERING TECHNOLOGY', 'AGRICULTURAL ENGINEERING', $value);
        $option = $this->words($fields['specialization'] ?? '');
        if (!$option && preg_match('/\(([^)]+)\)/', $fields['qualification'] ?? '', $match)) {
            $option = $this->words($match[1]);
        }
        $value = preg_replace('/\b(OPTION|OPTIONS)\b/', '', $value);
        $option = trim(preg_replace('/\b(OPTION|OPTIONS)\b/', '', $option));
        if ($option !== '' && !str_contains($value, $option)) {
            $value .= ' '.$option;
        }
        return trim(preg_replace('/\s+/', ' ', $value));
    }

    private function years(string $value): array
    {
        if (preg_match('/((?:19|20)\d{2})\s*[\/-]\s*(\d{2})\b/', $value, $m)) {
            $value = str_replace($m[0], $m[1].'/'.substr($m[1], 0, 2).$m[2], $value);
        }
        preg_match_all('/\b(?:19|20)\d{2}\b/', $value, $m);
        return $m[0];
    }

    private function equivalent(string $key, array $a, array $b): bool
    {
        $x = $a[$key] ?? '';
        $y = $b[$key] ?? '';
        return match ($key) {
            'name' => $this->name($x) === $this->name($y),
            'institution' => $this->institution($x) === $this->institution($y),
            'qualification' => $this->qualification($x) === $this->qualification($y),
            'course' => $this->course($a) === $this->course($b),
            'session' => $this->sameSession($x, $y),
            'grade' => $this->grade($x) === $this->grade($y),
            'matric_number' => preg_replace('/[^A-Z0-9]/', '', strtoupper($x)) === preg_replace('/[^A-Z0-9]/', '', strtoupper($y)),
            default => $this->words($x) === $this->words($y),
        };
    }

    private function grade(string $value): string
    {
        return strtr($this->words($value), ['SECOND CLASS HONOURS UPPER DIVISION' => 'SECOND CLASS UPPER',
            'SECOND CLASS HONOURS LOWER DIVISION' => 'SECOND CLASS LOWER',
            'SECOND CLASS UPPER DIVISION' => 'SECOND CLASS UPPER', 'SECOND CLASS LOWER DIVISION' => 'SECOND CLASS LOWER',
            '2 1' => 'SECOND CLASS UPPER', '2 2' => 'SECOND CLASS LOWER']);
    }

    private function sameSession(string $a, string $b): bool
    {
        $x = $this->years($a);
        $y = $this->years($b);
        if (!$x || !$y) {
            return $this->words($a) === $this->words($b);
        }
        return count($x) === 1 || count($y) === 1 ? end($x) === end($y) : $x === $y;
    }

    public function compare(array $certificate, ?array $report): array
    {
        if ($report === null) {
            return ['status' => 'Needs review', 'reason' => 'No confidently matched report. Select a report or reupload corrected documents.', 'comparisons' => []];
        }
        $comparisons = [];
        foreach ([...self::FIELDS, 'matric_number'] as $key) {
            $a = trim((string) ($certificate[$key] ?? ''));
            $b = trim((string) ($report[$key] ?? ''));
            $state = !$this->available($a) || !$this->available($b) ? ($key === 'matric_number' ? 'Not compared' : 'Missing')
                : ($this->equivalent($key, $certificate, $report) ? 'Match' : 'Different');
            $comparisons[] = ['field' => $key, 'certificate' => $a, 'report' => $b, 'state' => $state];
        }
        $different = array_filter($comparisons, fn ($c) => $c['state'] === 'Different');
        $missing = array_filter($comparisons, fn ($c) => $c['state'] === 'Missing');
        $status = $different ? 'Disparity' : ($missing ? 'Needs review' : 'Yes');
        $messages = array_map(fn ($c) => $c['field'].': certificate “'.$c['certificate'].'”; report “'.$c['report'].'”', $different);
        foreach ($missing as $c) {
            $sides = [];
            if (!$this->available($c['certificate'])) $sides[] = 'certificate';
            if (!$this->available($c['report'])) $sides[] = 'report';
            $messages[] = $c['field'].': missing or unreadable in '.implode(' and ', $sides);
        }
        return ['status' => $status, 'reason' => $messages ? implode('; ', $messages) : 'All required details agree with the supplied report.', 'comparisons' => $comparisons];
    }

    public function candidate(array $a, array $b): array
    {
        $agree = [];
        foreach (['name', 'institution', 'qualification', 'session', 'matric_number'] as $key) {
            $agree[$key] = $this->available($a[$key] ?? '') && $this->available($b[$key] ?? '') && $this->equivalent($key, $a, $b);
        }
        $an = $this->name($a['name'] ?? '');
        $bn = $this->name($b['name'] ?? '');
        $similarity = $this->available($a['name'] ?? '') && $this->available($b['name'] ?? '')
            ? 1 - levenshtein(substr($an, 0, 250), substr($bn, 0, 250)) / max(strlen($an), strlen($bn)) : 0;
        $support = (int) $agree['institution'] + (int) $agree['qualification'] + (int) $agree['session'];
        $idConflict = $this->available($a['matric_number'] ?? '') && $this->available($b['matric_number'] ?? '') && !$agree['matric_number'];
        $eligible = !$idConflict && (($agree['matric_number'] && ($agree['institution'] || $similarity >= .8))
            || ($agree['name'] && $support >= 2) || ($similarity >= .9 && $support === 3));
        $score = (int) $agree['matric_number'] * 5 + $similarity * 3
            + (int) $agree['institution'] * 2 + (int) $agree['qualification'] * 2 + (int) $agree['session'];
        return ['score' => round($score, 4), 'eligible' => $eligible,
            'reason' => $idConflict ? 'Conflicting student identifiers' : 'Agrees on: '.implode(', ', array_keys(array_filter($agree)))];
    }

    public function build(VerificationBatch $batch): void
    {
        $records = VerificationRecord::where('batch_id', $batch->id)->get();
        $certificates = $records->where('role', 'certificate');
        $reports = $records->where('role', 'report');
        $ranked = [];
        $reverse = [];
        foreach ($certificates as $certificate) {
            $options = [];
            foreach ($reports as $report) {
                $candidate = $this->candidate($certificate->fields, $report->fields);
                $options[] = ['report_id' => $report->id, ...$candidate];
                if ($candidate['eligible']) {
                    $reverse[$report->id][] = ['certificate_id' => $certificate->id, 'score' => $candidate['score']];
                }
            }
            usort($options, fn ($a, $b) => $b['score'] <=> $a['score']);
            $ranked[$certificate->id] = $options;
        }
        foreach ($reverse as &$choices) {
            usort($choices, fn ($a, $b) => $b['score'] <=> $a['score']);
        }
        unset($choices);
        foreach ($certificates as $certificate) {
            $options = $ranked[$certificate->id];
            $eligible = array_values(array_filter($options, fn ($o) => $o['eligible']));
            $best = $eligible[0] ?? null;
            $report = null;
            if ($best && (!isset($eligible[1]) || $best['score'] - $eligible[1]['score'] >= 1)) {
                $other = $reverse[$best['report_id']];
                if ($other[0]['certificate_id'] === $certificate->id && (!isset($other[1]) || $other[0]['score'] - $other[1]['score'] >= 1)) {
                    $report = $reports->firstWhere('id', $best['report_id']);
                }
            }
            VerificationResult::create(['batch_id' => $batch->id, 'certificate_id' => $certificate->id,
                'report_id' => $report?->id, 'candidates' => array_slice($options, 0, 5),
                ...$this->compare($certificate->fields, $report?->fields)]);
        }
        $batch->increment('revision');
    }
}
