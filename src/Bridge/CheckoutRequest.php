<?php

declare(strict_types=1);

namespace Box\Mod\Servicesaas\Bridge;

final readonly class CheckoutRequest
{
    public const string ACTION_BUY = 'buy';
    public const string ACTION_UPDATE = 'update';

    private const array ALLOWED_ACTIONS = [
        self::ACTION_BUY,
        self::ACTION_UPDATE,
    ];

    public string $appId;
    public string $externalUserId;
    public string $email;
    public string $planId;
    public string $action;
    public ?string $redirectUrl;
    /** @var array<string, mixed> */
    public array $planMetadata;

    /**
     * @param array<string, mixed>|string $appIdOrData
     */
    public function __construct(
        array|string $appIdOrData,
        string $externalUserId = '',
        string $email = '',
    ) {
        if (is_array($appIdOrData)) {
            $this->appId = (string) ($appIdOrData['appId'] ?? $appIdOrData['app_id'] ?? '');
            $this->externalUserId = (string) ($appIdOrData['externalUserId'] ?? $appIdOrData['external_user_id'] ?? '');
            $this->email = (string) ($appIdOrData['email'] ?? '');
            $this->planId = (string) ($appIdOrData['planId'] ?? $appIdOrData['plan_id'] ?? '');
            $this->action = (string) ($appIdOrData['action'] ?? self::ACTION_BUY);
            $this->redirectUrl = isset($appIdOrData['redirectUrl']) || isset($appIdOrData['redirect_url'])
                ? (string) ($appIdOrData['redirectUrl'] ?? $appIdOrData['redirect_url'])
                : null;
            $meta = $appIdOrData['planMetadata'] ?? $appIdOrData['plan_metadata'] ?? [];
            $this->planMetadata = is_array($meta) ? $meta : [];

            return;
        }

        $args = func_get_args();
        $this->appId = $appIdOrData;
        $this->externalUserId = $externalUserId;
        $this->email = $email;
        $this->planId = isset($args[3]) && is_scalar($args[3]) ? (string) $args[3] : '';
        $this->action = isset($args[4]) && is_scalar($args[4]) ? (string) $args[4] : self::ACTION_BUY;
        $this->redirectUrl = isset($args[5]) && is_string($args[5]) && $args[5] !== '' ? $args[5] : null;
        $this->planMetadata = isset($args[6]) && is_array($args[6]) ? $args[6] : [];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromDecryptedPayload(array $data): self
    {
        $appId = self::extractRequiredString($data, 'app_id', 'appId');
        $planId = self::extractRequiredString($data, 'plan_id', 'planId');
        $email = self::extractValidEmail($data);
        $action = self::extractValidAction($data);
        $externalUserId = trim((string) ($data['external_user_id'] ?? $data['externalUserId'] ?? ''));
        $redirectUrl = self::extractOptionalString($data, 'redirect_url', 'redirectUrl');
        $planMetadata = self::extractMetadata($data);

        return new self([
            'appId' => $appId,
            'externalUserId' => $externalUserId,
            'email' => $email,
            'planId' => $planId,
            'action' => $action,
            'redirectUrl' => $redirectUrl,
            'planMetadata' => $planMetadata,
        ]);
    }

    public function isUpdate(): bool
    {
        return $this->action === self::ACTION_UPDATE;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'app_id' => $this->appId,
            'external_user_id' => $this->externalUserId,
            'email' => $this->email,
            'plan_id' => $this->planId,
            'action' => $this->action,
            'redirect_url' => $this->redirectUrl,
            'plan_metadata' => $this->planMetadata,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function extractRequiredString(array $data, string $snakeKey, string $camelKey): string
    {
        $rawValue = $data[$snakeKey] ?? $data[$camelKey] ?? '';
        if (!is_scalar($rawValue)) {
            throw new \InvalidArgumentException(sprintf('Field "%s" must be a non-empty string.', $snakeKey));
        }

        $trimmed = trim((string) $rawValue);
        if ($trimmed === '') {
            throw new \InvalidArgumentException(sprintf('Field "%s" is required and cannot be empty.', $snakeKey));
        }

        return $trimmed;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function extractValidEmail(array $data): string
    {
        $rawEmail = $data['email'] ?? '';
        if (!is_scalar($rawEmail)) {
            throw new \InvalidArgumentException('Field "email" must be a valid email address.');
        }

        $email = strtolower(trim((string) $rawEmail));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Field "email" must be a valid email address.');
        }

        return $email;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function extractValidAction(array $data): string
    {
        $rawAction = $data['action'] ?? '';
        if (!is_scalar($rawAction)) {
            throw new \InvalidArgumentException('Field "action" must be either "buy" or "update".');
        }

        $action = strtolower(trim((string) $rawAction));
        if (!in_array($action, self::ALLOWED_ACTIONS, true)) {
            throw new \InvalidArgumentException(sprintf('Invalid checkout action "%s". Allowed: buy, update.', $action));
        }

        return $action;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function extractOptionalString(array $data, string $snakeKey, string $camelKey): ?string
    {
        $rawValue = $data[$snakeKey] ?? $data[$camelKey] ?? $data['return_redirect_url'] ?? null;
        if (!is_scalar($rawValue)) {
            return null;
        }

        $trimmed = trim((string) $rawValue);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function extractMetadata(array $data): array
    {
        $meta = $data['plan_metadata'] ?? $data['planMetadata'] ?? [];

        return is_array($meta) ? $meta : [];
    }
}
