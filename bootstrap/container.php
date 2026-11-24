<?php

declare(strict_types=1);

use App\Core\Config\Config;
use App\Core\Container;
use App\Core\Database\Database;
use App\Core\Database\Migrations\MigrationRunner;
use App\Core\ExceptionHandler;
use App\Core\Http\RequestContext;
use App\Core\View\AppExtension;
use App\Core\View\AssetVersions;
use App\Core\View\View;
use App\Modules\Auth\Context\AuthContext;
use App\Modules\Auth\Context\CurrentMembership;
use App\Modules\Platform\Context\PlatformAuthContext;
use App\Modules\Platform\Services\SupportSessionService;
use App\Modules\Platform\Support\SupportEntryToken;
use App\Modules\System\Services\HealthCheck;
use App\Shared\I18n\Translator;
use App\Shared\Logging\LoggerFactory;
use App\Shared\Mail\LogMailer;
use App\Shared\Mail\Mailer;
use App\Shared\Mail\SmtpMailer;
use App\Shared\Money\CurrencyCatalog;
use App\Shared\Money\CurrencyFormatter;
use App\Shared\Money\DatabaseCurrencyCatalog;
use App\Shared\Security\CsrfService;
use App\Shared\Security\Encrypter;
use App\Shared\Security\PasswordPolicy;
use App\Shared\Support\HostMap;
use App\Shared\Support\MaintenanceMode;
use App\Shared\Support\TokenGenerator;
use App\Shared\Support\TrustedProxies;
use App\Shared\Time\Clock;
use App\Shared\Time\SystemClock;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Support\SupportSessionLocator;
use Psr\Log\LoggerInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/*
 * Service definitions. Shared (one instance per request) unless noted.
 * Concrete classes not listed here are autowired from type hints.
 */
return static function (Container $c, Config $config, string $basePath): void {
    $c->set(RequestContext::class, static fn(): RequestContext => new RequestContext());

    $c->set(Clock::class, static fn(): Clock => new SystemClock());

    $c->set(LoggerInterface::class, static fn(Container $c): LoggerInterface => LoggerFactory::create(
        $config,
        $c->get(RequestContext::class),
        $basePath,
    ));

    $c->set(Database::class, static fn(): Database => new Database($config->array('database')));

    $c->set(MigrationRunner::class, static fn(Container $c): MigrationRunner => new MigrationRunner(
        // CLI migrations connect as the schema owner when one is configured;
        // web requests (the /health migration check is read-only) never get
        // those credentials.
        PHP_SAPI === 'cli' && $config->string('database.migration_username') !== ''
            ? new Database([
                'username' => $config->string('database.migration_username'),
                'password' => $config->string('database.migration_password'),
            ] + $config->array('database'))
            : $c->get(Database::class),
        $basePath . '/' . $config->string('database.migrations_path', 'database/migrations'),
    ));

    $c->set(HostMap::class, static fn(): HostMap => new HostMap($config->array('app.hosts')));

    $c->set(TrustedProxies::class, static fn(): TrustedProxies => new TrustedProxies(
        array_values(array_filter($config->array('security.trusted_proxies'), 'is_string')),
    ));

    $c->set(MaintenanceMode::class, static fn(): MaintenanceMode => new MaintenanceMode(
        $basePath . '/' . $config->string('app.maintenance_file'),
    ));

    $c->set(CsrfService::class, static fn(Container $c): CsrfService => new CsrfService(
        $config->appKey(),
        $c->get(TokenGenerator::class),
    ));

    // Request-scoped identity holders: middleware, controllers and views must
    // all see the same instance for the duration of one request.
    $c->set(AuthContext::class, static fn(): AuthContext => new AuthContext());
    $c->set(CurrentMembership::class, static fn(): CurrentMembership => new CurrentMembership());
    $c->set(CurrentTenant::class, static fn(): CurrentTenant => new CurrentTenant());
    $c->set(PlatformAuthContext::class, static fn(): PlatformAuthContext => new PlatformAuthContext());

    $c->set(Encrypter::class, static fn(): Encrypter => new Encrypter($config->appKey()));

    $c->set(SupportEntryToken::class, static fn(Container $c): SupportEntryToken => new SupportEntryToken(
        $config->appKey(),
        $c->get(Clock::class),
    ));
    $c->set(SupportSessionLocator::class, static fn(Container $c): SupportSessionLocator => $c->get(SupportSessionService::class));

    $c->set(PasswordPolicy::class, static fn(): PasswordPolicy => new PasswordPolicy($config, $basePath));

    $c->set(Mailer::class, static function () use ($config, $basePath): Mailer {
        if ($config->string('mail.transport') === 'smtp') {
            return new SmtpMailer(
                $config->string('mail.smtp.host'),
                $config->int('mail.smtp.port', 587),
                $config->string('mail.smtp.encryption', 'tls'),
                $config->string('mail.smtp.username'),
                $config->string('mail.smtp.password'),
                $config->string('mail.from_address'),
                $config->string('mail.from_name', 'AlbaBook'),
            );
        }

        return new LogMailer(
            $basePath . '/' . $config->string('mail.log_path', 'storage/logs/mail.log'),
            $config->string('mail.from_address'),
            $config->string('mail.from_name', 'AlbaBook'),
            in_array($config->string('app.env'), ['staging', 'production'], true),
        );
    });

    $c->set(Translator::class, static fn(Container $c): Translator => new Translator(
        $basePath . '/' . $config->string('i18n.path', 'lang'),
        $config->string('i18n.fallback_locale', 'en'),
        $c->get(RequestContext::class),
    ));

    $c->set(CurrencyCatalog::class, static fn(Container $c): CurrencyCatalog => new DatabaseCurrencyCatalog($c->get(Database::class)));

    $c->set(CurrencyFormatter::class, static fn(Container $c): CurrencyFormatter => new CurrencyFormatter($c->get(CurrencyCatalog::class)));

    $c->set(AssetVersions::class, static fn(): AssetVersions => new AssetVersions($basePath . '/public/assets'));

    $c->set(Environment::class, static function (Container $c) use ($config, $basePath): Environment {
        $twig = new Environment(new FilesystemLoader($basePath . '/resources/views'), [
            'autoescape' => 'html',
            'strict_variables' => true,
            'cache' => $config->isDebug() ? false : $basePath . '/storage/cache/twig',
            'auto_reload' => $config->isDebug(),
            'debug' => $config->isDebug(),
        ]);
        $twig->addExtension(new AppExtension(
            $c->get(RequestContext::class),
            $c->get(Translator::class),
            $c->get(AssetVersions::class),
            $config,
            $c->get(CurrencyFormatter::class),
        ));

        return $twig;
    });

    $c->set(View::class, static fn(Container $c): View => new View($c->get(Environment::class)));

    $c->set(HealthCheck::class, static fn(Container $c): HealthCheck => new HealthCheck(
        $c->get(Database::class),
        $c->get(MigrationRunner::class),
        $c->get(MaintenanceMode::class),
        $c->get(LoggerInterface::class),
        $basePath,
    ), shared: false);

    $c->set(ExceptionHandler::class, static fn(Container $c): ExceptionHandler => new ExceptionHandler(
        $c->get(LoggerInterface::class),
        $c->get(RequestContext::class),
        $c->get(View::class),
        $config->isDebug(),
    ));
};
