<?php

declare(strict_types=1);

namespace Saucy\Core\Subscriptions\Lanes;

use Illuminate\Database\ConnectionInterface;

/**
 * One row per lane in `lane_coordination`.
 *
 * Every mutation runs inside a transaction with `lockForUpdate()` on the row, so a bump returns
 * the value of its own increment even under concurrency (see {@see LaneCoordinator}). Note that
 * a host wrapping its commands in an outer `DB::transaction()` will hold that row lock until the
 * outer transaction commits.
 */
final readonly class IlluminateLaneCoordinator implements LaneCoordinator
{
    public function __construct(
        private ConnectionInterface $connection,
        private string $tableName = 'lane_coordination',
    ) {}

    public function read(string $lane): LaneCoordinationState
    {
        $row = $this->row($lane);

        if ($row === null) {
            return LaneCoordinationState::empty();
        }

        return new LaneCoordinationState(
            membershipVersion: $this->toInt($row['membership_version'] ?? 0),
            structuralPending: (bool) ($row['structural_pending'] ?? false),
            acknowledgedVersion: $this->toInt($row['acknowledged_version'] ?? 0),
            claimedMembers: array_keys($this->decodeClaims($row['claimed_members'] ?? null)),
        );
    }

    public function bumpMembership(string $lane, bool $structural = true): int
    {
        return $this->mutate($lane, function (array $row) use ($structural): array {
            $version = $this->toInt($row['membership_version'] ?? 0) + 1;

            return [
                'update' => ['membership_version' => $version]
                    + ($structural ? ['structural_pending' => true] : []),
                'return' => $version,
            ];
        });
    }

    public function structuralPending(string $lane): bool
    {
        return $this->read($lane)->structuralPending;
    }

    public function membershipVersion(string $lane): int
    {
        return $this->read($lane)->membershipVersion;
    }

    public function acknowledge(string $lane, int $version): void
    {
        $this->mutate($lane, function (array $row) use ($version): array {
            $update = [
                'acknowledged_version' => max($this->toInt($row['acknowledged_version'] ?? 0), $version),
            ];

            // Only clear the flag when no newer bump landed after the acknowledged version.
            if ($this->toInt($row['membership_version'] ?? 0) <= $version) {
                $update['structural_pending'] = false;
            }

            return ['update' => $update, 'return' => 0];
        });
    }

    public function acknowledgedVersion(string $lane): int
    {
        return $this->read($lane)->acknowledgedVersion;
    }

    public function claim(string $lane, string $memberId): int
    {
        return $this->mutateClaims($lane, static function (array $claims) use ($memberId): array {
            $claims[$memberId] = true;

            return $claims;
        });
    }

    public function release(string $lane, string $memberId): int
    {
        return $this->mutateClaims($lane, static function (array $claims) use ($memberId): array {
            unset($claims[$memberId]);

            return $claims;
        });
    }

    public function claimedMembers(string $lane): array
    {
        return $this->read($lane)->claimedMembers;
    }

    /**
     * A claim change is also a (non-structural) claim bump, applied in the same row lock.
     *
     * @param callable(array<string, true>): array<string, true> $mutateClaims
     */
    private function mutateClaims(string $lane, callable $mutateClaims): int
    {
        return $this->mutate($lane, function (array $row) use ($mutateClaims): array {
            $claims = $mutateClaims($this->decodeClaims($row['claimed_members'] ?? null));
            $version = $this->toInt($row['membership_version'] ?? 0) + 1;

            return [
                'update' => [
                    'claimed_members' => json_encode(array_keys($claims)),
                    'membership_version' => $version,
                ],
                'return' => $version,
            ];
        });
    }

    /**
     * Locks the lane row, hands it to $mutate and applies the update it asks for.
     *
     * @param callable(array<string, mixed>): array{update: array<string, mixed>, return: int} $mutate
     */
    private function mutate(string $lane, callable $mutate): int
    {
        $this->ensureRow($lane);

        /** @var int $result */
        $result = $this->connection->transaction(function () use ($lane, $mutate): int {
            $row = $this->connection->table($this->tableName)
                ->where('lane', $lane)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return 0;
            }

            $outcome = $mutate((array) $row);

            if ($outcome['update'] !== []) {
                $this->connection->table($this->tableName)
                    ->where('lane', $lane)
                    ->update($outcome['update']);
            }

            return $outcome['return'];
        });

        return $result;
    }

    /**
     * @return array<string, true>
     */
    private function decodeClaims(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $claims = [];
        foreach ($decoded as $memberId) {
            if (is_string($memberId)) {
                $claims[$memberId] = true;
            }
        }

        return $claims;
    }

    private function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function row(string $lane): ?array
    {
        $row = $this->connection->table($this->tableName)->where('lane', $lane)->first();

        return $row === null ? null : (array) $row;
    }

    private function ensureRow(string $lane): void
    {
        // Read first so the steady state is a plain SELECT. An unconditional INSERT IGNORE would
        // take a shared lock on the existing row for the rest of the transaction on InnoDB, and
        // two concurrent claims inside an enclosing host transaction would then deadlock on the
        // lockForUpdate() that follows. insertOrIgnore (rather than an insert in a swallowed
        // try) keeps a lost race from aborting an enclosing Postgres transaction.
        if ($this->connection->table($this->tableName)->where('lane', $lane)->exists()) {
            return;
        }

        $this->connection->table($this->tableName)->insertOrIgnore([
            'lane' => $lane,
            'membership_version' => 0,
            'acknowledged_version' => 0,
            'structural_pending' => false,
            'claimed_members' => json_encode([]),
        ]);
    }
}
