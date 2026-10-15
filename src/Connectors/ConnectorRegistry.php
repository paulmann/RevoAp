<?php
/**
 * ConnectorRegistry: Auto-discovers *Connector.php files and registers
 * those that report isAvailable() === true.
 *
 * Discovery rules:
 *  - Scan the configured directory for files named "*Connector.php"
 *    excluding "AbstractConnector.php".
 *  - Resolve to a fully qualified class name in the App\Connectors namespace.
 *  - Instantiate via no-argument constructor; classes requiring arguments
 *    are skipped with a logged note (return-only registry).
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-05-05
 */
declare(strict_types=1);

namespace App\Connectors;

final class ConnectorRegistry
{
    /** @var array<string,ConnectorInterface> */
    private array $byProvider = [];

    /** @var ConnectorInterface[] */
    private array $all = [];

    private bool $loaded = false;

    /** @var string[] Names of skipped classes (for diagnostics). */
    private array $skipped = [];

    public function __construct(private readonly string $connectorsDir)
    {
    }

    /**
     * Lazily load and instantiate connectors.
     */
    public function load(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;
        if (!is_dir($this->connectorsDir)) {
            return;
        }
        $files = glob($this->connectorsDir . '/*Connector.php') ?: [];
        foreach ($files as $file) {
            $base = basename($file, '.php');
            if ($base === 'AbstractConnector') {
                continue;
            }
            $fqcn = 'App\\Connectors\\' . $base;
            if (!class_exists($fqcn)) {
                @require_once $file;
            }
            if (!class_exists($fqcn)) {
                $this->skipped[] = $base . ' (class missing)';
                continue;
            }
            try {
                $ref = new \ReflectionClass($fqcn);
                if ($ref->isAbstract() || !$ref->implementsInterface(ConnectorInterface::class)) {
                    $this->skipped[] = $base . ' (not a ConnectorInterface)';
                    continue;
                }
                $ctor = $ref->getConstructor();
                if ($ctor !== null && $ctor->getNumberOfRequiredParameters() > 0) {
                    $this->skipped[] = $base . ' (constructor requires arguments)';
                    continue;
                }
                /** @var ConnectorInterface $instance */
                $instance = $ref->newInstance();
                $this->all[] = $instance;
                if ($instance->isAvailable()) {
                    $this->byProvider[strtolower($instance->getProviderName())] = $instance;
                }
            } catch (\Throwable $e) {
                $this->skipped[] = $base . ' (' . $e->getMessage() . ')';
            }
        }
    }

    /**
     * @return ConnectorInterface[] Connectors with isAvailable()===true.
     */
    public function all(): array
    {
        $this->load();
        return array_values($this->byProvider);
    }

    /**
     * @return ConnectorInterface[] Every discovered connector regardless of availability.
     */
    public function discovered(): array
    {
        $this->load();
        return $this->all;
    }

    public function getByProvider(string $provider): ?ConnectorInterface
    {
        $this->load();
        return $this->byProvider[strtolower($provider)] ?? null;
    }

    /**
     * Whether any active connector exists for the given provider.
     */
    public function hasProvider(string $provider): bool
    {
        return $this->getByProvider($provider) !== null;
    }

    /** @return string[] */
    public function skipped(): array
    {
        $this->load();
        return $this->skipped;
    }
}
