<?php
/**
 * UserProfile: readonly DTO for a user's profile row.
 *
 * Carries the per-user data surfaced by the Settings → Profile tab:
 * contact details, free-form identity (name / job / bio) and the
 * pipeline-relevant `systemPromptAddendum` that the prompt assembler
 * appends after the base system prompt file.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-05-13
 */
declare(strict_types=1);

namespace App\Profiles;

final readonly class UserProfile
{
    public function __construct(
        public ?int   $id,
        public int    $userId,
        public string $externalId,
        public string $email,
        public string $phone,
        public string $firstName,
        public string $lastName,
        public string $jobTitle,
        public string $bio,
        public string $systemPromptAddendum,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id'                      => $this->id,
            'user_id'                 => $this->userId,
            'external_id'             => $this->externalId,
            'email'                   => $this->email,
            'phone'                   => $this->phone,
            'first_name'              => $this->firstName,
            'last_name'               => $this->lastName,
            'job_title'               => $this->jobTitle,
            'bio'                     => $this->bio,
            'system_prompt_addendum'  => $this->systemPromptAddendum,
        ];
    }

    public static function empty(int $userId, string $externalId): self
    {
        return new self(null, $userId, $externalId, '', '', '', '', '', '', '');
    }
}
