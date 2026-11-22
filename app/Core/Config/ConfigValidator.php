<?php

declare(strict_types=1);

namespace App\Core\Config;

use App\Shared\Support\TrustedProxies;

/**
 * Validates configuration at startup so a misconfigured server fails before
 * serving a single request. Messages name the problem, never the value.
 */
final class ConfigValidator
{
    private const ENVIRONMENTS = ['local', 'testing', 'staging', 'production'];
    private const LOG_LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    /** @return list<string> */
    public function validate(Config $config): array
    {
        $errors = [];
        $env = $config->string('app.env');
        $production = $env === 'production';
        $deployed = in_array($env, ['staging', 'production'], true);

        if (!in_array($env, self::ENVIRONMENTS, true)) {
            $errors[] = 'APP_ENV must be one of: ' . implode(', ', self::ENVIRONMENTS) . '.';
        }

        if (self::decodeKey($config->string('app.key')) === null) {
            $errors[] = 'APP_KEY must be "base64:" followed by 32 random bytes (run: php bin/console key:generate).';
        }

        if ($production && $config->bool('app.debug')) {
            $errors[] = 'APP_DEBUG must be false in production.';
        }

        $hosts = $config->array('app.hosts');
        foreach (['app' => 'APP_HOST_TENANT', 'book' => 'APP_HOST_BOOKING', 'api' => 'APP_HOST_API', 'admin' => 'APP_HOST_ADMIN', 'site' => 'APP_HOST_MARKETING'] as $group => $var) {
            $host = $hosts[$group] ?? '';
            if (!is_string($host) || !self::isHostname($host)) {
                $errors[] = "{$var} must be a valid lowercase hostname without scheme or port.";
            }
        }
        $hostValues = array_filter($hosts, 'is_string');
        if (count($hostValues) !== count(array_unique($hostValues))) {
            $errors[] = 'APP_HOST_TENANT, APP_HOST_BOOKING, APP_HOST_API, APP_HOST_ADMIN and APP_HOST_MARKETING must all differ.';
        }

        foreach (['host' => 'DB_HOST', 'database' => 'DB_DATABASE', 'username' => 'DB_USERNAME'] as $key => $var) {
            if ($config->string("database.{$key}") === '') {
                $errors[] = "{$var} is required.";
            }
        }
        $port = $config->int('database.port');
        if ($port < 1 || $port > 65535) {
            $errors[] = 'DB_PORT must be a valid port number.';
        }
        if ($deployed && $config->string('database.password') === '') {
            $errors[] = 'DB_PASSWORD must not be empty in staging or production.';
        }

        if (!in_array($config->string('app.default_tenant_timezone'), \DateTimeZone::listIdentifiers(), true)) {
            $errors[] = 'DEFAULT_TENANT_TIMEZONE must be a valid IANA timezone (e.g. Africa/Nairobi).';
        }
        if (preg_match('/^[A-Z]{3}$/', $config->string('app.default_tenant_currency')) !== 1) {
            $errors[] = 'DEFAULT_TENANT_CURRENCY must be an ISO 4217 code (e.g. KES).';
        }

        if ($deployed && !$config->bool('security.secure_cookies')) {
            $errors[] = 'SESSION_SECURE_COOKIE must be true in staging or production.';
        }

        foreach ($config->array('security.trusted_proxies') as $proxy) {
            if (!is_string($proxy) || !TrustedProxies::isValidEntry($proxy)) {
                $errors[] = 'TRUSTED_PROXIES contains an entry that is not an IP address or CIDR range.';
                break;
            }
        }

        foreach (['security.session.cookie_secure', 'security.remember.cookie_secure', 'security.support.cookie_secure', 'security.platform.cookie_secure'] as $key) {
            if (!str_starts_with($config->string($key), '__Host-')) {
                $errors[] = strtoupper(str_replace('.', '_', $key)) . ' must start with __Host-.';
            }
        }

        if ($config->int('security.session.idle_timeout_minutes') < 1 || $config->int('security.session.absolute_timeout_minutes') < 1) {
            $errors[] = 'Session idle and absolute timeouts must be at least one minute.';
        }
        if ($config->int('security.session.idle_timeout_minutes') > $config->int('security.session.absolute_timeout_minutes')) {
            $errors[] = 'Session idle timeout cannot exceed the absolute timeout.';
        }

        if ($config->int('auth.password.min_length') < 8 || $config->int('auth.password.max_length') > 128) {
            $errors[] = 'Password length limits are outside the supported range.';
        }
        if ($config->string('auth.password.common_passwords_path') === '' || !is_file(dirname(__DIR__, 3) . '/' . $config->string('auth.password.common_passwords_path'))) {
            $errors[] = 'auth.password.common_passwords_path must point to the bundled common-password list.';
        }
        if ($config->int('auth.mfa.leeway_steps') < 0 || $config->int('auth.mfa.leeway_steps') > 2) {
            $errors[] = 'auth.mfa.leeway_steps must be 0, 1 or 2.';
        }

        $slugPattern = $config->string('tenancy.slug.pattern');
        if (@preg_match($slugPattern, '') === false) {
            $errors[] = 'tenancy.slug.pattern is not a valid regular expression.';
        }
        if ($config->int('tenancy.support.max_minutes') < 1
            || $config->int('tenancy.support.default_minutes') > $config->int('tenancy.support.max_minutes')) {
            $errors[] = 'Support session durations must be positive and the default cannot exceed the maximum.';
        }

        if (!in_array($config->string('mail.transport'), ['log', 'smtp'], true)) {
            $errors[] = 'MAIL_TRANSPORT must be "log" or "smtp".';
        }
        if ($config->string('mail.transport') === 'smtp'
            && ($config->string('mail.smtp.host') === '' || $config->string('mail.from_address') === '')) {
            $errors[] = 'MAIL_TRANSPORT=smtp requires MAIL_HOST and MAIL_FROM_ADDRESS.';
        }
        if ($deployed && $config->string('mail.transport') === 'log') {
            $errors[] = 'MAIL_TRANSPORT=log is refused in staging and production; configure a real transport before deploying.';
        }
        if (filter_var($config->string('mail.from_address'), FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'MAIL_FROM_ADDRESS must be a valid email address.';
        }

        $smsProvider = $config->string('notifications.platform.sms.provider');
        if ($smsProvider !== '' && !in_array($smsProvider, ['africastalking', 'africastalking_sms', 'twilio', 'twilio_sms'], true)) {
            $errors[] = 'SMS_PROVIDER must be "africastalking" or "twilio" (or the full provider code).';
        }
        if (in_array($smsProvider, ['africastalking', 'africastalking_sms'], true)
            && ($config->string('notifications.platform.sms.credentials.username') === ''
                || $config->string('notifications.platform.sms.credentials.api_key') === '')) {
            $errors[] = 'SMS_PROVIDER=africastalking requires SMS_USERNAME and SMS_API_KEY.';
        }

        foreach ($config->array('security.platform.ip_allowlist') as $entry) {
            if (!is_string($entry) || !TrustedProxies::isValidEntry($entry)) {
                $errors[] = 'SECURITY_PLATFORM_IP_ALLOWLIST contains an entry that is not an IP address or CIDR range.';
                break;
            }
        }

        if (!in_array($config->string('logging.level'), self::LOG_LEVELS, true)) {
            $errors[] = 'LOG_LEVEL must be one of: ' . implode(', ', self::LOG_LEVELS) . '.';
        }
        if (!in_array($config->string('logging.channel'), ['file', 'stderr'], true)) {
            $errors[] = 'LOG_CHANNEL must be "file" or "stderr".';
        }
        if (!in_array($config->string('logging.format'), ['line', 'json'], true)) {
            $errors[] = 'LOG_FORMAT must be "line" or "json".';
        }

        return $errors;
    }

    /** @throws ConfigurationException */
    public function assertValid(Config $config): void
    {
        $errors = $this->validate($config);
        if ($errors !== []) {
            throw new ConfigurationException($errors);
        }
    }

    public static function decodeKey(string $key): ?string
    {
        if (!str_starts_with($key, 'base64:')) {
            return null;
        }
        $decoded = base64_decode(substr($key, 7), true);

        return is_string($decoded) && strlen($decoded) === 32 ? $decoded : null;
    }

    public static function isHostname(string $host): bool
    {
        return $host === strtolower($host)
            && strlen($host) <= 253
            && preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/', $host) === 1;
    }
}
