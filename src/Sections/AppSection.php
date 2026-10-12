<?php

namespace MarvelousDigital\WideEvents\Sections;

use MarvelousDigital\WideEvents\Attributes\Key;
use MarvelousDigital\WideEvents\Section;
use OpenTelemetry\SemConv\Attributes\ServiceAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\DeploymentIncubatingAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\HostIncubatingAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\ProcessIncubatingAttributes;

final class AppSection extends Section
{
    #[Key(DeploymentIncubatingAttributes::DEPLOYMENT_ENVIRONMENT_NAME)]
    public readonly string $environment;

    #[Key(ServiceAttributes::SERVICE_VERSION)]
    public readonly ?string $version;

    // No constant outside the deprecated ResourceAttributes class.
    #[Key('cloud.region')]
    public readonly ?string $region;

    #[Key(HostIncubatingAttributes::HOST_NAME)]
    public readonly string|false $host;

    #[Key(ProcessIncubatingAttributes::PROCESS_RUNTIME_VERSION)]
    public readonly string $php;

    #[Key('laravel.version')]
    public readonly string $laravel;

    public function __construct()
    {
        $this->environment = (string) config('app.env');
        // ?: not ??: jot:env writes the keys empty, and env() reads APP_VERSION= as "".
        $this->version = config('jot.app.version') ?: null;
        $this->region = config('jot.app.region') ?: null;
        $this->host = gethostname();
        $this->php = PHP_VERSION;
        $this->laravel = app()->version();
    }
}
