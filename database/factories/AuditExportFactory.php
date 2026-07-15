<?php

namespace Database\Factories;

use App\Enums\AuditExportFormat;
use App\Enums\AuditExportLogType;
use App\Enums\AuditExportStatus;
use App\Models\AuditExport;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditExport>
 */
class AuditExportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'requested_by' => User::factory(),
            'log_type' => AuditExportLogType::Audit,
            'format' => AuditExportFormat::Csv,
            'range_start' => null,
            'range_end' => null,
            'status' => AuditExportStatus::Pending,
            'path' => null,
            'expires_at' => null,
        ];
    }

    /**
     * Export the security-event log instead of the audit log.
     */
    public function security(): static
    {
        return $this->state(fn (array $attributes): array => [
            'log_type' => AuditExportLogType::Security,
        ]);
    }

    /**
     * Write the export as JSON instead of CSV.
     */
    public function json(): static
    {
        return $this->state(fn (array $attributes): array => [
            'format' => AuditExportFormat::Json,
        ]);
    }

    /**
     * Mark the export as built and ready to download.
     */
    public function ready(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AuditExportStatus::Ready,
            'path' => 'audit-exports/'.fake()->uuid().'.'.$this->extensionFor($attributes),
            'expires_at' => now()->addDays(7),
        ]);
    }

    /**
     * Mark the export as expired (ready, but past its download window).
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AuditExportStatus::Ready,
            'path' => 'audit-exports/'.fake()->uuid().'.'.$this->extensionFor($attributes),
            'expires_at' => now()->subDay(),
        ]);
    }

    /**
     * Resolve the file extension a built export's path should carry from the
     * format already set on the state (always an enum: the definition and the
     * json() state both set an AuditExportFormat).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function extensionFor(array $attributes): string
    {
        /** @var AuditExportFormat $format */
        $format = $attributes['format'] ?? AuditExportFormat::Csv;

        return $format->extension();
    }

    /**
     * Mark the export as failed to build.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AuditExportStatus::Failed,
        ]);
    }
}
