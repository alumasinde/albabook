<?php

declare(strict_types=1);

namespace App\Core\View;

use App\Core\Config\Config;
use App\Core\Http\RequestContext;
use App\Shared\I18n\Translator;
use App\Shared\Money\CurrencyFormatter;
use App\Shared\Money\Money;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\Markup;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Template helpers. Templates format and translate; they never query or decide
 * (plan.md §16.1).
 */
final class AppExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly RequestContext $context,
        private readonly Translator $translator,
        private readonly AssetVersions $assets,
        private readonly Config $config,
        private readonly ?CurrencyFormatter $currency = null,
        private readonly ?\App\Modules\Auth\Context\AuthContext $auth = null,
    ) {}

    /** @return array<string, mixed> */
    public function getGlobals(): array
    {
        return [
            'app_name' => $this->config->string('app.name', 'AlbaBook'),
            'app_debug' => $this->config->isDebug(),
            'support_session' => $this->context->supportSession,
            'spa' => $this->context->isSpa,
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('t', fn(string $key, array $replace = []): string => $this->translator->trans($key, $replace)),
            new TwigFunction('asset', fn(string $path): string => $this->assets->url($path, $this->context->hostGroup)),
            new TwigFunction('csrf_token', fn(): string => $this->context->csrfToken ?? ''),
            new TwigFunction('csrf_field', fn(): Markup => new Markup(sprintf(
                '<input type="hidden" name="%s" value="%s">',
                htmlspecialchars($this->config->string('security.csrf.field', '_csrf'), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($this->context->csrfToken ?? '', ENT_QUOTES, 'UTF-8'),
            ), 'UTF-8')),
            new TwigFunction('csp_nonce', fn(): string => $this->context->cspNonce ?? ''),
            new TwigFunction('request_id', fn(): string => $this->context->requestId ?? ''),
            new TwigFunction('locale', fn(): string => $this->context->locale),
            new TwigFunction('host_group', fn(): ?string => $this->context->hostGroup?->value),
            // Signed-in tenant user for shared chrome (account menu). Read at
            // call time: middleware sets it after Twig is built.
            new TwigFunction('current_user', fn(): ?array => $this->auth?->user === null ? null : [
                'name' => $this->auth->user->displayName(),
                'email' => $this->auth->user->email,
                'initials' => mb_strtoupper(mb_substr($this->auth->user->firstName, 0, 1) . mb_substr($this->auth->user->lastName, 0, 1)),
            ]),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('money', function (Money $money, bool $alwaysShowDecimals = false): string {
                if ($this->currency === null) {
                    throw new \LogicException('CurrencyFormatter is not available.');
                }

                return $this->currency->format($money, $alwaysShowDecimals);
            }),
        ];
    }
}
