<?php

declare(strict_types=1);

namespace Box\Mod\Servicesaas\Client;

final readonly class ProvisioningPayload
{
    public string $action;
    public string $appId;
    public ?int $orderId;
    public int $clientId;
    public string $email;
    public ?string $externalUserId;
    public string $planId;

    /** @var array<string, mixed> */
    public array $planMetadata;

    public ?int $expiresAtMicro;

    /**
     * @param string|array<string, mixed> $actionOrData
     */
    public function __construct(
        string|array $actionOrData,
        string $appId = '',
        ?int $orderId = null,
    ) {
        $normalized = is_array($actionOrData)
            ? self::fromArrayData($actionOrData)
            : self::fromPositionalArgs(func_get_args(), $appId, $orderId);

        $this->action = $normalized['action'];
        $this->appId = $normalized['app_id'];
        $this->orderId = $normalized['order_id'];
        $this->clientId = $normalized['client_id'];
        $this->email = $normalized['email'];
        $this->externalUserId = $normalized['external_user_id'];
        $this->planId = $normalized['plan_id'];
        $this->planMetadata = $normalized['plan_metadata'];
        $this->expiresAtMicro = $normalized['expires_at_micro'];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'app_id' => $this->appId,
            'order_id' => $this->orderId,
            'client_id' => $this->clientId,
            'email' => $this->email,
            'external_user_id' => $this->externalUserId,
            'plan_id' => $this->planId,
            'plan_metadata' => $this->planMetadata,
            'expires_at_micro' => $this->expiresAtMicro,
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{
     *     action: string,
     *     app_id: string,
     *     order_id: ?int,
     *     client_id: int,
     *     email: string,
     *     external_user_id: ?string,
     *     plan_id: string,
     *     plan_metadata: array<string, mixed>,
     *     expires_at_micro: ?int
     * }
     */
    private static function fromArrayData(array $data): array
    {
        $rawOrderId = $data['order_id'] ?? $data['orderId'] ?? null;
        $rawExpires = $data['expires_at_micro'] ?? $data['expiresAtMicro'] ?? null;
        $rawExtUser = $data['external_user_id'] ?? $data['externalUserId'] ?? null;
        $rawMetadata = $data['plan_metadata'] ?? $data['planMetadata'] ?? [];

        return [
            'action' => (string) ($data['action'] ?? ''),
            'app_id' => (string) ($data['app_id'] ?? $data['appId'] ?? ''),
            'order_id' => $rawOrderId !== null && $rawOrderId !== '' ? (int) $rawOrderId : null,
            'client_id' => (int) ($data['client_id'] ?? $data['clientId'] ?? 0),
            'email' => strtolower(trim((string) ($data['email'] ?? ''))),
            'external_user_id' => is_string($rawExtUser) && $rawExtUser !== '' ? $rawExtUser : null,
            'plan_id' => (string) ($data['plan_id'] ?? $data['planId'] ?? ''),
            'plan_metadata' => is_array($rawMetadata) ? $rawMetadata : [],
            'expires_at_micro' => $rawExpires !== null && $rawExpires !== '' ? (int) $rawExpires : null,
        ];
    }

    /**
     * @param array<int, mixed> $args
     *
     * @return array{
     *     action: string,
     *     app_id: string,
     *     order_id: ?int,
     *     client_id: int,
     *     email: string,
     *     external_user_id: ?string,
     *     plan_id: string,
     *     plan_metadata: array<string, mixed>,
     *     expires_at_micro: ?int
     * }
     */
    private static function fromPositionalArgs(array $args, string $appId, ?int $orderId): array
    {
        return self::fromArrayData([
            'action' => $args[0] ?? '',
            'app_id' => $appId,
            'order_id' => $orderId,
            'client_id' => $args[3] ?? 0,
            'email' => $args[4] ?? '',
            'external_user_id' => $args[5] ?? null,
            'plan_id' => $args[6] ?? '',
            'plan_metadata' => $args[7] ?? [],
            'expires_at_micro' => $args[8] ?? null,
        ]);
    }
}
