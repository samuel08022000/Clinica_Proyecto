<?php

namespace App\Services\Auth;

use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;

final class ChallengeStore
{
    public function __construct(private DatabaseManager $database, private Encrypter $encrypter) {}

    public function issue(string $purpose, ?string $userId, array $payload, int $ttlSeconds, bool $revokeForUser = false): string
    {
        $raw = Str::random(64);
        $db = $this->database->connection();
        $db->transaction(function () use ($db, $purpose, $userId, $payload, $ttlSeconds, $revokeForUser, $raw): void {
            if ($revokeForUser && $userId) {
                $db->table('auth_challenges')->where('purpose', $purpose)->where('user_id', $userId)
                    ->whereNull('consumed_at')->update(['consumed_at' => now(), 'updated_at' => now()]);
            }
            $db->table('auth_challenges')->insert([
                'token_hash' => hash('sha256', $raw), 'purpose' => $purpose, 'user_id' => $userId,
                'payload' => $this->encrypter->encrypt(json_encode($payload, JSON_THROW_ON_ERROR)),
                'expires_at' => now()->addSeconds($ttlSeconds), 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return $raw;
    }

    public function read(string $raw, string $purpose): ?object
    {
        if ($raw === '') {
            return null;
        }
        $row = $this->database->connection()->table('auth_challenges')
            ->where('token_hash', hash('sha256', $raw))->where('purpose', $purpose)
            ->whereNull('consumed_at')->where('expires_at', '>', now())->first();

        return $row ? $this->hydrate($row) : null;
    }

    public function claim(string $raw, string $purpose): ?object
    {
        return $this->database->connection()->transaction(function ($db) use ($raw, $purpose) {
            $hash = hash('sha256', $raw);
            $row = $db->table('auth_challenges')->where('token_hash', $hash)->where('purpose', $purpose)
                ->whereNull('consumed_at')->where('expires_at', '>', now())->first();
            if (! $row || $db->table('auth_challenges')->where('token_hash', $hash)->whereNull('consumed_at')
                ->update(['consumed_at' => now(), 'updated_at' => now()]) !== 1) {
                return null;
            }

            return $this->hydrate($row);
        }, 1);
    }

    public function consume(string $raw, string $purpose): bool
    {
        return $this->database->connection()->table('auth_challenges')
            ->where('token_hash', hash('sha256', $raw))->where('purpose', $purpose)
            ->whereNull('consumed_at')->update(['consumed_at' => now(), 'updated_at' => now()]) === 1;
    }

    public function revoke(string $raw): void
    {
        $this->database->connection()->table('auth_challenges')->where('token_hash', hash('sha256', $raw))->delete();
    }

    private function hydrate(object $row): object
    {
        $payload = json_decode($this->encrypter->decrypt($row->payload), true, 32, JSON_THROW_ON_ERROR);

        return (object) ['userId' => $row->user_id, 'payload' => $payload, 'expiresAt' => $row->expires_at];
    }
}
