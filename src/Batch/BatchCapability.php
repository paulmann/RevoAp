<?php
/**
 * BatchCapability: can this deployment run a Batch API job right now?
 *
 * Two independent preconditions have to hold before "Run via Batch API" can
 * produce anything, and the operator deserves to see which one is missing
 * rather than a generic failure ten seconds into a run:
 *
 *   1. **A key.** The batch routes live under the same `bothub` base URL as
 *      `/chat/completions` and authenticate with the same credential. A
 *      deployment that has not configured the connector cannot submit a
 *      batch, and this is by far the most common reason.
 *   2. **ext-curl.** The upload is multipart and the result is a raw
 *      document download; both go through ext-curl. Hardened hosts and
 *      minimal PHP builds ship without it.
 *
 * The class exists mainly so that ONE place answers the question, and so the
 * answer can be reported — in `/api/boot-config`, in
 * `GET /api/council/batch/models`, and in the refusal a job records — with
 * the same wording.
 *
 * ## What this is NOT
 *
 * It is not a per-model verdict. Whether a given model can be batched is a
 * question for the provider (`GET /batches/models`), and the answer is
 * three-valued: supported, or unknown. This class never contributes to the
 * "unknown" side of that; it only says whether the machinery is present.
 * See {@see \App\Connectors\Batch\BatchModelCatalog} for why the tri-state
 * matters.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-21
 */
declare(strict_types=1);

namespace App\Batch;

use App\Connectors\AbstractConnector;
use App\Connectors\Batch\BothubBatchClient;
use App\Connectors\Batch\BatchTransportInterface;
use App\Connectors\Batch\CurlBatchTransport;
use App\Connectors\ConnectorRegistry;

final class BatchCapability
{
    /** The provider whose Batch API this deployment talks to. */
    public const PROVIDER = 'bothub';

    private function __construct(
        private readonly bool             $ready,
        private readonly string           $reason,
        private readonly BothubBatchClient $client,
    ) {}

    /**
     * Inspect the deployment.
     *
     * @param ConnectorRegistry|null $connectors Registry to take the
     *        `bothub` connector from. Null (or a registry without it) falls
     *        back to a fresh {@see \App\Connectors\BothubConnector}, which
     *        resolves the same configuration.
     * @param BatchTransportInterface|null $transport Offline seam for tests.
     */
    public static function fromEnvironment(
        ?ConnectorRegistry $connectors = null,
        ?BatchTransportInterface $transport = null,
    ): self {
        $connector = $connectors?->getByProvider(self::PROVIDER);
        $abstract  = $connector instanceof AbstractConnector ? $connector : null;
        $client    = new BothubBatchClient($abstract, $transport);

        $key = '';
        try {
            $key = $client->connector()->credential();
        } catch (\Throwable) {
            $key = '';
        }
        if ($key === '') {
            return new self(
                ready:  false,
                reason: 'No API key resolved for the "bothub" connector. The Batch API uses the same credential '
                    . 'as the chat endpoint — add the key (Settings → Connectors, or `.bothub.key`) to use it.',
                client: $client,
            );
        }
        // The transport is only probed when it IS the curl transport; an
        // injected fixture transport is by definition usable.
        if ($client->transportIsDefault() && !CurlBatchTransport::isAvailable()) {
            return new self(
                ready:  false,
                reason: CurlBatchTransport::unavailableMessage(),
                client: $client,
            );
        }
        return new self(ready: true, reason: '', client: $client);
    }

    public function ready(): bool
    {
        return $this->ready;
    }

    /** Why the batch path is unavailable, or '' when it is available. */
    public function reason(): string
    {
        return $this->reason;
    }

    /** The client every batch caller should use. */
    public function client(): BothubBatchClient
    {
        return $this->client;
    }

    /**
     * Browser-safe report. `ready` gates the Chat toggle; `reason` is what
     * the UI shows when it is disabled, so a disabled control always
     * explains itself.
     *
     * @return array{ok:bool,ready:bool,provider:string,reason:string,transport:string}
     */
    public function toArray(): array
    {
        return [
            'ok'        => $this->ready,
            'ready'     => $this->ready,
            'provider'  => self::PROVIDER,
            'reason'    => $this->reason,
            'transport' => 'ext-curl',
        ];
    }
}
