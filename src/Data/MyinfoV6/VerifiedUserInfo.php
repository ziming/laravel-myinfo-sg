<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Data\MyinfoV6;

use InvalidArgumentException;
use LogicException;

final readonly class VerifiedUserInfo
{
    /**
     * Claim values are intentionally heterogeneous because the verified JWT may
     * contain scope-dependent, provider-defined JSON claims.
     *
     * @param array<string, mixed> $claims
     */
    public function __construct(private array $claims)
    {
        if (! is_array($claims['person_info'] ?? null)) {
            throw new InvalidArgumentException('Verified UserInfo claims are invalid.');
        }
    }

    /**
     * Claim values remain mixed for the same provider-defined JSON reason noted
     * on the constructor; consumers can use typed accessors for stable fields.
     *
     * @return array<string, mixed>
     */
    public function claims(): array
    {
        return $this->claims;
    }

    /**
     * MyInfo attributes vary by requested scope and contain nested JSON values,
     * so only the stable top-level object shape can be typed here.
     *
     * @return array<string, mixed>
     */
    public function personInfo(): array
    {
        $personInfo = $this->claims['person_info'];

        return is_array($personInfo)
            ? $personInfo
            : throw new LogicException('Verified person information is unavailable.');
    }

    public function subject(): string
    {
        $subject = $this->claims['sub'] ?? null;

        return is_string($subject)
            ? $subject
            : throw new LogicException('Verified UserInfo subject is unavailable.');
    }

    /**
     * Keep personal data out of debug output.
     *
     * @return array{claims: string, person_info: string}
     */
    public function __debugInfo(): array
    {
        return [
            'claims' => '[verified]',
            'person_info' => '[redacted]',
        ];
    }
}
