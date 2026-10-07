<?php

declare(strict_types=1);

namespace Opmin\Module\Check;

/**
 * The machine-readable outputs of `opmin check` (the table is drawn by the command).
 *
 * The annotation formats (`github`, `gitlab`, `checkstyle`) carry what is worth a mark on a line:
 * grown functions (error), new functions above the limit (warning) and decreased ones (a hint to
 * update the baseline). New functions within the limit and removed ones are only in `json`.
 *
 * @internal
 */
final readonly class Renderer
{
    /**
     * @param int<0, max> $tolerance
     * @param positive-int|null $limit
     * @param non-empty-string|null $base The base ref; null — the whole project was checked.
     */
    public function __construct(
        private Comparison $comparison,
        private int $tolerance,
        private ?int $limit,
        private ?string $base,
        private string $php,
    ) {}

    public function render(Format $format): string
    {
        return match ($format) {
            Format::Table, Format::Json => $this->json(),
            Format::Github => $this->github(),
            Format::Gitlab => $this->gitlab(),
            Format::Checkstyle => $this->checkstyle(),
        };
    }

    public function json(): string
    {
        $totals = ['checked' => $this->comparison->checked, 'unchanged' => $this->comparison->unchanged];
        foreach (FindingKind::cases() as $kind) {
            $totals[$kind->value] = $this->comparison->count($kind);
        }

        return \json_encode([
            'status' => $this->comparison->failed() ? 'failed' : 'passed',
            'php' => $this->php,
            'scope' => $this->base === null ? 'all' : 'changed',
            'base' => $this->base,
            'tolerance' => $this->tolerance,
            'max_ops_new_function' => $this->limit,
            'totals' => $totals,
            'findings' => \array_map(static fn(Finding $f): array => $f->toArray(), $this->comparison->findings),
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * Workflow commands of GitHub Actions: `::error file=…,line=…,title=…::message`.
     */
    public function github(): string
    {
        $lines = '';
        foreach ($this->annotated() as $finding) {
            $command = match ($finding->kind->severity()) {
                Severity::Error => 'error',
                Severity::Warning => 'warning',
                Severity::Info => 'notice',
            };
            $lines .= \sprintf(
                "::%s file=%s,line=%d,title=%s::%s\n",
                $command,
                self::githubProperty($finding->file),
                (int) $finding->line,
                self::githubProperty('opmin: ' . $finding->kind->value),
                self::githubData($finding->message($this->tolerance, $this->limit)),
            );
        }

        return $lines;
    }

    /**
     * GitLab Code Quality report: a JSON array of issues with a stable fingerprint (no line in it,
     * so an issue moved by an edit above it stays the same issue between pipelines).
     */
    public function gitlab(): string
    {
        $issues = [];
        foreach ($this->annotated() as $finding) {
            $issues[] = [
                'description' => $finding->message($this->tolerance, $this->limit),
                'check_name' => 'opmin.' . $finding->kind->value,
                'fingerprint' => \md5($finding->kind->value . "\0" . $finding->key),
                'severity' => match ($finding->kind->severity()) {
                    Severity::Error => 'major',
                    Severity::Warning => 'minor',
                    Severity::Info => 'info',
                },
                'location' => ['path' => $finding->file, 'lines' => ['begin' => (int) $finding->line]],
            ];
        }

        return \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR) . "\n";
    }

    public function checkstyle(): string
    {
        $files = [];
        foreach ($this->annotated() as $finding) {
            $files[$finding->file][] = $finding;
        }

        \ksort($files, \SORT_STRING);
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<checkstyle version=\"4.3\">\n";
        foreach ($files as $file => $findings) {
            $xml .= \sprintf("  <file name=\"%s\">\n", self::xml($file));
            foreach ($findings as $finding) {
                $xml .= \sprintf(
                    "    <error line=\"%d\" column=\"1\" severity=\"%s\" message=\"%s\" source=\"opmin.%s\"/>\n",
                    (int) $finding->line,
                    $finding->kind->severity()->value,
                    self::xml($finding->message($this->tolerance, $this->limit)),
                    $finding->kind->value,
                );
            }

            $xml .= "  </file>\n";
        }

        return $xml . "</checkstyle>\n";
    }

    private static function githubData(string $value): string
    {
        return \strtr($value, ['%' => '%25', "\r" => '%0D', "\n" => '%0A']);
    }

    private static function githubProperty(string $value): string
    {
        return \strtr($value, ['%' => '%25', "\r" => '%0D', "\n" => '%0A', ':' => '%3A', ',' => '%2C']);
    }

    private static function xml(string $value): string
    {
        return \htmlspecialchars($value, \ENT_XML1 | \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @return list<Finding>
     */
    private function annotated(): array
    {
        return $this->comparison->of(FindingKind::Grown, FindingKind::NewOverLimit, FindingKind::Decreased);
    }
}
