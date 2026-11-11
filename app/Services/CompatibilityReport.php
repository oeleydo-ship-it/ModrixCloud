<?php

namespace Marketplace\Services;

/**
 * The result of scanning a package: what core it needs, which features set
 * that minimum, and problems found. Errors stop a submission; warnings are
 * shown to the publisher and the reviewer. Stored with the submission as an array.
 */
final class CompatibilityReport
{
    /**
     * @param  list<array{level: string, message: string}>  $issues
     * @param  array<string, string>  $uses  feature (e.g. "place checkout.after_shipping") => first core version that has it
     * @param  array<string, string>  $requires  package identifier => version constraint
     */
    public function __construct(
        public readonly array $issues = [],
        public readonly ?string $platformVersion = null,
        public readonly ?string $minimumCore = null,
        public readonly ?string $firstCore = null,
        public readonly ?string $latestCore = null,
        public readonly array $uses = [],
        public readonly array $requires = [],
        public readonly ?string $scannedAt = null,
    ) {}

    /** @return list<string> */
    public function errors(): array
    {
        return $this->messages('error');
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->messages('warning');
    }

    /** @return list<string> possible malicious code: the package is rejected automatically */
    public function malicious(): array
    {
        return $this->messages('malicious');
    }

    /** @return list<string> unusual code the reviewer should look at */
    public function suspicious(): array
    {
        return $this->messages('suspicious');
    }

    public function passed(): bool
    {
        return $this->errors() === [] && $this->malicious() === [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'scanned_at' => $this->scannedAt,
            'platform_version' => $this->platformVersion,
            'minimum_core' => $this->minimumCore,
            'first_core' => $this->firstCore,
            'latest_core' => $this->latestCore,
            'uses' => $this->uses,
            'requires' => $this->requires,
            'issues' => $this->issues,
        ];
    }

    /** @param  array<string, mixed>|null  $data */
    public static function fromArray(?array $data): ?self
    {
        if (! is_array($data) || ! isset($data['issues'])) {
            return null;
        }

        return new self(
            issues: array_values(array_filter((array) $data['issues'], 'is_array')),
            platformVersion: $data['platform_version'] ?? null,
            minimumCore: $data['minimum_core'] ?? null,
            firstCore: $data['first_core'] ?? null,
            latestCore: $data['latest_core'] ?? null,
            uses: (array) ($data['uses'] ?? []),
            requires: (array) ($data['requires'] ?? []),
            scannedAt: $data['scanned_at'] ?? null,
        );
    }

    /** @return list<string> */
    private function messages(string $level): array
    {
        return array_values(array_map(
            fn (array $issue) => (string) $issue['message'],
            array_filter($this->issues, fn (array $issue) => ($issue['level'] ?? null) === $level),
        ));
    }
}
